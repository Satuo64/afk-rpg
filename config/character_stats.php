<?php
/**
 * config/character_stats.php
 *
 * Centralizes the same derived-stat formulas character.php and skills.php
 * already use (Level = sum of skill levels, Attack = active skill's stat,
 * Defense = base + armor, etc.) so every page that needs a character's
 * combat stats computes them the same way, in one place. If you tune the
 * balance constants, tune them here — not in every page separately.
 */

if (!defined('STAT_BASE')) {
    define('STAT_BASE', 10);             // a skill's underlying stat at level 1
    define('STAT_PER_LEVEL', 2);         // + per level of that skill
    define('DEFENSE_BASE', 3);           // base defense at minimum total level — lowered so early monsters' attack isn't fully absorbed
    define('DEFENSE_PER_LEVEL', 2);      // + per total-level point above the minimum
    define('HP_BASE', 100);              // max HP at minimum total level
    define('HP_PER_LEVEL', 20);          // + max HP per total-level point above the minimum
    define('CRIT_BASE', 0.01);           // 1% base crit chance
    define('CRIT_PER_RANGED_LEVEL', 0.002); // + per Ranged skill level above 1
    define('XP_PER_LEVEL', 100);         // placeholder leveling curve
}

/**
 * Returns the logged-in user's character plus every derived combat stat,
 * or null if (somehow) they have no character row — callers that already
 * ran require_character() shouldn't ever actually see null here.
 */
function get_character_stats(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM characters WHERE user_id = :uid');
    $stmt->execute(['uid' => $userId]);
    $character = $stmt->fetch();
    if (!$character) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT s.skill_id, s.skill_name, s.primary_stat, s.grants_crit, s.damage_multiplier, s.weapon_type,
                cs.skill_level, cs.current_xp
         FROM character_skills cs
         JOIN skills s ON s.skill_id = cs.skill_id
         WHERE cs.character_id = :cid
         ORDER BY s.skill_id'
    );
    $stmt->execute(['cid' => $character['character_id']]);
    $skillRows = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(i.defense_bonus), 0) AS armor_bonus
         FROM character_inventory ci
         JOIN items i ON i.item_id = ci.item_id
         WHERE ci.character_id = :cid AND ci.equipped = 1 AND i.item_type IN ('armor', 'helmet')"
    );
    $stmt->execute(['cid' => $character['character_id']]);
    $armorBonus = (int) $stmt->fetch()['armor_bonus'];

    $minTotalLevel  = count($skillRows) ?: 1;
    $totalLevel     = array_sum(array_column($skillRows, 'skill_level'));
    $levelsAboveMin = max(0, $totalLevel - $minTotalLevel);

    $activeSkill = null;
    $rangedLevel = 1;
    foreach ($skillRows as $row) {
        if ((int) $row['skill_id'] === (int) $character['active_skill_id']) {
            $activeSkill = $row;
        }
        if ($row['skill_name'] === 'Ranged') {
            $rangedLevel = (int) $row['skill_level'];
        }
    }

    // Equipped weapon's bonus only counts if its weapon_type matches the
    // active skill (Melee -> sword, Ranged -> bow, Magic -> wand) — per
    // the original design, training Magic with a sword equipped gets no
    // weapon bonus, only the Magic skill stat.
    $stmt = $pdo->prepare(
        "SELECT i.item_name, i.weapon_type, i.attack_bonus
         FROM character_inventory ci
         JOIN items i ON i.item_id = ci.item_id
         WHERE ci.character_id = :cid AND ci.equipped = 1 AND i.item_type = 'weapon'
         LIMIT 1"
    );
    $stmt->execute(['cid' => $character['character_id']]);
    $equippedWeapon = $stmt->fetch() ?: null;

    $weaponBonus = 0;
    if ($equippedWeapon && $activeSkill && $equippedWeapon['weapon_type'] === $activeSkill['weapon_type']) {
        $weaponBonus = (int) $equippedWeapon['attack_bonus'];
    }

    $baseAttack = $activeSkill
        ? STAT_BASE + (((int) $activeSkill['skill_level']) - 1) * STAT_PER_LEVEL
        : STAT_BASE;
    $attackValue = $baseAttack + $weaponBonus;
    $activeStatLabel = $activeSkill
        ? ucfirst(str_replace('_', ' ', $activeSkill['primary_stat']))
        : 'Attack';

    $baseDefense  = DEFENSE_BASE + $levelsAboveMin * DEFENSE_PER_LEVEL;
    $defenseValue = $baseDefense + $armorBonus;

    $maxHp = HP_BASE + $levelsAboveMin * HP_PER_LEVEL;
    // Real, persisted, live HP now — clamped so a stale stored value can
    // never display higher than the character's current derived max.
    $currentHp = max(0, min((int) $character['hp'], $maxHp));

    $critChance = CRIT_BASE + max(0, $rangedLevel - 1) * CRIT_PER_RANGED_LEVEL;

    return [
        'character'       => $character,
        'skillRows'       => $skillRows,
        'activeSkill'      => $activeSkill, // full row, incl. grants_crit + damage_multiplier — used by combat resolution
        'totalLevel'      => $totalLevel,
        'baseAttack'      => $baseAttack,
        'weaponBonus'     => $weaponBonus,
        'equippedWeapon'  => $equippedWeapon,
        'attackValue'     => $attackValue,
        'activeStatLabel' => $activeStatLabel,
        'baseDefense'     => $baseDefense,
        'armorBonus'      => $armorBonus,
        'defenseValue'    => $defenseValue,
        'maxHp'           => $maxHp,
        'currentHp'       => $currentHp,
        'critChance'      => $critChance,
    ];
}