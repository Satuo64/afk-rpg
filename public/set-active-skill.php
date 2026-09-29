<?php
/**
 * public/set-active-skill.php
 *
 * The missing piece behind skills.php's "Train This Skill" buttons — this
 * is what actually flips characters.active_skill_id in the database.
 * Ownership is enforced by scoping the UPDATE to WHERE user_id = the
 * logged-in session's user_id, never by trusting a client-supplied
 * character_id (which anyone could tamper with in devtools/Postman).
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: skills.php');
    exit;
}

$skillId = (int) ($_POST['skill_id'] ?? 0);

// Confirm this is really one of the 3 valid skills before writing it
$stmt = $pdo->prepare('SELECT skill_id FROM skills WHERE skill_id = :id');
$stmt->execute(['id' => $skillId]);

if (!$stmt->fetch()) {
    header('Location: skills.php?error=invalid_skill');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'UPDATE characters SET active_skill_id = :skill_id WHERE user_id = :uid'
);
$stmt->execute([
    'skill_id' => $skillId,
    'uid'      => $userId,
]);

header('Location: skills.php');
exit;
