<?php
/**
 * public/craft-item.php
 *
 * Crafts one item from a recipe: checks gold + every ingredient quantity
 * up front, then — only if everything is sufficient — deducts gold,
 * deducts each ingredient (removing the inventory row entirely if it hits
 * zero), and adds the output item. All in one transaction, so a failure
 * partway through never leaves materials consumed with no item produced.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: crafting.php');
    exit;
}

$userId   = (int) $_SESSION['user_id'];
$recipeId = (int) ($_POST['recipe_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$character   = $stmt->fetch();
$characterId = (int) $character['character_id'];

$stmt = $pdo->prepare(
    'SELECT cr.*, i.item_name AS output_name
     FROM crafting_recipes cr
     JOIN items i ON i.item_id = cr.output_item_id
     WHERE cr.recipe_id = :rid'
);
$stmt->execute(['rid' => $recipeId]);
$recipe = $stmt->fetch();

if (!$recipe) {
    header('Location: crafting.php?error=recipe_not_found');
    exit;
}

if ((int) $character['gold'] < (int) $recipe['gold_cost']) {
    header('Location: crafting.php?error=insufficient_gold');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT ri.item_id, ri.quantity_required, i.item_name,
            COALESCE(ci.quantity, 0) AS owned
     FROM recipe_ingredients ri
     JOIN items i ON i.item_id = ri.item_id
     LEFT JOIN character_inventory ci ON ci.item_id = ri.item_id AND ci.character_id = :cid
     WHERE ri.recipe_id = :rid'
);
$stmt->execute(['cid' => $characterId, 'rid' => $recipeId]);
$ingredients = $stmt->fetchAll();

foreach ($ingredients as $ing) {
    if ((int) $ing['owned'] < (int) $ing['quantity_required']) {
        header('Location: crafting.php?error=missing_ingredients');
        exit;
    }
}

try {
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE characters SET gold = gold - :cost WHERE character_id = :cid')
        ->execute(['cost' => $recipe['gold_cost'], 'cid' => $characterId]);

    foreach ($ingredients as $ing) {
        $pdo->prepare(
            'UPDATE character_inventory SET quantity = quantity - :qty
             WHERE character_id = :cid AND item_id = :iid'
        )->execute(['qty' => $ing['quantity_required'], 'cid' => $characterId, 'iid' => $ing['item_id']]);

        // Clean up rows that hit zero rather than leaving a "x0" item in the bag
        $pdo->prepare(
            'DELETE FROM character_inventory WHERE character_id = :cid AND item_id = :iid AND quantity <= 0'
        )->execute(['cid' => $characterId, 'iid' => $ing['item_id']]);
    }

    $pdo->prepare(
        'INSERT INTO character_inventory (character_id, item_id, quantity, equipped)
         VALUES (:cid, :iid, :qty, 0)
         ON DUPLICATE KEY UPDATE quantity = quantity + :qty2'
    )->execute([
        'cid'  => $characterId,
        'iid'  => $recipe['output_item_id'],
        'qty'  => $recipe['output_quantity'],
        'qty2' => $recipe['output_quantity'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: crafting.php?error=craft_failed');
    exit;
}

header('Location: crafting.php?crafted=' . urlencode($recipe['output_name']));
exit;
