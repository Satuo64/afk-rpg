<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

$userId = (int) $_SESSION['user_id'];
$stats  = get_character_stats($pdo, $userId);
$character = $stats['character'];

$monsterId = (int) ($_GET['monster_id'] ?? $character['current_monster_id']);

$stmt = $pdo->prepare('SELECT * FROM monsters WHERE monster_id = :id');
$stmt->execute(['id' => $monsterId]);
$monster = $stmt->fetch();

if (!$monster) {
    header('Location: battle-list.php');
    exit;
}

// Lock check — same rule as battle-list.php, enforced again here so
// ?monster_id=<locked> typed directly into the URL still gets bounced.
$stmt = $pdo->prepare('SELECT sequence_order FROM monsters WHERE monster_id = :id');
$stmt->execute(['id' => $character['current_monster_id']]);
$currentSeq = (int) ($stmt->fetch()['sequence_order'] ?? 1);

if ((int) $monster['sequence_order'] > $currentSeq) {
    header('Location: battle-list.php?error=locked');
    exit;
}

// ---- Start a fresh encounter, or resume the one already in progress -------
if ((int) ($character['battle_monster_id'] ?? 0) !== $monsterId) {
    // Not currently mid-fight with THIS monster: starting fresh abandons any
    // unfinished encounter with a different monster (no flee logged for it —
    // a known simplification) and fully heals, since you're walking up to
    // this fight rested, not mid-battle.
    $pdo->prepare(
        'UPDATE characters SET battle_monster_id = :mid, battle_monster_hp = :mhp, hp = :hp WHERE character_id = :cid'
    )->execute([
        'mid' => $monsterId,
        'mhp' => (int) $monster['hp'],
        'hp'  => $stats['maxHp'],
        'cid' => $character['character_id'],
    ]);

    $characterHp = $stats['maxHp'];
    $battleMonsterHp = (int) $monster['hp'];
} else {
    // Resuming: show exactly where the fight left off
    $characterHp = $stats['currentHp'];
    $battleMonsterHp = (int) $character['battle_monster_hp'];
}

$maxHp = $stats['maxHp'];
$monsterMaxHp = (int) $monster['hp'];
$playerHpPercent = $maxHp > 0 ? max(0, min(100, round($characterHp / $maxHp * 100))) : 0;
$monsterHpPercent = $monsterMaxHp > 0 ? max(0, min(100, round($battleMonsterHp / $monsterMaxHp * 100))) : 0;

// Drop table for this monster
$stmt = $pdo->prepare(
    'SELECT item_name, drop_chance FROM items WHERE drop_monster_id = :mid ORDER BY drop_chance DESC'
);
$stmt->execute(['mid' => $monster['monster_id']]);
$drops = $stmt->fetchAll();

// Last few real log lines for this character + monster
$stmt = $pdo->prepare(
    'SELECT damage_dealt, damage_taken, result, drops_summary, created_at
     FROM combat_logs
     WHERE character_id = :cid AND monster_id = :mid
     ORDER BY combat_id DESC
     LIMIT 6'
);
$stmt->execute(['cid' => $character['character_id'], 'mid' => $monsterId]);
$recentLogs = array_reverse($stmt->fetchAll());

$emojiPool = ['💧', '🧌', '👹', '🐺', '🐉', '🦂', '👻', '🗿'];
$monsterIcon = $emojiPool[((int) $monster['monster_id'] - 1) % count($emojiPool)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Combat Arena</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'battle'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">

            <div class="battle-arena-stage">

                <div class="fighter-box player-side">
                    <div class="fighter-header-info">
                        <span class="fighter-name"><?= htmlspecialchars($character['character_name']) ?> (Lvl <?= $stats['totalLevel'] ?>)</span>
                        <span class="fighter-hp-val">HP <?= $characterHp ?>/<?= $maxHp ?></span>
                    </div>
                    <div class="arena-hp-bar-track">
                        <div class="arena-hp-bar-fill fill-green" style="width: <?= $playerHpPercent ?>%;"></div>
                    </div>
                    <div class="actor-sprite player-graphic">🥷</div>
                </div>

                <div class="fighter-box monster-side">
                    <div class="fighter-header-info">
                        <span class="fighter-name"><?= htmlspecialchars($monster['monster_name']) ?> (Lvl <?= (int) $monster['level'] ?>)</span>
                        <span class="fighter-hp-val">HP <?= $battleMonsterHp ?>/<?= $monsterMaxHp ?></span>
                    </div>
                    <div class="arena-hp-bar-track">
                        <div class="arena-hp-bar-fill fill-red" style="width: <?= $monsterHpPercent ?>%;"></div>
                    </div>
                    <div class="actor-sprite monster-graphic"><?= $monsterIcon ?></div>
                </div>

            </div>

            <!-- Real recent log lines from combat_logs, not static placeholder text -->
            <div class="arena-battle-log-feed">
                <?php if (empty($recentLogs)): ?>
                    <div class="arena-log-line">You size up <?= htmlspecialchars($monster['monster_name']) ?>. Attack when ready.</div>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <?php if ($log['result'] === 'win'): ?>
                            <div class="arena-log-line highlight-green">
                                You dealt <?= (int) $log['damage_dealt'] ?> damage and defeated <?= htmlspecialchars($monster['monster_name']) ?>! Loot: <?= htmlspecialchars($log['drops_summary']) ?>
                            </div>
                        <?php elseif ($log['result'] === 'loss'): ?>
                            <div class="arena-log-line" style="color:#f87171;">
                                <?= htmlspecialchars($monster['monster_name']) ?> dealt <?= (int) $log['damage_taken'] ?> damage and defeated you. You retreated to heal.
                            </div>
                        <?php else: ?>
                            <div class="arena-log-line">
                                You dealt <?= (int) $log['damage_dealt'] ?> damage; <?= htmlspecialchars($monster['monster_name']) ?> dealt <?= (int) $log['damage_taken'] ?> back.
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Real, monster-specific drop table -->
            <div class="arena-battle-log-feed" style="margin-top:-10px;">
                <div class="arena-log-line" style="color:#94a3b8; font-weight:700;">Possible Drops</div>
                <?php foreach ($drops as $d): ?>
                    <div class="arena-log-line">
                        <?= htmlspecialchars($d['item_name']) ?> — <?= rtrim(rtrim(number_format($d['drop_chance'], 1), '0'), '.') ?>% chance
                    </div>
                <?php endforeach; ?>
                <div class="arena-log-line"><?= (int) $monster['gold_min'] ?>-<?= (int) $monster['gold_max'] ?> Gold</div>
            </div>

            <div class="arena-actions-footer" style="display:flex; gap:16px;">
                <form method="POST" action="resolve-attack.php" style="flex:1;">
                    <button type="submit" class="arena-continue-btn">⚔️ Attack</button>
                </form>
                <form method="POST" action="flee.php" style="flex:0 0 160px;">
                    <button type="submit" class="arena-continue-btn" style="background-color:#334155;">Flee</button>
                </form>
            </div>

        </main>

    </div>
</body>
</html>