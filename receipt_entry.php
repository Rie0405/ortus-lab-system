<?php
$errors = [];
$successMessage = '';

const DB_HOST = 'localhost';
const DB_PORT = '3308';
const DB_NAME = 'ortus_db';
const DB_USER = 'root';
const DB_PASS = '';

function create_pdo(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['date'] ?? '');
    $supplier = trim($_POST['supplier'] ?? '');
    $lineTypes = $_POST['line_type'] ?? [];
    $itemNames = $_POST['item_name'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitCosts = $_POST['unit_cost'] ?? [];
    $totalCosts = $_POST['total_cost'] ?? [];

    if ($date === '') {
        $errors[] = 'Date is required.';
    }
    if ($supplier === '') {
        $errors[] = 'Supplier is required.';
    }

    $lines = [];
    $lineCount = max(
        count($lineTypes),
        count($itemNames),
        count($quantities),
        count($unitCosts),
        count($totalCosts)
    );

    for ($i = 0; $i < $lineCount; $i++) {
        $lineType = trim((string)($lineTypes[$i] ?? ''));
        $itemName = trim((string)($itemNames[$i] ?? ''));
        $quantity = (float)($quantities[$i] ?? 0);
        $unitCost = (float)($unitCosts[$i] ?? 0);
        $totalCost = (float)($totalCosts[$i] ?? 0);

        $isEmptyRow = $lineType === '' && $itemName === '' && $quantity == 0 && $unitCost == 0 && $totalCost == 0;
        if ($isEmptyRow) {
            continue;
        }

        if (!in_array($lineType, ['Menu', 'Ingredient'], true)) {
            $errors[] = 'Each line must have a valid LineType.';
            continue;
        }
        if ($itemName === '') {
            $errors[] = 'Each line must have an ItemName.';
            continue;
        }
        if ($quantity <= 0 || $unitCost < 0 || $totalCost < 0) {
            $errors[] = 'Quantity must be greater than 0, and costs cannot be negative.';
            continue;
        }

        $lines[] = [
            'line_type' => $lineType,
            'item_name' => $itemName,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
        ];
    }

    if (count($lines) === 0) {
        $errors[] = 'At least one valid line item is required.';
    }

    if (count($errors) === 0) {
        try {
            $pdo = create_pdo();
            $pdo->beginTransaction();

            $totalAmount = array_sum(array_column($lines, 'total_cost'));

            $receiptStmt = $pdo->prepare(
                'INSERT INTO Receipts (`Date`, Supplier, TotalAmount) VALUES (?, ?, ?)'
            );
            $receiptStmt->execute([$date, $supplier, $totalAmount]);

            $receiptId = (int)$pdo->lastInsertId();

            $lineStmt = $pdo->prepare(
                'INSERT INTO ReceiptLines (ReceiptID, LineType, ItemName, Quantity, UnitCost, TotalCost)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($lines as $line) {
                $lineStmt->execute([
                    $receiptId,
                    $line['line_type'],
                    $line['item_name'],
                    $line['quantity'],
                    $line['unit_cost'],
                    $line['total_cost'],
                ]);
            }

            $pdo->commit();
            $successMessage = "Receipt saved successfully. ReceiptID: {$receiptId}";
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to save receipt: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Entry</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { margin-bottom: 16px; }
        .row { margin-bottom: 12px; }
        label { display: inline-block; width: 100px; }
        input, select, button { padding: 6px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .actions { margin-top: 14px; display: flex; gap: 10px; }
        .error { background: #ffe8e8; color: #9a0000; padding: 10px; margin-bottom: 12px; }
        .success { background: #e8ffed; color: #0e6b21; padding: 10px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>Receipt Entry</h1>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="row">
            <label for="date">Date</label>
            <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($_POST['date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="row">
            <label for="supplier">Supplier</label>
            <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars($_POST['supplier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <table id="lineItemsTable">
            <thead>
                <tr>
                    <th>LineType</th>
                    <th>ItemName</th>
                    <th>Quantity</th>
                    <th>UnitCost</th>
                    <th>TotalCost</th>
                </tr>
            </thead>
            <tbody id="lineItemsBody">
                <tr>
                    <td>
                        <select name="line_type[]" required>
                            <option value="Menu">Menu</option>
                            <option value="Ingredient">Ingredient</option>
                        </select>
                    </td>
                    <td><input type="text" name="item_name[]" required></td>
                    <td><input type="number" step="0.01" min="0.01" name="quantity[]" required></td>
                    <td><input type="number" step="0.01" min="0" name="unit_cost[]" required></td>
                    <td><input type="number" step="0.01" min="0" name="total_cost[]" required></td>
                </tr>
            </tbody>
        </table>

        <div class="actions">
            <button type="button" id="addLineBtn">Add line</button>
            <button type="submit">Save</button>
        </div>
    </form>

    <script>
        const bodyEl = document.getElementById('lineItemsBody');
        const addLineBtn = document.getElementById('addLineBtn');

        addLineBtn.addEventListener('click', function () {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <select name="line_type[]" required>
                        <option value="Menu">Menu</option>
                        <option value="Ingredient">Ingredient</option>
                    </select>
                </td>
                <td><input type="text" name="item_name[]" required></td>
                <td><input type="number" step="0.01" min="0.01" name="quantity[]" required></td>
                <td><input type="number" step="0.01" min="0" name="unit_cost[]" required></td>
                <td><input type="number" step="0.01" min="0" name="total_cost[]" required></td>
            `;
            bodyEl.appendChild(tr);
        });
    </script>
</body>
</html>
