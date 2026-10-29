<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$user_id = (int) $_SESSION['user_id'];
$message = $_SESSION['notification_flash'] ?? '';
unset($_SESSION['notification_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_post();
    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$user_id]);
        $_SESSION['notification_flash'] = 'All notifications marked as read.';
    } elseif (isset($_POST['notification_id'])) {
        $notification_id = filter_var($_POST['notification_id'], FILTER_VALIDATE_INT);
        if ($notification_id) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            $stmt->execute([$notification_id, $user_id]);
        }
        $_SESSION['notification_flash'] = 'Notification marked as read.';
    }
    header('Location: notifications.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, booking_id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC');
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Notifications';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h2 class="h3 mb-1">Notifications</h2><p class="text-muted mb-0">Booking and payment updates for your account.</p></div>
    <?php if ($notifications): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" name="mark_all_read" class="btn btn-outline-primary"><i class="fa-solid fa-check-double me-1"></i> Mark all read</button>
        </form>
    <?php endif; ?>
</div>
<?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if (!$notifications): ?>
    <div class="card p-5 text-center"><i class="fa-regular fa-bell-slash text-muted mb-3" style="font-size:42px;"></i><h5>You're all caught up</h5></div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($notifications as $notification): ?>
            <div class="list-group-item py-3 <?php echo $notification['is_read'] ? '' : 'list-group-item-primary'; ?>">
                <div class="d-flex justify-content-between gap-3">
                    <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?><?php if (!$notification['is_read']): ?> <span class="badge text-bg-primary">New</span><?php endif; ?></div>
                        <p class="mb-1"><?php echo htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <small class="text-muted"><?php echo htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php if ($notification['booking_id']): ?>
                            <a class="d-block small mt-1" href="booking_confirmation.php?id=<?php echo (int) $notification['booking_id']; ?>">View booking</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!$notification['is_read']): ?>
                        <form method="POST" class="align-self-center">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Mark read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>