<?php

/**
 * Cost per use = (bulk price / pack size) × amount used in recipe.
 * Example: (₱600 / 1000g) × 18g = ₱10.80
 */

function normalize_recipe_measure_unit(string $unit): string
{
    $u = strtolower(trim($unit));
    if ($u === '' || $u === 'units') {
        return 'unit';
    }
    return $u;
}

function recipe_measure_family(string $unit): string
{
    $u = normalize_recipe_measure_unit($unit);
    if ($u === 'g' || $u === 'kg') {
        return 'weight';
    }
    if ($u === 'ml' || $u === 'l') {
        return 'volume';
    }
    return 'count';
}

/**
 * @return array{family:string,amount:float}
 */
function recipe_amount_to_base(float $amount, string $unit): array
{
    $u = normalize_recipe_measure_unit($unit);
    $n = max(0, $amount);

    if ($u === 'kg') {
        return ['family' => 'weight', 'amount' => $n * 1000.0];
    }
    if ($u === 'g') {
        return ['family' => 'weight', 'amount' => $n];
    }
    if ($u === 'l') {
        return ['family' => 'volume', 'amount' => $n * 1000.0];
    }
    if ($u === 'ml') {
        return ['family' => 'volume', 'amount' => $n];
    }

    return ['family' => 'count', 'amount' => $n];
}

function compute_ingredient_line_cost(
    float $bulkPrice,
    float $packAmount,
    string $packUnit,
    float $useAmount,
    string $useUnit
): float {
    $bulkPrice = max(0, $bulkPrice);
    $packAmount = max(0, $packAmount);
    $useAmount = max(0, $useAmount);

    if ($bulkPrice <= 0 || $useAmount <= 0) {
        return 0.0;
    }
    if ($packAmount <= 0) {
        $packAmount = 1.0;
    }

    $packBase = recipe_amount_to_base($packAmount, $packUnit);
    $useBase = recipe_amount_to_base($useAmount, $useUnit);

    if ($packBase['family'] !== $useBase['family'] || $packBase['amount'] <= 0) {
        return 0.0;
    }

    $pricePerBaseUnit = $bulkPrice / $packBase['amount'];

    return round($pricePerBaseUnit * $useBase['amount'], 2);
}

function compute_ingredient_line_cost_from_inventory_row(?array $row, float $useAmount, string $useUnit): float
{
    if (!$row) {
        return 0.0;
    }

    return compute_ingredient_line_cost(
        (float)($row['unit_cost'] ?? 0),
        (float)($row['per_stock_amount'] ?? 1),
        (string)($row['per_stock_unit'] ?? 'pcs'),
        $useAmount,
        $useUnit
    );
}

function fetch_inventory_cost_row(PDO $pdo, ?int $inventoryItemId, string $ingredientName): ?array
{
    if ($inventoryItemId && $inventoryItemId > 0) {
        $stmt = $pdo->prepare(
            'SELECT unit_cost, per_stock_amount, per_stock_unit
             FROM inventory_items
             WHERE id = :id
               AND is_active = 1
               AND menu_item_id IS NULL
             LIMIT 1'
        );
        $stmt->execute([':id' => $inventoryItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $name = trim($ingredientName);
    if ($name === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT unit_cost, per_stock_amount, per_stock_unit
         FROM inventory_items
         WHERE is_active = 1
           AND menu_item_id IS NULL
           AND LOWER(TRIM(item_name)) = LOWER(TRIM(:name))
         ORDER BY id ASC
         LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
