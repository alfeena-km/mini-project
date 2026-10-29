<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();
$staff_location_id = (int) $_SESSION['staff_location_id'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_id'], $_POST['payment_action'])) {
    require_valid_csrf_post();
    $payment_id = filter_var($_POST['payment_id'], FILTER_VALIDATE_INT);
    $action = $_POST['payment_action'];

    if (!$payment_id || !in_array($action, ['confirm', 'refund', 'fail'], true)) {
        $error = 'Choose a valid payment action.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT p.id, p.booking_id, p.user_id, p.amount, p.payment_method, p.payment_status,
                    b.booking_code, b.status AS booking_status, u.name AS user_name
                FROM payments p
                JOIN bookings b ON b.id = p.booking_id
                JOIN parking_slots ps ON ps.id = b.slot_id
                JOIN users u ON u.id = p.user_id
                WHERE p.id = ? AND ps.location_id = ? FOR UPDATE");
            $stmt->execute([$payment_id, $staff_location_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $pdo->rollBack();
                http_response_code(403);
                $error = 'Payment not found or you are not authorized to manage this location.';
            } elseif ($action === 'confirm' && $payment['payment_status'] === 'completed') {
                $pdo->rollBack();
                $error = 'Payment has already been confirmed.';
            } elseif ($action === 'confirm' && $payment['payment_status'] !== 'pending') {
                $pdo->rollBack();
                $error = 'Only pending payments can be confirmed.';
            } elseif ($action === 'confirm' && !in_array($payment['payment_method'], ['upi', 'cash_on_delivery'], true)) {
                $pdo->rollBack();
                $error = 'Only UPI and Cash on Delivery payments can be processed.';
            } elseif ($action === 'confirm' && in_array($payment['booking_status'], ['cancelled', 'rejected'], true)) {
                $pdo->rollBack();
                $error = 'Payments for cancelled or rejected bookings cannot be confirmed.';
            } elseif ($action === 'refund' && ($payment['payment_status'] !== 'completed' || !in_array($payment['booking_status'], ['cancelled', 'rejected'], true))) {
                $pdo->rollBack();
                $error = 'Only completed payments for cancelled or rejected bookings can be refunded.';
            } elseif ($action === 'fail' && ($payment['payment_status'] !== 'pending' || !in_array($payment['booking_status'], ['cancelled', 'rejected'], true))) {
                $pdo->rollBack();
                $error = 'Only pending payments for cancelled or rejected bookings can be marked failed.';
            } else {
                $new_status = $action === 'confirm' ? 'completed' : ($action === 'refund' ? 'refunded' : 'failed');
                if ($action === 'confirm') {
                    $stmt = $pdo->prepare("UPDATE payments SET payment_status = ?, confirmed_by = ?, paid_at = NOW(), updated_at = NOW() WHERE id = ? AND payment_status = 'pending'");
                    $stmt->execute([$new_status, $_SESSION['user_id'], $payment_id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE payments SET payment_status = ?, updated_at = NOW() WHERE id = ? AND payment_status = ?');
                    $stmt->execute([$new_status, $payment_id, $payment['payment_status']]);
                }
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Payment status changed during the staff action.');
                }

                $stmt = $pdo->prepare('UPDATE bookings SET payment_status = ? WHERE id = ?');
                $stmt->execute([$new_status, $payment['booking_id']]);

                $stmt = $pdo->prepare("INSERT INTO payment_history
                    (payment_id, booking_id, user_id, staff_id, previous_status, new_status, payment_method)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$payment_id, $payment['booking_id'], $payment['user_id'], $_SESSION['user_id'], $payment['payment_status'], $new_status, $payment['payment_method']]);

                $method_message = $action === 'confirm'
                    ? ($payment['payment_method'] === 'upi' ? 'Your UPI payment has been verified successfully.' : 'Your cash payment has been received successfully.')
                    : ($action === 'refund' ? 'Your payment has been refunded by parking staff.' : 'Your pending payment was closed after the booking was cancelled.');
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, booking_id, title, message, type) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $payment['user_id'],
                    $payment['booking_id'],
                    $action === 'confirm' ? 'Payment Confirmed' : ($action === 'refund' ? 'Payment Refunded' : 'Payment Closed'),
                    'Your payment of ₹' . number_format((float) $payment['amount'], 2) . ' for Booking #' . $payment['booking_code'] . '. ' . $method_message,
                    'payment',
                ]);

                $pdo->commit();
                $message = $action === 'confirm' ? 'Payment confirmed and the user has been notified.' : ($action === 'refund' ? 'Payment refunded and the user has been notified.' : 'Payment marked failed and the user has been notified.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff payment confirmation failed: ' . $e->getMessage());
            $error = 'Unable to confirm this payment right now.';
        }
    }
}

$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all', 'pending', 'completed', 'failed', 'refunded'], true)) {
    $status_filter = 'all';
}
$method_filter = $_GET['method'] ?? 'all';
if (!in_array($method_filter, ['all', 'upi', 'cash_on_delivery'], true)) {
    $method_filter = 'all';
}
$search = trim($_GET['search'] ?? '');
$where = ['ps.location_id = ?'];
$params = [$staff_location_id];
if ($status_filter !== 'all') {
    $where[] = 'p.payment_status = ?';
    $params[] = $status_filter;
}
if ($method_filter !== 'all') {
    $where[] = 'p.payment_method = ?';
    $params[] = $method_filter;
}
if ($search !== '') {
    $where[] = '(CAST(p.id AS CHAR) LIKE ? OR b.booking_code LIKE ? OR u.name LIKE ? OR b.vehicle_number LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT p.id, p.booking_id, p.user_id, p.amount, p.payment_method, p.payment_status,
        p.transaction_id, p.payment_date, p.paid_at, p.confirmed_by, b.booking_code, b.booking_date,
        b.status AS booking_status, b.vehicle_number, u.name AS user_name, u.phone AS user_phone,
        staff.name AS staff_name
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN parking_slots ps ON ps.id = b.slot_id
    JOIN users u ON u.id = p.user_id
    LEFT JOIN users staff ON staff.id = p.confirmed_by
    $where_sql
    ORDER BY FIELD(p.payment_status, 'pending', 'failed', 'completed', 'refunded'), p.payment_date DESC, p.id DESC
    LIMIT 200");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Staff Payments';
include '../includes/header.php';
include '../includes/staff_sidebar.php';
?>
<h1 class="h3 mb-3">Payments</h1>
<?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

<form method="GET" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-5"><label class="form-label" for="paymentSearch">Search</label><input id="paymentSearch" name="search" class="form-control" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Payment ID, booking, user, vehicle"></div>
        <div class="col-md-3"><label class="form-label" for="paymentMethod">Method</label><select id="paymentMethod" name="method" class="form-select"><option value="all">All methods</option><option value="upi" <?php echo $method_filter === 'upi' ? 'selected' : ''; ?>>UPI</option><option value="cash_on_delivery" <?php echo $method_filter === 'cash_on_delivery' ? 'selected' : ''; ?>>Cash on Delivery</option></select></div>
        <div class="col-md-2"><label class="form-label" for="paymentStatus">Status</label><select id="paymentStatus" name="status" class="form-select"><option value="all">All statuses</option><?php foreach (['pending', 'completed', 'failed', 'refunded'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button><a class="btn btn-outline-secondary" href="payments.php">Clear</a></div>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-bordered table-hover bg-white align-middle">
        <thead class="table-dark"><tr><th>Payment ID</th><th>Booking</th><th>User</th><th>Vehicle</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$payments): ?><tr><td colspan="9" class="text-center text-muted py-4">No payments match these filters.</td></tr><?php endif; ?>
        <?php foreach ($payments as $payment): ?>
            <?php
            $method_label = payment_method_label($payment['payment_method']);
            $confirm_message = 'Booking #' . $payment['booking_code'] . ' | ' . $payment['user_name'] . ' | ₹' . number_format((float) $payment['amount'], 2) . ' | ' . $method_label . ' | Current status: ' . ucfirst($payment['payment_status']) . "\n\nAre you sure you want to confirm this payment?";
            $refund_message = 'Refund payment #' . $payment['id'] . ' for booking #' . $payment['booking_code'] . ' (' . $payment['user_name'] . ', ₹' . number_format((float) $payment['amount'], 2) . ')?';
            $failed_message = 'Mark payment #' . $payment['id'] . ' for cancelled booking #' . $payment['booking_code'] . ' as failed?';
            ?>
            <tr>
                <td>#<?php echo (int) $payment['id']; ?></td>
                <td><?php echo htmlspecialchars($payment['booking_code'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars($payment['booking_date'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                <td><?php echo htmlspecialchars($payment['user_name'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars($payment['user_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
                <td><?php echo htmlspecialchars($payment['vehicle_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>₹<?php echo number_format((float) $payment['amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($method_label, ENT_QUOTES, 'UTF-8'); ?><?php if ($payment['transaction_id']): ?><small class="d-block text-muted">Ref: <?php echo htmlspecialchars($payment['transaction_id'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                <td><span class="badge text-bg-<?php echo $payment['payment_status'] === 'completed' ? 'success' : ($payment['payment_status'] === 'pending' ? 'warning text-dark' : 'secondary'); ?>"><?php echo htmlspecialchars(ucfirst($payment['payment_status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><?php echo htmlspecialchars($payment['payment_date'], ENT_QUOTES, 'UTF-8'); ?><?php if ($payment['staff_name']): ?><small class="d-block text-muted">By <?php echo htmlspecialchars($payment['staff_name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></td>
                <td class="text-nowrap">
                    <details><summary class="btn btn-sm btn-outline-secondary">View</summary><div class="small mt-2">Booking status: <?php echo htmlspecialchars(ucfirst($payment['booking_status']), ENT_QUOTES, 'UTF-8'); ?><br>Confirmed: <?php echo htmlspecialchars($payment['paid_at'] ?? 'Not confirmed', ENT_QUOTES, 'UTF-8'); ?></div></details>
                    <?php if ($payment['payment_status'] === 'pending' && in_array($payment['payment_method'], ['upi', 'cash_on_delivery'], true)): ?>
                        <form method="POST" class="mt-2" onsubmit="return confirm(this.dataset.confirmMessage);" data-confirm-message="<?php echo htmlspecialchars($confirm_message, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="payment_id" value="<?php echo (int) $payment['id']; ?>">
                            <input type="hidden" name="payment_action" value="confirm">
                            <button type="submit" class="btn btn-sm btn-success">Confirm Payment</button>
                        </form>
                    <?php elseif ($payment['payment_status'] === 'completed' && in_array($payment['booking_status'], ['cancelled', 'rejected'], true)): ?>
                        <form method="POST" class="mt-2" onsubmit="return confirm(this.dataset.confirmMessage);" data-confirm-message="<?php echo htmlspecialchars($refund_message, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="payment_id" value="<?php echo (int) $payment['id']; ?>">
                            <input type="hidden" name="payment_action" value="refund">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Refund</button>
                        </form>
                    <?php elseif ($payment['payment_status'] === 'pending' && in_array($payment['booking_status'], ['cancelled', 'rejected'], true)): ?>
                        <form method="POST" class="mt-2" onsubmit="return confirm(this.dataset.confirmMessage);" data-confirm-message="<?php echo htmlspecialchars($failed_message, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="payment_id" value="<?php echo (int) $payment['id']; ?>">
                            <input type="hidden" name="payment_action" value="fail">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Mark Failed</button>
                        </form>
                    <?php elseif ($payment['payment_status'] === 'pending'): ?>
                        <small class="d-block text-muted mt-2">Legacy method: staff processing is unavailable.</small>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</main>
</div>
<?php include '../includes/footer.php'; ?>