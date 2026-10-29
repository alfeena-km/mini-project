<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_staff(true);

$staff_location_id = (int) ($_SESSION['staff_location_id'] ?? 0);
$location = null;
$stats = [];
$recent_bookings = [];
if (!$staff_location_id) {
    $page_title = 'Staff Dashboard';
    include 'includes/header.php';
    ?>
    <main class="mx-auto" style="max-width:640px">
        <div class="alert alert-warning p-4" role="alert">
            <h1 class="h4">Location assignment required</h1>
            <p class="mb-0">Your account has not been assigned to a parking location. Please contact the administrator.</p>
        </div>
    </main>
    <?php
    include 'includes/footer.php';
    exit;
}

$stmt = $pdo->prepare('SELECT id, name, address, city, status FROM locations WHERE id = ?');
$stmt->execute([$staff_location_id]);
$location = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$location) {
    http_response_code(403);
    exit('Your assigned parking location is unavailable. Please contact the administrator.');
}
if ($location['status'] !== 'active') {
    $page_title = 'Staff Dashboard';
    include 'includes/header.php';
    ?>
    <main class="mx-auto" style="max-width:640px"><div class="alert alert-warning p-4" role="alert"><h1 class="h4">Location unavailable</h1><p class="mb-0">Your assigned parking location is not active. Please contact the administrator.</p></div></main>
    <?php
    include 'includes/footer.php';
    exit;
}
$stats = $pdo->query("SELECT
    (SELECT COUNT(*) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.booking_date=CURDATE()) AS today_bookings,
    (SELECT COUNT(*) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.status='active') AS active_bookings,
    (SELECT COUNT(*) FROM parking_slots ps WHERE ps.location_id=$staff_location_id AND ps.status='available'
        AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.slot_id=ps.id AND b.status='active')
        AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.slot_id=ps.id AND b.status='confirmed' AND TIMESTAMP(b.booking_date,b.end_time)>NOW())) AS available_slots,
    (SELECT COUNT(DISTINCT b.slot_id) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.status='active') AS occupied_slots,
    (SELECT COUNT(DISTINCT b.slot_id) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.status='confirmed' AND TIMESTAMP(b.booking_date,b.end_time)>NOW()) AS reserved_slots,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='pending') AS pending_payments,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='completed') AS completed_payments,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='completed' AND p.payment_method='cash_on_delivery') AS cash_payments,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='completed' AND p.payment_method='upi') AS upi_payments")->fetch(PDO::FETCH_ASSOC);

$recent_bookings = $pdo->query("SELECT b.id, b.booking_code, b.booking_date, b.start_time, b.end_time, b.status, b.payment_status,
        u.name AS user_name, ps.slot_number, l.name AS location_name, p.payment_method
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN parking_slots ps ON ps.id = b.slot_id
    JOIN locations l ON l.id = ps.location_id
    LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
    WHERE ps.location_id = $staff_location_id
    ORDER BY b.booking_date DESC, b.start_time DESC
    LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Staff Dashboard';
include 'includes/header.php';
?>

<h3 class="mb-1"><i class="fa-solid fa-clipboard-check"></i> Staff Dashboard</h3>
<p class="text-muted mb-3">Welcome, <?php echo htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8'); ?></p>
<?php include 'includes/staff_sidebar.php'; ?>

<section class="staff-location-card card p-4 mb-4" aria-label="Assigned parking location">
    <div class="small text-uppercase fw-semibold">Managing Location</div>
    <h2 class="h4 mt-2 mb-1"><i class="fa-solid fa-location-dot me-2"></i><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <p class="mb-2"><?php echo htmlspecialchars(trim(($location['address'] ?? '') . (($location['city'] ?? '') !== '' ? ', ' . $location['city'] : ''), ', '), ENT_QUOTES, 'UTF-8'); ?></p>
    <strong><?php echo (int) $pdo->query("SELECT COUNT(*) FROM parking_slots WHERE location_id = $staff_location_id")->fetchColumn(); ?> parking slots</strong>
</section>

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <?php foreach ([
        ['today_bookings', 'fa-calendar-day', 'primary', "Today's Bookings", $stats['today_bookings']],
        ['active_bookings', 'fa-car-side', 'success', 'Active Bookings', $stats['active_bookings']],
        ['available_slots', 'fa-square-parking', 'info', 'Available Parking Slots', $stats['available_slots']],
        ['occupied_slots', 'fa-road', 'warning', 'Occupied Slots', $stats['occupied_slots']],
        ['reserved_slots', 'fa-bookmark', 'primary', 'Reserved Slots', $stats['reserved_slots']],
        ['pending_payments', 'fa-hourglass-half', 'warning', 'Pending Payments', $stats['pending_payments']],
        ['completed_payments', 'fa-circle-check', 'success', 'Completed Payments', $stats['completed_payments']],
        ['cash_payments', 'fa-money-bill-wave', 'secondary', 'Cash Payments', $stats['cash_payments']],
        ['upi_payments', 'fa-mobile-screen-button', 'primary', 'UPI Payments', $stats['upi_payments']],
    ] as [$metric, $icon, $color, $label, $value]): ?>
        <div class="col"><div class="card p-3 h-100"><i class="fa-solid <?php echo $icon; ?> text-<?php echo $color; ?> mb-2"></i><h4 class="mb-1" data-staff-metric="<?php echo $metric; ?>"><?php echo (int) $value; ?></h4><p class="text-muted mb-0"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></p></div></div>
    <?php endforeach; ?>
</div>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="fa-solid fa-clock-rotate-left me-2"></i>Latest Booking Activity</h5>
        <a href="staff/bookings.php" class="btn btn-sm btn-outline-primary">View all bookings</a>
    </div>
    <?php if (empty($recent_bookings)): ?>
        <div class="text-muted">No booking activity recorded yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered align-middle bg-white mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Code</th>
                        <th>User</th>
                        <th>Location</th>
                        <th>Slot</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Method</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_bookings as $booking): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($booking['booking_code']); ?></td>
                            <td><?php echo htmlspecialchars($booking['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($booking['location_name']); ?></td>
                            <td><?php echo htmlspecialchars($booking['slot_number']); ?></td>
                            <td><?php echo htmlspecialchars($booking['booking_date']); ?></td>
                            <td><span class="badge text-bg-<?php echo $booking['status'] === 'confirmed' ? 'success' : ($booking['status'] === 'cancelled' ? 'danger' : 'secondary'); ?>"><?php echo htmlspecialchars(strtoupper($booking['status'])); ?></span></td>
                            <td><?php echo htmlspecialchars(payment_method_label($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge text-bg-<?php echo $booking['payment_status'] === 'completed' ? 'success' : ($booking['payment_status'] === 'pending' ? 'warning text-dark' : 'secondary'); ?>"><?php echo htmlspecialchars(strtoupper($booking['payment_status'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
async function refreshStaffMetrics() {
    try {
        const response = await fetch('api/staff_metrics.php', { cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const metrics = await response.json();
        document.querySelectorAll('[data-staff-metric]').forEach(node => {
            const value = metrics[node.dataset.staffMetric];
            if (value !== undefined) node.textContent = value;
        });
    } catch (error) {
        console.error('Could not refresh staff metrics.', error);
    }
}
window.setInterval(refreshStaffMetrics, 15000);
</script>
</main>
</div>

<?php include 'includes/footer.php'; ?>
