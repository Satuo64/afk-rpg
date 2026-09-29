<?php
/**
 * public/change-role.php
 *
 * Admin-only. Delegates to sp_change_user_role(), which sets
 * @audit_actor_id before the UPDATE so trg_users_audit_role_change can
 * attribute the change correctly.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-panel.php');
    exit;
}

$actorId  = (int) $_SESSION['user_id'];
$targetId = (int) ($_POST['user_id'] ?? 0);
$newRole  = $_POST['new_role'] ?? '';

$allowedRoles = ['player', 'moderator', 'admin'];
if (!in_array($newRole, $allowedRoles, true)) {
    header('Location: admin-panel.php?error=invalid_role');
    exit;
}

// Can't change your own role through this tool (avoid accidentally
// demoting/locking yourself out)
if ($targetId === $actorId) {
    header('Location: admin-panel.php?error=self_action');
    exit;
}

$stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = :id');
$stmt->execute(['id' => $targetId]);
if (!$stmt->fetch()) {
    header('Location: admin-panel.php?error=not_found');
    exit;
}

$stmt = $pdo->prepare('CALL sp_change_user_role(:actor, :target, :role)');
$stmt->execute(['actor' => $actorId, 'target' => $targetId, 'role' => $newRole]);

header('Location: admin-panel.php?ok=1');
exit;
