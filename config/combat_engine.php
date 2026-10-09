<?php
/**
 * config/combat_engine.php
 *
 * The actual combat math, extracted out of resolve-attack.php so it can
 * be called two ways: once per HTTP request (a real Attack click) or in
 * a loop (offline simulation at login, replaying however many ticks the
 * elapsed time away accounts for).
 */
require_once __DIR__ . '/character_stats.php';

const AUTO_HEAL_THRESHOLD_PERCENT = 30;

/**
 * Damage formula (replaces the missing MySQL fn_calculate_damage).
 * Always at least 1 so a fight can never stall at 0 damage.
 */
function calculate_damage(int $atk, int $def): int
{
    return max(1, $atk - $def);
}

function resolve_one_combat_tick(PDO $pdo, int $characterId): array
{
    $stmt = $pdo->prepare('SELECT * FROM characters WHERE character_id = :cid');
    $stmt->execute(['cid' => $characterId]);
    $character = $stmt->fetch();

    if (!$character || empty($character['battle_monster_id'])) {
        return ['status' => 'no_battle'];
    }

    $stats = get_character_stats($pdo, (int) $character['user_id']);
    $character = $stats['character'];

    $monsterId = (int) $character['battle_monster_id'];

    $stmt = $pdo->prepare('SELECT * FROM monsters WHERE monster_id = :id');
    $stmt->execute(['id' => $monsterId]);
    $monster = $stmt->fetch();

    if (!$monster) {
        return ['status' => 'no_battle'];
    }

    $monsterHp   = (int) $character['battle_monster_hp'];
    $characterHp = (int) $character['hp'];
    $maxHp       = $stats['maxHp'];
    $activeSkill = $stats['activeSkill'];

    // ---- Auto-heal: if auto_battle is on and HP is low, drink the
    // strongest available potion before this tick's attack. -------------
    $autoHealedWith = null;

    if ((bool) ($character['auto_battle'] ?? 0) && $maxHp > 0 && ($characterHp / $maxHp * 100) < AUTO_HEAL_THRESHOLD_PERCENT) {
        $potionStmt = $pdo->prepare(
            "SELECT ci.item_id, i.item_name, i.heal_amount
             FROM character_inventory ci
             JOIN items i ON i.item_id = ci.item_id
             WHERE ci.character_id = :cid AND i.item_type = 'consumable' AND i.heal_amount > 0
             ORDER BY i.heal_amount DESC
             LIMIT 1"
        );
        $potionStmt->execute(['cid' => $characterId]);
        $potion = $potionStmt->fetch();

        if ($potion) {
            $characterHp = min($maxHp, $characterHp + (int) $potion['heal_amount']);
            $autoHealedWith = $potion['item_name'];

            $pdo->prepare('UPDATE characters SET hp = :hp, last_hp_update = NOW() WHERE character_id = :cid')
                ->execute(['hp' => $characterHp, 'cid' => $characterId]);

            $pdo->prepare('UPDATE character_inventory SET quantity = quantity - 1 WHERE character_id = :cid AND item_id = :iid')
                ->execute(['cid' => $characterId, 'iid' => $potion['item_id']]);
            $pdo->prepare('DELETE FROM character_inventory WHERE character_id = :cid AND item_id = :iid AND quantity <= 0')
                ->execute(['cid' => $characterId, 'iid' => $potion['item_id']]);

            $pdo->prepare(
                "INSERT INTO combat_logs (character_id, monster_id, action_type, damage_dealt, damage_taken, result, drops_summary)
                 VALUES (:cid, :mid, 'item_use', 0, 0, NULL, :note)"
            )->execute([
                'cid'  => $characterId,
                'mid'  => $monsterId,
                'note' => 'Auto-used ' . $potion['item_name'],
            ]);
        }
    }

    // ---- damage dealt this tick -------------------------------------------
    $damageDealt = calculate_damage((int) $stats['attackValue'], (int) $monster['defense']);

    if ($activeSkill && $activeSkill['grants_crit']) {
        $roll = mt_rand() / mt_getrandmax();
        if ($roll < $stats['critChance']) {
            $multiplier  = (float) $activeSkill['damage_multiplier'];
            $damageDealt = (int) round($damageDealt * $multiplier);
        }
    }

    $monsterHp = max(0, $monsterHp - $damageDealt);

    $result       = null;
    $dropsSummary = null;
    $damageTaken  = 0;
    $goldWon      = 0;
    $droppedNames = [];

    try {
        $pdo->beginTransaction();

        if ($monsterHp <= 0) {
            $result = 'win';

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
                    'cid' => $characterId,
                    'sid' => $activeSkill['skill_id'],
                ]);
            }

            $goldWon = random_int((int) $monster['gold_min'], (int) $monster['gold_max']);
            $pdo->prepare('UPDATE characters SET gold = gold + :g WHERE character_id = :cid')
                ->execute(['g' => $goldWon, 'cid' => $characterId]);

            $dropStmt = $pdo->prepare(
                'SELECT item_id, item_name, drop_chance FROM items WHERE drop_monster_id = :mid'
            );
            $dropStmt->execute(['mid' => $monsterId]);

            $upsert = $pdo->prepare(
                'INSERT INTO character_inventory (character_id, item_id, quantity, equipped)
                 VALUES (:cid, :iid, 1, 0)
                 ON DUPLICATE KEY UPDATE quantity = quantity + 1'
            );

            foreach ($dropStmt->fetchAll() as $possible) {
                $roll = (mt_rand() / mt_getrandmax()) * 100;
                if ($roll <= (float) $possible['drop_chance']) {
                    $upsert->execute(['cid' => $characterId, 'iid' => $possible['item_id']]);
                    $droppedNames[] = $possible['item_name'];
                }
            }

            $dropsSummary = $goldWon . ' gold' . ($droppedNames ? ', ' . implode(', ', $droppedNames) : '');

            if ((int) $character['current_monster_id'] === $monsterId && !empty($monster['next_monster_id'])) {
                $pdo->prepare('UPDATE characters SET current_monster_id = :next WHERE character_id = :cid')
                    ->execute(['next' => $monster['next_monster_id'], 'cid' => $characterId]);
            }

            $pdo->prepare(
                'UPDATE characters SET battle_monster_id = NULL, battle_monster_hp = NULL, last_hp_update = NOW() WHERE character_id = :cid'
            )->execute(['cid' => $characterId]);

        } else {
            $damageTaken = calculate_damage((int) $monster['attack'], (int) $stats['defenseValue']);
            $characterHp = max(0, $characterHp - $damageTaken);

            if ($characterHp <= 0) {
                $result = 'loss';
                // Respawn at full HP, abandon this encounter, and turn off
                // auto_battle so the loop doesn't keep feeding the monster.
                $pdo->prepare(
                    'UPDATE characters SET hp = :hp, last_hp_update = NOW(), battle_monster_id = NULL, battle_monster_hp = NULL, auto_battle = 0
                     WHERE character_id = :cid'
                )->execute(['hp' => $maxHp, 'cid' => $characterId]);
            } else {
                $pdo->prepare(
                    'UPDATE characters SET hp = :hp, last_hp_update = NOW(), battle_monster_hp = :mhp WHERE character_id = :cid'
                )->execute(['hp' => $characterHp, 'mhp' => $monsterHp, 'cid' => $characterId]);
            }
        }

        $pdo->prepare(
            "INSERT INTO combat_logs (character_id, monster_id, action_type, item_used_id, damage_dealt, damage_taken, result, drops_summary)
             VALUES (:cid, :mid, 'attack', NULL, :dd, :dt, :res, :drops)"
        )->execute([
            'cid'   => $characterId,
            'mid'   => $monsterId,
            'dd'    => $damageDealt,
            'dt'    => $damageTaken,
            'res'   => $result,
            'drops' => $dropsSummary,
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['status' => 'error'];
    }

    return [
        'status'           => $result ?? 'ongoing',
        'monster_id'       => $monsterId,
        'monster_name'     => $monster['monster_name'],
        'damage_dealt'     => $damageDealt,
        'damage_taken'     => $damageTaken,
        'gold_won'         => $goldWon,
        'drops'            => $droppedNames,
        'auto_healed_with' => $autoHealedWith,
    ];
}

const OFFLINE_SECONDS_PER_TICK      = 2;
const OFFLINE_MAX_CREDITED_SECONDS  = 8 * 3600; // cap credited away-time at 8 hours
const OFFLINE_MAX_TICKS             = 2000;     // hard safety cap regardless of elapsed time
const OFFLINE_MIN_SECONDS_TO_BOTHER = 30;       // skip simulation for trivial gaps

function start_encounter(PDO $pdo, int $characterId, int $monsterId): bool
{
    $stmt = $pdo->prepare('SELECT hp FROM monsters WHERE monster_id = :id');
    $stmt->execute(['id' => $monsterId]);
    $monster = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT user_id FROM characters WHERE character_id = :cid');
    $stmt->execute(['cid' => $characterId]);
    $owner = $stmt->fetch();

    if (!$monster || !$owner) {
        return false;
    }

    $stats = get_character_stats($pdo, (int) $owner['user_id']);
    if (!$stats) {
        return false;
    }

    $pdo->prepare(
        'UPDATE characters
         SET battle_monster_id = :mid, battle_monster_hp = :mhp, hp = :hp, last_hp_update = NOW()
         WHERE character_id = :cid'
    )->execute([
        'mid' => $monsterId,
        'mhp' => (int) $monster['hp'],
        'hp'  => $stats['maxHp'],
        'cid' => $characterId,
    ]);

    return true;
}

function pick_resume_monster_id(PDO $pdo, array $character): ?int
{
    $stmt = $pdo->prepare(
        'SELECT monster_id FROM combat_logs WHERE character_id = :cid ORDER BY combat_id DESC LIMIT 1'
    );
    $stmt->execute(['cid' => $character['character_id']]);
    $last = $stmt->fetch();

    if ($last) {
        return (int) $last['monster_id'];
    }
    return !empty($character['current_monster_id']) ? (int) $character['current_monster_id'] : null;
}

function run_offline_simulation(PDO $pdo, int $characterId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM characters WHERE character_id = :cid');
    $stmt->execute(['cid' => $characterId]);
    $character = $stmt->fetch();

    if (!$character || !(bool) ($character['auto_battle'] ?? 0) || empty($character['last_hp_update'])) {
        return null;
    }

    $elapsedSeconds = min(OFFLINE_MAX_CREDITED_SECONDS, time() - strtotime($character['last_hp_update']));
    if ($elapsedSeconds < OFFLINE_MIN_SECONDS_TO_BOTHER) {
        return null;
    }

    $ticksToRun = min(OFFLINE_MAX_TICKS, (int) floor($elapsedSeconds / OFFLINE_SECONDS_PER_TICK));
    if ($ticksToRun <= 0) {
        return null;
    }

    if (empty($character['battle_monster_id'])) {
        $resumeId = pick_resume_monster_id($pdo, $character);
        if ($resumeId === null || !start_encounter($pdo, $characterId, $resumeId)) {
            return null;
        }
    }

    $ticksRun  = 0;
    $kills     = 0;
    $losses    = 0;
    $goldTotal = 0;
    $drops     = []; // item_name => count
    $potions   = 0;
    $lastMonsterName = null;

    for ($i = 0; $i < $ticksToRun; $i++) {
        $result = resolve_one_combat_tick($pdo, $characterId);

        if ($result['status'] === 'no_battle' || $result['status'] === 'error') {
            break;
        }

        $ticksRun++;
        $lastMonsterName = $result['monster_name'];

        if (!empty($result['auto_healed_with'])) {
            $potions++;
        }

        if ($result['status'] === 'win') {
            $kills++;
            $goldTotal += $result['gold_won'];
            foreach ($result['drops'] as $itemName) {
                $drops[$itemName] = ($drops[$itemName] ?? 0) + 1;
            }
            if (!start_encounter($pdo, $characterId, (int) $result['monster_id'])) {
                break;
            }
        } elseif ($result['status'] === 'loss') {
            $losses++;
            break;
        }
    }

    if ($ticksRun === 0) {
        return null;
    }

    $pdo->prepare('UPDATE characters SET last_hp_update = NOW() WHERE character_id = :cid')
        ->execute(['cid' => $characterId]);

    return [
        'elapsed_seconds' => $elapsedSeconds,
        'ticks_run'       => $ticksRun,
        'kills'           => $kills,
        'losses'          => $losses,
        'gold_won'        => $goldTotal,
        'drops'           => $drops,
        'potions_used'    => $potions,
        'monster_name'    => $lastMonsterName,
    ];
}

function apply_offline_progress(PDO $pdo, int $characterId): void
{
    $summary = run_offline_simulation($pdo, $characterId);
    if ($summary !== null) {
        $_SESSION['offline_summary'] = $summary;
    }
}