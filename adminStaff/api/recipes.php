<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recipe_helpers.php';
require_once __DIR__ . '/recipe_cost_helpers.php';
require_once __DIR__ . '/menu_helpers.php';
require_auth();

function ensure_recipe_schema(PDO $pdo): void {
    ensure_recipe_schema_shared($pdo);
}

function fetch_recipe_ingredient_lines(PDO $pdo, int $recipeId): array
{
    $lineStmt = $pdo->prepare(
        'SELECT
            id,
            inventory_item_id,
            ingredient_name,
            quantity,
            unit,
            unit_cost,
            line_cost,
            sort_order
         FROM recipe_ingredients
         WHERE recipe_id = :recipe_id
         ORDER BY sort_order ASC, id ASC'
    );
    $lineStmt->execute([':recipe_id' => $recipeId]);
    $lines = [];
    foreach ($lineStmt->fetchAll() as $row) {
        $lines[] = [
            'id' => (int)$row['id'],
            'inventory_item_id' => $row['inventory_item_id'] !== null ? (int)$row['inventory_item_id'] : null,
            'ingredient_name' => $row['ingredient_name'],
            'quantity' => (float)$row['quantity'],
            'unit' => $row['unit'],
            'unit_cost' => (float)$row['unit_cost'],
            'line_cost' => (float)$row['line_cost'],
            'sort_order' => (int)$row['sort_order'],
        ];
    }
    return $lines;
}

function recipe_row_to_payload(array $recipe, array $ingredients): array
{
    return [
        'recipe' => [
            'id' => (int)$recipe['id'],
            'menu_item_id' => (int)$recipe['menu_item_id'],
            'variant_signature' => (string)($recipe['variant_signature'] ?? ''),
            'recipe_type' => $recipe['recipe_type'],
            'unit_cost' => (float)$recipe['unit_cost'],
            'sale_price' => (float)$recipe['sale_price'],
            'gross_profit' => (float)$recipe['gross_profit'],
            'created_at' => $recipe['created_at'],
            'updated_at' => $recipe['updated_at'],
        ],
        'ingredients' => $ingredients,
    ];
}

function normalize_recipe_lines(PDO $pdo, array $linesRaw): array
{
    $lines = [];
    foreach ($linesRaw as $idx => $line) {
        if (!is_array($line)) {
            continue;
        }
        $name = trim((string)($line['ingredient_name'] ?? $line['name'] ?? ''));
        $qty = (float)($line['quantity'] ?? $line['qty'] ?? 0);
        $unit = trim((string)($line['unit'] ?? 'unit'));
        $invId = isset($line['inventory_item_id']) && $line['inventory_item_id'] !== ''
            ? (int)$line['inventory_item_id']
            : null;

        if ($name === '' && $qty <= 0) {
            continue;
        }
        if ($name === '') {
            fail('Each ingredient line needs a selected ingredient.');
        }
        if ($qty <= 0) {
            fail('Ingredient amount must be greater than zero.');
        }

        $invRow = fetch_inventory_cost_row($pdo, $invId, $name);
        $computedLineCost = compute_ingredient_line_cost_from_inventory_row($invRow, $qty, $unit);
        $clientLineCost = isset($line['line_cost']) ? round(max(0, (float)$line['line_cost']), 2) : null;
        $lineCost = ($clientLineCost !== null && $clientLineCost > 0)
            ? $clientLineCost
            : $computedLineCost;

        $pricePerUseUnit = $qty > 0 ? round($lineCost / $qty, 4) : 0.0;

        $lines[] = [
            'inventory_item_id' => ($invId && $invId > 0) ? $invId : null,
            'ingredient_name' => $name,
            'quantity' => $qty,
            'unit' => ($unit !== '' ? substr($unit, 0, 20) : 'unit'),
            'unit_cost' => $pricePerUseUnit,
            'line_cost' => $lineCost,
            'sort_order' => (int)$idx,
        ];
    }
    return $lines;
}

function upsert_recipe_set(
    PDO $pdo,
    int $menuId,
    string $variantSignature,
    string $recipeType,
    float $unitCost,
    float $salePrice,
    float $grossProfit,
    array $lines
): int {
    $findStmt = $pdo->prepare(
        'SELECT id FROM recipes
          WHERE menu_item_id = :menu_id AND variant_signature = :variant_signature
          LIMIT 1'
    );
    $findStmt->execute([
        ':menu_id' => $menuId,
        ':variant_signature' => $variantSignature,
    ]);
    $existing = $findStmt->fetch();
    $recipeId = $existing ? (int)$existing['id'] : 0;

    if ($recipeId > 0) {
        $upd = $pdo->prepare(
            'UPDATE recipes
             SET recipe_type = :recipe_type,
                 unit_cost = :unit_cost,
                 sale_price = :sale_price,
                 gross_profit = :gross_profit
             WHERE id = :id'
        );
        $upd->execute([
            ':recipe_type' => $recipeType,
            ':unit_cost' => $unitCost,
            ':sale_price' => $salePrice,
            ':gross_profit' => $grossProfit,
            ':id' => $recipeId,
        ]);
        $pdo->prepare('DELETE FROM recipe_ingredients WHERE recipe_id = :id')
            ->execute([':id' => $recipeId]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO recipes
                (menu_item_id, variant_signature, recipe_type, unit_cost, sale_price, gross_profit)
             VALUES
                (:menu_item_id, :variant_signature, :recipe_type, :unit_cost, :sale_price, :gross_profit)'
        );
        $ins->execute([
            ':menu_item_id' => $menuId,
            ':variant_signature' => $variantSignature,
            ':recipe_type' => $recipeType,
            ':unit_cost' => $unitCost,
            ':sale_price' => $salePrice,
            ':gross_profit' => $grossProfit,
        ]);
        $recipeId = (int)$pdo->lastInsertId();
    }

    $lineIns = $pdo->prepare(
        'INSERT INTO recipe_ingredients
            (recipe_id, inventory_item_id, ingredient_name, quantity, unit, unit_cost, line_cost, sort_order)
         VALUES
            (:recipe_id, :inventory_item_id, :ingredient_name, :quantity, :unit, :unit_cost, :line_cost, :sort_order)'
    );
    foreach ($lines as $line) {
        $lineIns->execute([
            ':recipe_id' => $recipeId,
            ':inventory_item_id' => $line['inventory_item_id'],
            ':ingredient_name' => $line['ingredient_name'],
            ':quantity' => $line['quantity'],
            ':unit' => $line['unit'],
            ':unit_cost' => $line['unit_cost'],
            ':line_cost' => $line['line_cost'],
            ':sort_order' => $line['sort_order'],
        ]);
    }

    return $recipeId;
}

$pdo = db();
ensure_recipe_schema($pdo);
$method = method();

if ($method === 'GET') {
    $menuId = isset($_GET['menu_id']) ? (int)$_GET['menu_id'] : 0;
    if ($menuId <= 0) {
        fail('menu_id is required.');
    }

    $menuStmt = $pdo->prepare('SELECT id, name, description, price FROM menu_items WHERE id = :id LIMIT 1');
    $menuStmt->execute([':id' => $menuId]);
    $menuItem = $menuStmt->fetch();
    if (!$menuItem) {
        fail('Menu item not found.', 404);
    }

    $parsedVariants = parse_variants_from_description((string)($menuItem['description'] ?? ''));
    $variantDefs = [];
    foreach ($parsedVariants as $variant) {
        $sig = variant_signature_from_parts(
            (string)($variant['size'] ?? ''),
            (string)($variant['label'] ?? '')
        );
        if ($sig === '') {
            continue;
        }
        $variantDefs[] = [
            'signature' => $sig,
            'size' => (string)($variant['size'] ?? ''),
            'label' => (string)($variant['label'] ?? ''),
            'price' => (float)($variant['price'] ?? 0),
        ];
    }

    $headStmt = $pdo->prepare(
        'SELECT id, menu_item_id, variant_signature, recipe_type, unit_cost, sale_price, gross_profit, created_at, updated_at
         FROM recipes
         WHERE menu_item_id = :menu_id
         ORDER BY variant_signature ASC, id ASC'
    );
    $headStmt->execute([':menu_id' => $menuId]);
    $recipeRows = $headStmt->fetchAll();

    $recipeSets = [];
    $recipeBySignature = [];
    foreach ($recipeRows as $recipe) {
        $sig = (string)($recipe['variant_signature'] ?? '');
        $ingredients = fetch_recipe_ingredient_lines($pdo, (int)$recipe['id']);
        $payload = recipe_row_to_payload($recipe, $ingredients);
        $recipeBySignature[$sig] = $payload;
        $recipeSets[] = array_merge($payload, [
            'variant_signature' => $sig,
            'variant_label' => $sig !== '' ? $sig : 'Default',
        ]);
    }

    $firstRecipe = $recipeSets[0]['recipe'] ?? null;
    $firstIngredients = $recipeSets[0]['ingredients'] ?? [];

    ok([
        'menu_item' => [
            'id' => (int)$menuItem['id'],
            'name' => (string)$menuItem['name'],
            'price' => (float)$menuItem['price'],
            'has_variants' => count($variantDefs) > 0,
        ],
        'variants' => $variantDefs,
        'recipe_sets' => $recipeSets,
        'recipes_by_variant' => $recipeBySignature,
        // Backward-compatible single-recipe fields (default / first partition).
        'recipe' => $firstRecipe,
        'ingredients' => $firstIngredients,
    ]);
}

if ($method !== 'POST') {
    fail('Method not allowed.', 405);
}

$b = body();
$menuId = (int)($b['menu_item_id'] ?? 0);
$recipeType = trim((string)($b['recipe_type'] ?? 'made_to_order'));

if ($menuId <= 0) {
    fail('menu_item_id is required.');
}
if (!in_array($recipeType, ['made_to_order', 'batch'], true)) {
    fail('Invalid recipe type.');
}

$menuStmt = $pdo->prepare('SELECT id, description FROM menu_items WHERE id = :id LIMIT 1');
$menuStmt->execute([':id' => $menuId]);
$menuRow = $menuStmt->fetch();
if (!$menuRow) {
    fail('Menu item not found.', 404);
}

$parsedVariants = parse_variants_from_description((string)($menuRow['description'] ?? ''));
$expectedSignatures = [];
foreach ($parsedVariants as $variant) {
    $sig = variant_signature_from_parts(
        (string)($variant['size'] ?? ''),
        (string)($variant['label'] ?? '')
    );
    if ($sig !== '') {
        $expectedSignatures[] = $sig;
    }
}

$setsRaw = $b['recipe_sets'] ?? null;
if (!is_array($setsRaw) || count($setsRaw) === 0) {
    // Legacy single-recipe payload.
    $setsRaw = [[
        'variant_signature' => trim((string)($b['variant_signature'] ?? '')),
        'recipe_type' => $recipeType,
        'unit_cost' => (float)($b['unit_cost'] ?? 0),
        'sale_price' => (float)($b['sale_price'] ?? 0),
        'gross_profit' => (float)($b['gross_profit'] ?? 0),
        'ingredients' => $b['ingredients'] ?? [],
    ]];
}

if ($expectedSignatures) {
    $provided = [];
    foreach ($setsRaw as $set) {
        if (!is_array($set)) {
            continue;
        }
        $sig = trim((string)($set['variant_signature'] ?? ''));
        if ($sig !== '') {
            $provided[] = $sig;
        }
    }
    foreach ($expectedSignatures as $needSig) {
        if (!in_array($needSig, $provided, true)) {
            fail('Recipe is required for variant: ' . $needSig);
        }
    }
}

try {
    $pdo->beginTransaction();

    $savedIds = [];
    $touchedSignatures = [];

    foreach ($setsRaw as $set) {
        if (!is_array($set)) {
            continue;
        }
        $variantSignature = trim((string)($set['variant_signature'] ?? ''));
        if ($expectedSignatures && $variantSignature === '') {
            continue;
        }
        if ($variantSignature !== '' && $expectedSignatures && !in_array($variantSignature, $expectedSignatures, true)) {
            fail('Unknown variant signature: ' . $variantSignature);
        }

        $setRecipeType = trim((string)($set['recipe_type'] ?? $recipeType));
        if (!in_array($setRecipeType, ['made_to_order', 'batch'], true)) {
            fail('Invalid recipe type.');
        }

        $salePrice = (float)($set['sale_price'] ?? 0);
        $lines = normalize_recipe_lines($pdo, is_array($set['ingredients'] ?? null) ? $set['ingredients'] : []);
        if (count($lines) === 0) {
            if ($expectedSignatures) {
                fail('Add at least one ingredient for variant: ' . ($variantSignature !== '' ? $variantSignature : 'default'));
            }
            fail('Add at least one ingredient with an amount.');
        }

        $unitCost = round(array_sum(array_column($lines, 'line_cost')), 2);
        $grossProfit = round($salePrice - $unitCost, 2);

        $savedIds[] = upsert_recipe_set(
            $pdo,
            $menuId,
            $variantSignature,
            $setRecipeType,
            $unitCost,
            $salePrice,
            $grossProfit,
            $lines
        );
        $touchedSignatures[] = $variantSignature;
    }

    if (!$savedIds) {
        fail('No recipe data to save.');
    }

    // Remove stale variant recipes when beverage variants changed.
    if ($expectedSignatures) {
        $placeholders = implode(',', array_fill(0, count($expectedSignatures), '?'));
        $params = array_merge([$menuId], $expectedSignatures);
        $del = $pdo->prepare(
            "DELETE FROM recipes
              WHERE menu_item_id = ?
                AND variant_signature <> ''
                AND variant_signature NOT IN ($placeholders)"
        );
        $del->execute($params);
    } elseif (count($touchedSignatures) === 1 && $touchedSignatures[0] === '') {
        $pdo->prepare(
            'DELETE FROM recipes
              WHERE menu_item_id = :menu_id AND variant_signature <> ""'
        )->execute([':menu_id' => $menuId]);
    }

    $pdo->commit();
    ok([
        'recipe_ids' => $savedIds,
        'message' => 'Recipe saved.',
    ], 200);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('Failed to save recipe: ' . $e->getMessage(), 500);
}
