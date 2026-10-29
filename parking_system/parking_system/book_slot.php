<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = (int) $_SESSION['user_id'];
$message = '';
$booking_id = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    exit('Your session could not validate this request. Return to parking search and try again.');
}

$slot_id = filter_var($_POST['slot_id'] ?? null, FILTER_VALIDATE_INT);
$date = trim($_POST['date'] ?? '');
$start_time = trim($_POST['start_time'] ?? '');
$end_time = trim($_POST['end_time'] ?? '');
$vehicle_number = strtoupper(trim($_POST['vehicle_number'] ?? ''));
$payment_method = $_POST['payment_method'] ?? '';

$date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$time_pattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

if (!$slot_id || !$date_value || $date_value->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
    $message = 'Choose a valid parking date and slot.';
} elseif (!preg_match($time_pattern, $start_time) || !preg_match($time_pattern, $end_time) || $end_time <= $start_time) {
    $message = 'Choose valid times. The end time must be later than the start time.';
} elseif ($date === date('Y-m-d') && $start_time <= date('H:i')) {
    $message = 'For today, choose a start time that has not passed.';
} elseif ($vehicle_number === '' || strlen($vehicle_number) > 20) {
    $message = 'Enter a valid vehicle number.';
} elseif (!in_array($payment_method, ['upi', 'cash_on_delivery'], true)) {
    $message = 'Choose UPI or Cash on Delivery as the payment method.';
} else {
    $start = DateTimeImmutable::createFromFormat('!H:i', $start_time);
    $end = DateTimeImmutable::createFromFormat('!H:i', $end_time);
    $duration_seconds = $end->getTimestamp() - $start->getTimestamp();
    $duration_hours = $duration_seconds / 3600;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT ps.id, ps.location_id, ps.slot_number, ps.slot_type, ps.price_per_hour, ps.status,
            l.name AS location_name, l.status AS location_status
            FROM parking_slots ps
            JOIN locations l ON l.id = ps.location_id
            WHERE ps.id = ? FOR UPDATE");
        $stmt->execute([$slot_id]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slot || $slot['status'] !== 'available' || $slot['location_status'] !== 'active') {
            $pdo->rollBack();
            $message = 'This parking slot is not available for booking.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM bookings
                WHERE slot_id = ? AND booking_date = ? AND status IN ('confirmed', 'active')
                  AND start_time < ? AND end_time > ?
                LIMIT 1");
            $stmt->execute([$slot_id, $date, $end_time, $start_time]);

            if ($stmt->fetch()) {
                $pdo->rollBack();
                $message = 'This parking slot is no longer available for the selected time.';
            } else {
                $stmt = $pdo->prepare('SELECT id, vehicle_number, vehicle_type FROM vehicles WHERE user_id = ? AND vehicle_number = ?');
                $stmt->execute([$user_id, $vehicle_number]);
                $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$vehicle || $vehicle['vehicle_type'] !== $slot['slot_type']) {
                    $pdo->rollBack();
                    $message = 'Add a vehicle matching this slot type to your account before booking.';
                } else {
                    $fee = round($duration_hours * (float) $slot['price_per_hour'], 2);
                    $booking_code = 'PK' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));

                    $stmt = $pdo->prepare("INSERT INTO bookings
                        (booking_code, user_id, slot_id, vehicle_id, vehicle_number, booking_date, start_time, end_time, duration_hours, price_per_hour, total_fee, status, payment_status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'pending')");
                    $stmt->execute([$booking_code, $user_id, $slot_id, $vehicle['id'], $vehicle['vehicle_number'], $date, $start_time, $end_time, $duration_hours, $slot['price_per_hour'], $fee]);
                    $booking_id = (int) $pdo->lastInsertId();

                    $stmt = $pdo->prepare("INSERT INTO payments (booking_id, user_id, amount, payment_method, payment_status, payment_date, created_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
                    $stmt->execute([$booking_id, $user_id, $fee, $payment_method]);

                    $stmt = $pdo->prepare("INSERT INTO booking_history (booking_id, user_id, action, new_date, new_start_time, new_end_time, remarks)
                        VALUES (?, ?, 'created', ?, ?, ?, 'Booking created')");
                    $stmt->execute([$booking_id, $user_id, $date, $start_time, $end_time]);

                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, title, message, type)
                        VALUES (?, ?, 'Booking confirmed', 'Your parking slot booking is confirmed. Payment is pending.', 'booking')");
                    $stmt->execute([$user_id, $booking_id]);

                    $staff_notice = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, location_id, title, message, type)
                        SELECT id, ?, ?, 'New booking at your location', ?, 'booking'
                        FROM users WHERE role = 'staff' AND account_status = 'approved' AND location_id = ?");
                    $staff_notice->execute([$booking_id, $slot['location_id'], 'A new booking was created for ' . $slot['location_name'] . ', slot ' . $slot['slot_number'] . '.', $slot['location_id']]);

                    $pdo->commit();
                    header('Location: booking_confirmation.php?id=' . $booking_id);
                    exit;
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Booking creation failed: ' . $e->getMessage());
        $message = 'Unable to complete this booking right now. Please try again.';
    }
}

$page_title = 'Booking Unavailable';
include 'includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card p-4 text-center">
            <i class="fa-solid fa-circle-exclamation text-warning mb-3" style="font-size:48px;"></i>
            <h3>Booking not completed</h3>
            <p class="text-muted"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
            <a href="dashboard.php" class="btn btn-primary">Return to parking search</a>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
