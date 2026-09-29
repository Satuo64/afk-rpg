<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT * FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$character   = $stmt->fetch();
$characterId = (int) $character['character_id'];

// Every recipe + its output item — grouped by category (potions, then
// swords/bows/wands, then armor, then helmets), tier order within each
// group via gold_cost, instead of one flat cost-sorted list.
$stmt = $pdo->query(
    "SELECT cr.recipe_id, cr.gold_cost, cr.description, cr.output_quantity,
            i.item_id AS output_item_id, i.item_name AS output_name, i.item_type AS output_type
     FROM crafting_recipes cr
     JOIN items i ON i.item_id = cr.output_item_id
     ORDER BY
        CASE i.item_type
            WHEN 'consumable' THEN 1
            WHEN 'weapon'     THEN 2
            WHEN 'armor'      THEN 3
            WHEN 'helmet'     THEN 4
            ELSE 5
        END,
        CASE i.weapon_type
            WHEN 'sword' THEN 1
            WHEN 'bow'   THEN 2
            WHEN 'wand'  THEN 3
            ELSE 0
        END,
        cr.gold_cost"
);
$recipes = $stmt->fetchAll();

// Every ingredient for every recipe, plus how many the character currently owns —
// one query instead of one-per-recipe.
$stmt = $pdo->prepare(
    'SELECT ri.recipe_id, ri.item_id, ri.quantity_required, i.item_name,
            COALESCE(ci.quantity, 0) AS owned
     FROM recipe_ingredients ri
     JOIN items i ON i.item_id = ri.item_id
     LEFT JOIN character_inventory ci ON ci.item_id = ri.item_id AND ci.character_id = :cid
     ORDER BY ri.recipe_id, i.item_name'
);
$stmt->execute(['cid' => $characterId]);
$allIngredients = $stmt->fetchAll();

$ingredientsByRecipe = [];
foreach ($allIngredients as $ing) {
    $ingredientsByRecipe[$ing['recipe_id']][] = $ing;
}

$typeIcon = [
    'weapon'     => '⚔️',
    'armor'      => '🛡️',
    'helmet'     => '🪖',
    'material'   => '🌿',
    'consumable' => '🧪',
];

$errorMessages = [
    'recipe_not_found'    => 'That recipe no longer exists.',
    'insufficient_gold'   => "You don't have enough gold for that.",
    'missing_ingredients' => "You don't have the required materials.",
    'craft_failed'        => 'Crafting failed. Please try again.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Crafting Station</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .recipe-materials { list-style-type: disc; padding-left: 20px; color: #94a3b8; font-size: 0.95rem; line-height: 1.6; }
        .recipe-materials .have    { color: #4ade80; }
        .recipe-materials .short   { color: #f87171; }
    </style>
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'crafting'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1>Crafting</h1>
                <p>Gold: <?= number_format((int) $character['gold']) ?></p>
            </div>

            <?php if (isset($_GET['crafted'])): ?>
                <p style="color:#4ade80; margin-bottom:20px;">Crafted <?= htmlspecialchars($_GET['crafted']) ?>!</p>
            <?php elseif (isset($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
                <p style="color:#f87171; margin-bottom:20px;"><?= htmlspecialchars($errorMessages[$_GET['error']]) ?></p>
            <?php endif; ?>

            <div class="crafting-table-container">
                <table class="crafting-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Description</th>
                            <th>Materials</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recipes as $recipe): ?>
                            <?php
                                $ings = $ingredientsByRecipe[$recipe['recipe_id']] ?? [];
                                $hasGold = (int) $character['gold'] >= (int) $recipe['gold_cost'];
                                $hasAllIngredients = true;
                                foreach ($ings as $ing) {
                                    if ((int) $ing['owned'] < (int) $ing['quantity_required']) {
                                        $hasAllIngredients = false;
                                    }
                                }
                                $canCraft = $hasGold && $hasAllIngredients;
                            ?>
                            <tr>
                                <td class="item-name-cell">
                                    <?= $typeIcon[$recipe['output_type']] ?? '❔' ?> <?= htmlspecialchars($recipe['output_name']) ?>
                                </td>
                                <td class="desc-cell"><?= htmlspecialchars($recipe['description'] ?? '') ?></td>
                                <td>
                                    <ul class="recipe-materials">
                                        <?php foreach ($ings as $ing): ?>
                                            <?php $enough = (int) $ing['owned'] >= (int) $ing['quantity_required']; ?>
                                            <li class="<?= $enough ? 'have' : 'short' ?>">
                                                <?= (int) $ing['quantity_required'] ?>x <?= htmlspecialchars($ing['item_name']) ?>
                                                (have <?= (int) $ing['owned'] ?>)
                                            </li>
                                        <?php endforeach; ?>
                                        <li class="<?= $hasGold ? 'have' : 'short' ?>">
                                            <?= (int) $recipe['gold_cost'] ?> Gold (have <?= number_format((int) $character['gold']) ?>)
                                        </li>
                                    </ul>
                                </td>
                                <td>
                                    <form method="POST" action="craft-item.php">
                                        <input type="hidden" name="recipe_id" value="<?= (int) $recipe['recipe_id'] ?>">
                                        <button type="submit" class="craft-btn" <?= $canCraft ? '' : 'disabled style="opacity:0.5; cursor:not-allowed;"' ?>>
                                            Craft
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>

    </div>
</body>
</html>