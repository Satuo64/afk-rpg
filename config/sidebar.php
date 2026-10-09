<?php
/**
 * config/sidebar.php
 *
 * Shared navigation sidebar. Every gameplay page sets $activePage
 * (e.g. 'home', 'battle', 'inventory') and includes this file, so the
 * menu lives in exactly one place. Admin/moderator accounts get an extra
 * panel link appended after Combat Log — they keep every normal player
 * menu item, since they can still play like anyone else.
 *
 *   $activePage = 'inventory';
 *   require __DIR__ . '/../config/sidebar.php';
 */

$activePage = $activePage ?? '';

$navItems = [
    'home'       => ['main-menu.php',   '🏠 Home'],
    'battle'     => ['battle-list.php', '⚔️ Battle'],
    'character'  => ['character.php',   '👤 Character'],
    'inventory'  => ['inventory.php',   '📦 Inventory'],
    'skills'     => ['skills.php',      '💡 Skills'],
    'crafting'   => ['crafting.php',    '⚒️ Crafting'],
    'combat-log' => ['combat-log.php',  '📜 Combat Log'],
    'leaderboard' => ['leaderboard.php', '🏆 Leaderboard'],
];

$sessionRole = $_SESSION['role'] ?? '';
if (in_array($sessionRole, ['admin', 'moderator'], true)) {
    $panelLabel = $sessionRole === 'admin' ? 'Admin' : 'Moderator';
    $navItems['admin'] = ['admin-panel.php', '🛠️ ' . $panelLabel . ' Panel'];
}
?>
<aside class="sidebar">
    <div class="logo-area">
        <div class="logo-icon">⚔️</div>
        <h2>AFK RPG</h2>
    </div>

    <nav class="nav-links">
        <?php foreach ($navItems as $key => [$href, $label]): ?>
            <a href="<?= $href ?>" class="nav-item<?= $activePage === $key ? ' active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="logout-area">
        <a href="logout.php" class="logout-btn" onclick="return confirm('Log out of AFK RPG?');">
            <span>↪</span> Logout
        </a>
    </div>
</aside>