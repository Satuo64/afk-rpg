<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT * FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$character = $stmt->fetch();

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

// Sum of equipped armor's defense_bonus. Returns 0 right now since nothing
// is equipped yet (no equip flow built), but this genuinely queries it —
// the moment gear gets equipped, this number moves on its own.
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(i.defense_bonus), 0) AS armor_bonus
     FROM character_inventory ci
     JOIN items i ON i.item_id = ci.item_id
     WHERE ci.character_id = :cid AND ci.equipped = 1 AND i.item_type IN ('armor', 'helmet')"
);
$stmt->execute(['cid' => $character['character_id']]);
$armorBonus = (int) $stmt->fetch()['armor_bonus'];

/**
 * ---- Balance constants -----------------------------------------------
 * Every stat below is DERIVED from skill levels, not read from the static
 * characters.strength/magic/ranged_attack/defense columns (those never
 * change on their own — nothing updates them). These numbers are a
 * placeholder curve so the page shows something real and growing; swap
 * them for your actual balance pass once combat XP gains exist.
 */
const STAT_BASE            = 10;   // a skill's underlying stat at level 1
const STAT_PER_LEVEL        = 2;    // + per level of that skill
const DEFENSE_BASE          = 10;   // character's base defense at minimum total level
const DEFENSE_PER_LEVEL     = 2;    // + per total-level point above the minimum
const HP_BASE               = 100;  // max HP at minimum total level
const HP_PER_LEVEL          = 20;   // + max HP per total-level point above the minimum
const CRIT_BASE             = 0.01; // 1% base crit chance
const CRIT_PER_RANGED_LEVEL = 0.002; // + per Ranged skill level above 1

$minTotalLevel = count($skillRows); // e.g. 3 skills at level 1 each = 3, the floor every character starts at
$totalLevel    = array_sum(array_column($skillRows, 'skill_level'));
$levelsAboveMin = max(0, $totalLevel - $minTotalLevel);

// ---- Attack: the ACTIVE skill's own underlying stat -----------------------
$activeSkill = null;
$rangedLevel = 1;
foreach ($skillRows as $row) {
    if ((int) $row['skill_id'] === (int) $character['active_skill_id']) {
        $activeSkill = $row;
    }
    if ($row['skill_name'] === 'Ranged') {
        $rangedLevel = (int) $row['skill_level'];
    }
}
$attackValue = $activeSkill
    ? STAT_BASE + (((int) $activeSkill['skill_level']) - 1) * STAT_PER_LEVEL
    : STAT_BASE;
$activeStatLabel = $activeSkill
    ? ucfirst(str_replace('_', ' ', $activeSkill['primary_stat']))
    : 'Attack';

// ---- Defense: base (scales with total level) + equipped armor -------------
$baseDefense   = DEFENSE_BASE + $levelsAboveMin * DEFENSE_PER_LEVEL;
$defenseValue  = $baseDefense + $armorBonus;

// ---- HP: scales with total level. No persisted combat damage system yet,
// so a character is always shown resting at full HP. -----------------------
$maxHp = HP_BASE + $levelsAboveMin * HP_PER_LEVEL;
$currentHp = $maxHp;

// ---- Critical chance: scales with Ranged skill level specifically ---------
$critChance = CRIT_BASE + max(0, $rangedLevel - 1) * CRIT_PER_RANGED_LEVEL;

$icon = ['Melee' => '⚔️', 'Ranged' => '🏹', 'Magic' => '⭐'];
$statColumn = ['strength' => 'Strength', 'ranged_attack' => 'Ranged', 'magic' => 'Magic'];
const XP_PER_LEVEL = 100; // same placeholder curve as skills.php — keep both in sync
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Character Overview</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'character'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1>Character Overview</h1>
            </div>

            <!-- Profile Badge Area -->
            <div class="profile-card">
                <div class="profile-avatar">👤</div>
                <div class="profile-text">
                    <h2><?= htmlspecialchars($character['character_name']) ?></h2>
                    <p>Level <?= $totalLevel ?></p>
                </div>
            </div>

            <!-- Stats Overview 4-Column Grid Block -->
            <div class="character-stats-grid">
                <div class="char-stat-card">
                    <span class="char-stat-icon red-icon">❤️</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label">HP</span>
                        <span class="char-stat-value"><?= $currentHp ?>/<?= $maxHp ?></span>
                    </div>
                </div>

                <div class="char-stat-card">
                    <span class="char-stat-icon">⚔️</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label"><?= htmlspecialchars($activeStatLabel) ?> (Active)</span>
                        <span class="char-stat-value"><?= $attackValue ?></span>
                    </div>
                </div>

                <div class="char-stat-card">
                    <span class="char-stat-icon">🛡️</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label">Defense</span>
                        <span class="char-stat-value">
                            <?= $defenseValue ?>
                            <?php if ($armorBonus > 0): ?>
                                <span style="font-size:0.6em; color:#64748b;">(<?= $baseDefense ?> base + <?= $armorBonus ?> armor)</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="char-stat-card">
                    <span class="char-stat-icon text-glow">💥</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label">Critical Chance</span>
                        <span class="char-stat-value"><?= number_format($critChance * 100, 2) ?>%</span>
                    </div>
                </div>
            </div>

            <!-- Skill Levels Section: real data, read-only here (change active skill on skills.php) -->
            <div class="equipped-gear-section">
                <h3>Skill Levels</h3>
                <div class="class-selection-row">
                    <?php foreach ($skillRows as $row): ?>
                        <?php
                            $isActive   = ((int) $character['active_skill_id'] === (int) $row['skill_id']);
                            $label      = $statColumn[$row['primary_stat']] ?? ucfirst($row['primary_stat']);
                            $statVal    = STAT_BASE + (((int) $row['skill_level']) - 1) * STAT_PER_LEVEL;
                            $requiredXp = max(1, (int) $row['skill_level'] * XP_PER_LEVEL);
                            $percent    = min(100, (int) round($row['current_xp'] / $requiredXp * 100));
                            $critLine   = $row['grants_crit']
                                ? ' · Crit: ' . number_format($critChance * 100, 2) . '%'
                                : '';
                        ?>
                        <div class="selection-card skill-card <?= $isActive ? 'active' : '' ?>" data-skill-id="<?= (int) $row['skill_id'] ?>">
                            <div class="card-icon-wrapper"><?= $icon[$row['skill_name']] ?? '❔' ?></div>
                            <h3><?= htmlspecialchars($row['skill_name']) ?></h3>
                            <div class="skill-stat-row">
                                <span class="skill-level-badge">Lvl <?= (int) $row['skill_level'] ?></span>
                                <span class="skill-stat-value"><?= htmlspecialchars($label) ?>: <?= $statVal ?><?= $critLine ?></span>
                            </div>
                            <div class="skill-xp-track">
                                <div class="skill-xp-fill" style="width: <?= $percent ?>%;"></div>
                            </div>
                            <p class="skill-xp-label"><?= number_format($row['current_xp']) ?> / <?= number_format($requiredXp) ?> XP to next level</p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p style="text-align:center; color:#64748b; font-size:0.85rem; margin-top:10px;">
                    Go to <a href="skills.php" style="color:#38bdf8;">Skills</a> to change your active skill.
                </p>
            </div>

            <!-- Bottom Section: Equipped Gear Title & Horizontal Blocks -->
            <div class="equipped-gear-section">
                <h3>Equipped Gear</h3>
                <div class="equipment-row">
                    <div class="equip-slot" title="Weapon Slot">🗡️</div>
                    <div class="equip-slot" title="Armor Slot">🛡️</div>
                    <div class="equip-slot" title="Helmet Slot">🪖</div>
                </div>
            </div>

        </main>

    </div>
</body>
</html>