<?php
/**
 * public/auth/signup.php
 *
 * THIS is the signup script — it creates a new user account. It does NOT
 * authenticate an existing one. If you're reading this comment inside a
 * file also containing a SELECT ... password_verify() flow, you have the
 * wrong file saved here; that's login.php's logic, not signup.php's.
 */

require __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../signup.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

// ---- validation -----------------------------------------------------------
if ($username === '' || $email === '' || $password === '') {
    header('Location: ../signup.html?error=missing_fields');
    exit;
}
if ($password !== $confirm) {
    header('Location: ../signup.html?error=password_mismatch');
    exit;
}
if (strlen($password) < 8) {
    header('Location: ../signup.html?error=password_too_short');
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ../signup.html?error=invalid_email');
    exit;
}

// ---- uniqueness check (this IS your flowchart's "Username already taken?") -
$stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = :username OR email = :email');
$stmt->execute(['username' => $username, 'email' => $email]);

if ($stmt->fetch()) {
    header('Location: ../signup.html?error=username_taken');
    exit;
}

// ---- hash + create -----------------------------------------------------
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash, email, role, is_suspended)
     VALUES (:username, :password_hash, :email, \'player\', 0)'
);
$stmt->execute([
    'username'      => $username,
    'password_hash' => $hash,
    'email'         => $email,
]);

$userId = (int) $pdo->lastInsertId();

// ---- log the new user in immediately (no need to make them re-login) ------
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = $username;
$_SESSION['role']     = 'player';

// Brand-new account has no character row yet -> send to class selection
header('Location: ../class-selection.php');
exit;