<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();
$staff_location_id = (int) $_SESSION['staff_location_id'];

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['action'])) {
    require_valid_csrf_post();
    $booking_id = filter_var($_POST['booking_id'], FILTER_VALIDATE_INT);
    $action = $_POST['action'];
    $targets = ['confirm' => 'confirmed', 'cancel' => 'cancelled', 'reject' => 'rejected'];
    if (!$booking_id || !isset($targets[$action])) {
        $error = 'Invalid booking action.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT b.status, b.payment_status, b.user_id FROM bookings b JOIN parking_slots ps ON ps.id = b.slot_id WHERE b.id = ? AND ps.location_id = ? FOR UPDATE');
            $stmt->execute([$booking_id, $staff_location_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            $new_status = $targets[$action];
            $valid_transition = $booking && (
                ($action === 'confirm' && $booking['status'] === 'pending')
                || ($action === 'reject' && in_array($booking['status'], ['pending', 'confirmed'], true))
                || ($action === 'cancel' && in_array($booking['status'], ['pending', 'confirmed'], true))
            );

            if (!$booking) {
                $pdo->rollBack();
                http_response_code(403);
                $error = 'You are not authorized to manage this location.';
            } elseif (!$valid_transition) {
                $pdo->rollBack();
                $error = 'This booking can no longer be changed with that action.';
            } elseif ($action === 'cancel' && $booking['payment_status'] === 'completed') {
                $pdo->rollBack();
                $error = 'This paid booking requires a refund before it can be cancelled.';
            } else {
                $stmt = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?');
                $stmt->execute([$new_status, $booking_id]);
                $history_action = $new_status === 'cancelled' ? 'cancelled' : ($new_status === 'rejected' ? 'rejected' : 'confirmed');
                $stmt = $pdo->prepare('INSERT INTO booking_history (booking_id, user_id, action, remarks) VALUES (?, ?, ?, ?)');
                $stmt->execute([$booking_id, $booking['user_id'], $history_action, 'Updated by parking staff']);
                $title = 'Booking ' . $new_status;
                $notice = 'Your booking #' . $booking_id . ' was ' . $new_status . ' by parking staff.';
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, booking_id, title, message, type) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$booking['user_id'], $booking_id, $title, $notice, $new_status === 'cancelled' ? 'cancellation' : 'booking']);
                $pdo->commit();
                $message = 'Booking status updated.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff booking update failed: ' . $e->getMessage());
            $error = 'Unable to update this booking right now.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$date = trim($_GET['date'] ?? '');
$date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$date_value || $date_value->format('Y-m-d') !== $date) {
    $date = '';
}
$booking_filter = $_GET['booking_status'] ?? 'all';
if (!in_array($booking_filter, ['all', 'pending', 'confirmed', 'active', 'completed', 'cancelled', 'rejected'], true)) {
    $booking_filter = 'all';
}
$payment_filter = $_GET['payment_status'] ?? 'all';
if (!in_array($payment_filter, ['all', 'pending', 'completed', 'failed', 'refunded'], true)) {
    $payment_filter = 'all';
}
$method_filter = $_GET['method'] ?? 'all';
if (!in_array($method_filter, ['all', 'upi', 'cash_on_delivery'], true)) {
    $method_filter = 'all';
}
$vehicle_type_filter = $_GET['vehicle_type'] ?? 'all';
if (!in_array($vehicle_type_filter, ['all', 'Car', 'Bike', 'EV'], true)) {
    $vehicle_type_filter = 'all';
}
$date_preset = $_GET['date_preset'] ?? 'all';
if (!in_array($date_preset, ['all', 'today', 'tomorrow'], true)) {
    $date_preset = 'all';
}
$where = ['ps.location_id = ?'];
$params = [$staff_location_id];
if ($search !== '') {
    $where[] = '(CAST(b.id AS CHAR) LIKE ? OR b.booking_code LIKE ? OR u.name LIKE ? OR u.phone LIKE ? OR b.vehicle_number LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
if ($date !== '') {
    $where[] = 'b.booking_date = ?';
    $params[] = $date;
} elseif ($date_preset === 'today') {
    $where[] = 'b.booking_date = CURDATE()';
} elseif ($date_preset === 'tomorrow') {
    $where[] = 'b.booking_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
}
if ($booking_filter !== 'all') {
    $where[] = 'b.status = ?';
    $params[] = $booking_filter;
}
if ($payment_filter !== 'all') {
    $where[] = 'b.payment_status = ?';
    $params[] = $payment_filter;
}
if ($method_filter !== 'all') {
    $where[] = 'p.payment_method = ?';
    $params[] = $method_filter;
}
if ($vehicle_type_filter !== 'all') {
    $where[] = 'ps.slot_type = ?';
    $params[] = $vehicle_type_filter;
}
$where_sql = 'WHERE ' . implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT b.id, b.booking_code, b.booking_date, b.start_time, b.end_time, b.total_fee,
        b.status AS booking_status, b.payment_status, b.vehicle_number, u.name AS user_name, u.phone AS user_phone,
        COALESCE(v.vehicle_type, ps.slot_type) AS vehicle_type, l.name AS location_name, ps.slot_number,
        p.payment_method, p.payment_status AS recorded_payment_status
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN parking_slots ps ON ps.id = b.slot_id
    JOIN locations l ON l.id = ps.location_id
    LEFT JOIN vehicles v ON v.id = b.vehicle_id
    LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
    $where_sql
    ORDER BY b.booking_date DESC, b.start_time DESC, b.id DESC LIMIT 300");
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Staff Bookings';
include '../includes/header.php';
include '../includes/staff_sidebar.php';
?>
<h1 class="h3 mb-3">Bookings</h1>
<?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<form method="GET" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-xl-3"><label class="form-label" for="bookingSearch">Search</label><input id="bookingSearch" name="search" class="form-control" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="ID, user, phone, vehicle"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label" for="bookingDatePreset">Day</label><select id="bookingDatePreset" name="date_preset" class="form-select"><option value="all">Any day</option><option value="today" <?php echo $date_preset === 'today' ? 'selected' : ''; ?>>Today</option><option value="tomorrow" <?php echo $date_preset === 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option></select><input id="bookingDate" name="date" type="date" class="form-control mt-1" value="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Specific booking date"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label" for="bookingStatus">Booking</label><select id="bookingStatus" name="booking_status" class="form-select"><option value="all">All</option><?php foreach (['pending', 'confirmed', 'active', 'completed', 'cancelled', 'rejected'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $booking_filter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label" for="paymentStatus">Payment</label><select id="paymentStatus" name="payment_status" class="form-select"><option value="all">All</option><?php foreach (['pending', 'completed', 'failed', 'refunded'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $payment_filter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label" for="paymentMethod">Method</label><select id="paymentMethod" name="method" class="form-select"><option value="all">All</option><option value="upi" <?php echo $method_filter === 'upi' ? 'selected' : ''; ?>>UPI</option><option value="cash_on_delivery" <?php echo $method_filter === 'cash_on_delivery' ? 'selected' : ''; ?>>Cash on Delivery</option></select></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label" for="vehicleType">Vehicle type</label><select id="vehicleType" name="vehicle_type" class="form-select"><option value="all">All</option><?php foreach (['Car', 'Bike', 'EV'] as $vehicle_type): ?><option value="<?php echo $vehicle_type; ?>" <?php echo $vehicle_type_filter === $vehicle_type ? 'selected' : ''; ?>><?php echo $vehicle_type; ?></option><?php endforeach; ?></select></div>
        <div class="col-xl-1"><button class="btn btn-primary w-100" type="submit" aria-label="Apply filters"><i class="fa-solid fa-filter"></i></button></div>
    </div>
</form>
<div class="table-responsive"><table class="table table-bordered table-hover bg-white align-middle">
    <thead class="table-dark"><tr><th>Booking</th><th>User / Phone</th><th>Vehicle</th><th>Location / Slot</th><th>Date / Time</th><th>Amount</th><th>Method</th><th>Payment</th><th>Status / Action</th></tr></thead>
    <tbody>
    <?php if (!$bookings): ?><tr><td colspan="9" class="text-center text-muted py-4">No bookings match these filters.</td></tr><?php endif; ?>
    <?php foreach ($bookings as $booking): ?><tr>
        <td>#<?php echo (int) $booking['id']; ?><small class="d-block text-muted"><?php echo htmlspecialchars($booking['booking_code'], ENT_QUOTES, 'UTF-8'); ?></small></td>
        <td><?php echo htmlspecialchars($booking['user_name'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars($booking['user_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
        <td><?php echo htmlspecialchars($booking['vehicle_number'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars($booking['vehicle_type'], ENT_QUOTES, 'UTF-8'); ?></small></td>
        <td><?php echo htmlspecialchars($booking['location_name'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted">Slot <?php echo htmlspecialchars($booking['slot_number'], ENT_QUOTES, 'UTF-8'); ?></small></td>
        <td><?php echo htmlspecialchars($booking['booking_date'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars(format_time_ampm($booking['start_time']) . ' - ' . format_time_ampm($booking['end_time']), ENT_QUOTES, 'UTF-8'); ?></small></td>
        <td>₹<?php echo number_format((float) $booking['total_fee'], 2); ?></td>
        <td><?php echo htmlspecialchars(payment_method_label($booking['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars(ucfirst($booking['recorded_payment_status'] ?? $booking['payment_status']), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><span class="badge text-bg-light text-dark"><?php echo htmlspecialchars(ucfirst($booking['booking_status']), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if (in_array($booking['booking_status'], ['pending', 'confirmed'], true)): ?>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    <?php if ($booking['booking_status'] === 'pending'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>"><button class="btn btn-sm btn-success" name="action" value="confirm">Confirm</button></form><?php endif; ?>
                    <?php foreach (['cancel' => 'Cancel', 'reject' => 'Reject'] as $action => $label): ?><form method="POST" onsubmit="return confirm('<?php echo $label; ?> booking #<?php echo (int) $booking['id']; ?>?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>"><button class="btn btn-sm btn-outline-danger" name="action" value="<?php echo $action; ?>"><?php echo $label; ?></button></form><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($booking['booking_status'] === 'confirmed' && $booking['booking_date'] === date('Y-m-d')): ?><a class="btn btn-sm btn-outline-primary mt-2" href="check_in.php?search=<?php echo (int) $booking['id']; ?>">Check in</a><?php elseif ($booking['booking_status'] === 'active'): ?><a class="btn btn-sm btn-outline-primary mt-2" href="check_in.php?search=<?php echo (int) $booking['id']; ?>">Check out</a><?php endif; ?>
        </td>
    </tr><?php endforeach; ?>
    </tbody>
</table></div>
</main>
</div>
<?php include '../includes/footer.php'; ?>