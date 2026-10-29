<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$user_id = (int) $_SESSION['user_id'];
$booking_id = filter_var($_POST['booking_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT);
$message = '';
$error = '';

if (!$booking_id) {
    http_response_code(400);
    exit('A valid booking is required.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_post();
    $reference = trim($_POST['transaction_reference'] ?? '');
    if (strlen($reference) > 100) {
        $error = 'The UPI reference must be 100 characters or fewer.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT p.id, p.payment_method, p.payment_status
                FROM payments p JOIN bookings b ON b.id = p.booking_id
                WHERE b.id = ? AND b.user_id = ? ORDER BY p.id DESC LIMIT 1 FOR UPDATE");
            $stmt->execute([$booking_id, $user_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $pdo->rollBack();
                http_response_code(404);
                exit('Payment record not found.');
            }
            if ($payment['payment_status'] !== 'pending' || $payment['payment_method'] !== 'upi') {
                $pdo->rollBack();
                $error = 'A UPI reference can only be submitted for a pending UPI payment.';
            } else {
                $stmt = $pdo->prepare("UPDATE payments SET transaction_id = ?, updated_at = NOW() WHERE id = ? AND payment_status = 'pending'");
                $stmt->execute([$reference === '' ? null : $reference, $payment['id']]);
                $pdo->commit();
                $message = 'Reference saved. Payment remains pending until staff verifies it.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('UPI reference submission failed: ' . $e->getMessage());
            $error = 'Unable to save the UPI reference right now.';
        }
    }
}

$stmt = $pdo->prepare("SELECT b.id, b.booking_code, b.booking_date, b.start_time, b.end_time, b.total_fee, b.status AS booking_status,
        ps.slot_number, l.name AS location_name, p.id AS payment_id, p.payment_method, p.payment_status, p.transaction_id
    FROM bookings b
    JOIN parking_slots ps ON ps.id = b.slot_id
    JOIN locations l ON l.id = ps.location_id
    LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id AND p2.user_id = b.user_id)
    WHERE b.id = ? AND b.user_id = ?");
$stmt->execute([$booking_id, $user_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    http_response_code(404);
    exit('Booking not found.');
}
if (!$booking['payment_id']) {
    http_response_code(404);
    exit('No payment record exists for this booking. Please contact parking staff.');
}
if (in_array($booking['booking_status'], ['cancelled', 'rejected'], true)) {
    http_response_code(409);
    exit('This booking is no longer eligible for payment.');
}

$page_title = 'Payment Instructions';
include 'includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card p-4">
            <h2 class="h4 mb-1">Payment Instructions</h2>
            <p class="text-muted">Payment status is updated only after staff verifies receipt.</p>
            <?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <dl class="row border-top pt-3">
                <dt class="col-5">Booking</dt><dd class="col-7"><?php echo htmlspecialchars($booking['booking_code'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt class="col-5">Location</dt><dd class="col-7"><?php echo htmlspecialchars($booking['location_name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt class="col-5">Slot</dt><dd class="col-7"><?php echo htmlspecialchars($booking['slot_number'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt class="col-5">Date and time</dt><dd class="col-7"><?php echo htmlspecialchars($booking['booking_date'] . ' · ' . format_time_ampm($booking['start_time']) . '–' . format_time_ampm($booking['end_time']), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt class="col-5">Amount</dt><dd class="col-7 fw-bold">₹<?php echo number_format((float) $booking['total_fee'], 2); ?></dd>
                <dt class="col-5">Payment method</dt><dd class="col-7"><?php echo htmlspecialchars(payment_method_label($booking['payment_method']), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt class="col-5">Payment status</dt><dd class="col-7"><span class="badge text-bg-<?php echo $booking['payment_status'] === 'completed' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars(ucfirst($booking['payment_status']), ENT_QUOTES, 'UTF-8'); ?></span></dd>
            </dl>
            <?php if ($booking['payment_status'] === 'pending' && $booking['payment_method'] === 'upi'): ?>
                <div class="alert alert-info">Pay using your UPI app and provide the transaction reference below. Staff must verify the payment before it is marked completed.</div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>">
                    <label for="transaction_reference" class="form-label">UPI transaction reference</label>
                    <input id="transaction_reference" name="transaction_reference" class="form-control mb-3" maxlength="100" value="<?php echo htmlspecialchars($booking['transaction_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-outline-primary w-100">Save reference for staff verification</button>
                </form>
            <?php elseif ($booking['payment_status'] === 'pending'): ?>
                <div class="alert alert-info">Pay the parking staff at the location. Staff will record and confirm the cash payment.</div>
            <?php else: ?>
                <div class="alert alert-success">This payment has been confirmed by parking staff.</div>
            <?php endif; ?>
            <a href="my_bookings.php" class="btn btn-link w-100 mt-2">Return to My Bookings</a>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>