<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

$userId = (int) $_SESSION['user_id'];
$stats  = get_character_stats($pdo, $userId);
$character = $stats['character'];

// The sequence_order of the character's current (furthest unlocked) monster —
// anything beyond this is locked. Anything at or before it can be fought.
$stmt = $pdo->prepare('SELECT sequence_order FROM monsters WHERE monster_id = :id');
$stmt->execute(['id' => $character['current_monster_id']]);
$currentSeq = (int) ($stmt->fetch()['sequence_order'] ?? 1);

$monsters = $pdo->query('SELECT * FROM monsters ORDER BY sequence_order ASC')->fetchAll();

$dropsStmt = $pdo->prepare(
    'SELECT item_name, drop_chance FROM items WHERE drop_monster_id = :mid ORDER BY drop_chance DESC'
);

// No icon column on monsters — cycle through a small emoji pool by id so
// each monster at least looks visually distinct until you add a real
// sprite/icon column.
$emojiPool = ['💧', '🧌', '👹', '🐺', '🐉', '🦂', '👻', '🗿'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Monster Selection</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'battle'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1>Monster List</h1>
            </div>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'locked'): ?>
                <p style="color:#f87171; margin-bottom:20px;">
                    That monster isn't unlocked yet — defeat your current one first.
                </p>
            <?php endif; ?>

            <div class="monster-list-container">
                <table class="monster-table">
                    <thead>
                        <tr>
                            <th>Monster</th>
                            <th>HP</th>
                            <th>Drops</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monsters as $monster): ?>
                            <?php
                                $icon = $emojiPool[((int) $monster['monster_id'] - 1) % count($emojiPool)];
                                $isLocked = (int) $monster['sequence_order'] > $currentSeq;

                                $dropsStmt->execute(['mid' => $monster['monster_id']]);
                                $drops = $dropsStmt->fetchAll();
                            ?>
                            <tr>
                                <td>
                                    <div class="monster-profile">
                                        <div class="monster-sprite-small"><?= $isLocked ? '🔒' : $icon ?></div>
                                        <span class="monster-title">
                                            <?= htmlspecialchars($monster['monster_name']) ?> (Lvl. <?= (int) $monster['level'] ?>)
                                        </span>
                                    </div>
                                </td>
                                <td class="hp-cell"><?= (int) $monster['hp'] ?> Hp</td>
                                <td>
                                    <ul class="materials-list">
                                        <?php foreach ($drops as $d): ?>
                                            <li><?= htmlspecialchars($d['item_name']) ?> (<?= rtrim(rtrim(number_format($d['drop_chance'], 1), '0'), '.') ?>%)</li>
                                        <?php endforeach; ?>
                                        <li><?= (int) $monster['gold_min'] ?>-<?= (int) $monster['gold_max'] ?> Gold</li>
                                    </ul>
                                </td>
                                <td>
                                    <?php if ($isLocked): ?>
                                        <button class="fight-btn" disabled style="opacity:0.5; cursor:not-allowed;">🔒 Locked</button>
                                    <?php else: ?>
                                        <a href="battle-arena.php?monster_id=<?= (int) $monster['monster_id'] ?>"
                                           class="fight-btn" style="text-decoration:none; display:inline-block; text-align:center;">Fight</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>

    </div>
</body>
</html>