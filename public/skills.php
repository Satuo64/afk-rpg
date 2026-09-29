<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

$userId = (int) $_SESSION['user_id'];

// This character (guaranteed to exist — require_character() already checked)
$stmt = $pdo->prepare('SELECT * FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$character = $stmt->fetch();

// All 3 skills for this character, joined against the skills lookup table
$stmt = $pdo->prepare(
    'SELECT s.skill_id, s.skill_name, s.primary_stat, s.grants_crit,
            cs.skill_level, cs.current_xp
     FROM character_skills cs
     JOIN skills s ON s.skill_id = cs.skill_id
     WHERE cs.character_id = :cid
     ORDER BY s.skill_id'
);
$stmt->execute(['cid' => $character['character_id']]);
$skillRows = $stmt->fetchAll();

// Placeholder leveling curve (xp needed for level N = N * 100) — tune this
// once you've decided your real XP formula; everything below only cares
// that "required XP" is some function of skill_level.
const XP_PER_LEVEL = 100;

$icon = ['Melee' => '⚔️', 'Ranged' => '🏹', 'Magic' => '⭐'];
$btnClass = ['Melee' => 'btn-blue', 'Ranged' => 'btn-green', 'Magic' => 'btn-purple'];
$description = [
    'Melee'  => "Strength-based. Uses a sword's bonus when equipped.",
    'Ranged' => "Ranged Attack + Critical Chance. Uses a bow's bonus, x2 damage multiplier.",
    'Magic'  => "Magic-based. Uses a wand's bonus when equipped.",
];
$statColumn = [
    'strength'      => 'strength',
    'ranged_attack' => 'ranged_attack',
    'magic'         => 'magic',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Skills</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'skills'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1>Skills</h1>
                <p>Each skill levels independently from combat XP. Pick one as your active skill to train it in battle.</p>
            </div>

            <div class="class-selection-row">
                <?php foreach ($skillRows as $row): ?>
                    <?php
                        $isActive     = ((int) $character['active_skill_id'] === (int) $row['skill_id']);
                        $column       = $statColumn[$row['primary_stat']] ?? null;
                        $statValue    = $column ? (int) $character[$column] : 0;
                        $statLabel    = ucfirst(str_replace('_', ' ', $row['primary_stat']));
                        $requiredXp   = max(1, (int) $row['skill_level'] * XP_PER_LEVEL);
                        $percent      = min(100, (int) round($row['current_xp'] / $requiredXp * 100));
                        $critSuffix   = $row['grants_crit']
                            ? ' · Crit: ' . number_format($character['crit_chance'] * 100, 2) . '%'
                            : '';
                        $skillName    = $row['skill_name'];
                    ?>

                    <?php if ($isActive): ?>
                        <div class="selection-card skill-card active" data-skill-id="<?= (int) $row['skill_id'] ?>">
                            <div class="card-icon-wrapper"><?= $icon[$skillName] ?? '❔' ?></div>
                            <h3><?= htmlspecialchars($skillName) ?></h3>
                            <p><?= htmlspecialchars($description[$skillName] ?? '') ?></p>

                            <div class="skill-stat-row">
                                <span class="skill-level-badge">Lvl <?= (int) $row['skill_level'] ?></span>
                                <span class="skill-stat-value"><?= htmlspecialchars($statLabel) ?>: <?= $statValue ?><?= $critSuffix ?></span>
                            </div>
                            <div class="skill-xp-track">
                                <div class="skill-xp-fill" style="width: <?= $percent ?>%;"></div>
                            </div>
                            <p class="skill-xp-label"><?= number_format($row['current_xp']) ?> / <?= number_format($requiredXp) ?> XP to next level</p>

                            <button class="select-btn btn-blue" disabled>Currently Training</button>
                        </div>
                    <?php else: ?>
                        <form class="selection-card skill-card" method="POST" action="set-active-skill.php">
                            <div class="card-icon-wrapper"><?= $icon[$skillName] ?? '❔' ?></div>
                            <h3><?= htmlspecialchars($skillName) ?></h3>
                            <p><?= htmlspecialchars($description[$skillName] ?? '') ?></p>

                            <div class="skill-stat-row">
                                <span class="skill-level-badge">Lvl <?= (int) $row['skill_level'] ?></span>
                                <span class="skill-stat-value"><?= htmlspecialchars($statLabel) ?>: <?= $statValue ?><?= $critSuffix ?></span>
                            </div>
                            <div class="skill-xp-track">
                                <div class="skill-xp-fill" style="width: <?= $percent ?>%;"></div>
                            </div>
                            <p class="skill-xp-label"><?= number_format($row['current_xp']) ?> / <?= number_format($requiredXp) ?> XP to next level</p>

                            <input type="hidden" name="skill_id" value="<?= (int) $row['skill_id'] ?>">
                            <button type="submit" class="select-btn <?= $btnClass[$skillName] ?? 'btn-blue' ?>">Train This Skill</button>
                        </form>
                    <?php endif; ?>

                <?php endforeach; ?>
            </div>
        </main>

    </div>
</body>
</html>