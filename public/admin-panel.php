<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_role(['admin', 'moderator']);

$isAdmin  = ($_SESSION['role'] === 'admin');
$myUserId = (int) $_SESSION['user_id'];

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        'SELECT user_id, username, email, role, is_suspended, created_at, last_login
         FROM users
         WHERE username LIKE :s OR email LIKE :s
         ORDER BY username'
    );
    $stmt->execute(['s' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query(
        'SELECT user_id, username, email, role, is_suspended, created_at, last_login
         FROM users ORDER BY username'
    );
}
$users = $stmt->fetchAll();

// Recent audit trail — proves the triggers are genuinely firing, not just
// UI-side reporting.
$stmt = $pdo->query(
    "SELECT al.action_type, al.details, al.created_at,
            actor.username AS actor_name, target.username AS target_name
     FROM audit_log al
     LEFT JOIN users actor  ON actor.user_id  = al.actor_user_id
     LEFT JOIN users target ON target.user_id = al.target_user_id
     ORDER BY al.audit_id DESC
     LIMIT 15"
);
$auditRows = $stmt->fetchAll();

$errorMessages = [
    'self_action'  => "You can't perform that action on your own account.",
    'not_found'    => 'User not found.',
    'invalid_role' => 'Invalid role selected.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - <?= $isAdmin ? 'Admin' : 'Moderator' ?> Panel</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Two equal columns: View | Suspend on the first row, role select | Set Role
           on the second — so Suspend/Reactivate is exactly as wide as Set Role. */
        .action-grid { display: grid; grid-template-columns: 125px 125px; gap: 8px; }
        .action-grid form { display: contents; }
        .action-grid .select-btn,
        .action-grid select {
            height: 38px;
            padding: 0 10px;
            font-size: 0.85rem;
            width: 100%;
        }
        .action-grid a.select-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid #334155;
            color: #cbd5e1;
        }
        .action-grid select {
            background-color: #0d1b2e;
            border: 1px solid #22334d;
            border-radius: 6px;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">

<?php $activePage = 'admin'; require __DIR__ . '/../config/sidebar.php'; ?>

        <main class="main-content">
            <div class="content-header">
                <h1><?= $isAdmin ? 'Admin' : 'Moderator' ?> Panel</h1>
                <p>Signed in as <?= htmlspecialchars($_SESSION['username']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)</p>
            </div>

            <?php if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
                <p style="color:#f87171; margin-bottom:20px;"><?= htmlspecialchars($errorMessages[$_GET['error']]) ?></p>
            <?php elseif (isset($_GET['ok'])): ?>
                <p style="color:#4ade80; margin-bottom:20px;">Action completed successfully.</p>
            <?php endif; ?>

            <!-- Search -->
            <form method="GET" action="admin-panel.php" style="margin-bottom:25px; display:flex; gap:12px; max-width:500px;">
                <input type="text" name="search" placeholder="Search by username or email..."
                       value="<?= htmlspecialchars($search) ?>"
                       style="flex:1; padding:12px 16px; background-color:#0d1b2e; border:1px solid #22334d; border-radius:8px; color:#fff;">
                <button type="submit" class="action-btn" style="width:auto; padding:12px 24px; margin-top:0;">Search</button>
            </form>

            <!-- Results -->
            <div class="monster-list-container">
                <table class="monster-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php
                                $isSelf  = ((int) $u['user_id'] === $myUserId);
                                $viewUrl = 'player-overview.php?id=' . (int) $u['user_id']
                                         . ($search !== '' ? '&search=' . urlencode($search) : '');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($u['username']) ?><?= $isSelf ? ' (you)' : '' ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($u['role'])) ?></td>
                                <td><?= $u['is_suspended'] ? '<span style="color:#f87171;">Suspended</span>' : '<span style="color:#4ade80;">Active</span>' ?></td>
                                <td><?= $u['last_login'] ? htmlspecialchars($u['last_login']) : 'Never' ?></td>
                                <td>
                                    <div class="action-grid">
                                        <a href="<?= htmlspecialchars($viewUrl) ?>" class="select-btn btn-outline">View</a>

                                        <?php if (!$isSelf): ?>
                                            <!-- Suspend/Reactivate: both moderator and admin -->
                                            <form method="POST" action="suspend-user.php">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                                <input type="hidden" name="action" value="<?= $u['is_suspended'] ? 'reactivate' : 'suspend' ?>">
                                                <button type="submit" class="select-btn <?= $u['is_suspended'] ? 'btn-green' : 'btn-blue' ?>">
                                                    <?= $u['is_suspended'] ? 'Reactivate' : 'Suspend' ?>
                                                </button>
                                            </form>

                                            <!-- Role change: admin only -->
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" action="change-role.php">
                                                    <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                                    <select name="new_role">
                                                        <?php foreach (['player', 'moderator', 'admin'] as $roleOpt): ?>
                                                            <option value="<?= $roleOpt ?>" <?= $u['role'] === $roleOpt ? 'selected' : '' ?>><?= ucfirst($roleOpt) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="select-btn btn-purple">Set Role</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Real audit trail -->
            <div class="content-header" style="margin-top:35px;">
                <h1 style="font-size:1.8rem;">Recent Audit Log</h1>
                <p>Written automatically by database triggers whenever role or suspension status changes.</p>
            </div>
            <div class="combat-log-container">
                <div class="combat-log-terminal" style="height:260px;">
                    <?php if (empty($auditRows)): ?>
                        <div class="log-entry system">[SYSTEM] No moderation actions recorded yet.</div>
                    <?php endif; ?>
                    <?php foreach ($auditRows as $row): ?>
                        <div class="log-entry system">
                            [<?= htmlspecialchars($row['created_at']) ?>]
                            <?= htmlspecialchars($row['actor_name'] ?? 'unknown') ?>
                            performed <strong><?= htmlspecialchars($row['action_type']) ?></strong>
                            on <?= htmlspecialchars($row['target_name'] ?? 'unknown') ?>
                            <?= $row['details'] ? '(' . htmlspecialchars($row['details']) . ')' : '' ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>

    </div>
</body>
</html>