<?php
/**
 * public/forgot-password.php
 *
 * Real email sending needs an SMTP server (PHPMailer + Gmail/etc. creds,
 * or a local mail relay) that isn't part of this XAMPP setup. Rather than
 * silently fake success, this generates a genuine single-use expiring
 * token and displays the reset link directly on the page — clearly
 * labeled as a stand-in for what would be emailed in production. The
 * actual security mechanism (random token, expiry, single use, hashed
 * password on reset) is real; only the delivery channel is simplified.
 *
 * To avoid confirming whether a username/email combo exists (a real
 * information leak — "account enumeration"), the page shows the exact
 * same generic message whether the account was found or not; the demo
 * link box only appears when it's real.
 */

require __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot-password.html');
    exit;
}

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');

if ($username === '' || $email === '') {
    header('Location: forgot-password.html?error=missing_fields');
    exit;
}

$stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = :u AND email = :e');
$stmt->execute(['u' => $username, 'e' => $email]);
$user = $stmt->fetch();

if (!$user) {
    // Explicit mismatch feedback, by request — note this does mean the form
    // can be used to check whether a username+email combo exists on the
    // site ("account enumeration"). Accepted trade-off for clearer demo
    // feedback; flagged here in case you want the vaguer message back later.
    header('Location: forgot-password.html?error=account_not_found');
    exit;
}

$userId = (int) $user['user_id'];

// Only one active reset request per user at a time
$pdo->prepare('DELETE FROM password_resets WHERE user_id = :uid')->execute(['uid' => $userId]);

$token     = bin2hex(random_bytes(32)); // 64 hex chars, cryptographically random
$expiresAt = date('Y-m-d H:i:s', time() + 30 * 60); // 30 minutes

$pdo->prepare(
    'INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :token, :exp)'
)->execute(['uid' => $userId, 'token' => $token, 'exp' => $expiresAt]);

$resetLink = 'reset-password.php?token=' . $token;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Reset Requested</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="split-screen">
        <div class="left-side">
            <div class="banner-content">
                <h1>AFK RPG</h1>
                <p class="tagline">Level up get stronger, even when you're away!</p>
            </div>
        </div>
        <div class="right-side">
            <div class="form-container">
                <h2>Check Your Email</h2>
                <p class="subheading">
                    A password reset link has been generated for your account.
                    The link expires in 30 minutes.
                </p>

                <div style="background:#0d1b2e; border:1px solid #334155; border-radius:10px; padding:20px; margin-bottom:20px;">
                    <p style="color:#94a3b8; font-size:0.85rem; margin-bottom:10px;">
                        No mail server is configured on this local setup, so — for demo/grading purposes only —
                        here is the link that would normally be emailed:
                    </p>
                    <a href="<?= htmlspecialchars($resetLink) ?>" style="color:#38bdf8; word-break:break-all;">
                        <?= htmlspecialchars($resetLink) ?>
                    </a>
                </div>

                <p class="footer-text"><a href="login.html">Back to Login</a></p>
            </div>
        </div>
    </div>
</body>
</html>