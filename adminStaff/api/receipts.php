<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';
require_once __DIR__ . '/recipe_helpers.php';
require_once __DIR__ . '/activity_log_helpers.php';

// Always return JSON on unexpected fatals/exceptions (Hostinger often returns a blank 500 page).
set_exception_handler(static function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (ob_get_length()) {
        @ob_clean();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'where' => basename((string)$e->getFile()) . ':' . (int)$e->getLine(),
    ]);
    exit;
});
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (ob_get_length()) {
        @ob_clean();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Fatal: ' . (string)$err['message'],
        'where' => basename((string)$err['file']) . ':' . (int)$err['line'],
    ]);
});

require_auth();

function normalize_receipt_entry_source(string $source): string
{
    $value = strtolower(trim($source));
    return $value === 'staff' ? 'staff' : 'admin';
}

function infer_receipt_entry_source(?string $storedSource, ?string $supplier): string
{
    $source = strtolower(trim((string)$storedSource));
    if ($source === 'staff' || $source === 'admin') {
        return $source;
    }
    if (stripos(trim((string)$supplier), 'Restocked by ') === 0) {
        return 'staff';
    }
    return 'admin';
}

function ensure_receipt_schema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS Receipts (
            ReceiptID INT AUTO_INCREMENT PRIMARY KEY,
            `Date` DATE NOT NULL,
            OrderedDate DATE NULL,
            ExpectedReceiveDate DATE NULL,
            Supplier VARCHAR(140) NOT NULL,
            TotalAmount DECIMAL(12,2) NOT NULL DEFAULT 0,
            CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $chkOrderedDate = $pdo->query("SHOW COLUMNS FROM Receipts LIKE 'OrderedDate'");
    if (!$chkOrderedDate || !$chkOrderedDate->fetch()) {
        try {
            $pdo->exec('ALTER TABLE Receipts ADD COLUMN OrderedDate DATE NULL AFTER `Date`');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkExpectedDate = $pdo->query("SHOW COLUMNS FROM Receipts LIKE 'ExpectedReceiveDate'");
    if (!$chkExpectedDate || !$chkExpectedDate->fetch()) {
        try {
            $pdo->exec('ALTER TABLE Receipts ADD COLUMN ExpectedReceiveDate DATE NULL AFTER OrderedDate');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
    $chkEntrySource = $pdo->query("SHOW COLUMNS FROM Receipts LIKE 'EntrySource'");
    if (!$chkEntrySource || !$chkEntrySource->fetch()) {
        try {
            $pdo->exec("ALTER TABLE Receipts ADD COLUMN EntrySource VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER Supplier");
        } catch (Throwable $e) {
            try {
                $pdo->exec("ALTER TABLE Receipts ADD COLUMN EntrySource VARCHAR(20) NOT NULL DEFAULT 'admin'");
            } catch (Throwable $e2) {
                // Ignore migration issues on environments with restricted ALTER privileges.
            }
        }
    }

    // Hostinger/InnoDB: constraint/index names are unique DB-wide. Leftover names
    // (errno 121) break CREATE even when ReceiptLines itself is missing.
    $receiptLinesExists = receipt_table_exists($pdo, 'ReceiptLines');
    $createError = '';

    if (!$receiptLinesExists) {
        $createAttempts = [
            // Minimal table — no named FK/index (avoids errno 121 collisions).
            'CREATE TABLE ReceiptLines (
                ReceiptLineID INT AUTO_INCREMENT PRIMARY KEY,
                ReceiptID INT NOT NULL,
                LineType VARCHAR(100) NOT NULL,
                ItemName VARCHAR(160) NOT NULL,
                Quantity DECIMAL(12,2) NOT NULL,
                UnitCost DECIMAL(12,2) NOT NULL,
                TotalCost DECIMAL(12,2) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            // Last resort: MyISAM has no InnoDB FK/index-name namespace issues.
            'CREATE TABLE ReceiptLines (
                ReceiptLineID INT AUTO_INCREMENT PRIMARY KEY,
                ReceiptID INT NOT NULL,
                LineType VARCHAR(100) NOT NULL,
                ItemName VARCHAR(160) NOT NULL,
                Quantity DECIMAL(12,2) NOT NULL,
                UnitCost DECIMAL(12,2) NOT NULL,
                TotalCost DECIMAL(12,2) NOT NULL
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($createAttempts as $sql) {
            try {
                $pdo->exec($sql);
                $receiptLinesExists = receipt_table_exists($pdo, 'ReceiptLines');
                if ($receiptLinesExists) {
                    $createError = '';
                    break;
                }
            } catch (Throwable $e) {
                $createError = $e->getMessage();
            }
        }
    }

    if (!$receiptLinesExists) {
        throw new RuntimeException(
            'ReceiptLines table is missing. In Hostinger phpMyAdmin, open database u462030735_ortus and run the SQL from the admin instructions (create ReceiptLines).'
            . ($createError !== '' ? ' Auto-create error: ' . $createError : '')
        );
    }

    // Optional FK (best-effort). Skip if constraint name already taken elsewhere.
    try {
        $pdo->exec(
            'ALTER TABLE ReceiptLines
                ADD CONSTRAINT fk_ortus_receipt_lines_receiptid
                FOREIGN KEY (ReceiptID) REFERENCES Receipts(ReceiptID)
                ON DELETE CASCADE'
        );
    } catch (Throwable $e) {
        // Non-fatal: register/stock-log work without the FK.
    }

    // Allow any inventory category name (not limited to Bar/Kitchen).
    try {
        $pdo->exec('ALTER TABLE ReceiptLines MODIFY COLUMN LineType VARCHAR(100) NOT NULL');
    } catch (Throwable $e) {
        // Ignore migration issues on environments with restricted ALTER privileges.
    }

    $chkInUse = $pdo->query("SHOW COLUMNS FROM ReceiptLines LIKE 'InUse'");
    if (!$chkInUse || !$chkInUse->fetch()) {
        try {
            $pdo->exec('ALTER TABLE ReceiptLines ADD COLUMN InUse DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER Quantity');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE ReceiptLines ADD COLUMN InUse DECIMAL(12,2) NOT NULL DEFAULT 0');
            } catch (Throwable $e2) {
                // Ignore.
            }
        }
    }

    $chkPerStock = $pdo->query("SHOW COLUMNS FROM ReceiptLines LIKE 'PerStockAmount'");
    if (!$chkPerStock || !$chkPerStock->fetch()) {
        try {
            $pdo->exec('ALTER TABLE ReceiptLines ADD COLUMN PerStockAmount DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER InUse');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE ReceiptLines ADD COLUMN PerStockAmount DECIMAL(12,2) NOT NULL DEFAULT 1');
            } catch (Throwable $e2) {
                // Ignore.
            }
        }
    }

    $chkUnit = $pdo->query("SHOW COLUMNS FROM ReceiptLines LIKE 'UnitName'");
    if (!$chkUnit || !$chkUnit->fetch()) {
        try {
            $pdo->exec("ALTER TABLE ReceiptLines ADD COLUMN UnitName VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER PerStockAmount");
        } catch (Throwable $e) {
            try {
                $pdo->exec("ALTER TABLE ReceiptLines ADD COLUMN UnitName VARCHAR(20) NOT NULL DEFAULT 'pcs'");
            } catch (Throwable $e2) {
                // Ignore.
            }
        }
    }
}

function receipt_table_exists(PDO $pdo, string $table): bool
{
    try {
        foreach ($pdo->query('SHOW TABLES') as $row) {
            $name = (string)(array_values($row)[0] ?? '');
            if (strcasecmp($name, $table) === 0) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function ensure_inventory_schema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_item_id INT NULL,
            item_name VARCHAR(140) NOT NULL,
            category_name VARCHAR(100) NOT NULL,
            supplier VARCHAR(140) NULL,
            stock_units INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 10,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_inventory_menu_item (menu_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $chkInUse = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'units_in_use'");
    if (!$chkInUse || !$chkInUse->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN units_in_use INT NOT NULL DEFAULT 0 AFTER stock_units');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkPerStock = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'per_stock_amount'");
    if (!$chkPerStock || !$chkPerStock->fetch()) {
        try {
            $pdo->exec('ALTER TABLE inventory_items ADD COLUMN per_stock_amount DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER units_in_use');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkPerStockUnit = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'per_stock_unit'");
    if (!$chkPerStockUnit || !$chkPerStockUnit->fetch()) {
        try {
            $pdo->exec("ALTER TABLE inventory_items ADD COLUMN per_stock_unit VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER per_stock_amount");
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkInUseMeta = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'units_in_use'");
    $inUseMeta = $chkInUseMeta ? $chkInUseMeta->fetch(PDO::FETCH_ASSOC) : false;
    $inUseType = strtolower((string)($inUseMeta['Type'] ?? ''));
    if ($inUseMeta && strpos($inUseType, 'decimal') === false && strpos($inUseType, 'float') === false && strpos($inUseType, 'double') === false) {
        try {
            $pdo->exec('ALTER TABLE inventory_items MODIFY COLUMN units_in_use DECIMAL(12,2) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
}

function normalize_units_in_use(float $inUse, float $perStockAmount, string $unitName): float {
    $unit = strtolower(trim($unitName));
    if ($inUse <= 0) return 0.0;
    if ($perStockAmount <= 0) return round($inUse, 2);
    if ($unit === 'pcs' || $unit === 'unit' || $unit === 'units') {
        return round($inUse, 2);
    }
    return round($inUse * $perStockAmount, 2);
}

/**
 * Receipt "open box" count → orders left in the single open box.
 * 1 means one full open box (item capacity), not 1 order slot.
 */
function receipt_open_boxes_to_orders_left(float $openBoxes, int $ordersPerBox): float
{
    if ($openBoxes < 1 || $ordersPerBox < 1) {
        return 0.0;
    }

    return (float)$ordersPerBox;
}

/**
 * Apply sealed stock + optional open-item count from a receipt line.
 */
function apply_receipt_stock_counts(
    int $incomingSealed,
    float $receiptOpenItems,
    int $existingSealed,
    float $existingOrdersLeft,
    int $existingOpenCount,
    int $ordersPerBox
): array {
    $stockUnits = $existingSealed + max(0, $incomingSealed);
    $ordersLeft = max(0, $existingOrdersLeft);
    $openCount = open_items_count_for_row([
        'open_items_count' => $existingOpenCount,
        'units_in_use' => $existingOrdersLeft,
    ]);
    $maxOpen = inventory_max_open_items();

    $addOpen = max(0, (int)round($receiptOpenItems));
    // Use configured capacity only — never invent "1" via per_stock_amount fallback.
    $capacity = max(0, $ordersPerBox);

    // Open-item field is ADDITIVE: current open + this entry (0 = no change to open).
    $requestedOpen = min($maxOpen, $openCount + $addOpen);

    try {
        $reconciled = reconcile_inventory_counts(
            $stockUnits,
            $ordersLeft,
            $openCount,
            $requestedOpen,
            $ordersLeft > 0 ? $ordersLeft : null,
            max(0, $capacity)
        );
        $stockUnits = $reconciled['stock_units'];
        $ordersLeft = $reconciled['units_in_use'];
        $openCount = $reconciled['open_items_count'];
    } catch (InvalidArgumentException $e) {
        // Not enough sealed stock to open that many — open as many as possible.
        while ($openCount < $requestedOpen && $stockUnits > 0) {
            $stockUnits -= 1;
            $openCount += 1;
        }
        if ($openCount > 0 && $ordersLeft <= 0 && $capacity > 0) {
            $ordersLeft = (float)$capacity;
        }
        if ($openCount <= 0) {
            $ordersLeft = 0;
        }
    }

    $openCount = max(0, min($maxOpen, $openCount));
    if ($openCount <= 0) {
        $ordersLeft = 0;
    }

    return [
        'stock_units' => max(0, $stockUnits),
        'units_in_use' => max(0, $ordersLeft),
        'open_items_count' => $openCount,
    ];
}

$pdo = db();
try {
    ensure_receipt_schema($pdo);
    ensure_inventory_items_base_schema($pdo);
    ensure_main_categories_schema($pdo);
    ensure_inventory_category_types_schema($pdo);
} catch (Throwable $e) {
    // Schema bootstrap should not hard-block register if core tables already exist.
    $hasInv = receipt_table_exists($pdo, 'inventory_items');
    $hasReceipts = receipt_table_exists($pdo, 'Receipts');
    $hasLines = receipt_table_exists($pdo, 'ReceiptLines');
    if (!$hasInv || !$hasReceipts || !$hasLines) {
        fail('Inventory schema setup failed: ' . $e->getMessage(), 500);
    }
}

if (method() === 'GET') {
    $requestedReceiptId = isset($_GET['receipt_id']) ? (int)$_GET['receipt_id'] : 0;
    if ($requestedReceiptId > 0) {
        $headStmt = $pdo->prepare(
            'SELECT ReceiptID, `Date`, OrderedDate, ExpectedReceiveDate, Supplier, TotalAmount, CreatedAt
             FROM Receipts
             WHERE ReceiptID = :receipt_id
             LIMIT 1'
        );
        $headStmt->execute([':receipt_id' => $requestedReceiptId]);
        $receipt = $headStmt->fetch();
        if (!$receipt) {
            fail('Receipt not found.', 404);
        }

        $lineStmt = $pdo->prepare(
            'SELECT
                ReceiptLineID,
                LineType,
                ItemName,
                Quantity,
                InUse,
                PerStockAmount,
                UnitName,
                UnitCost,
                TotalCost
             FROM ReceiptLines
             WHERE ReceiptID = :receipt_id
             ORDER BY ReceiptLineID ASC'
        );
        $lineStmt->execute([':receipt_id' => $requestedReceiptId]);
        $lines = [];
        foreach ($lineStmt->fetchAll() as $lineRow) {
            $lines[] = [
                'receipt_line_id' => (int)$lineRow['ReceiptLineID'],
                'line_type' => $lineRow['LineType'],
                'item_name' => $lineRow['ItemName'],
                'stocks' => (float)$lineRow['Quantity'],
                'quantity' => (float)$lineRow['Quantity'],
                'in_use' => (float)($lineRow['InUse'] ?? 0),
                'per_stock_amount' => (float)($lineRow['PerStockAmount'] ?? 1),
                'unit' => (string)($lineRow['UnitName'] ?? 'pcs'),
                'unit_cost' => (float)$lineRow['UnitCost'],
                'total_cost' => (float)$lineRow['TotalCost'],
            ];
        }

        ok([
            'receipt' => [
                'receipt_id' => (int)$receipt['ReceiptID'],
                'date' => $receipt['Date'],
                'ordered_date' => $receipt['OrderedDate'],
                'expected_receive_date' => $receipt['ExpectedReceiveDate'],
                'supplier' => $receipt['Supplier'],
                'total_amount' => (float)$receipt['TotalAmount'],
                'created_at' => $receipt['CreatedAt'],
            ],
            'lines' => $lines,
        ]);
    }

    $datesRaw = trim((string)($_GET['dates'] ?? ''));
    $dateList = [];
    if ($datesRaw !== '') {
        foreach (explode(',', $datesRaw) as $piece) {
            $piece = trim($piece);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $piece)) {
                $dateList[] = $piece;
            }
        }
        $dateList = array_values(array_unique($dateList));
    }

    if ($dateList) {
        $placeholders = [];
        $params = [];
        foreach ($dateList as $i => $dateValue) {
            $key = ':d' . $i;
            $placeholders[] = $key;
            $params[$key] = $dateValue;
        }
        $inClause = implode(', ', $placeholders);
        $stmt = $pdo->prepare(
            "SELECT
                r.ReceiptID,
                r.`Date`,
                r.OrderedDate,
                r.ExpectedReceiveDate,
                r.Supplier,
                r.EntrySource,
                r.TotalAmount,
                r.CreatedAt,
                COUNT(rl.ReceiptLineID) AS line_count
             FROM Receipts r
             LEFT JOIN ReceiptLines rl ON rl.ReceiptID = r.ReceiptID
             WHERE r.`Date` IN ($inClause)
             GROUP BY r.ReceiptID
             ORDER BY r.`Date` DESC, r.ReceiptID DESC
             LIMIT 500"
        );
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query(
            'SELECT
                r.ReceiptID,
                r.`Date`,
                r.OrderedDate,
                r.ExpectedReceiveDate,
                r.Supplier,
                r.EntrySource,
                r.TotalAmount,
                r.CreatedAt,
                COUNT(rl.ReceiptLineID) AS line_count
             FROM Receipts r
             LEFT JOIN ReceiptLines rl ON rl.ReceiptID = r.ReceiptID
             GROUP BY r.ReceiptID
             ORDER BY r.`Date` DESC, r.ReceiptID DESC
             LIMIT 100'
        );
    }
    $receipts = [];
    foreach ($stmt->fetchAll() as $row) {
        $receipts[] = [
            'receipt_id' => (int)$row['ReceiptID'],
            'date' => $row['Date'],
            'ordered_date' => $row['OrderedDate'],
            'expected_receive_date' => $row['ExpectedReceiveDate'],
            'supplier' => $row['Supplier'],
            'entry_source' => infer_receipt_entry_source($row['EntrySource'] ?? '', $row['Supplier'] ?? ''),
            'total_amount' => (float)$row['TotalAmount'],
            'line_count' => (int)$row['line_count'],
            'created_at' => $row['CreatedAt'],
        ];
    }
    ok(['receipts' => $receipts, 'dates' => $dateList]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

try {
$b = body();
$registerMode = strtolower(trim((string)($b['mode'] ?? ''))) === 'register';
$date = trim((string)($b['date'] ?? ''));
$orderedDate = trim((string)($b['ordered_date'] ?? ''));
$expectedReceiveDate = trim((string)($b['expected_receive_date'] ?? ''));
$supplier = trim((string)($b['supplier'] ?? ''));
$entrySource = normalize_receipt_entry_source((string)($b['entry_source'] ?? ''));
if ($entrySource === 'admin' && stripos($supplier, 'Restocked by ') === 0) {
    $entrySource = 'staff';
}
$linesRaw = $b['lines'] ?? [];

if ($date === '') {
    $date = date('Y-m-d');
}
if ($expectedReceiveDate === '') {
    $expectedReceiveDate = $date;
}

// Receipts.Supplier is NOT NULL — keep a placeholder there.
// inventory_items must NOT use "Unassigned" on register: inventory GET used to
// soft-delete those rows and they vanished from the list right after register.
$receiptSupplier = ($supplier !== '') ? $supplier : 'Unassigned';
$inventorySupplier = null;
if ($registerMode) {
    if ($supplier !== '' && strcasecmp($supplier, 'Unassigned') !== 0) {
        $inventorySupplier = $supplier;
    }
} else {
    $inventorySupplier = ($supplier !== '') ? $supplier : 'Unassigned';
}
if (!is_array($linesRaw) || count($linesRaw) === 0) fail('At least one line item is required.');

if (function_exists('assert_inventory_register_columns')) {
    assert_inventory_register_columns($pdo);
}

$lines = [];
foreach ($linesRaw as $line) {
    if (!is_array($line)) continue;
    $lineType = trim((string)($line['line_type'] ?? ''));
    $itemName = trim((string)($line['item_name'] ?? ''));
    $stocks = (float)($line['stocks'] ?? $line['quantity'] ?? 0);
    $inUse = (float)($line['in_use'] ?? 0);
    $perStockAmount = max(0.01, (float)($line['per_stock_amount'] ?? 1));
    $unitName = trim((string)($line['unit'] ?? 'pcs'));
    $unitCost = (float)($line['unit_cost'] ?? 0);
    $totalCost = (float)($line['total_cost'] ?? 0);

    if ($lineType === '' || strlen($lineType) > 100) {
        fail('Category is required for all lines.');
    }
    if (!main_category_name_is_valid($pdo, $lineType)) {
        fail('Invalid main category. Choose Bar, Kitchen, or another active main category.');
    }
    if ($itemName === '') {
        fail('Item name is required for all lines.');
    }
    if ($stocks < 0 || $inUse < 0 || $unitCost < 0 || $totalCost < 0) {
        fail('Line values are invalid.');
    }

    $categoryType = normalize_inventory_category_type($line['category_type'] ?? 'main');
    // Register mode: Mode comes from the category type, not the line item.
    $entryMode = $registerMode
        ? entry_mode_for_inventory_category_type($pdo, $categoryType)
        : normalize_inventory_entry_mode($line['entry_mode'] ?? 'automatic');

    $lines[] = [
        'line_type' => $lineType,
        'item_name' => $itemName,
        'stocks' => $stocks,
        'in_use' => $inUse,
        'per_stock_amount' => $perStockAmount,
        'unit' => ($unitName !== '' ? substr($unitName, 0, 20) : 'pcs'),
        'unit_cost' => $unitCost,
        'total_cost' => $totalCost,
        'stock_type' => normalize_inventory_stock_type($line['stock_type'] ?? 'consumable'),
        'entry_mode' => $entryMode,
        'stock_status' => normalize_inventory_stock_status($line['stock_status'] ?? 'good'),
        'category_type' => $categoryType,
    ];
}

if (count($lines) === 0) fail('No valid line items found.');

if ($registerMode) {
    $seenRegisterKeys = [];
    foreach ($lines as $line) {
        $key = strtolower(trim((string)$line['item_name'])) . "\0" . trim((string)$line['line_type']);
        if (isset($seenRegisterKeys[$key])) {
            fail('Item already registered.');
        }
        $seenRegisterKeys[$key] = true;
    }
    $dupCheckStmt = $pdo->prepare(
        'SELECT id
         FROM inventory_items
         WHERE menu_item_id IS NULL
           AND is_active = 1
           AND LOWER(TRIM(item_name)) = LOWER(TRIM(:item_name))
           AND category_name = :category_name
         LIMIT 1'
    );
    foreach ($lines as $line) {
        $dupCheckStmt->execute([
            ':item_name' => $line['item_name'],
            ':category_name' => $line['line_type'],
        ]);
        if ($dupCheckStmt->fetch()) {
            fail('Item already registered.');
        }
    }
}

    $pdo->beginTransaction();
    $totalAmount = array_sum(array_column($lines, 'total_cost'));
    // Register = catalog only. Stock Log (Receipts/ReceiptLines) is supply-in only.
    $receiptId = null;
    $hasReceiptInUse = false;
    $hasReceiptPerStock = false;
    $hasReceiptUnit = false;
    $lineStmt = null;

    if (!$registerMode) {
        $hasEntrySource = false;
        try {
            $chkEs = $pdo->query("SHOW COLUMNS FROM Receipts LIKE 'EntrySource'");
            $hasEntrySource = $chkEs && $chkEs->fetch();
        } catch (Throwable $e) {
            $hasEntrySource = false;
        }

        $hasOrderedDate = false;
        $hasExpectedDate = false;
        try {
            $hasOrderedDate = (bool)$pdo->query("SHOW COLUMNS FROM Receipts LIKE 'OrderedDate'")->fetch();
            $hasExpectedDate = (bool)$pdo->query("SHOW COLUMNS FROM Receipts LIKE 'ExpectedReceiveDate'")->fetch();
        } catch (Throwable $e) {
            // ignore
        }

        if ($hasEntrySource && $hasOrderedDate && $hasExpectedDate) {
            $receiptStmt = $pdo->prepare(
                'INSERT INTO Receipts (`Date`, OrderedDate, ExpectedReceiveDate, Supplier, EntrySource, TotalAmount)
                 VALUES (:date, :ordered_date, :expected_receive_date, :supplier, :entry_source, :total_amount)'
            );
            $receiptStmt->execute([
                ':date' => $date,
                ':ordered_date' => ($orderedDate !== '' ? $orderedDate : null),
                ':expected_receive_date' => $expectedReceiveDate,
                ':supplier' => $receiptSupplier,
                ':entry_source' => $entrySource,
                ':total_amount' => $totalAmount,
            ]);
        } elseif ($hasOrderedDate && $hasExpectedDate) {
            $receiptStmt = $pdo->prepare(
                'INSERT INTO Receipts (`Date`, OrderedDate, ExpectedReceiveDate, Supplier, TotalAmount)
                 VALUES (:date, :ordered_date, :expected_receive_date, :supplier, :total_amount)'
            );
            $receiptStmt->execute([
                ':date' => $date,
                ':ordered_date' => ($orderedDate !== '' ? $orderedDate : null),
                ':expected_receive_date' => $expectedReceiveDate,
                ':supplier' => $receiptSupplier,
                ':total_amount' => $totalAmount,
            ]);
        } else {
            $receiptStmt = $pdo->prepare(
                'INSERT INTO Receipts (`Date`, Supplier, TotalAmount)
                 VALUES (:date, :supplier, :total_amount)'
            );
            $receiptStmt->execute([
                ':date' => $date,
                ':supplier' => $receiptSupplier,
                ':total_amount' => $totalAmount,
            ]);
        }
        $receiptId = (int)$pdo->lastInsertId();

        $receiptLineCols = [];
        try {
            foreach ($pdo->query('SHOW COLUMNS FROM ReceiptLines') as $col) {
                $receiptLineCols[strtolower((string)$col['Field'])] = true;
            }
        } catch (Throwable $e) {
            $receiptLineCols = [];
        }
        $hasReceiptInUse = isset($receiptLineCols['inuse']);
        $hasReceiptPerStock = isset($receiptLineCols['perstockamount']);
        $hasReceiptUnit = isset($receiptLineCols['unitname']);
        if ($hasReceiptInUse && $hasReceiptPerStock && $hasReceiptUnit) {
            $lineStmt = $pdo->prepare(
                'INSERT INTO ReceiptLines
                    (ReceiptID, LineType, ItemName, Quantity, InUse, PerStockAmount, UnitName, UnitCost, TotalCost)
                 VALUES
                    (:receipt_id, :line_type, :item_name, :stocks, :in_use, :per_stock_amount, :unit_name, :unit_cost, :total_cost)'
            );
        } else {
            $lineStmt = $pdo->prepare(
                'INSERT INTO ReceiptLines
                    (ReceiptID, LineType, ItemName, Quantity, UnitCost, TotalCost)
                 VALUES
                    (:receipt_id, :line_type, :item_name, :stocks, :unit_cost, :total_cost)'
            );
        }
    }

    $invCols = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM inventory_items') as $col) {
            $invCols[strtolower((string)$col['Field'])] = true;
        }
    } catch (Throwable $e) {
        $invCols = [];
    }
    $findSelect = ['id', 'stock_units', 'category_name'];
    foreach (['units_in_use', 'open_items_count', 'orders_per_box', 'per_stock_amount', 'per_stock_unit', 'stock_type'] as $optionalCol) {
        if (isset($invCols[$optionalCol])) {
            $findSelect[] = $optionalCol;
        }
    }
    $findInventoryStmt = $pdo->prepare(
        'SELECT ' . implode(', ', $findSelect) . '
         FROM inventory_items
         WHERE menu_item_id IS NULL
           AND is_active = 1
           AND LOWER(TRIM(item_name)) = LOWER(TRIM(:item_name))
           AND category_name = :category_name
         LIMIT 1'
    );

    $updateInventorySet = ['stock_units = :stock_units', 'supplier = :supplier', 'unit_cost = :unit_cost'];
    if (isset($invCols['units_in_use'])) $updateInventorySet[] = 'units_in_use = :units_in_use';
    if (isset($invCols['open_items_count'])) $updateInventorySet[] = 'open_items_count = :open_items_count';
    $updateInventoryStmt = $pdo->prepare(
        'UPDATE inventory_items SET ' . implode(', ', $updateInventorySet) . ' WHERE id = :id'
    );

    $createCols = ['menu_item_id', 'item_name', 'category_name', 'supplier', 'stock_units', 'reorder_level', 'unit_cost', 'is_active'];
    $createVals = ['NULL', ':item_name', ':category_name', ':supplier', ':stock_units', '10', ':unit_cost', '1'];
    $createParamsBase = true;
    foreach ([
        'category_type' => ':category_type',
        'units_in_use' => ':units_in_use',
        'open_items_count' => ':open_items_count',
        'per_stock_amount' => ':per_stock_amount',
        'per_stock_unit' => ':per_stock_unit',
        'stock_type' => ':stock_type',
        'entry_mode' => ':entry_mode',
        'stock_status' => ':stock_status',
    ] as $colName => $placeholder) {
        if (!isset($invCols[$colName])) {
            continue;
        }
        // Insert category_type after category_name when present.
        if ($colName === 'category_type') {
            array_splice($createCols, 3, 0, [$colName]);
            array_splice($createVals, 3, 0, [$placeholder]);
        } else {
            $createCols[] = $colName;
            $createVals[] = $placeholder;
        }
    }
    $createInventoryStmt = $pdo->prepare(
        'INSERT INTO inventory_items (' . implode(', ', $createCols) . ') VALUES (' . implode(', ', $createVals) . ')'
    );
    unset($createParamsBase);

    foreach ($lines as $line) {
        // Register does not add supply — stock starts at 0 until Stock Log / restock.
        $incomingUnits = $registerMode ? 0 : (int)round((float)$line['stocks']);
        if ($incomingUnits < 0) $incomingUnits = 0;
        $receiptOpenBoxes = $registerMode ? 0.0 : (float)$line['in_use'];

        $findInventoryStmt->execute([
            ':item_name' => $line['item_name'],
            ':category_name' => $line['line_type'],
        ]);
        $existingInventory = $findInventoryStmt->fetch();

        // Stock log entries keep the registered unit label; do not overwrite it.
        $lineUnit = (string)($line['unit'] ?? 'pcs');
        if (!$registerMode && $existingInventory) {
            $registeredUnit = trim((string)($existingInventory['per_stock_unit'] ?? ''));
            if ($registeredUnit !== '') {
                $lineUnit = $registeredUnit;
            }
        }
        $line['unit'] = ($lineUnit !== '' ? substr($lineUnit, 0, 20) : 'pcs');

        if (!$registerMode && $lineStmt && $receiptId) {
            $lineParams = [
                ':receipt_id' => $receiptId,
                ':line_type' => $line['line_type'],
                ':item_name' => $line['item_name'],
                ':stocks' => $line['stocks'],
                ':unit_cost' => $line['unit_cost'],
                ':total_cost' => $line['total_cost'],
            ];
            if ($hasReceiptInUse && $hasReceiptPerStock && $hasReceiptUnit) {
                $lineParams[':in_use'] = $line['in_use'];
                $lineParams[':per_stock_amount'] = $line['per_stock_amount'];
                $lineParams[':unit_name'] = $line['unit'];
            }
            $lineStmt->execute($lineParams);
        }

        if ($existingInventory) {
            if ($registerMode) {
                // Defensive: pre-check above should already block duplicates.
                fail('Item already registered.');
            }

            $usesBatch = inventory_uses_batch_logic($existingInventory);
            if ($usesBatch) {
                $batchSize = batch_size_for_inventory_row($existingInventory);
                $counts = apply_batch_receipt_stock_counts(
                    $incomingUnits,
                    (int)round($receiptOpenBoxes),
                    (int)$existingInventory['stock_units'],
                    (float)($existingInventory['units_in_use'] ?? 0),
                    $batchSize
                );
            } else {
                $ordersPerBox = configured_item_capacity($existingInventory);
                $counts = apply_receipt_stock_counts(
                    $incomingUnits,
                    $receiptOpenBoxes,
                    (int)$existingInventory['stock_units'],
                    (float)($existingInventory['units_in_use'] ?? 0),
                    (int)($existingInventory['open_items_count'] ?? 0),
                    $ordersPerBox
                );
            }
            $updParams = [
                ':stock_units' => $counts['stock_units'],
                ':supplier' => $inventorySupplier,
                ':unit_cost' => $line['unit_cost'],
                ':id' => (int)$existingInventory['id'],
            ];
            if (isset($invCols['units_in_use'])) $updParams[':units_in_use'] = $counts['units_in_use'];
            if (isset($invCols['open_items_count'])) $updParams[':open_items_count'] = $counts['open_items_count'];
            $updateInventoryStmt->execute($updParams);
            continue;
        }

        if ($registerMode) {
            $counts = [
                'stock_units' => 0,
                'units_in_use' => 0.0,
                'open_items_count' => 0,
            ];
        } else {
            $usesBatch = inventory_uses_batch_logic([
                'stock_type' => $line['stock_type'],
                'category_name' => $line['line_type'],
            ]);
            if ($usesBatch) {
                $batchSize = batch_size_for_inventory_row([
                    'category_name' => $line['line_type'],
                    'orders_per_box' => 0,
                    'per_stock_amount' => $line['per_stock_amount'] ?? 1,
                ]);
                $counts = apply_batch_receipt_stock_counts(
                    $incomingUnits,
                    (int)round($receiptOpenBoxes),
                    0,
                    0.0,
                    $batchSize
                );
            } else {
                // New inventory rows have no Item Capacity yet — don't invent capacity = 1.
                $counts = apply_receipt_stock_counts(
                    $incomingUnits,
                    $receiptOpenBoxes,
                    0,
                    0.0,
                    0,
                    0
                );
            }
        }

        $createParams = [
            ':item_name' => $line['item_name'],
            ':category_name' => $line['line_type'],
            ':supplier' => $inventorySupplier,
            ':stock_units' => $counts['stock_units'],
            ':unit_cost' => $line['unit_cost'],
        ];
        if (isset($invCols['category_type'])) $createParams[':category_type'] = $line['category_type'];
        if (isset($invCols['units_in_use'])) $createParams[':units_in_use'] = $counts['units_in_use'];
        if (isset($invCols['open_items_count'])) $createParams[':open_items_count'] = $counts['open_items_count'];
        if (isset($invCols['per_stock_amount'])) $createParams[':per_stock_amount'] = $line['per_stock_amount'];
        if (isset($invCols['per_stock_unit'])) $createParams[':per_stock_unit'] = $line['unit'];
        if (isset($invCols['stock_type'])) $createParams[':stock_type'] = $line['stock_type'];
        if (isset($invCols['entry_mode'])) $createParams[':entry_mode'] = $line['entry_mode'];
        if (isset($invCols['stock_status'])) $createParams[':stock_status'] = $line['stock_status'];
        $createInventoryStmt->execute($createParams);
    }

    $pdo->commit();

    $isStaffRestock = $entrySource === 'staff' || stripos($supplier, 'Restocked by ') === 0;
    $restockActor = $isStaffRestock
        ? activity_actor_from_restock_supplier($supplier)
        : activity_actor_from_session();

    foreach ($lines as $line) {
        $itemName = trim((string)($line['item_name'] ?? 'Item')) ?: 'Item';
        $qty = (float)($line['stocks'] ?? 0);
        $unit = trim((string)($line['unit'] ?? 'pcs')) ?: 'pcs';
        $qtyText = activity_format_qty_unit($qty, $unit);

        if ($registerMode) {
            log_system_activity($pdo, [
                'source_key' => 'register_inventory',
                'source_label' => 'Register Inventory Item',
                'action' => 'inventory item registered: ' . $itemName,
                'entity_type' => 'inventory_item',
                'user' => activity_actor_from_session(),
            ]);
            continue;
        }

        if ($isStaffRestock) {
            log_system_activity($pdo, [
                'source_key' => 'restock_inventory',
                'source_label' => 'Restock Inventory',
                'action' => strtolower($itemName) . ' restock to ' . $qtyText,
                'entity_type' => 'inventory_item',
                'user' => $restockActor,
            ]);
            continue;
        }

        log_system_activity($pdo, [
            'source_key' => 'stock_log',
            'source_label' => 'Stock Log',
            'action' => strtolower($itemName) . ' restock to ' . $qtyText,
            'entity_type' => 'inventory_item',
            'user' => activity_actor_from_session(),
        ]);
    }

    $okPayload = [
        'message' => $registerMode ? 'Inventory item registered.' : 'Receipt saved.',
    ];
    if (!$registerMode) {
        $okPayload['receipt_id'] = $receiptId;
        $okPayload['total_amount'] = (float)$totalAmount;
    }
    ok($okPayload, 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $prefix = (!empty($registerMode))
        ? 'Failed to register inventory item: '
        : 'Failed to save receipt: ';
    fail($prefix . $e->getMessage(), 500);
}
