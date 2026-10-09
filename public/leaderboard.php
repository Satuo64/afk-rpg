<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

/**
 * A Common Table Expression (WITH ranked_players AS (...)) feeding a
 * ROW_NUMBER() window function — ranks every character by total skill
 * level without a self-join or a correlated subquery. Built on top of
 * vw_player_overview rather than re-deriving total_level from scratch.
 */
$stmt = $pdo->query(
    "WITH ranked_players AS (
        SELECT
            user_id, username, character_name, total_level, wins, losses, gold,
            ROW_NUMBER() OVER (ORDER BY total_level DESC, wins DESC) AS player_rank
        FROM vw_player_overview
        WHERE character_id IS NOT NULL
    )
    SELECT * FROM ranked_players ORDER BY player_rank LIMIT 20"
);
$rankedPlayers = $stmt->fetchAll();

$myUserId = (int) $_SESSION['user_id'];
$medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Leaderboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'leaderboard'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1>Leaderboard</h1>
                <p>Top 20 players by total skill level</p>
            </div>

            <div class="monster-list-container">
                <table class="monster-table">
                    <thead>
                        <tr>
                            <th>Rank</th><th>Player</th><th>Level</th><th>Wins</th><th>Losses</th><th>Gold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rankedPlayers as $p): ?>
                            <?php $isMe = ((int) $p['user_id'] === $myUserId); ?>
                            <tr style="<?= $isMe ? 'background-color: rgba(59,130,246,0.08);' : '' ?>">
                                <td><?= $medals[$p['player_rank']] ?? '#' . (int) $p['player_rank'] ?></td>
                                <td><?= htmlspecialchars($p['character_name'] ?? $p['username']) ?><?= $isMe ? ' (you)' : '' ?></td>
                                <td><?= (int) $p['total_level'] ?></td>
                                <td><?= (int) $p['wins'] ?></td>
                                <td><?= (int) $p['losses'] ?></td>
                                <td><?= number_format((int) $p['gold']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>

    </div>
</body>
</html>
