<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();
$staff_location_id = (int) $_SESSION['staff_location_id'];

$search = trim($_GET['search'] ?? '');
$staff_filter = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$method_filter = $_GET['method'] ?? 'all';
if (!in_array($method_filter, ['all', 'upi', 'cash_on_delivery'], true)) {
    $method_filter = 'all';
}
$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all', 'pending', 'completed', 'failed', 'refunded'], true)) {
    $status_filter = 'all';
}
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
foreach (['start_date' => &$start_date, 'end_date' => &$end_date] as &$date_value) {
    $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_value);
    if (!$parsed_date || $parsed_date->format('Y-m-d') !== $date_value) {
        $date_value = '';
    }
}
unset($date_value);

$where = ['ps.location_id = ?'];
$params = [$staff_location_id];
if ($search !== '') {
    $where[] = '(CAST(ph.payment_id AS CHAR) LIKE ? OR CAST(ph.booking_id AS CHAR) LIKE ? OR u.name LIKE ? OR b.vehicle_number LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
if ($staff_filter) {
    $where[] = 'ph.staff_id = ?';
    $params[] = $staff_filter;
}
if ($method_filter !== 'all') {
    $where[] = 'ph.payment_method = ?';
    $params[] = $method_filter;
}
if ($status_filter !== 'all') {
    $where[] = 'ph.new_status = ?';
    $params[] = $status_filter;
}
if ($start_date !== '') {
    $where[] = 'DATE(ph.changed_at) >= ?';
    $params[] = $start_date;
}
if ($end_date !== '') {
    $where[] = 'DATE(ph.changed_at) <= ?';
    $params[] = $end_date;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT ph.payment_id, ph.booking_id, ph.previous_status, ph.new_status, ph.payment_method, ph.changed_at,
        u.name AS user_name, b.vehicle_number, staff.name AS staff_name
    FROM payment_history ph
    JOIN users u ON u.id = ph.user_id
    JOIN bookings b ON b.id = ph.booking_id
    JOIN parking_slots ps ON ps.id = b.slot_id
    LEFT JOIN users staff ON staff.id = ph.staff_id
    $where_sql
    ORDER BY ph.changed_at DESC, ph.id DESC LIMIT 300");
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
$staff_stmt = $pdo->prepare("SELECT id, name FROM users WHERE role = 'staff' AND location_id = ? ORDER BY name");
$staff_stmt->execute([$staff_location_id]);
$staff_members = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Staff Payment History';
include '../includes/header.php';
include '../includes/staff_sidebar.php';
?>
<h1 class="h3 mb-3">Payment History</h1>
<form method="GET" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3"><label class="form-label" for="historySearch">Search</label><input id="historySearch" name="search" class="form-control" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Payment, booking, user, vehicle"></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="historyStartDate">From</label><input id="historyStartDate" type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="historyEndDate">To</label><input id="historyEndDate" type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="historyStaff">Staff</label><select id="historyStaff" name="staff_id" class="form-select"><option value="">All staff</option><?php foreach ($staff_members as $member): ?><option value="<?php echo (int) $member['id']; ?>" <?php echo $staff_filter === (int) $member['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="historyMethod">Method</label><select id="historyMethod" name="method" class="form-select"><option value="all">All methods</option><option value="upi" <?php echo $method_filter === 'upi' ? 'selected' : ''; ?>>UPI</option><option value="cash_on_delivery" <?php echo $method_filter === 'cash_on_delivery' ? 'selected' : ''; ?>>Cash on Delivery</option></select></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="historyStatus">Status</label><select id="historyStatus" name="status" class="form-select"><option value="all">All statuses</option><?php foreach (['pending', 'completed', 'failed', 'refunded'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></div>
        <div class="col-lg-1"><button class="btn btn-primary w-100" type="submit" aria-label="Apply filters"><i class="fa-solid fa-filter"></i></button></div>
    </div>
</form>
<div class="table-responsive"><table class="table table-bordered table-hover bg-white align-middle">
    <thead class="table-dark"><tr><th>Payment</th><th>Booking</th><th>User / Vehicle</th><th>Method</th><th>Previous</th><th>New</th><th>Staff</th><th>Confirmed at</th></tr></thead>
    <tbody>
    <?php if (!$history): ?><tr><td colspan="8" class="text-center text-muted py-4">No payment history matches these filters.</td></tr><?php endif; ?>
    <?php foreach ($history as $row): ?><tr>
        <td>#<?php echo (int) $row['payment_id']; ?></td><td>#<?php echo (int) $row['booking_id']; ?></td>
        <td><?php echo htmlspecialchars($row['user_name'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars($row['vehicle_number'], ENT_QUOTES, 'UTF-8'); ?></small></td>
        <td><?php echo htmlspecialchars(payment_method_label($row['payment_method']), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars(ucfirst($row['previous_status']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(ucfirst($row['new_status']), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($row['staff_name'] ?? 'Staff account removed', ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
    </tr><?php endforeach; ?>
    </tbody>
</table></div>
</main>
</div>
<?php include '../includes/footer.php'; ?>