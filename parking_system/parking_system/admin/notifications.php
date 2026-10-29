<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$errors = [];
$notice = $_SESSION['admin_notification_flash'] ?? '';
unset($_SESSION['admin_notification_flash']);

$users_stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'user' AND account_status = 'approved' ORDER BY name, email");
$active_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_notification') {
    require_valid_csrf_post();
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $recipient = $_POST['recipient'] ?? 'all';

    if ($title === '' || strlen($title) > 150) {
        $errors[] = 'Enter a title up to 150 characters.';
    }
    if ($message === '' || strlen($message) > 5000) {
        $errors[] = 'Enter a message up to 5,000 characters.';
    }
    if ($recipient !== 'all' && !filter_var($recipient, FILTER_VALIDATE_INT)) {
        $errors[] = 'Choose a valid recipient.';
    }

    if (!$errors) {
        if ($recipient === 'all') {
            $recipients = $active_users;
        } else {
            $recipient_stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'user' AND account_status = 'approved'");
            $recipient_stmt->execute([(int) $recipient]);
            $user = $recipient_stmt->fetch(PDO::FETCH_ASSOC);
            $recipients = $user ? [$user] : [];
            if (!$recipients) {
                $errors[] = 'That user is no longer active. Refresh and choose another recipient.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'system')");
                foreach ($recipients as $user) {
                    $insert->execute([$user['id'], $title, $message]);
                }
                $pdo->commit();
                $_SESSION['admin_notification_flash'] = 'Notification sent to ' . count($recipients) . ' active user(s).';
                header('Location: notifications.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Admin notification send failed: ' . $e->getMessage());
                $errors[] = 'Unable to send the notification right now.';
            }
        }
    }
}

$stmt = $pdo->query('SELECT n.id, n.title, n.message, n.type, n.is_read, n.created_at, u.name AS user_name, u.email, b.booking_code
    FROM notifications n
    JOIN users u ON u.id = n.user_id
    LEFT JOIN bookings b ON b.id = n.booking_id
    ORDER BY n.created_at DESC, n.id DESC');
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Notifications';
include '../includes/header.php';
?>
<h2 class="h3 mb-4"><i class="fa-regular fa-bell me-2"></i>Notifications</h2>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endforeach; ?>
<div class="card p-4 mb-4">
    <h3 class="h5 mb-3">Send a notification</h3>
    <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="send_notification">
        <div class="col-md-4">
            <label for="recipient" class="form-label">Send to</label>
            <select id="recipient" name="recipient" class="form-select" required>
                <option value="all" <?php echo ($_POST['recipient'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All active users (<?php echo count($active_users); ?>)</option>
                <?php foreach ($active_users as $user): ?>
                    <option value="<?php echo (int) $user['id']; ?>" <?php echo (string) ($_POST['recipient'] ?? '') === (string) $user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['name'] . ' · ' . $user['email'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-8">
            <label for="notificationTitle" class="form-label">Title</label>
            <input id="notificationTitle" name="title" class="form-control" maxlength="150" required value="<?php echo htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-12">
            <label for="notificationMessage" class="form-label">Message</label>
            <textarea id="notificationMessage" name="message" class="form-control" rows="3" maxlength="5000" required><?php echo htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>
        <div class="col-12"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i> Send notification</button></div>
    </form>
</div>
<h3 class="h5 mb-3">Sent and system notifications</h3>
<?php if (!$notifications): ?>
    <div class="card p-5 text-center"><p class="text-muted mb-0">No notifications have been created.</p></div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($notifications as $notification): ?>
            <div class="list-group-item py-3">
                <div class="d-flex justify-content-between gap-3">
                    <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <p class="mb-1"><?php echo htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <small class="text-muted"><?php echo htmlspecialchars($notification['user_name'] . ' · ' . $notification['email'] . ' · ' . $notification['created_at'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php if ($notification['booking_code']): ?><span class="badge text-bg-light ms-2"><?php echo htmlspecialchars($notification['booking_code'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    </div>
                    <span class="badge text-bg-<?php echo $notification['is_read'] ? 'secondary' : 'primary'; ?> align-self-start"><?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>