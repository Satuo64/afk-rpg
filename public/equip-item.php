<?php
/**
 * public/equip-item.php
 *
 * Toggles equip state for one item_id. Ownership is enforced by requiring
 * a matching character_inventory row for THIS session's character — never
 * trust a client-supplied character_id. Only one item per item_type can be
 * equipped at a time (one weapon slot, one armor slot, one helmet slot),
 * so equipping a new one first unequips whatever else of that type was on.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$itemId = (int) ($_POST['item_id'] ?? 0);

$stmt = $pdo->prepare('SELECT character_id FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$characterId = (int) $stmt->fetch()['character_id'];

// Confirm this item is actually in THIS character's inventory, and get its
// type + current equipped state in the same query.
$stmt = $pdo->prepare(
    'SELECT ci.equipped, i.item_type
     FROM character_inventory ci
     JOIN items i ON i.item_id = ci.item_id
     WHERE ci.character_id = :cid AND ci.item_id = :iid'
);
$stmt->execute(['cid' => $characterId, 'iid' => $itemId]);
$owned = $stmt->fetch();

if (!$owned) {
    header('Location: inventory.php?error=not_owned');
    exit;
}

if (!in_array($owned['item_type'], ['weapon', 'armor', 'helmet'], true)) {
    // Materials/consumables aren't equippable — nothing to do.
    header('Location: inventory.php');
    exit;
}

try {
    $pdo->beginTransaction();

    if ((bool) $owned['equipped']) {
        // Already equipped -> unequip it
        $pdo->prepare(
            'UPDATE character_inventory SET equipped = 0 WHERE character_id = :cid AND item_id = :iid'
        )->execute(['cid' => $characterId, 'iid' => $itemId]);
    } else {
        // Unequip any other item of the SAME type first (only one weapon,
        // one armor, one helmet slot), then equip this one.
        $pdo->prepare(
            'UPDATE character_inventory ci
             JOIN items i ON i.item_id = ci.item_id
             SET ci.equipped = 0
             WHERE ci.character_id = :cid AND i.item_type = :type AND ci.equipped = 1'
        )->execute(['cid' => $characterId, 'type' => $owned['item_type']]);

        $pdo->prepare(
            'UPDATE character_inventory SET equipped = 1 WHERE character_id = :cid AND item_id = :iid'
        )->execute(['cid' => $characterId, 'iid' => $itemId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: inventory.php?error=equip_failed');
    exit;
}

header('Location: inventory.php');
exit;
