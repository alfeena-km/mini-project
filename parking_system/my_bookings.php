<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$user_id = $_SESSION['user_id'];
$message = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Mutating booking actions require an intentional, same-session POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']))) {
    $message = 'Your session could not validate this request. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking_id'])) {
    $bid = filter_var($_POST['cancel_booking_id'], FILTER_VALIDATE_INT);

    if (!$bid) {
        $message = 'Invalid booking.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT booking_date, start_time, end_time, payment_status FROM bookings WHERE id=? AND user_id=? AND status='confirmed' FOR UPDATE");
            $stmt->execute([$bid, $user_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                $pdo->rollBack();
                $message = 'This booking could not be cancelled.';
            } else {
                $stmt = $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=? AND user_id=? AND status='confirmed'");
                $stmt->execute([$bid, $user_id]);

                $stmt = $pdo->prepare("INSERT INTO booking_history (booking_id, user_id, action, old_date, old_start_time, old_end_time, remarks) VALUES (?, ?, 'cancelled', ?, ?, ?, 'Cancelled by user')");
                $stmt->execute([$bid, $user_id, $booking['booking_date'], $booking['start_time'], $booking['end_time']]);

                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, title, message, type) VALUES (?, ?, 'Booking cancelled', 'Your booking was cancelled successfully.', 'cancellation')");
                $stmt->execute([$user_id, $bid]);
                $pdo->commit();
                $message = $booking['payment_status'] === 'completed'
                    ? 'Booking cancelled. Contact parking staff to arrange a payment refund.'
                    : 'Booking cancelled successfully.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('User booking cancellation failed: ' . $e->getMessage());
            $message = 'Unable to cancel this booking right now. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modify_booking_id'])) {
    $bid = filter_var($_POST['modify_booking_id'], FILTER_VALIDATE_INT);
    $new_date = trim($_POST['date'] ?? '');
    $new_start = trim($_POST['start_time'] ?? '');
    $new_end = trim($_POST['end_time'] ?? '');
    $date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $new_date);

    if (!$bid || !$date_value || $date_value->format('Y-m-d') !== $new_date || $new_date < date('Y-m-d')
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $new_start)
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $new_end)
        || $new_end <= $new_start
        || ($new_date === date('Y-m-d') && $new_start <= date('H:i'))) {
        $message = 'Choose a valid future date and a valid time range.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT b.booking_date, b.start_time, b.end_time, b.slot_id, b.payment_status, ps.price_per_hour
                FROM bookings b JOIN parking_slots ps ON ps.id = b.slot_id
                WHERE b.id = ? AND b.user_id = ? AND b.status = 'confirmed' FOR UPDATE");
            $stmt->execute([$bid, $user_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking || $booking['payment_status'] !== 'pending') {
                $pdo->rollBack();
                $message = 'Only confirmed bookings with pending payment can be modified.';
            } else {
                $slot_lock = $pdo->prepare('SELECT id FROM parking_slots WHERE id = ? FOR UPDATE');
                $slot_lock->execute([$booking['slot_id']]);

                $check = $pdo->prepare("SELECT id FROM bookings WHERE slot_id = ? AND booking_date = ? AND status = 'confirmed'
                    AND id <> ? AND start_time < ? AND end_time > ? LIMIT 1");
                $check->execute([$booking['slot_id'], $new_date, $bid, $new_end, $new_start]);

                if ($check->fetch()) {
                    $pdo->rollBack();
                    $message = 'Cannot modify: the slot is already booked for that time.';
                } else {
                    $old_date = $booking['booking_date'];
                    $old_start = $booking['start_time'];
                    $old_end = $booking['end_time'];
                    $duration_hours = (strtotime($new_end) - strtotime($new_start)) / 3600;
                    $new_fee = round($duration_hours * (float) $booking['price_per_hour'], 2);

                    $stmt = $pdo->prepare('UPDATE bookings SET booking_date = ?, start_time = ?, end_time = ?, duration_hours = ?, total_fee = ? WHERE id = ? AND user_id = ?');
                    $stmt->execute([$new_date, $new_start, $new_end, $duration_hours, $new_fee, $bid, $user_id]);
                    $stmt = $pdo->prepare("UPDATE payments SET amount = ?, updated_at = NOW() WHERE booking_id = ? AND user_id = ? AND payment_status = 'pending'");
                    $stmt->execute([$new_fee, $bid, $user_id]);

                    $stmt = $pdo->prepare("INSERT INTO booking_history (booking_id, user_id, action, old_date, old_start_time, old_end_time, new_date, new_start_time, new_end_time, remarks)
                        VALUES (?, ?, 'modified', ?, ?, ?, ?, ?, ?, 'Modified by user')");
                    $stmt->execute([$bid, $user_id, $old_date, $old_start, $old_end, $new_date, $new_start, $new_end]);

                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, title, message, type) VALUES (?, ?, 'Booking modified', 'Your booking details were updated successfully.', 'booking')");
                    $stmt->execute([$user_id, $bid]);
                    $pdo->commit();
                    $message = 'Booking updated successfully. New fee: ₹' . number_format($new_fee, 2);
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('User booking modification failed: ' . $e->getMessage());
            $message = 'Unable to modify this booking right now. Please try again.';
        }
    }
}

$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all', 'confirmed', 'completed', 'cancelled'], true)) {
    $status_filter = 'all';
}
$search = trim($_GET['search'] ?? '');
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$per_page = 10;
$where = ['b.user_id = ?'];
$params = [$user_id];
if ($status_filter !== 'all') {
    $where[] = 'b.status = ?';
    $params[] = $status_filter;
}
if ($search !== '') {
    $where[] = '(b.booking_code LIKE ? OR b.vehicle_number LIKE ? OR l.name LIKE ?)';
    $search_term = '%' . $search . '%';
    array_push($params, $search_term, $search_term, $search_term);
}
$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b JOIN parking_slots ps ON b.slot_id = ps.id JOIN locations l ON ps.location_id = l.id WHERE $where_sql");
$stmt->execute($params);
$filtered_count = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($filtered_count / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$stmt = $pdo->prepare("SELECT b.*, ps.slot_number, ps.slot_type, l.name AS location_name, p.payment_method,
        p.payment_status AS recorded_payment_status
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN locations l ON ps.location_id = l.id
    LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
    WHERE $where_sql
    ORDER BY b.booking_date DESC, b.start_time DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$summary_stmt = $pdo->prepare("SELECT COUNT(*) AS total,
    SUM(status IN ('confirmed', 'active')) AS active,
    SUM(status = 'completed') AS completed,
    SUM(status = 'cancelled') AS cancelled
    FROM bookings WHERE user_id = ?");
$summary_stmt->execute([$user_id]);
$booking_summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "My Bookings";
include 'includes/header.php';

$active_count = (int) $booking_summary['active'];
$completed_count = (int) $booking_summary['completed'];
$cancelled_count = (int) $booking_summary['cancelled'];
?>

<style>
    .mb-summary {
        border-radius: 14px;
        padding: 20px;
        text-align: center;
        background: #fff;
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    }
    .mb-summary h3 { font-weight: 700; margin-bottom: 2px; }
    .booking-card {
        border-radius: 14px;
        border: none;
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        margin-bottom: 18px;
        overflow: hidden;
    }
    .booking-card .stripe {
        width: 6px;
    }
    .stripe-confirmed { background: #198754; }
    .stripe-completed { background: #6c757d; }
    .stripe-cancelled { background: #dc3545; }
    .badge-confirmed { background: #d1f5e0; color: #146c43; }
    .badge-completed { background: #e2e3e5; color: #41464b; }
    .badge-cancelled { background: #f8d7da; color: #842029; }
    .booking-icon {
        width: 46px; height: 46px;
        border-radius: 10px;
        background: #eef4ff;
        color: #0d6efd;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px;
    }
</style>

<h3 class="mb-4"><i class="fa-solid fa-clipboard-list"></i> My Bookings</h3>

<?php if ($message): ?>
    <div class="alert alert-info"><i class="fa-solid fa-circle-info me-1"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Summary strip -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="mb-summary"><h3 class="text-primary"><?php echo (int) $booking_summary['total']; ?></h3><small class="text-muted">Total Bookings</small></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mb-summary"><h3 class="text-success"><?php echo $active_count; ?></h3><small class="text-muted">Active</small></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mb-summary"><h3 class="text-secondary"><?php echo $completed_count; ?></h3><small class="text-muted">Completed</small></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mb-summary"><h3 class="text-danger"><?php echo $cancelled_count; ?></h3><small class="text-muted">Cancelled</small></div>
    </div>
</div>

<form method="GET" class="card p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-6"><label for="bookingSearch" class="form-label">Search bookings</label><input id="bookingSearch" name="search" class="form-control" placeholder="Booking code, vehicle, or location" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-md-3"><label for="bookingStatus" class="form-label">Status</label><select id="bookingStatus" name="status" class="form-select"><option value="all">All statuses</option><?php foreach (['confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $status_filter === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i> Filter</button><a class="btn btn-outline-secondary" href="my_bookings.php">Clear</a></div>
    </div>
</form>

<?php if ($filtered_count === 0): ?>
    <div class="card p-5 text-center">
        <i class="fa-solid fa-square-parking text-muted mb-3" style="font-size:50px;"></i>
        <h5><?php echo $search !== '' || $status_filter !== 'all' ? 'No matching bookings' : 'No bookings yet'; ?></h5>
        <p class="text-muted"><?php echo $search !== '' || $status_filter !== 'all' ? 'Try changing your search or status filter.' : 'You have not reserved a parking slot yet.'; ?></p>
        <?php if ($search === '' && $status_filter === 'all'): ?><a href="dashboard.php" class="btn btn-primary mx-auto" style="width:fit-content;">Search & Book a Slot</a><?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($bookings as $b): ?>
    <div class="card booking-card">
        <div class="d-flex">
            <div class="stripe stripe-<?php echo $b['status']; ?>"></div>
            <div class="p-3 flex-grow-1">
                <div class="row align-items-center g-3">
                    <div class="col-auto">
                        <div class="booking-icon"><i class="fa-solid fa-square-parking"></i></div>
                    </div>
                    <div class="col-md-3">
                        <div class="fw-semibold">#<?php echo $b['id']; ?> &middot; <?php echo htmlspecialchars($b['location_name']); ?></div>
                        <small class="text-muted">Slot <?php echo htmlspecialchars($b['slot_number']); ?> (<?php echo htmlspecialchars($b['slot_type']); ?>)</small>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted d-block">Date</small>
                        <?php echo htmlspecialchars($b['booking_date']); ?>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted d-block">Time</small>
                        <?php echo htmlspecialchars(format_time_ampm($b['start_time']) . ' - ' . format_time_ampm($b['end_time'])); ?>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted d-block">Vehicle</small>
                        <?php echo htmlspecialchars($b['vehicle_number']); ?>
                    </div>
                    <div class="col-md-1">
                        <small class="text-muted d-block">Fee</small>
                        <span class="fw-semibold">₹<?php echo number_format($b['total_fee'], 2); ?></span>
                    </div>
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2 border-top pt-2">
                        <div data-payment-booking="<?php echo (int) $b['id']; ?>">Payment method: <strong data-payment-method><?php echo htmlspecialchars(payment_method_label($b['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span class="ms-2 badge text-bg-<?php echo ($b['recorded_payment_status'] ?? $b['payment_status']) === 'completed' ? 'success' : 'warning text-dark'; ?>" data-payment-status>Payment <?php echo htmlspecialchars(ucfirst($b['recorded_payment_status'] ?? $b['payment_status']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php if (($b['recorded_payment_status'] ?? $b['payment_status']) === 'pending'): ?><a class="btn btn-sm btn-outline-primary" data-payment-instructions="<?php echo (int) $b['id']; ?>" href="payment.php?id=<?php echo (int) $b['id']; ?>">Payment instructions</a><?php endif; ?>
                    </div>
                    <div class="col-md-2 text-md-end">
                        <span class="badge badge-<?php echo $b['status']; ?> mb-2 px-3 py-2"><?php echo ucfirst($b['status']); ?></span>
                        <?php if ($b['status'] === 'confirmed'): ?>
                            <div>
                                <?php if ($b['payment_status'] === 'pending'): ?><button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modifyModal<?php echo $b['id']; ?>"><i class="fa-solid fa-pen"></i> Modify</button><?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this booking?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="cancel_booking_id" value="<?php echo (int) $b['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i> Cancel</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modify Modal -->
    <?php if ($b['status'] === 'confirmed' && $b['payment_status'] === 'pending'): ?>
    <div class="modal fade" id="modifyModal<?php echo $b['id']; ?>" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-header">
              <h5 class="modal-title">Modify Booking #<?php echo $b['id']; ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="modify_booking_id" value="<?php echo $b['id']; ?>">
                <div class="mb-2">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" min="<?php echo date('Y-m-d'); ?>" value="<?php echo $b['booking_date']; ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">Start Time (AM/PM)</label>
                    <input type="time" name="start_time" class="form-control" lang="en-US" value="<?php echo htmlspecialchars(substr($b['start_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">End Time (AM/PM)</label>
                    <input type="time" name="end_time" class="form-control" lang="en-US" value="<?php echo htmlspecialchars(substr($b['end_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
        <?php endif; ?>
<?php endforeach; ?>

<?php if ($total_pages > 1): ?>
    <nav aria-label="Booking pages" class="mt-3"><ul class="pagination justify-content-center">
        <?php for ($page_number = 1; $page_number <= $total_pages; $page_number++): ?>
            <?php $page_query = http_build_query(['search' => $search, 'status' => $status_filter, 'page' => $page_number]); ?>
            <li class="page-item <?php echo $page_number === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo htmlspecialchars($page_query, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $page_number === $page ? ' aria-current="page"' : ''; ?>><?php echo $page_number; ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<script>
async function refreshUserPaymentStatuses() {
    try {
        const response = await fetch('api/payment_status.php', { cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = await response.json();
        document.querySelectorAll('[data-payment-booking]').forEach(row => {
            const payment = data.payments[row.dataset.paymentBooking];
            if (!payment) return;
            const method = payment.method === 'cash_on_delivery' ? 'Cash on Delivery' : (payment.method === 'upi' ? 'UPI' : 'Not selected');
            const status = payment.status || 'pending';
            row.querySelector('[data-payment-method]').textContent = method;
            const statusBadge = row.querySelector('[data-payment-status]');
            statusBadge.textContent = 'Payment ' + status.charAt(0).toUpperCase() + status.slice(1);
            statusBadge.className = 'ms-2 badge text-bg-' + (status === 'completed' ? 'success' : (status === 'pending' ? 'warning text-dark' : 'secondary'));
            const instructions = document.querySelector('[data-payment-instructions="' + row.dataset.paymentBooking + '"]');
            if (instructions && status !== 'pending') instructions.remove();
        });
    } catch (error) {
        console.error('Could not refresh payment statuses.', error);
    }
}
refreshUserPaymentStatuses();
window.setInterval(refreshUserPaymentStatuses, 15000);
</script>

<?php include 'includes/footer.php'; ?>