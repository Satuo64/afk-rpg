<?php
/**
 * public/logout.php
 *
 * Your "Logout" links currently just point at login.html, which never
 * touches the session — so $_SESSION['user_id'] stays set even after
 * "logging out". This is the fix: destroy the session for real, then
 * send them to login.html.
 */

require __DIR__ . '/../config/db.php'; // starts the session so we can destroy it

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

header('Location: login.html');
exit;
