<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

$userId = (int) $_SESSION['user_id'];
$stats  = get_character_stats($pdo, $userId);
$character = $stats['character'];

$stmt = $pdo->prepare('SELECT * FROM monsters WHERE monster_id = :id');
$stmt->execute(['id' => $character['current_monster_id']]);
$monster = $stmt->fetch();

$drops = [];
if ($monster) {
    $stmt = $pdo->prepare(
        'SELECT item_name, drop_chance FROM items WHERE drop_monster_id = :mid ORDER BY drop_chance DESC'
    );
    $stmt->execute(['mid' => $monster['monster_id']]);
    $drops = $stmt->fetchAll();
}

$emojiPool = ['💧', '🧌', '👹', '🐺', '🐉', '🦂', '👻', '🗿'];
$monsterIcon = $monster ? $emojiPool[((int) $monster['monster_id'] - 1) % count($emojiPool)] : '❔';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Main Menu</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'home'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">

            <div class="hero-banner">
                <div class="banner-overlay-text">
                    <h1>Welcome back, <?= htmlspecialchars($character['character_name']) ?></h1>
                    <p>Your adventure continues...</p>
                </div>
            </div>

            <!-- Now reads the same real, derived stats as character.php -->
            <div class="stats-row">
                <div class="stat-badge">
                    <span class="stat-icon">⚔️</span>
                    <div class="stat-info">
                        <span class="stat-label">Level</span>
                        <span class="stat-value"><?= $stats['totalLevel'] ?></span>
                    </div>
                </div>
                <div class="stat-badge">
                    <span class="stat-icon">❤️</span>
                    <div class="stat-info">
                        <span class="stat-label">HP</span>
                        <span class="stat-value"><?= $stats['currentHp'] ?> / <?= $stats['maxHp'] ?></span>
                    </div>
                </div>
                <div class="stat-badge">
                    <span class="stat-icon">⚔️</span>
                    <div class="stat-info">
                        <span class="stat-label"><?= htmlspecialchars($stats['activeStatLabel']) ?></span>
                        <span class="stat-value"><?= $stats['attackValue'] ?></span>
                    </div>
                </div>
                <div class="stat-badge">
                    <span class="stat-icon">🛡️</span>
                    <div class="stat-info">
                        <span class="stat-label">Defense</span>
                        <span class="stat-value"><?= $stats['defenseValue'] ?></span>
                    </div>
                </div>
            </div>

            <!-- Real current/most-recent monster, not a hardcoded Goblin Warrior -->
            <div class="battle-card-container">
                <div class="battle-card-content">
                    <div class="monster-avatar-block">
                        <div class="monster-art"><?= $monsterIcon ?></div>
                        <div class="monster-details">
                            <h3>Current Monster</h3>
                            <h4 id="monster-name"><?= $monster ? htmlspecialchars($monster['monster_name']) : 'None yet' ?></h4>
                            <p class="hp-text">
                                HP <span id="monster-hp-display"><?= $monster ? (int) $monster['hp'] . '/' . (int) $monster['hp'] : '—' ?></span>
                            </p>
                            <div class="health-bar-track">
                                <div id="monster-health-fill" class="health-bar-fill" style="width: 100%;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="battle-actions-block">
                        <?php if ($monster): ?>
                            <button class="menu-action-btn btn-blue" onclick="continueBattle()">Battle</button>
                        <?php else: ?>
                            <button class="menu-action-btn btn-blue" disabled>Battle</button>
                        <?php endif; ?>
                        <button class="menu-action-btn btn-outline" onclick="toggleDrops()">View Drops</button>
                    </div>
                </div>

                <!-- Real drop table for the current monster, hidden until "View Drops" is clicked -->
                <div id="drop-panel" style="display:none; margin-top:20px; padding-top:20px; border-top:1px solid #1e293b; text-align:left;">
                    <h4 style="color:#94a3b8; margin-bottom:12px;">
                        Possible Drops — <?= $monster ? htmlspecialchars($monster['monster_name']) : '' ?>
                    </h4>
                    <ul class="materials-list">
                        <?php foreach ($drops as $d): ?>
                            <li><?= htmlspecialchars($d['item_name']) ?> — <?= rtrim(rtrim(number_format($d['drop_chance'], 1), '0'), '.') ?>% chance</li>
                        <?php endforeach; ?>
                        <?php if ($monster): ?>
                            <li><?= (int) $monster['gold_min'] ?>-<?= (int) $monster['gold_max'] ?> Gold</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="quick-nav-row">
                <button class="quick-btn btn-blue" onclick="window.location.href='inventory.php'">Inventory</button>
                <button class="quick-btn btn-purple" onclick="window.location.href='skills.php'">View Skills</button>
                <button class="quick-btn btn-green" onclick="window.location.href='crafting.php'">Open Crafting</button>
            </div>

        </main>

    </div>

    <script>
        function toggleDrops() {
            const el = document.getElementById('drop-panel');
            el.style.display = (el.style.display === 'none') ? 'block' : 'none';
        }

        // Continues the fight against the character's current_monster_id —
        // battle-arena.php defaults to that monster when no ?monster_id is given.
        function continueBattle() {
            window.location.href = "battle-arena.php";
        }
    </script>
</body>
</html>