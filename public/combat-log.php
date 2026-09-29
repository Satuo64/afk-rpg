<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_character();

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT character_id FROM characters WHERE user_id = :uid');
$stmt->execute(['uid' => $userId]);
$characterId = (int) $stmt->fetch()['character_id'];

// Full combat history for this character, newest first, capped at 100 lines
// per the original design spec — then reversed so the terminal reads
// chronologically top-to-bottom, oldest to newest, like a real scroll log.
$stmt = $pdo->prepare(
    'SELECT cl.action_type, cl.damage_dealt, cl.damage_taken, cl.result, cl.drops_summary, cl.created_at,
            m.monster_name
     FROM combat_logs cl
     JOIN monsters m ON m.monster_id = cl.monster_id
     WHERE cl.character_id = :cid
     ORDER BY cl.combat_id DESC
     LIMIT 100'
);
$stmt->execute(['cid' => $characterId]);
$logs = array_reverse($stmt->fetchAll());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Combat Log</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'combat-log'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1>Combat Log History</h1>
                <p>Your last <?= count($logs) ?> combat events (capped at 100 lines), oldest to newest.</p>
            </div>

            <div class="combat-log-container">
                <div class="combat-log-terminal" id="log-terminal">
                    <?php if (empty($logs)): ?>
                        <div class="log-entry system">[SYSTEM] No battles fought yet. Head to Battle to start your first fight.</div>
                    <?php endif; ?>

                    <?php foreach ($logs as $log): ?>
                        <?php $ts = date('H:i:s', strtotime($log['created_at'])); ?>

                        <?php if ($log['action_type'] === 'flee'): ?>
                            <div class="log-entry system">[<?= $ts ?>] You fled from <?= htmlspecialchars($log['monster_name']) ?>.</div>

                        <?php else: ?>
                            <?php if ((int) $log['damage_dealt'] > 0): ?>
                                <div class="log-entry damage-out">[<?= $ts ?>] You dealt <?= (int) $log['damage_dealt'] ?> damage to <?= htmlspecialchars($log['monster_name']) ?>.</div>
                            <?php endif; ?>

                            <?php if ((int) $log['damage_taken'] > 0): ?>
                                <div class="log-entry damage-in">[<?= $ts ?>] <?= htmlspecialchars($log['monster_name']) ?> dealt <?= (int) $log['damage_taken'] ?> damage to you.</div>
                            <?php endif; ?>

                            <?php if ($log['result'] === 'win'): ?>
                                <div class="log-entry victory">[<?= $ts ?>] <?= htmlspecialchars($log['monster_name']) ?> has been defeated!</div>
                                <?php if (!empty($log['drops_summary'])): ?>
                                    <div class="log-entry drop-item">[<?= $ts ?>] Rewards: <?= htmlspecialchars($log['drops_summary']) ?></div>
                                <?php endif; ?>
                            <?php elseif ($log['result'] === 'loss'): ?>
                                <div class="log-entry damage-in">[<?= $ts ?>] You were defeated by <?= htmlspecialchars($log['monster_name']) ?> and retreated to heal.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>

    </div>

    <script>
        // Auto-scroll to the most recent entry on load
        const terminal = document.getElementById('log-terminal');
        terminal.scrollTop = terminal.scrollHeight;
    </script>
</body>
</html>