<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$booking_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$stmt = $pdo->prepare("SELECT b.id, b.booking_code, b.booking_date, b.start_time, b.end_time, b.total_fee, b.payment_status,
        ps.slot_number, l.name AS location_name, p.payment_method, p.transaction_id, p.payment_date
    FROM bookings b
    JOIN parking_slots ps ON ps.id = b.slot_id
    JOIN locations l ON l.id = ps.location_id
    JOIN payments p ON p.booking_id = b.id AND p.user_id = b.user_id AND p.payment_status = 'completed'
    WHERE b.id = ? AND b.user_id = ? AND b.payment_status = 'completed'
    ORDER BY p.payment_date DESC
    LIMIT 1");
$stmt->execute([$booking_id ?: 0, $_SESSION['user_id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    http_response_code(404);
    exit('Payment confirmation not found.');
}

$page_title = 'Payment Successful';
include 'includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card p-4 text-center">
            <i class="fa-solid fa-circle-check text-success mb-3" style="font-size:56px;"></i>
            <h2 class="h3">Payment Confirmed</h2>
            <p class="text-muted">Parking staff confirmed this payment.</p>
            <div class="table-responsive mt-3">
                <table class="table table-bordered text-start">
                    <tr><th>Booking code</th><td><?php echo htmlspecialchars($payment['booking_code'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <tr><th>Location</th><td><?php echo htmlspecialchars($payment['location_name'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <tr><th>Slot</th><td><?php echo htmlspecialchars($payment['slot_number'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <tr><th>Date and time</th><td><?php echo htmlspecialchars($payment['booking_date'] . ' · ' . format_time_ampm($payment['start_time']) . '–' . format_time_ampm($payment['end_time']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <tr><th>Amount</th><td>₹<?php echo number_format((float) $payment['total_fee'], 2); ?></td></tr>
                    <tr><th>Method</th><td><?php echo htmlspecialchars($payment['payment_method'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <tr><th>Transaction reference</th><td><?php echo htmlspecialchars($payment['transaction_id'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                </table>
            </div>
            <div class="d-flex justify-content-center gap-2">
                <a href="booking_confirmation.php?id=<?php echo (int) $payment['id']; ?>" class="btn btn-outline-primary">View Booking</a>
                <a href="my_bookings.php" class="btn btn-primary">My Bookings</a>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>