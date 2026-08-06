<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/menu_helpers.php';
require_once __DIR__ . '/recipe_helpers.php';
require_auth();

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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ReceiptLines (
            ReceiptLineID INT AUTO_INCREMENT PRIMARY KEY,
            ReceiptID INT NOT NULL,
            LineType VARCHAR(100) NOT NULL,
            ItemName VARCHAR(160) NOT NULL,
            Quantity DECIMAL(12,2) NOT NULL,
            UnitCost DECIMAL(12,2) NOT NULL,
            TotalCost DECIMAL(12,2) NOT NULL,
            CONSTRAINT fk_receipt_lines_receipt
                FOREIGN KEY (ReceiptID) REFERENCES Receipts(ReceiptID)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

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
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkPerStock = $pdo->query("SHOW COLUMNS FROM ReceiptLines LIKE 'PerStockAmount'");
    if (!$chkPerStock || !$chkPerStock->fetch()) {
        try {
            $pdo->exec('ALTER TABLE ReceiptLines ADD COLUMN PerStockAmount DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER InUse');
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }

    $chkUnit = $pdo->query("SHOW COLUMNS FROM ReceiptLines LIKE 'UnitName'");
    if (!$chkUnit || !$chkUnit->fetch()) {
        try {
            $pdo->exec("ALTER TABLE ReceiptLines ADD COLUMN UnitName VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER PerStockAmount");
        } catch (Throwable $e) {
            // Ignore migration issues on environments with restricted ALTER privileges.
        }
    }
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
ensure_receipt_schema($pdo);
ensure_inventory_items_base_schema($pdo);

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

    $stmt = $pdo->query(
        'SELECT
            r.ReceiptID,
            r.`Date`,
            r.OrderedDate,
            r.ExpectedReceiveDate,
            r.Supplier,
            r.TotalAmount,
            r.CreatedAt,
            COUNT(rl.ReceiptLineID) AS line_count
         FROM Receipts r
         LEFT JOIN ReceiptLines rl ON rl.ReceiptID = r.ReceiptID
         GROUP BY r.ReceiptID
         ORDER BY r.`Date` DESC, r.ReceiptID DESC
         LIMIT 100'
    );
    $receipts = [];
    foreach ($stmt->fetchAll() as $row) {
        $receipts[] = [
            'receipt_id' => (int)$row['ReceiptID'],
            'date' => $row['Date'],
            'ordered_date' => $row['OrderedDate'],
            'expected_receive_date' => $row['ExpectedReceiveDate'],
            'supplier' => $row['Supplier'],
            'total_amount' => (float)$row['TotalAmount'],
            'line_count' => (int)$row['line_count'],
            'created_at' => $row['CreatedAt'],
        ];
    }
    ok(['receipts' => $receipts]);
}

if (method() !== 'POST') {
    fail('Method not allowed.', 405);
}

$b = body();
$date = trim((string)($b['date'] ?? ''));
$orderedDate = trim((string)($b['ordered_date'] ?? ''));
$expectedReceiveDate = trim((string)($b['expected_receive_date'] ?? ''));
$supplier = trim((string)($b['supplier'] ?? ''));
$linesRaw = $b['lines'] ?? [];

if ($date === '') fail('Date ordered is required.');
if ($expectedReceiveDate === '') fail('Date received is required.');
if ($supplier === '') {
    $supplier = 'Unassigned';
}
if (!is_array($linesRaw) || count($linesRaw) === 0) fail('At least one line item is required.');

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
    ];
}

if (count($lines) === 0) fail('No valid line items found.');

try {
    $pdo->beginTransaction();
    $totalAmount = array_sum(array_column($lines, 'total_cost'));

    $receiptStmt = $pdo->prepare(
        'INSERT INTO Receipts (`Date`, OrderedDate, ExpectedReceiveDate, Supplier, TotalAmount)
         VALUES (:date, :ordered_date, :expected_receive_date, :supplier, :total_amount)'
    );
    $receiptStmt->execute([
        ':date' => $date,
        ':ordered_date' => ($orderedDate !== '' ? $orderedDate : null),
        ':expected_receive_date' => $expectedReceiveDate,
        ':supplier' => $supplier,
        ':total_amount' => $totalAmount,
    ]);

    $receiptId = (int)$pdo->lastInsertId();
    $lineStmt = $pdo->prepare(
        'INSERT INTO ReceiptLines
            (ReceiptID, LineType, ItemName, Quantity, InUse, PerStockAmount, UnitName, UnitCost, TotalCost)
         VALUES
            (:receipt_id, :line_type, :item_name, :stocks, :in_use, :per_stock_amount, :unit_name, :unit_cost, :total_cost)'
    );
    $findInventoryStmt = $pdo->prepare(
        'SELECT id, stock_units, units_in_use, open_items_count, orders_per_box, per_stock_amount, stock_type, category_name
         FROM inventory_items
         WHERE menu_item_id IS NULL
           AND is_active = 1
           AND LOWER(TRIM(item_name)) = LOWER(TRIM(:item_name))
           AND category_name = :category_name
         LIMIT 1'
    );
    $updateInventoryStmt = $pdo->prepare(
        'UPDATE inventory_items
         SET stock_units = :stock_units,
             units_in_use = :units_in_use,
             open_items_count = :open_items_count,
             supplier = :supplier,
             unit_cost = :unit_cost
         WHERE id = :id'
    );
    $createInventoryStmt = $pdo->prepare(
        'INSERT INTO inventory_items
            (menu_item_id, item_name, category_name, supplier, stock_units, units_in_use, open_items_count, per_stock_amount, per_stock_unit, reorder_level, unit_cost, stock_type, is_active)
         VALUES
            (NULL, :item_name, :category_name, :supplier, :stock_units, :units_in_use, :open_items_count, :per_stock_amount, :per_stock_unit, 10, :unit_cost, :stock_type, 1)'
    );

    foreach ($lines as $line) {
        $lineStmt->execute([
            ':receipt_id' => $receiptId,
            ':line_type' => $line['line_type'],
            ':item_name' => $line['item_name'],
            ':stocks' => $line['stocks'],
            ':in_use' => $line['in_use'],
            ':per_stock_amount' => $line['per_stock_amount'],
            ':unit_name' => $line['unit'],
            ':unit_cost' => $line['unit_cost'],
            ':total_cost' => $line['total_cost'],
        ]);

        $incomingUnits = (int)round((float)$line['stocks']);
        if ($incomingUnits < 0) $incomingUnits = 0;
        $receiptOpenBoxes = (float)$line['in_use'];

        $findInventoryStmt->execute([
            ':item_name' => $line['item_name'],
            ':category_name' => $line['line_type'],
        ]);
        $existingInventory = $findInventoryStmt->fetch();

        if ($existingInventory) {
            $usesBatch = inventory_uses_batch_logic($existingInventory);
            if ($usesBatch) {
                $batchSize = batch_size_for_inventory_row($existingInventory);
                $counts = apply_batch_receipt_stock_counts(
                    $incomingUnits,
                    (int)round($receiptOpenBoxes),
                    (int)$existingInventory['stock_units'],
                    (float)$existingInventory['units_in_use'],
                    $batchSize
                );
            } else {
                $ordersPerBox = configured_item_capacity($existingInventory);
                $counts = apply_receipt_stock_counts(
                    $incomingUnits,
                    $receiptOpenBoxes,
                    (int)$existingInventory['stock_units'],
                    (float)$existingInventory['units_in_use'],
                    (int)($existingInventory['open_items_count'] ?? 0),
                    $ordersPerBox
                );
            }
            $updateInventoryStmt->execute([
                ':stock_units' => $counts['stock_units'],
                ':units_in_use' => $counts['units_in_use'],
                ':open_items_count' => $counts['open_items_count'],
                ':supplier' => $supplier,
                ':unit_cost' => $line['unit_cost'],
                ':id' => (int)$existingInventory['id'],
            ]);
            if ((int)$counts['open_items_count'] <= 0 && !$usesBatch) {
                ensure_open_box_when_stock_available($pdo, (int)$existingInventory['id']);
            }
            continue;
        }

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

        $createInventoryStmt->execute([
            ':item_name' => $line['item_name'],
            ':category_name' => $line['line_type'],
            ':supplier' => $supplier,
            ':stock_units' => $counts['stock_units'],
            ':units_in_use' => $counts['units_in_use'],
            ':open_items_count' => $counts['open_items_count'],
            ':per_stock_amount' => $line['per_stock_amount'],
            ':per_stock_unit' => $line['unit'],
            ':unit_cost' => $line['unit_cost'],
            ':stock_type' => $line['stock_type'],
        ]);
        $newInvId = (int)$pdo->lastInsertId();
        if ((int)$counts['open_items_count'] <= 0 && !$usesBatch) {
            ensure_open_box_when_stock_available($pdo, $newInvId);
        }
    }

    $pdo->commit();
    ok([
        'receipt_id' => $receiptId,
        'total_amount' => (float)$totalAmount,
        'message' => 'Receipt saved.'
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('Failed to save receipt: ' . $e->getMessage(), 500);
}
