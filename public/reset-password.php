<?php
/**
 * public/reset-password.php
 *
 * GET  ?token=... : validates the token (exists + not expired) and shows
 *                    the new-password form, or an error if it's bad/expired.
 * POST            : re-validates the same token, hashes the new password,
 *                    updates users, and deletes the token so it can't be
 *                    reused — all in one transaction.
 */

require __DIR__ . '/../config/db.php';

function find_valid_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT pr.reset_id, pr.user_id, pr.expires_at
         FROM password_resets pr
         WHERE pr.token = :token'
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null; // expired — treat the same as not found
    }
    return $row;
}

$errorMessage = null;
$successMessage = null;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $valid = find_valid_token($pdo, $token);

    if (!$valid) {
        $errorMessage = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif ($password === '' || strlen($password) < 8) {
        $errorMessage = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errorMessage = 'Passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :uid')
                ->execute(['hash' => $hash, 'uid' => $valid['user_id']]);

            // Single-use: delete it (and any other stale ones for this user)
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = :uid')
                ->execute(['uid' => $valid['user_id']]);

            $pdo->commit();
            $successMessage = 'Your password has been reset. You can now log in with your new password.';
            $token = null; // hide the form now that it's done
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errorMessage = 'Something went wrong resetting your password. Please try again.';
        }
    }
} else {
    // GET — just validate for display purposes
    if (!find_valid_token($pdo, $token)) {
        $errorMessage = 'This reset link is invalid or has expired. Please request a new one.';
        $token = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Reset Password</title>
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
                <h2>Reset Password</h2>

                <?php if ($errorMessage): ?>
                    <p class="footer-text" style="color:#f87171;"><?= htmlspecialchars($errorMessage) ?></p>
                    <p class="footer-text"><a href="forgot-password.html">Request a new reset link</a></p>

                <?php elseif ($successMessage): ?>
                    <p class="footer-text" style="color:#4ade80;"><?= htmlspecialchars($successMessage) ?></p>
                    <p class="footer-text"><a href="login.html">Go to Login</a></p>

                <?php else: ?>
                    <p class="subheading">Choose a new password.</p>
                    <form method="POST" action="reset-password.php">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" required minlength="8">
                        </div>

                        <div class="form-group">
                            <label for="confirm-password">Confirm New Password</label>
                            <input type="password" id="confirm-password" name="confirm_password" required minlength="8">
                        </div>

                        <button type="submit" class="action-btn">Reset Password</button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>
