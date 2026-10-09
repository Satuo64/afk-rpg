<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

$userId = (int) $_SESSION['user_id'];
$stats  = get_character_stats($pdo, $userId);
$character = $stats['character'];

// Equipped gear for the bottom section — was static placeholder icons
// before, now reads real character_inventory rows.
$stmt = $pdo->prepare(
    "SELECT i.item_name, i.item_type
     FROM character_inventory ci
     JOIN items i ON i.item_id = ci.item_id
     WHERE ci.character_id = :cid AND ci.equipped = 1"
);
$stmt->execute(['cid' => $character['character_id']]);
$equippedRows = $stmt->fetchAll();

$equippedByType = ['weapon' => null, 'armor' => null, 'helmet' => null];
foreach ($equippedRows as $row) {
    $equippedByType[$row['item_type']] = $row['item_name'];
}

$icon = ['Melee' => '⚔️', 'Ranged' => '🏹', 'Magic' => '⭐'];
$statColumn = ['strength' => 'Strength', 'ranged_attack' => 'Ranged', 'magic' => 'Magic'];
$slotIcon = ['weapon' => '🗡️', 'armor' => '🛡️', 'helmet' => '🪖'];
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
                    <p>Level <?= $stats['totalLevel'] ?></p>
                </div>
            </div>

            <!-- Stats Overview 4-Column Grid Block -->
            <div class="character-stats-grid">
                <div class="char-stat-card">
                    <span class="char-stat-icon red-icon">❤️</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label">HP</span>
                        <span class="char-stat-value"><?= $stats['currentHp'] ?>/<?= $stats['maxHp'] ?></span>
                    </div>
                </div>

                <div class="char-stat-card">
                    <span class="char-stat-icon">⚔️</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label"><?= htmlspecialchars($stats['activeStatLabel']) ?> (Active)</span>
                        <span class="char-stat-value">
                            <?= $stats['attackValue'] ?>
                            <?php if ($stats['weaponBonus'] > 0): ?>
                                <span style="font-size:0.6em; color:#64748b;">(<?= $stats['baseAttack'] ?> base + <?= $stats['weaponBonus'] ?> <?= htmlspecialchars($stats['equippedWeapon']['item_name']) ?>)</span>
                            <?php elseif ($stats['equippedWeapon']): ?>
                                <span style="font-size:0.6em; color:#64748b;">(<?= htmlspecialchars($stats['equippedWeapon']['item_name']) ?> doesn't match active skill)</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="char-stat-card">
                    <span class="char-stat-icon">🛡️</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label">Defense</span>
                        <span class="char-stat-value">
                            <?= $stats['defenseValue'] ?>
                            <?php if ($stats['armorBonus'] > 0): ?>
                                <span style="font-size:0.6em; color:#64748b;">(<?= $stats['baseDefense'] ?> base + <?= $stats['armorBonus'] ?> armor)</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="char-stat-card">
                    <span class="char-stat-icon text-glow">💥</span>
                    <div class="char-stat-info">
                        <span class="char-stat-label">Critical Chance</span>
                        <span class="char-stat-value"><?= number_format($stats['critChance'] * 100, 2) ?>%</span>
                    </div>
                </div>
            </div>

            <!-- Skill Levels Section: real data, read-only here (change active skill on skills.php) -->
            <div class="equipped-gear-section">
                <h3>Skill Levels</h3>
                <div class="class-selection-row">
                    <?php foreach ($stats['skillRows'] as $row): ?>
                        <?php
                            $isActive   = ((int) $character['active_skill_id'] === (int) $row['skill_id']);
                            $label      = $statColumn[$row['primary_stat']] ?? ucfirst($row['primary_stat']);
                            $statVal    = STAT_BASE + (((int) $row['skill_level']) - 1) * STAT_PER_LEVEL;
                            $requiredXp = max(1, (int) $row['skill_level'] * XP_PER_LEVEL);
                            $percent    = min(100, (int) round($row['current_xp'] / $requiredXp * 100));
                            $critLine   = $row['grants_crit']
                                ? ' · Crit: ' . number_format($stats['critChance'] * 100, 2) . '%'
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

            <!-- Bottom Section: Equipped Gear — now shows what's actually equipped -->
            <div class="equipped-gear-section">
                <h3>Equipped Gear</h3>
                <div class="equipment-row">
                    <?php foreach (['weapon' => 'Weapon', 'armor' => 'Armor', 'helmet' => 'Helmet'] as $type => $label): ?>
                        <div class="equip-slot" title="<?= $equippedByType[$type] ? htmlspecialchars($equippedByType[$type]) : "$label Slot (empty)" ?>">
                            <?= $slotIcon[$type] ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$equippedByType['weapon'] && !$equippedByType['armor'] && !$equippedByType['helmet']): ?>
                    <p style="text-align:center; color:#64748b; font-size:0.85rem; margin-top:10px;">
                        Nothing equipped yet. Go to <a href="inventory.php" style="color:#38bdf8;">Inventory</a> to equip gear.
                    </p>
                <?php endif; ?>
            </div>

        </main>

    </div>
</body>
</html>