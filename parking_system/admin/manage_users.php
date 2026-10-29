<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['account_status'])) {
    require_valid_csrf_post();
    $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
    $account_status = $_POST['account_status'];

    if ($user_id && in_array($account_status, ['approved', 'blocked', 'rejected'], true)) {
        $stmt = $pdo->prepare("UPDATE users SET account_status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND role = 'user'");
        $stmt->execute([$account_status, $_SESSION['user_id'], $user_id]);
        $message = $stmt->rowCount() ? 'Account status updated.' : 'Account status unchanged or account not found.';
    } else {
        $message = 'Invalid account status request.';
    }
}

$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM bookings WHERE user_id = u.id) AS booking_count
                       FROM users u WHERE role = 'user' ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Users";
include '../includes/header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-users"></i> Manage Users & Staff</h3>
<?php if ($message): ?><div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<table class="table table-bordered bg-white">
<thead class="table-dark"><tr><th>ID</th><th>Role</th><th>Name</th><th>Email</th><th>Phone</th><th>Bookings</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
<tbody>
<?php if (empty($users)): ?>
    <tr><td colspan="9" class="text-center text-muted">No registered accounts yet.</td></tr>
<?php endif; ?>
<?php foreach ($users as $u): ?>
<tr>
    <td><?php echo (int) $u['id']; ?></td>
    <td><span class="badge text-bg-light text-dark"><?php echo htmlspecialchars(strtoupper($u['role'])); ?></span></td>
    <td><?php echo htmlspecialchars($u['name']); ?></td>
    <td><?php echo htmlspecialchars($u['email']); ?></td>
    <td><?php echo htmlspecialchars($u['phone']); ?></td>
    <td><?php echo (int) $u['booking_count']; ?></td>
    <td>
        <?php $status = $u['account_status']; ?>
        <span class="badge text-bg-<?php echo $status === 'approved' ? 'success' : ($status === 'pending' ? 'warning text-dark' : ($status === 'rejected' ? 'danger' : 'secondary')); ?>"><?php echo htmlspecialchars(strtoupper($status)); ?></span>
    </td>
    <td><?php echo htmlspecialchars($u['created_at']); ?></td>
    <td>
        <?php if ($u['account_status'] !== 'approved'): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                <input type="hidden" name="account_status" value="approved">
                <button type="submit" class="btn btn-sm btn-success">Approve</button>
            </form>
        <?php else: ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                <input type="hidden" name="account_status" value="blocked">
                <button type="submit" class="btn btn-sm btn-outline-danger">Block</button>
            </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php include '../includes/footer.php'; ?>
