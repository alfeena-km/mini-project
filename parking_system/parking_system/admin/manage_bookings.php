<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['action'])) {
    require_valid_csrf_post();
    $booking_id = filter_var($_POST['booking_id'], FILTER_VALIDATE_INT);
    $action = $_POST['action'];

    if (!$booking_id || !in_array($action, ['cancel', 'complete'], true)) {
        $message = 'Invalid booking action.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT user_id, booking_date, start_time, end_time, status, payment_status FROM bookings WHERE id = ? FOR UPDATE");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            $new_status = $action === 'cancel' ? 'cancelled' : 'completed';

            if (!$booking || $booking['status'] !== 'confirmed') {
                $pdo->rollBack();
                $message = 'Only confirmed bookings can be changed.';
            } else {
                if ($action === 'cancel') {
                    $stmt = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?');
                    $stmt->execute([$new_status, $booking_id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?');
                    $stmt->execute([$new_status, $booking_id]);
                }

                $history_action = $new_status === 'cancelled' ? 'cancelled' : 'completed';
                $stmt = $pdo->prepare('INSERT INTO booking_history (booking_id, user_id, action, old_date, old_start_time, old_end_time, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$booking_id, $booking['user_id'], $history_action, $booking['booking_date'], $booking['start_time'], $booking['end_time'], 'Updated by administrator']);

                $notification_type = $new_status === 'cancelled' ? 'cancellation' : 'booking';
                $title = $new_status === 'cancelled' ? 'Booking cancelled' : 'Booking completed';
                $notification_message = $new_status === 'cancelled' ? 'An administrator cancelled your parking booking.' : 'Your parking booking was marked as completed.';
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, booking_id, title, message, type) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$booking['user_id'], $booking_id, $title, $notification_message, $notification_type]);

                $pdo->commit();
                $message = $new_status === 'cancelled' ? 'Booking cancelled.' : 'Booking marked as completed.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin booking update failed: ' . $e->getMessage());
            $message = 'Unable to update this booking right now.';
        }
    }
}

// Optional filter by status
$filter = $_GET['status'] ?? 'all';
$allowed_filters = ['all', 'pending', 'confirmed', 'active', 'completed', 'cancelled', 'rejected'];
if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}
$payment_filter = $_GET['payment_status'] ?? 'all';
if (!in_array($payment_filter, ['all', 'pending', 'completed', 'failed', 'refunded'], true)) {
    $payment_filter = 'all';
}
$locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$location_filter = $_GET['location_id'] ?? 'all';
$selected_location_id = filter_var($location_filter, FILTER_VALIDATE_INT);
if ($location_filter !== 'all' && (!$selected_location_id || !in_array($selected_location_id, array_map('intval', array_column($locations, 'id')), true))) {
    $location_filter = 'all';
    $selected_location_id = null;
}
$search = trim($_GET['search'] ?? '');
$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');
$valid_report_date = static function ($value) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
if (($from_date !== '' && !$valid_report_date($from_date)) || ($to_date !== '' && !$valid_report_date($to_date)) || ($from_date !== '' && $to_date !== '' && $from_date > $to_date)) {
    $from_date = '';
    $to_date = '';
    $message = 'Invalid date filter. Date filters were cleared.';
}
$where = [];
$params = [];
if ($selected_location_id) {
    $where[] = 'ps.location_id = ?';
    $params[] = $selected_location_id;
}
if ($filter !== 'all') {
    $where[] = 'b.status = ?';
    $params[] = $filter;
}
if ($payment_filter !== 'all') {
    $where[] = 'b.payment_status = ?';
    $params[] = $payment_filter;
}
if ($from_date !== '') {
    $where[] = 'b.booking_date >= ?';
    $params[] = $from_date;
}
if ($to_date !== '') {
    $where[] = 'b.booking_date <= ?';
    $params[] = $to_date;
}
if ($search !== '') {
    $where[] = '(b.booking_code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR l.name LIKE ? OR ps.slot_number LIKE ?)';
    $search_term = '%' . $search . '%';
    array_push($params, $search_term, $search_term, $search_term, $search_term, $search_term);
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$per_page = 20;
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN locations l ON ps.location_id = l.id$where_sql");
$count_stmt->execute($params);
$filtered_count = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($filtered_count / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$sql = "SELECT b.*, u.name AS user_name, u.email, ps.slot_number, l.name AS location_name
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN parking_slots ps ON b.slot_id = ps.id
        JOIN locations l ON ps.location_id = l.id$where_sql
        ORDER BY b.booking_date DESC, b.start_time DESC
        LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Bookings";
include '../includes/header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-clipboard-list"></i> Manage Bookings</h3>
<?php if ($message): ?><div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="mb-3">
    <a href="?status=all" class="btn btn-sm btn-outline-dark <?php echo $filter=='all'?'active':''; ?>">All</a>
    <a href="?status=confirmed" class="btn btn-sm btn-outline-success <?php echo $filter=='confirmed'?'active':''; ?>">Confirmed</a>
    <a href="?status=completed" class="btn btn-sm btn-outline-secondary <?php echo $filter=='completed'?'active':''; ?>">Completed</a>
    <a href="?status=cancelled" class="btn btn-sm btn-outline-danger <?php echo $filter=='cancelled'?'active':''; ?>">Cancelled</a>
</div>

<form method="GET" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3"><label for="bookingSearch" class="form-label">Search</label><input id="bookingSearch" name="search" class="form-control" placeholder="Code, user, location, slot" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-sm-6 col-lg-2"><label for="fromDate" class="form-label">From</label><input id="fromDate" type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-sm-6 col-lg-2"><label for="toDate" class="form-label">To</label><input id="toDate" type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-sm-6 col-lg-2"><label for="paymentFilter" class="form-label">Payment</label><select id="paymentFilter" name="payment_status" class="form-select"><option value="all">All</option><?php foreach (['pending', 'completed', 'failed', 'refunded'] as $payment_status): ?><option value="<?php echo $payment_status; ?>" <?php echo $payment_filter === $payment_status ? 'selected' : ''; ?>><?php echo ucfirst($payment_status); ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><label for="bookingLocation" class="form-label">Location</label><select id="bookingLocation" name="location_id" class="form-select"><option value="all">All Locations</option><?php foreach ($locations as $location): ?><option value="<?php echo (int) $location['id']; ?>" <?php echo $selected_location_id === (int) $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-lg-3 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter me-1"></i> Filter</button><a href="manage_bookings.php" class="btn btn-outline-secondary">Clear</a></div>
    </div>
</form>
<p class="small text-muted">Showing <?php echo $filtered_count ? $offset + 1 : 0; ?>–<?php echo min($offset + $per_page, $filtered_count); ?> of <?php echo $filtered_count; ?> bookings</p>

<div class="table-responsive">
<table class="table table-bordered bg-white">
<thead class="table-dark">
<tr><th>ID</th><th>User</th><th>Location</th><th>Slot</th><th>Date</th><th>Time</th><th>Fee</th><th>Status</th><th>Actions</th></tr>
</thead>
<tbody>
<?php if (empty($bookings)): ?>
    <tr><td colspan="9" class="text-center text-muted">No bookings found.</td></tr>
<?php endif; ?>
<?php foreach ($bookings as $b): ?>
<tr>
    <td>#<?php echo $b['id']; ?></td>
    <td><?php echo htmlspecialchars($b['user_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($b['email']); ?></small></td>
    <td><?php echo htmlspecialchars($b['location_name']); ?></td>
    <td><?php echo htmlspecialchars($b['slot_number']); ?></td>
    <td><?php echo htmlspecialchars($b['booking_date']); ?></td>
    <td><?php echo htmlspecialchars(format_time_ampm($b['start_time']) . ' - ' . format_time_ampm($b['end_time'])); ?></td>
    <td>₹<?php echo number_format($b['total_fee'], 2); ?></td>
    <td class="status-<?php echo $b['status']; ?>"><?php echo ucfirst($b['status']); ?></td>
    <td>
        <?php if ($b['status'] === 'confirmed'): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="booking_id" value="<?php echo (int) $b['id']; ?>">
                <input type="hidden" name="action" value="complete">
                <button type="submit" class="btn btn-sm btn-secondary">Mark Completed</button>
            </form>
            <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this booking?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="booking_id" value="<?php echo (int) $b['id']; ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
            </form>
        <?php else: ?>&mdash;<?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($total_pages > 1): ?>
    <nav aria-label="Booking pages"><ul class="pagination justify-content-center mt-3">
        <?php for ($page_number = 1; $page_number <= $total_pages; $page_number++): ?>
            <?php $page_query = http_build_query(['search' => $search, 'from_date' => $from_date, 'to_date' => $to_date, 'payment_status' => $payment_filter, 'status' => $filter, 'page' => $page_number]); ?>
            <li class="page-item <?php echo $page_number === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo htmlspecialchars($page_query, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $page_number === $page ? ' aria-current="page"' : ''; ?>><?php echo $page_number; ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
