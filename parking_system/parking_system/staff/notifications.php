<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();
$staff_location_id = (int) $_SESSION['staff_location_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_post();
    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND location_id = ? AND is_read = 0');
        $stmt->execute([$_SESSION['user_id'], $staff_location_id]);
    } elseif (isset($_POST['notification_id'])) {
        $notification_id = filter_var($_POST['notification_id'], FILTER_VALIDATE_INT);
        if ($notification_id) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND location_id = ?');
            $stmt->execute([$notification_id, $_SESSION['user_id'], $staff_location_id]);
        }
    }
    header('Location: notifications.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, booking_id, title, message, is_read, created_at FROM notifications WHERE user_id = ? AND location_id = ? ORDER BY created_at DESC, id DESC');
$stmt->execute([$_SESSION['user_id'], $staff_location_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Staff Notifications';
include '../includes/header.php';
include '../includes/staff_sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3 mb-0">Notifications</h1>
    <?php if ($notifications): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><button class="btn btn-outline-primary" name="mark_all_read" type="submit"><i class="fa-solid fa-check-double me-1"></i> Mark all read</button></form><?php endif; ?>
</div>
<?php if (!$notifications): ?><div class="card p-4 text-muted">No notifications.</div><?php else: ?>
    <div class="list-group"><?php foreach ($notifications as $notification): ?>
        <div class="list-group-item py-3<?php echo $notification['is_read'] ? '' : ' list-group-item-primary'; ?>">
            <div class="d-flex justify-content-between gap-3"><div>
                <div class="fw-semibold"><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?><?php if (!$notification['is_read']): ?> <span class="badge text-bg-primary">New</span><?php endif; ?></div>
                <p class="mb-1"><?php echo htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                <small class="text-muted"><?php echo htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php if ($notification['booking_id']): ?><a class="d-block small mt-1" href="bookings.php?search=<?php echo (int) $notification['booking_id']; ?>">View booking #<?php echo (int) $notification['booking_id']; ?></a><?php endif; ?>
            </div>
            <?php if (!$notification['is_read']): ?><form method="POST" class="align-self-center"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Mark read</button></form><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?></div>
<?php endif; ?>
</main>
</div>
<?php include '../includes/footer.php'; ?>