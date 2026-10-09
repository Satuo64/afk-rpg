<?php
/**
 * public/resolve-attack.php
 *
 * One attack tick. All combat logic lives in config/combat_engine.php
 * (resolve_one_combat_tick), so the live Attack button, Auto-Fight and the
 * offline simulation all share the same code. The monster is always read
 * from characters.battle_monster_id, never from the client.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/combat_engine.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: battle-list.php');
    exit;
}

$stmt = $pdo->prepare('SELECT character_id, battle_monster_id FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => (int) $_SESSION['user_id']]);
$character = $stmt->fetch();

$monsterId = (int) ($character['battle_monster_id'] ?? 0);
if ($monsterId === 0) {
    header('Location: battle-list.php');
    exit;
}

$result = resolve_one_combat_tick($pdo, (int) $character['character_id']);

if ($result['status'] === 'no_battle') {
    header('Location: battle-list.php');
    exit;
}

if ($result['status'] === 'error') {
    header('Location: battle-arena.php?monster_id=' . $monsterId . '&error=combat_failed');
    exit;
}

header('Location: battle-arena.php?monster_id=' . $monsterId);
exit;