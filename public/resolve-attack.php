<?php
/**
 * public/resolve-attack.php
 *
 * Resolves exactly one attack tick against whatever monster the character
 * is currently mid-battle with (characters.battle_monster_id — never a
 * client-supplied monster id, so there's nothing here for a tampered
 * request to redirect at a different monster).
 *
 * Wrapped in a transaction: a kill can touch up to 5 tables in one go
 * (character_skills XP/level-up, characters.gold, character_inventory
 * drops, characters.current_monster_id progression, characters HP/battle
 * state) and combat_logs always gets a row. All or nothing — a crash
 * mid-reward should never leave gold added but no drop rolled, etc.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: battle-list.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stats  = get_character_stats($pdo, $userId);
$character = $stats['character'];

if (empty($character['battle_monster_id'])) {
    // No fight in progress — nothing to resolve. Probably a stale/replayed form.
    header('Location: battle-list.php');
    exit;
}

$monsterId = (int) $character['battle_monster_id'];

$stmt = $pdo->prepare('SELECT * FROM monsters WHERE monster_id = :id');
$stmt->execute(['id' => $monsterId]);
$monster = $stmt->fetch();

if (!$monster) {
    header('Location: battle-list.php');
    exit;
}

$monsterHp   = (int) $character['battle_monster_hp'];
$characterHp = (int) $character['hp'];
$maxHp       = $stats['maxHp'];
$activeSkill = $stats['activeSkill'];

// ---- damage dealt this tick -------------------------------------------
$damageDealt = max(1, $stats['attackValue'] - (int) $monster['defense']);

if ($activeSkill && $activeSkill['grants_crit']) {
    $roll = mt_rand() / mt_getrandmax();
    if ($roll < $stats['critChance']) {
        $multiplier  = (float) $activeSkill['damage_multiplier'];
        $damageDealt = (int) round($damageDealt * $multiplier);
    }
}

$monsterHp = max(0, $monsterHp - $damageDealt);

$result       = null;   // 'win' | 'loss' | null (fight continues)
$dropsSummary = null;
$damageTaken  = 0;

try {
    $pdo->beginTransaction();

    if ($monsterHp <= 0) {
        // ---- Monster defeated this tick ------------------------------
        $result = 'win';

        // XP + level-up(s) on the active skill only
        if ($activeSkill) {
            $newXp    = (int) $activeSkill['current_xp'] + (int) $monster['exp_reward'];
            $newLevel = (int) $activeSkill['skill_level'];
            $required = $newLevel * XP_PER_LEVEL;

            while ($newXp >= $required) {
                $newXp -= $required;
                $newLevel++;
                $required = $newLevel * XP_PER_LEVEL;
            }

            $pdo->prepare(
                'UPDATE character_skills SET skill_level = :lvl, current_xp = :xp
                 WHERE character_id = :cid AND skill_id = :sid'
            )->execute([
                'lvl' => $newLevel,
                'xp'  => $newXp,
                'cid' => $character['character_id'],
                'sid' => $activeSkill['skill_id'],
            ]);
        }

        // Gold, random within this monster's range
        $goldWon = random_int((int) $monster['gold_min'], (int) $monster['gold_max']);
        $pdo->prepare('UPDATE characters SET gold = gold + :g WHERE character_id = :cid')
            ->execute(['g' => $goldWon, 'cid' => $character['character_id']]);

        // Item drops — independent roll per possible drop, upsert into inventory
        $dropStmt = $pdo->prepare(
            'SELECT item_id, item_name, drop_chance FROM items WHERE drop_monster_id = :mid'
        );
        $dropStmt->execute(['mid' => $monsterId]);

        $droppedNames = [];
        $upsert = $pdo->prepare(
            'INSERT INTO character_inventory (character_id, item_id, quantity, equipped)
             VALUES (:cid, :iid, 1, 0)
             ON DUPLICATE KEY UPDATE quantity = quantity + 1'
        );

        foreach ($dropStmt->fetchAll() as $possible) {
            $roll = (mt_rand() / mt_getrandmax()) * 100;
            if ($roll <= (float) $possible['drop_chance']) {
                $upsert->execute([
                    'cid' => $character['character_id'],
                    'iid' => $possible['item_id'],
                ]);
                $droppedNames[] = $possible['item_name'];
            }
        }

        $dropsSummary = $goldWon . ' gold' . ($droppedNames ? ', ' . implode(', ', $droppedNames) : '');

        // Advance the unlock chain ONLY if this was the character's frontier monster
        if ((int) $character['current_monster_id'] === $monsterId && !empty($monster['next_monster_id'])) {
            $pdo->prepare('UPDATE characters SET current_monster_id = :next WHERE character_id = :cid')
                ->execute(['next' => $monster['next_monster_id'], 'cid' => $character['character_id']]);
        }

        // Encounter over — clear battle state so the next visit starts fresh
        $pdo->prepare(
            'UPDATE characters SET battle_monster_id = NULL, battle_monster_hp = NULL WHERE character_id = :cid'
        )->execute(['cid' => $character['character_id']]);

    } else {
        // ---- Monster survives: it counterattacks -------------------------
        $damageTaken = max(1, (int) $monster['attack'] - $stats['defenseValue']);
        $characterHp = max(0, $characterHp - $damageTaken);

        if ($characterHp <= 0) {
            $result = 'loss';
            // Respawn at full HP, abandon this encounter — no rewards, no progression
            $pdo->prepare(
                'UPDATE characters SET hp = :hp, battle_monster_id = NULL, battle_monster_hp = NULL
                 WHERE character_id = :cid'
            )->execute(['hp' => $maxHp, 'cid' => $character['character_id']]);
        } else {
            $pdo->prepare(
                'UPDATE characters SET hp = :hp, battle_monster_hp = :mhp WHERE character_id = :cid'
            )->execute(['hp' => $characterHp, 'mhp' => $monsterHp, 'cid' => $character['character_id']]);
        }
    }

    // One combat_logs row per tick, regardless of outcome
    $pdo->prepare(
        "INSERT INTO combat_logs (character_id, monster_id, action_type, item_used_id, damage_dealt, damage_taken, result, drops_summary)
         VALUES (:cid, :mid, 'attack', NULL, :dd, :dt, :res, :drops)"
    )->execute([
        'cid'   => $character['character_id'],
        'mid'   => $monsterId,
        'dd'    => $damageDealt,
        'dt'    => $damageTaken,
        'res'   => $result,
        'drops' => $dropsSummary,
    ]);

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: battle-arena.php?monster_id=' . $monsterId . '&error=combat_failed');
    exit;
}

header('Location: battle-arena.php?monster_id=' . $monsterId);
exit;
