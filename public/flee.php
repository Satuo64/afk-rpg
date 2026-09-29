<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: battle-list.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT character_id, battle_monster_id FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$character = $stmt->fetch();

if (!empty($character['battle_monster_id'])) {
    $pdo->prepare(
        "INSERT INTO combat_logs (character_id, monster_id, action_type, damage_dealt, damage_taken, result)
         VALUES (:cid, :mid, 'flee', 0, 0, NULL)"
    )->execute([
        'cid' => $character['character_id'],
        'mid' => $character['battle_monster_id'],
    ]);

    $pdo->prepare(
        'UPDATE characters SET battle_monster_id = NULL, battle_monster_hp = NULL WHERE character_id = :cid'
    )->execute(['cid' => $character['character_id']]);
}

header('Location: battle-list.php');
exit;
