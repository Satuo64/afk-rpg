<?php
/**
 * public/use-item.php
 *
 * Consumes one potion: adds its heal_amount to the character's HP (never
 * above their derived max), then decrements the inventory row — deleting
 * it entirely if that was the last one. Only items with heal_amount > 0
 * are usable here; other consumables with different effects would need
 * their own handling later.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$itemId = (int) ($_POST['item_id'] ?? 0);

// Where to send the player back to. Only two shapes are ever allowed —
// never trust this value directly, or it becomes an open-redirect hole.
$returnTo = $_POST['return_to'] ?? 'inventory.php';
if ($returnTo !== 'inventory.php' && !preg_match('/^battle-arena\.php\?monster_id=\d+$/', $returnTo)) {
    $returnTo = 'inventory.php';
}

$stats       = get_character_stats($pdo, $userId);
$character   = $stats['character'];
$characterId = (int) $character['character_id'];

$stmt = $pdo->prepare(
    'SELECT ci.quantity, i.item_name, i.item_type, i.heal_amount
     FROM character_inventory ci
     JOIN items i ON i.item_id = ci.item_id
     WHERE ci.character_id = :cid AND ci.item_id = :iid'
);
$stmt->execute(['cid' => $characterId, 'iid' => $itemId]);
$owned = $stmt->fetch();

$sep = (strpos($returnTo, '?') !== false) ? '&' : '?';

if (!$owned || $owned['item_type'] !== 'consumable' || (int) $owned['heal_amount'] <= 0) {
    header('Location: ' . $returnTo . $sep . 'error=not_usable');
    exit;
}

$healAmount = (int) $owned['heal_amount'];
$newHp      = min($stats['maxHp'], $stats['currentHp'] + $healAmount);

try {
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE characters SET hp = :hp WHERE character_id = :cid')
        ->execute(['hp' => $newHp, 'cid' => $characterId]);

    $pdo->prepare('UPDATE character_inventory SET quantity = quantity - 1 WHERE character_id = :cid AND item_id = :iid')
        ->execute(['cid' => $characterId, 'iid' => $itemId]);

    $pdo->prepare('DELETE FROM character_inventory WHERE character_id = :cid AND item_id = :iid AND quantity <= 0')
        ->execute(['cid' => $characterId, 'iid' => $itemId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: ' . $returnTo . $sep . 'error=use_failed');
    exit;
}

header('Location: ' . $returnTo . $sep . 'healed=' . $healAmount . '&item=' . urlencode($owned['item_name']));
exit;