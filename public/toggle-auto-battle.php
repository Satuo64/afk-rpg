<?php
/**
 * public/toggle-auto-battle.php
 *
 * Flips characters.auto_battle — the server-side flag that both the live
 * Auto-Fight loop (battle-arena.php) and the offline simulation
 * (combat_engine.php) read. This replaces the old localStorage-only toggle,
 * which the server could never see.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: battle-list.php');
    exit;
}

$userId    = (int) $_SESSION['user_id'];
$monsterId = (int) ($_POST['monster_id'] ?? 0);

// Fetching stats first applies any pending passive HP regen, so flipping the
// timestamp below doesn't throw away regen the character already earned.
$stats     = get_character_stats($pdo, $userId);
$character = $stats['character'];
$turnOn    = !(bool) $character['auto_battle'];

if ($turnOn) {
    // Reset the "last accounted for" timestamp: anything before this moment
    // was NOT auto-fighting, so it must never be credited as offline combat.
    $pdo->prepare('UPDATE characters SET auto_battle = 1, last_hp_update = NOW() WHERE character_id = :cid')
        ->execute(['cid' => $character['character_id']]);
} else {
    $pdo->prepare('UPDATE characters SET auto_battle = 0 WHERE character_id = :cid')
        ->execute(['cid' => $character['character_id']]);
}

header('Location: ' . ($monsterId > 0 ? 'battle-arena.php?monster_id=' . $monsterId : 'battle-list.php'));
exit;
