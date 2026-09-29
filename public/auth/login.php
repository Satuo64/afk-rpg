<?php
/**
 * public/auth/login.php
 *
 * THIS is the login script — it authenticates against an existing user,
 * it does NOT create one. If you're reading this comment inside a file
 * that also has an INSERT INTO users statement, you have the wrong file
 * saved here; that's signup.php's logic, not login.php's.
 */

require __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: ../login.html?error=missing_fields');
    exit;
}

// ---- look up the account ---------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

// Same generic error whether the username doesn't exist or the password is
// wrong — never reveal which one it was, that's a login-enumeration leak.
if (!$user || !password_verify($password, $user['password_hash'])) {
    header('Location: ../login.html?error=invalid_login');
    exit;
}

// ---- suspension check ---------------------------------------------------
if ((bool) $user['is_suspended']) {
    header('Location: ../login.html?error=account_suspended');
    exit;
}

// ---- success: establish the session --------------------------------------
$_SESSION['user_id']  = (int) $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role']     = $user['role'];

$pdo->prepare('UPDATE users SET last_login = NOW() WHERE user_id = :id')
    ->execute(['id' => $user['user_id']]);

// ---- route: everyone (player, moderator, admin) plays the game the same way.
// Admins/moderators reach the panel through the extra sidebar link instead
// of being diverted away from the game at login.
// ---- does a character already exist? -------------------------
$stmt = $pdo->prepare('SELECT character_id FROM characters WHERE user_id = :id');
$stmt->execute(['id' => $user['user_id']]);

if ($stmt->fetch()) {
    header('Location: ../main-menu.php');       // returning player -> connector "A"
} else {
    header('Location: ../class-selection.php'); // first login, no character yet
}
exit;