<?php
/**
 * config/auth_guard.php
 *
 * Include this at the very top of every page that shouldn't be reachable
 * by direct URL navigation alone. Always require db.php first (it starts
 * the session), then call exactly the guard function(s) that page needs:
 *
 *   require __DIR__ . '/../../config/db.php';
 *   require __DIR__ . '/../../config/auth_guard.php';
 *   require_login();
 *   require_character();   // only on pages that need an existing character
 *
 * This is what actually stops someone from typing main-menu.html straight
 * into the address bar and skipping login/class-selection — nothing about
 * login.php's redirect logic can prevent that on its own, because it only
 * runs once, at the moment of logging in.
 */

/**
 * Must be logged in at all. Kicks anonymous visitors back to login.html.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        // Relative, not absolute — works no matter what your project folder
        // is actually named in htdocs. Every guarded page lives directly in
        // public/, same as login.html, so a bare filename resolves correctly.
        header('Location: login.html');
        exit;
    }

    // Re-check the account against the database on every request, not just
    // at login — otherwise a suspension or role change wouldn't take effect
    // until the person happened to log out and back in.
    global $pdo;
    $stmt = $pdo->prepare('SELECT role, is_suspended FROM users WHERE user_id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $account = $stmt->fetch();

    if (!$account || (bool) $account['is_suspended']) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.html?error=account_suspended');
        exit;
    }

    $_SESSION['role'] = $account['role']; // keeps the sidebar + require_role() current
}

/**
 * Must be logged in AND already have a character. Use this on every
 * gameplay page (main-menu, battle-list, battle-arena, character, skills,
 * inventory, crafting, combat-log). Sends a class-less player back to
 * class-selection.html instead of letting them see gameplay pages that
 * assume a character row exists.
 */
function require_character(): void
{
    require_login();

    global $pdo;
    $stmt = $pdo->prepare('SELECT character_id FROM characters WHERE user_id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row) {
        header('Location: class-selection.php'); // .php, not .html — it was renamed
        exit;
    }

    // If Auto-Fight is on and there's a real gap since the character was
    // last accounted for, credit it as simulated combat now — before the
    // page renders, so every number on screen already includes it. Cheap
    // no-op (one SELECT) whenever auto_battle is off or the gap is tiny.
    require_once __DIR__ . '/combat_engine.php';
    apply_offline_progress($pdo, (int) $row['character_id']);
}

/**
 * Must be logged in AND already have a character AND NOT already pick one.
 * Use this on class-selection.php itself — if the player already has a
 * character, there's nothing to select, so bounce them to main-menu.html
 * instead of letting them create a second character.
 */
function require_no_character_yet(): void
{
    require_login();

    global $pdo;
    $stmt = $pdo->prepare('SELECT character_id FROM characters WHERE user_id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);

    if ($stmt->fetch()) {
        header('Location: main-menu.php');
        exit;
    }
}

/**
 * Restrict to specific roles, e.g. require_role(['admin', 'moderator']).
 * Use on future admin/moderator-only pages.
 */
function require_role(array $allowedRoles): void
{
    require_login();

    if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
        header('Location: main-menu.php');
        exit;
    }
}