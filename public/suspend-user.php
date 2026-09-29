<?php
/**
 * public/suspend-user.php
 *
 * Both moderator and admin can suspend/reactivate accounts. Delegates to
 * sp_set_user_suspension(), which sets @audit_actor_id before the UPDATE
 * so trg_users_audit_suspension_change can attribute the action correctly.
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_role(['admin', 'moderator']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-panel.php');
    exit;
}

$actorId  = (int) $_SESSION['user_id'];
$targetId = (int) ($_POST['user_id'] ?? 0);
$suspend  = ($_POST['action'] ?? '') === 'suspend' ? 1 : 0;

// Can't suspend/reactivate your own account through this tool
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

$stmt = $pdo->prepare('CALL sp_set_user_suspension(:actor, :target, :suspend)');
$stmt->execute(['actor' => $actorId, 'target' => $targetId, 'suspend' => $suspend]);

header('Location: admin-panel.php?ok=1');
exit;
