<?php
/**
 * public/player-overview.php
 *
 * Read-only overview of one player, opened from the "View" button in
 * admin-panel.php. Its own page (not stacked above the search box) so the
 * search results are exactly where you left them when you go back.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_role(['admin', 'moderator']);

$viewId  = (int) ($_GET['id'] ?? 0);
$search  = trim($_GET['search'] ?? '');
$backUrl = 'admin-panel.php' . ($search !== '' ? '?search=' . urlencode($search) : '');

// Account/character summary comes from the vw_player_overview SQL view;
// combat stats reuse get_character_stats() like every other page, so a
// moderator sees exactly the numbers the player sees.
$stmt = $pdo->prepare('SELECT * FROM vw_player_overview WHERE user_id = :id');
$stmt->execute(['id' => $viewId]);
$overview = $stmt->fetch() ?: null;

$pStats      = null;
$pInventory  = [];
$pRecentLogs = [];

if ($overview && $overview['character_id']) {
    $pStats = get_character_stats($pdo, $viewId);

    $stmt = $pdo->prepare(
        'SELECT i.item_name, i.item_type, i.rarity, ci.quantity, ci.equipped
         FROM character_inventory ci
         JOIN items i ON i.item_id = ci.item_id
         WHERE ci.character_id = :cid
         ORDER BY ci.equipped DESC, i.item_type, i.item_name'
    );
    $stmt->execute(['cid' => $overview['character_id']]);
    $pInventory = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT cl.action_type, cl.damage_dealt, cl.damage_taken, cl.result, cl.drops_summary,
                cl.created_at, m.monster_name
         FROM combat_logs cl
         JOIN monsters m ON m.monster_id = cl.monster_id
         WHERE cl.character_id = :cid
         ORDER BY cl.combat_id DESC
         LIMIT 10'
    );
    $stmt->execute(['cid' => $overview['character_id']]);
    $pRecentLogs = array_reverse($stmt->fetchAll());
}

$ovName = $overview ? ($overview['character_name'] ?? $overview['username']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Player Overview</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .ov-table td, .ov-table th { padding: 12px 10px; }
        .ov-block { margin-bottom: 25px; }
        .back-link {
            display: inline-block;
            padding: 10px 22px;
            border: 1px solid #334155;
            border-radius: 8px;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover { background-color: rgba(255, 255, 255, 0.05); border-color: #475569; }
    </style>
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'admin'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1>Player Overview</h1>
                <p style="margin-top:12px;"><a href="<?= htmlspecialchars($backUrl) ?>" class="back-link">← Back to players</a></p>
            </div>

            <?php if (!$overview): ?>
                <p style="color:#f87171;">That player doesn't exist.</p>
            <?php else: ?>

                <div class="profile-card">
                    <div class="profile-avatar">👤</div>
                    <div class="profile-text" style="flex:1;">
                        <h2><?= htmlspecialchars($ovName) ?></h2>
                        <p>
                            @<?= htmlspecialchars($overview['username']) ?> ·
                            <?= htmlspecialchars(ucfirst($overview['role'])) ?> ·
                            <?= $overview['is_suspended'] ? '<span style="color:#f87171;">Suspended</span>' : '<span style="color:#4ade80;">Active</span>' ?>
                            <?php if ($overview['character_id']): ?> · Level <?= (int) $overview['total_level'] ?><?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if (!$overview['character_id']): ?>
                    <p style="color:#94a3b8;">This account hasn't created a character yet, so there's no game data to show.</p>
                <?php else: ?>

                    <div class="character-stats-grid">
                        <div class="char-stat-card">
                            <span class="char-stat-icon red-icon">❤️</span>
                            <div class="char-stat-info">
                                <span class="char-stat-label">HP</span>
                                <span class="char-stat-value"><?= $pStats['currentHp'] ?>/<?= $pStats['maxHp'] ?></span>
                            </div>
                        </div>
                        <div class="char-stat-card">
                            <span class="char-stat-icon">⚔️</span>
                            <div class="char-stat-info">
                                <span class="char-stat-label"><?= htmlspecialchars($pStats['activeStatLabel']) ?> (Active)</span>
                                <span class="char-stat-value"><?= $pStats['attackValue'] ?></span>
                            </div>
                        </div>
                        <div class="char-stat-card">
                            <span class="char-stat-icon">🛡️</span>
                            <div class="char-stat-info">
                                <span class="char-stat-label">Defense</span>
                                <span class="char-stat-value"><?= $pStats['defenseValue'] ?></span>
                            </div>
                        </div>
                        <div class="char-stat-card">
                            <span class="char-stat-icon">💰</span>
                            <div class="char-stat-info">
                                <span class="char-stat-label">Gold</span>
                                <span class="char-stat-value"><?= number_format((int) $overview['gold']) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="monster-list-container ov-block">
                        <table class="monster-table ov-table">
                            <tbody>
                                <tr><td style="color:#64748b;">Email</td><td><?= htmlspecialchars($overview['email']) ?></td>
                                    <td style="color:#64748b;">Joined</td><td><?= htmlspecialchars($overview['created_at']) ?></td></tr>
                                <tr><td style="color:#64748b;">Last login</td><td><?= $overview['last_login'] ? htmlspecialchars($overview['last_login']) : 'Never' ?></td>
                                    <td style="color:#64748b;">Active skill</td><td><?= htmlspecialchars($overview['active_skill'] ?? '—') ?></td></tr>
                                <tr><td style="color:#64748b;">Current monster</td><td><?= htmlspecialchars($overview['current_monster'] ?? '—') ?></td>
                                    <td style="color:#64748b;">Record</td>
                                    <td><?= (int) $overview['wins'] ?> wins · <?= (int) $overview['losses'] ?> losses · <?= (int) $overview['flees'] ?> fled</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="monster-list-container ov-block">
                        <table class="monster-table ov-table">
                            <thead><tr><th>Skill</th><th>Level</th><th>XP</th></tr></thead>
                            <tbody>
                                <?php foreach ($pStats['skillRows'] as $sk): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($sk['skill_name']) ?></td>
                                        <td>Lvl <?= (int) $sk['skill_level'] ?></td>
                                        <td><?= number_format((int) $sk['current_xp']) ?> / <?= number_format((int) $sk['skill_level'] * XP_PER_LEVEL) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="monster-list-container ov-block">
                        <table class="monster-table ov-table">
                            <thead><tr><th>Item</th><th>Type</th><th>Rarity</th><th>Qty</th><th>Equipped</th></tr></thead>
                            <tbody>
                                <?php if (empty($pInventory)): ?>
                                    <tr><td colspan="5" style="color:#64748b;">Inventory is empty.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($pInventory as $it): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['item_name']) ?></td>
                                        <td><?= htmlspecialchars(ucfirst($it['item_type'])) ?></td>
                                        <td><?= htmlspecialchars($it['rarity'] ?? 'Common') ?></td>
                                        <td><?= (int) $it['quantity'] ?></td>
                                        <td><?= $it['equipped'] ? '<span style="color:#4ade80;">Yes</span>' : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="combat-log-container ov-block">
                        <div class="combat-log-terminal" style="height:240px;">
                            <?php if (empty($pRecentLogs)): ?>
                                <div class="log-entry system">[SYSTEM] No combat recorded for this player yet.</div>
                            <?php endif; ?>
                            <?php foreach ($pRecentLogs as $lg): ?>
                                <?php $ts = date('H:i:s', strtotime($lg['created_at'])); ?>
                                <?php if ($lg['action_type'] === 'flee'): ?>
                                    <div class="log-entry system">[<?= $ts ?>] Fled from <?= htmlspecialchars($lg['monster_name']) ?>.</div>
                                <?php elseif ($lg['result'] === 'win'): ?>
                                    <div class="log-entry victory">[<?= $ts ?>] Defeated <?= htmlspecialchars($lg['monster_name']) ?> — <?= htmlspecialchars($lg['drops_summary'] ?? '') ?></div>
                                <?php elseif ($lg['result'] === 'loss'): ?>
                                    <div class="log-entry damage-in">[<?= $ts ?>] Lost to <?= htmlspecialchars($lg['monster_name']) ?>.</div>
                                <?php else: ?>
                                    <div class="log-entry damage-out">[<?= $ts ?>] Dealt <?= (int) $lg['damage_dealt'] ?>, took <?= (int) $lg['damage_taken'] ?> vs <?= htmlspecialchars($lg['monster_name']) ?>.</div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php endif; ?>
            <?php endif; ?>
        </main>

    </div>
</body>
</html>
