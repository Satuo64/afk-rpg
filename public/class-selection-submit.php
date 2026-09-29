<?php
/**
 * public/class-selection-submit.php
 *
 * Handles the actual "Select" button submissions on class-selection.php.
 * This is what was missing: the page could redirect you, but nothing ever
 * wrote a characters row, so require_character() on every other page kept
 * bouncing you right back here.
 *
 * Wrapped in a transaction because it's two related writes (the character
 * row, then three character_skills rows) that must succeed or fail
 * together — a character with only 1 of 3 skills seeded would be broken.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_no_character_yet();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: class-selection.php');
    exit;
}

$skillName = $_POST['skill_name'] ?? '';
$allowedSkills = ['Melee', 'Ranged', 'Magic'];

if (!in_array($skillName, $allowedSkills, true)) {
    header('Location: class-selection.php?error=invalid_class');
    exit;
}

$userId   = (int) $_SESSION['user_id'];
$username = $_SESSION['username'];

try {
    $pdo->beginTransaction();

    // Resolve the chosen skill's id
    $stmt = $pdo->prepare('SELECT skill_id FROM skills WHERE skill_name = :name');
    $stmt->execute(['name' => $skillName]);
    $skill = $stmt->fetch();

    if (!$skill) {
        throw new RuntimeException("Skill '{$skillName}' not found in skills table.");
    }
    $activeSkillId = (int) $skill['skill_id'];

    // First monster in the fixed sequence — new characters start here
    $firstMonster = $pdo->query(
        'SELECT monster_id FROM monsters ORDER BY sequence_order ASC LIMIT 1'
    )->fetch();
    $firstMonsterId = $firstMonster ? (int) $firstMonster['monster_id'] : null;

    // Create the character. character_name defaults to the account's
    // username since class-selection.html has no name field yet.
    $stmt = $pdo->prepare(
        'INSERT INTO characters (user_id, character_name, active_skill_id, current_monster_id)
         VALUES (:user_id, :character_name, :active_skill_id, :current_monster_id)'
    );
    $stmt->execute([
        'user_id'            => $userId,
        'character_name'     => $username,
        'active_skill_id'    => $activeSkillId,
        'current_monster_id' => $firstMonsterId,
    ]);
    $characterId = (int) $pdo->lastInsertId();

    // Seed ALL three skills at level 1 — Melee/Ranged/Magic all progress
    // independently regardless of which one is picked as active.
    $allSkills = $pdo->query('SELECT skill_id FROM skills')->fetchAll();

    $insertSkill = $pdo->prepare(
        'INSERT INTO character_skills (character_id, skill_id, skill_level, current_xp)
         VALUES (:character_id, :skill_id, 1, 0)'
    );
    foreach ($allSkills as $row) {
        $insertSkill->execute([
            'character_id' => $characterId,
            'skill_id'     => $row['skill_id'],
        ]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: class-selection.php?error=creation_failed');
    exit;
}

header('Location: main-menu.php');
exit;
