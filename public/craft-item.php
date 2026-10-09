<?php
/**
 * public/craft-item.php
 *
 * All the actual logic now lives in sp_craft_item (migration 014) — gold
 * check, ingredient check, and the deduct-materials/add-output transaction
 * all happen inside the database. This file just calls it and reads back
 * the OUT parameters.
 *
 * PDO has no direct OUT-parameter binding for CALL, so the standard
 * pattern is: pass OUT params as @session_variables in the CALL, then
 * SELECT them back in a second query on the same connection.
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

$stmt = $pdo->prepare('SELECT character_id FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$characterId = (int) $stmt->fetch()['character_id'];

$pdo->prepare('CALL sp_craft_item(:cid, :rid, @success, @message)')
    ->execute(['cid' => $characterId, 'rid' => $recipeId]);

$result  = $pdo->query('SELECT @success AS success, @message AS message')->fetch();
$success = (bool) $result['success'];
$message = $result['message'];

if (!$success) {
    header('Location: crafting.php?error=' . urlencode($message));
    exit;
}

// Look up the crafted item's name for the confirmation message
$stmt = $pdo->prepare(
    'SELECT i.item_name FROM crafting_recipes cr JOIN items i ON i.item_id = cr.output_item_id WHERE cr.recipe_id = :rid'
);
$stmt->execute(['rid' => $recipeId]);
$outputName = $stmt->fetch()['item_name'] ?? 'item';

header('Location: crafting.php?crafted=' . urlencode($outputName));
exit;