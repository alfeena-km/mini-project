<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();
$staff_location_id = (int) $_SESSION['staff_location_id'];

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['session_action'])) {
    require_valid_csrf_post();
    $booking_id = filter_var($_POST['booking_id'], FILTER_VALIDATE_INT);
    $action = $_POST['session_action'];
    if (!$booking_id || !in_array($action, ['check_in', 'check_out'], true)) {
        $error = 'Enter a valid booking and action.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT b.id, b.user_id, b.booking_date, b.status, b.payment_status FROM bookings b JOIN parking_slots ps ON ps.id = b.slot_id WHERE b.id = ? AND ps.location_id = ? FOR UPDATE');
            $stmt->execute([$booking_id, $staff_location_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking) {
                $pdo->rollBack();
                http_response_code(403);
                $error = 'Booking not found or you are not authorized to manage this location.';
            } elseif ($action === 'check_in' && ($booking['status'] !== 'confirmed' || $booking['booking_date'] !== date('Y-m-d'))) {
                $pdo->rollBack();
                $error = 'Only a confirmed booking for today can be checked in.';
            } elseif ($action === 'check_out' && $booking['status'] !== 'active') {
                $pdo->rollBack();
                $error = 'Only an active parking session can be checked out.';
            } elseif ($action === 'check_out' && $booking['payment_status'] !== 'completed') {
                $pdo->rollBack();
                $error = 'PAYMENT PENDING: confirm payment before completing checkout.';
            } else {
                $new_status = $action === 'check_in' ? 'active' : 'completed';
                $time_column = $action === 'check_in' ? 'checked_in_at' : 'checked_out_at';
                $stmt = $pdo->prepare("UPDATE bookings SET status = ?, $time_column = NOW() WHERE id = ? AND slot_id IN (SELECT id FROM parking_slots WHERE location_id = ?)");
                $stmt->execute([$new_status, $booking_id, $staff_location_id]);
                $history_action = $action === 'check_in' ? 'checked_in' : 'checked_out';
                $stmt = $pdo->prepare('INSERT INTO booking_history (booking_id, user_id, action, remarks) VALUES (?, ?, ?, ?)');
                $stmt->execute([$booking_id, $booking['user_id'], $history_action, 'Parking session ' . str_replace('_', ' ', $action) . ' by staff']);
                $title = $action === 'check_in' ? 'Parking session started' : 'Parking session completed';
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, booking_id, title, message, type) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$booking['user_id'], $booking_id, $title, 'Your booking #' . $booking_id . ' was updated by parking staff.', 'booking']);
                $pdo->commit();
                $message = $action === 'check_in' ? 'Vehicle checked in.' : 'Vehicle checked out and booking completed.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff check-in/out failed: ' . $e->getMessage());
            $error = 'Unable to update the parking session right now.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$bookings = [];
if ($search !== '') {
    $term = '%' . $search . '%';
    $stmt = $pdo->prepare("SELECT b.id, b.booking_code, b.booking_date, b.start_time, b.end_time, b.status AS booking_status,
            b.payment_status, b.total_fee, b.vehicle_number, b.checked_in_at, b.checked_out_at,
            u.name AS user_name, u.phone AS user_phone, ps.slot_number, ps.slot_type,
            l.name AS location_name, p.payment_method, p.payment_status AS recorded_payment_status
        FROM bookings b
        JOIN users u ON u.id = b.user_id
        JOIN parking_slots ps ON ps.id = b.slot_id
        JOIN locations l ON l.id = ps.location_id
        LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
        WHERE ps.location_id = ? AND (CAST(b.id AS CHAR) LIKE ? OR u.phone LIKE ? OR b.vehicle_number LIKE ?)
        ORDER BY b.booking_date DESC, b.start_time DESC LIMIT 50");
    $stmt->execute([$staff_location_id, $term, $term, $term]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Staff Check-In / Check-Out';
include '../includes/header.php';
include '../includes/staff_sidebar.php';
?>
<h1 class="h3 mb-3">Check-In / Check-Out</h1>
<?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<form method="GET" class="card p-3 mb-3"><label class="form-label" for="bookingLookup">Booking ID, user phone, or vehicle number</label><div class="input-group"><input id="bookingLookup" name="search" class="form-control" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" required><button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button></div></form>
<?php if ($search !== '' && !$bookings): ?><div class="alert alert-info">No matching booking found.</div><?php endif; ?>
<?php foreach ($bookings as $booking): ?>
    <section class="card p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
            <div><h2 class="h5 mb-1">Booking #<?php echo (int) $booking['id']; ?> <small class="text-muted"><?php echo htmlspecialchars($booking['booking_code'], ENT_QUOTES, 'UTF-8'); ?></small></h2>
                <div><?php echo htmlspecialchars($booking['user_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($booking['user_phone'] ?? 'No phone', ENT_QUOTES, 'UTF-8'); ?></div>
                <div><?php echo htmlspecialchars($booking['vehicle_number'] . ' · ' . $booking['slot_type'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($booking['location_name'] . ' / ' . $booking['slot_number'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><?php echo htmlspecialchars($booking['booking_date'] . ' · ' . format_time_ampm($booking['start_time']) . '–' . format_time_ampm($booking['end_time']), ENT_QUOTES, 'UTF-8'); ?> · ₹<?php echo number_format((float) $booking['total_fee'], 2); ?></div>
                <div>Method: <?php echo htmlspecialchars(payment_method_label($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · Booking: <?php echo htmlspecialchars(ucfirst($booking['booking_status']), ENT_QUOTES, 'UTF-8'); ?> · Payment: <?php echo htmlspecialchars(ucfirst($booking['recorded_payment_status'] ?? $booking['payment_status']), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($booking['checked_in_at']): ?><small class="text-muted">Checked in <?php echo htmlspecialchars($booking['checked_in_at'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                <?php if ($booking['checked_out_at']): ?><small class="text-muted ms-2">Checked out <?php echo htmlspecialchars($booking['checked_out_at'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($booking['booking_status'] === 'confirmed' && $booking['booking_date'] === date('Y-m-d')): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>"><button class="btn btn-success" name="session_action" value="check_in">Check In</button></form><?php endif; ?>
                <?php if ($booking['booking_status'] === 'active'): ?><form method="POST" onsubmit="return confirm('Check out this vehicle and complete the booking?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>"><button class="btn btn-primary" name="session_action" value="check_out" <?php echo $booking['payment_status'] !== 'completed' ? 'disabled' : ''; ?>>Check Out</button></form><?php endif; ?>
            </div>
        </div>
        <?php if ($booking['booking_status'] === 'active' && $booking['payment_status'] !== 'completed'): ?><div class="alert alert-warning mt-3 mb-0"><strong>PAYMENT PENDING</strong> · Confirm payment on the Staff Payments page before checkout.</div><?php endif; ?>
    </section>
<?php endforeach; ?>
</main>
</div>
<?php include '../includes/footer.php'; ?>