<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/character_stats.php';
require_character();

$userId = (int) $_SESSION['user_id'];

$stats       = get_character_stats($pdo, $userId);
$character   = $stats['character'];
$characterId = (int) $character['character_id'];

$stmt = $pdo->prepare(
    'SELECT ci.item_id, ci.quantity, ci.equipped,
            i.item_name, i.item_type, i.rarity, i.weapon_type, i.description,
            i.attack_bonus, i.defense_bonus, i.heal_amount
     FROM character_inventory ci
     JOIN items i ON i.item_id = ci.item_id
     WHERE ci.character_id = :cid
     ORDER BY ci.equipped DESC, i.item_type, i.item_name'
);
$stmt->execute(['cid' => $characterId]);
$inventoryRows = $stmt->fetchAll();

$equippedWeapon = null;
$equippedArmor  = null;
$equippedHelmet = null;
$bagItems       = [];

foreach ($inventoryRows as $row) {
    if ($row['equipped'] && $row['item_type'] === 'weapon' && !$equippedWeapon) {
        $equippedWeapon = $row;
    } elseif ($row['equipped'] && $row['item_type'] === 'armor' && !$equippedArmor) {
        $equippedArmor = $row;
    } elseif ($row['equipped'] && $row['item_type'] === 'helmet' && !$equippedHelmet) {
        $equippedHelmet = $row;
    } else {
        $bagItems[] = $row;
    }
}

$typeIcon = [
    'weapon'     => '⚔️',
    'armor'      => '🛡️',
    'helmet'     => '🪖',
    'material'   => '🌿',
    'consumable' => '🧪',
];

function rarity_class(?string $rarity): string
{
    return match ($rarity) {
        'Rare' => 'item-rare',
        'Epic' => 'item-epic',
        'Legendary' => 'item-legendary',
        'Mythic' => 'item-mythic',
        default => '',
    };
}

// Renders one slot: the visible clickable tile, plus its hidden info panel
// (name, description, stat line, and — for equippable types — the real
// Equip/Unequip form). Panels are pre-rendered by PHP and just toggled by
// JS, so there's no client-side string-building or escaping to worry about.
function render_slot(?array $item, string $slotType, string $emptyLabel, array $typeIcon, string $cssClass): void
{
    static $counter = 0;
    $counter++;
    $tipId = "tip-{$counter}";

    if (!$item) {
        echo '<div class="' . $cssClass . '" title="' . htmlspecialchars($emptyLabel) . '">'
           . ($typeIcon[$slotType] ?? '❔') . '</div>';
        return;
    }

    $icon = $typeIcon[$item['item_type']] ?? '❔';
    $canEquip = in_array($item['item_type'], ['weapon', 'armor', 'helmet'], true);
    $canUse   = $item['item_type'] === 'consumable' && (int) $item['heal_amount'] > 0;
    $qtySuffix = $item['quantity'] > 1
        ? '<span style="font-size:0.6rem; margin-left:2px; align-self:flex-end;">×' . (int) $item['quantity'] . '</span>'
        : '';

    echo '<button type="button" class="' . $cssClass . ' ' . rarity_class($item['rarity']) . '" data-tooltip-id="' . $tipId . '">'
       . $icon . $qtySuffix . '</button>';

    echo '<div class="item-tooltip" id="' . $tipId . '">';
    echo '<h4>' . htmlspecialchars($item['item_name']) . '</h4>';
    echo '<p class="item-tooltip-meta">' . htmlspecialchars(ucfirst($item['item_type'])) . ' · ' . htmlspecialchars($item['rarity'] ?? 'Common') . '</p>';
    if (!empty($item['description'])) {
        echo '<p>' . htmlspecialchars($item['description']) . '</p>';
    }
    if ((int) $item['attack_bonus'] > 0) {
        echo '<p class="item-tooltip-stat">+' . (int) $item['attack_bonus'] . ' Attack</p>';
    }
    if ((int) $item['defense_bonus'] > 0) {
        echo '<p class="item-tooltip-stat">+' . (int) $item['defense_bonus'] . ' Defense</p>';
    }
    if ((int) $item['heal_amount'] > 0) {
        echo '<p class="item-tooltip-stat">Heals ' . (int) $item['heal_amount'] . ' HP</p>';
    }
    if ($item['quantity'] > 1) {
        echo '<p class="item-tooltip-meta">Quantity: ' . (int) $item['quantity'] . '</p>';
    }
    if ($canEquip) {
        echo '<form method="POST" action="equip-item.php">';
        echo '<input type="hidden" name="item_id" value="' . (int) $item['item_id'] . '">';
        echo '<button type="submit" class="select-btn ' . ($item['equipped'] ? 'btn-outline' : 'btn-blue') . '">'
           . ($item['equipped'] ? 'Unequip' : 'Equip') . '</button>';
        echo '</form>';
    }
    if ($canUse) {
        echo '<form method="POST" action="use-item.php">';
        echo '<input type="hidden" name="item_id" value="' . (int) $item['item_id'] . '">';
        echo '<button type="submit" class="select-btn btn-green">Use</button>';
        echo '</form>';
    }
    echo '</div>';
}

const BAG_SLOTS = 40;
$emptySlots = max(0, BAG_SLOTS - count($bagItems));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Inventory</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        button.bag-slot, button.equip-slot {
            font-family: inherit;
            padding: 0;
            margin: 0;
        }

        .item-tooltip {
            position: absolute;
            display: none;
            z-index: 1000;
            width: 230px;
            background-color: #0d1b2e;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 16px;
            text-align: left;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.45);
        }
        .item-tooltip.open { display: block; }
        .item-tooltip h4 { color: #ffffff; font-size: 1.05rem; margin-bottom: 4px; }
        .item-tooltip p { color: #94a3b8; font-size: 0.85rem; line-height: 1.4; margin-bottom: 8px; }
        .item-tooltip-meta { color: #64748b; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .item-tooltip-stat { color: #38bdf8; font-weight: 600; }
        .item-tooltip form { margin-top: 8px; }
        .item-tooltip .select-btn { padding: 8px; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'inventory'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="inventory-header">
                <div class="header-titles">
                    <h1>Inventory</h1>
                    <p>HP: <?= $stats['currentHp'] ?>/<?= $stats['maxHp'] ?> — click a potion and hit Use to heal</p>
                </div>
                <div class="gold-badge">
                    <span>Gold : <?= number_format((int) $character['gold']) ?></span>
                </div>
            </div>

            <?php if (isset($_GET['healed'])): ?>
                <p style="color:#4ade80; margin-bottom:20px;">
                    Used <?= htmlspecialchars($_GET['item'] ?? 'potion') ?> — restored <?= (int) $_GET['healed'] ?> HP.
                </p>
            <?php elseif (isset($_GET['error']) && in_array($_GET['error'], ['not_usable', 'use_failed'], true)): ?>
                <p style="color:#f87171; margin-bottom:20px;">That item couldn't be used.</p>
            <?php endif; ?>

            <div class="equipment-row">
                <?php render_slot($equippedWeapon, 'weapon', 'Weapon Slot (empty)', $typeIcon, 'equip-slot'); ?>
                <?php render_slot($equippedArmor, 'armor', 'Armor Slot (empty)', $typeIcon, 'equip-slot'); ?>
                <?php render_slot($equippedHelmet, 'helmet', 'Helmet Slot (empty)', $typeIcon, 'equip-slot'); ?>
            </div>

            <div class="inventory-bag-grid">
                <?php foreach ($bagItems as $item): ?>
                    <?php render_slot($item, $item['item_type'], '', $typeIcon, 'bag-slot'); ?>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $emptySlots; $i++): ?>
                    <div class="bag-slot"></div>
                <?php endfor; ?>
            </div>
        </main>

    </div>

    <script>
        let openTooltip = null;
        let pinned = false;

        function positionTooltip(tip, trigger) {
            const rect = trigger.getBoundingClientRect();
            tip.style.left = (rect.left + window.scrollX) + 'px';
            tip.style.top = (rect.bottom + window.scrollY + 8) + 'px';
        }

        function openFor(trigger) {
            const tip = document.getElementById(trigger.dataset.tooltipId);
            if (!tip) return;
            closeCurrent();
            positionTooltip(tip, trigger);
            tip.classList.add('open');
            openTooltip = tip;
        }

        function closeCurrent() {
            if (openTooltip) openTooltip.classList.remove('open');
            openTooltip = null;
            pinned = false;
        }

        document.querySelectorAll('[data-tooltip-id]').forEach(trigger => {
            trigger.addEventListener('mouseenter', () => {
                if (!pinned) openFor(trigger);
            });
            trigger.addEventListener('mouseleave', () => {
                if (!pinned) closeCurrent();
            });
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                const tip = document.getElementById(trigger.dataset.tooltipId);
                if (openTooltip === tip && pinned) {
                    closeCurrent();
                } else {
                    openFor(trigger);
                    pinned = true;
                }
            });
        });

        // Click anywhere outside the open panel (and outside any trigger) closes it
        document.addEventListener('click', (e) => {
            if (openTooltip && !openTooltip.contains(e.target) && !e.target.closest('[data-tooltip-id]')) {
                closeCurrent();
            }
        });
    </script>
</body>
</html>