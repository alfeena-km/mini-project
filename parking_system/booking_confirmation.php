<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT b.*, ps.slot_number, ps.slot_type, l.name AS location_name, l.address,
                        p.payment_method, p.payment_status AS recorded_payment_status
                        FROM bookings b
                        JOIN parking_slots ps ON b.slot_id = ps.id
                        JOIN locations l ON ps.location_id = l.id
                        LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
                        WHERE b.id = ? AND b.user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    die("Booking not found.");
}

$page_title = "Booking Confirmation";
include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card p-4 text-center">
            <i class="fa-solid fa-circle-check text-success" style="font-size:60px;"></i>
            <h3 class="mt-3">Booking Confirmed!</h3>
            <p class="text-muted">Your parking slot has been successfully reserved.</p>
            <table class="table table-bordered text-start mt-3">
                <tr><th>Booking ID</th><td>#<?php echo $booking['id']; ?></td></tr>
                <tr><th>Location</th><td><?php echo htmlspecialchars($booking['location_name'] . ' (' . $booking['address'] . ')'); ?></td></tr>
                <tr><th>Slot Number</th><td><?php echo htmlspecialchars($booking['slot_number']); ?> (<?php echo htmlspecialchars($booking['slot_type']); ?>)</td></tr>
                <tr><th>Date</th><td><?php echo htmlspecialchars($booking['booking_date']); ?></td></tr>
                <tr><th>Time</th><td><?php echo htmlspecialchars(format_time_ampm($booking['start_time']) . ' - ' . format_time_ampm($booking['end_time'])); ?></td></tr>
                <tr><th>Vehicle Number</th><td><?php echo htmlspecialchars($booking['vehicle_number']); ?></td></tr>
                <tr><th>Total Fee</th><td><strong>₹<?php echo number_format($booking['total_fee'], 2); ?></strong></td></tr>
                <tr><th>Status</th><td class="status-confirmed"><?php echo ucfirst($booking['status']); ?></td></tr>
                <tr><th>Payment Method</th><td><?php echo htmlspecialchars(payment_method_label($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                <tr><th>Payment Status</th><td><?php echo htmlspecialchars(ucfirst($booking['recorded_payment_status'] ?? $booking['payment_status']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
            </table>
            <?php if ($booking['payment_status'] === 'pending' && $booking['status'] === 'confirmed'): ?>
                <a href="payment.php?id=<?php echo (int) $booking['id']; ?>" class="btn btn-outline-primary mb-2">Payment instructions</a>
            <?php endif; ?>
            <a href="my_bookings.php" class="btn btn-primary">View My Bookings</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
