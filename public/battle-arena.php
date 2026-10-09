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

// Healing consumables owned by this character, so they can be used
// without leaving the fight.
$stmt = $pdo->prepare(
    "SELECT ci.item_id, ci.quantity, i.item_name, i.heal_amount
     FROM character_inventory ci
     JOIN items i ON i.item_id = ci.item_id
     WHERE ci.character_id = :cid AND i.item_type = 'consumable' AND i.heal_amount > 0
     ORDER BY i.heal_amount"
);
$stmt->execute(['cid' => $character['character_id']]);
$potions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Combat Arena</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Room at the bottom of the page so the fixed action bar never
           covers the last section (Potions). */
        main.main-content { padding-bottom: 110px; }

        /* Actually pin Attack/Flee to the bottom of the viewport — this
           class was referenced in the markup but never had a real rule,
           so it was just sitting in normal flow before. */
        .arena-fixed-actions {
            position: fixed;
            bottom: 0;
            left: 230px;   /* matches the sidebar width in style.css */
            right: 0;
            display: flex;
            gap: 16px;
            padding: 14px 36px;
            background-color: #0b1524;
            border-top: 1px solid #334155;
            box-shadow: 0 -6px 20px rgba(0, 0, 0, 0.35);
            z-index: 500;
        }
        @media (max-width: 768px) {
            .arena-fixed-actions { left: 0; padding: 12px 16px; }
        }

        /* The <summary> element's native rendering can end up visually
           centered/cramped depending on the browser — force it to behave
           like a normal left-aligned block. */
        .drops-collapsible summary { display: block; text-align: left; width: 100%; }
    </style>
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'battle'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content" style="padding-bottom: 100px;">

            <?php if (isset($_GET['healed'])): ?>
                <p style="color:#4ade80; margin-bottom:15px;">
                    Used <?= htmlspecialchars($_GET['item'] ?? 'potion') ?> — restored <?= (int) $_GET['healed'] ?> HP.
                </p>
            <?php elseif (isset($_GET['error']) && in_array($_GET['error'], ['not_usable', 'use_failed'], true)): ?>
                <p style="color:#f87171; margin-bottom:15px;">That item couldn't be used.</p>
            <?php endif; ?>

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

            <!-- Real, monster-specific drop table — collapsed by default -->
            <details class="drops-collapsible">
                <summary>Possible Drops</summary>
                <?php foreach ($drops as $d): ?>
                    <div class="arena-log-line">
                        <?= htmlspecialchars($d['item_name']) ?> — <?= rtrim(rtrim(number_format($d['drop_chance'], 1), '0'), '.') ?>% chance
                    </div>
                <?php endforeach; ?>
                <div class="arena-log-line"><?= (int) $monster['gold_min'] ?>-<?= (int) $monster['gold_max'] ?> Gold</div>
            </details>

            <!-- Healing potions, usable without leaving the fight -->
            <div class="arena-battle-log-feed">
                <div class="arena-log-line" style="color:#94a3b8; font-weight:700; margin-bottom:8px;">Potions</div>
                <?php if (empty($potions)): ?>
                    <div class="arena-log-line" style="color:#64748b;">No potions in inventory.</div>
                <?php else: ?>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <?php foreach ($potions as $p): ?>
                            <form method="POST" action="use-item.php" id="potion-form-<?= (int) $p['item_id'] ?>" style="display:inline-block;">
                                <input type="hidden" name="item_id" value="<?= (int) $p['item_id'] ?>">
                                <input type="hidden" name="return_to" value="battle-arena.php?monster_id=<?= (int) $monster['monster_id'] ?>">
                                <button type="submit" class="select-btn btn-green" style="width:auto; padding:10px 16px; font-size:0.9rem;">
                                    🧪 <?= htmlspecialchars($p['item_name']) ?> (+<?= (int) $p['heal_amount'] ?> HP) ×<?= (int) $p['quantity'] ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <p id="auto-fight-hint" style="text-align:center; color:#64748b; font-size:0.8rem; margin-bottom:8px;">
                Auto-Fight attacks every second and automatically drinks your smallest potion when HP drops below 40%.
            </p>

            <div class="arena-fixed-actions">
                <form method="POST" action="resolve-attack.php" id="attack-form" style="flex:1;">
                    <button type="submit" class="arena-continue-btn">⚔️ Attack</button>
                </form>
                <button type="button" id="auto-fight-toggle" class="arena-continue-btn" style="flex:0 0 190px;">▶ Auto-Fight: OFF</button>
                <form method="POST" action="flee.php" id="flee-form" style="flex:0 0 100px;"
                      onsubmit="try { localStorage.setItem('autoFight_<?= $monsterId ?>', 'off'); } catch(e) {} return confirm('Flee from <?= addslashes(htmlspecialchars($monster['monster_name'])) ?>? You\'ll lose progress on this fight.');">
                    <button type="submit" class="arena-continue-btn" style="background-color:#334155; width:100%;">Flee</button>
                </form>
            </div>

        </main>

    </div>

    <script>
        (function () {
            const monsterId  = <?= (int) $monsterId ?>;
            const storageKey = 'autoFight_' + monsterId;
            const currentHp  = <?= (int) $characterHp ?>;
            const maxHp      = <?= (int) $maxHp ?>;
            const HEAL_THRESHOLD_RATIO = 0.4; // auto-drink a potion below 40% HP
            const TICK_DELAY_MS = 1000;

            // Potions already arrive sorted smallest heal_amount first (see the
            // SQL ORDER BY), so potions[0] is always the "cheapest" one to
            // conserve bigger potions for when they're actually needed.
            const potions = <?= json_encode(array_map(
                fn($p) => ['id' => (int) $p['item_id'], 'heal' => (int) $p['heal_amount']],
                $potions
            )) ?>;

            function isAutoOn() {
                try { return localStorage.getItem(storageKey) === 'on'; }
                catch (e) { return false; }
            }
            function setAutoOn(on) {
                try { localStorage.setItem(storageKey, on ? 'on' : 'off'); }
                catch (e) { /* localStorage unavailable — auto-fight just won't persist across reloads */ }
            }

            function updateToggleButton() {
                const btn = document.getElementById('auto-fight-toggle');
                if (isAutoOn()) {
                    btn.textContent = '⏸ Auto-Fight: ON';
                    btn.style.backgroundColor = '#16a34a';
                } else {
                    btn.textContent = '▶ Auto-Fight: OFF';
                    btn.style.backgroundColor = '#334155';
                }
            }

            function runNextTick() {
                if (!isAutoOn()) return;
                setTimeout(function () {
                    if (!isAutoOn()) return; // could have been turned off during the delay

                    const needsHealing = currentHp < maxHp * HEAL_THRESHOLD_RATIO;
                    if (needsHealing && potions.length > 0) {
                        const form = document.getElementById('potion-form-' + potions[0].id);
                        if (form) { form.submit(); return; }
                    }
                    document.getElementById('attack-form').submit();
                }, TICK_DELAY_MS);
            }

            document.getElementById('auto-fight-toggle').addEventListener('click', function () {
                setAutoOn(!isAutoOn());
                updateToggleButton();
                if (isAutoOn()) runNextTick();
            });

            updateToggleButton();
            if (isAutoOn()) runNextTick();
        })();
    </script>
</body>
</html>