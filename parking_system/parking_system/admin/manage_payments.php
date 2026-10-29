<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all', 'pending', 'completed', 'failed', 'refunded'], true)) {
    $status_filter = 'all';
}
$method_filter = $_GET['method'] ?? 'all';
if (!in_array($method_filter, ['all', 'upi', 'cash_on_delivery'], true)) {
    $method_filter = 'all';
}
$locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$location_filter = $_GET['location_id'] ?? 'all';
$selected_location_id = filter_var($location_filter, FILTER_VALIDATE_INT);
if ($location_filter !== 'all' && (!$selected_location_id || !in_array($selected_location_id, array_map('intval', array_column($locations, 'id')), true))) {
    $location_filter = 'all';
    $selected_location_id = null;
}
$where = [];
$params = [];
if ($status_filter !== 'all') {
    $where[] = 'p.payment_status = ?';
    $params[] = $status_filter;
}
if ($method_filter !== 'all') {
    $where[] = 'p.payment_method = ?';
    $params[] = $method_filter;
}
if ($selected_location_id) {
    $where[] = 'ps.location_id = ?';
    $params[] = $selected_location_id;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT p.id, p.amount, p.payment_method, p.transaction_id, p.payment_status, p.payment_date,
        p.paid_at, b.booking_code, b.id AS booking_id, u.name AS user_name, u.email, staff.name AS confirmed_by_name
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN parking_slots ps ON ps.id = b.slot_id
    JOIN users u ON u.id = p.user_id
    LEFT JOIN users staff ON staff.id = p.confirmed_by
    $where_sql
    ORDER BY p.payment_date DESC, p.id DESC");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$history_where = $selected_location_id ? 'WHERE ps.location_id = ?' : '';
$history_stmt = $pdo->prepare("SELECT ph.payment_id, ph.booking_id, ph.previous_status, ph.new_status, ph.payment_method, ph.changed_at,
        u.name AS user_name, staff.name AS staff_name
    FROM payment_history ph
    JOIN users u ON u.id = ph.user_id
    JOIN bookings b ON b.id = ph.booking_id
    JOIN parking_slots ps ON ps.id = b.slot_id
    LEFT JOIN users staff ON staff.id = ph.staff_id
    $history_where
    ORDER BY ph.changed_at DESC, ph.id DESC LIMIT 100");
$history_stmt->execute($selected_location_id ? [$selected_location_id] : []);
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Manage Payments';
include '../includes/header.php';
?>
<h2 class="h3 mb-4"><i class="fa-solid fa-credit-card me-2"></i>Payments</h2>
<form method="GET" class="card p-3 mb-3"><div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label" for="adminPaymentStatus">Status</label><select id="adminPaymentStatus" name="status" class="form-select"><option value="all">All statuses</option><?php foreach (['pending', 'completed', 'failed', 'refunded'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="adminPaymentMethod">Method</label><select id="adminPaymentMethod" name="method" class="form-select"><option value="all">All methods</option><option value="upi" <?php echo $method_filter === 'upi' ? 'selected' : ''; ?>>UPI</option><option value="cash_on_delivery" <?php echo $method_filter === 'cash_on_delivery' ? 'selected' : ''; ?>>Cash on Delivery</option></select></div>
        <div class="col-md-3"><label class="form-label" for="adminPaymentLocation">Location</label><select id="adminPaymentLocation" name="location_id" class="form-select"><option value="all">All Locations</option><?php foreach ($locations as $location): ?><option value="<?php echo (int) $location['id']; ?>" <?php echo $selected_location_id === (int) $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i> Filter</button><a href="manage_payments.php" class="btn btn-outline-secondary">Clear</a></div>
</div></form>
<div class="card p-3 mb-4">
    <?php if (!$payments): ?>
        <p class="text-muted text-center my-4">No payments recorded yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Payment</th><th>Booking</th><th>User</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Confirmed by</th><th>Confirmation time</th></tr></thead>
                <tbody>
                <?php if (!$payments): ?><tr><td colspan="9" class="text-center text-muted py-4">No payments match these filters.</td></tr><?php endif; ?>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td>#<?php echo (int) $payment['id']; ?></td>
                        <td><?php echo htmlspecialchars($payment['booking_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($payment['user_name'] . ' · ' . $payment['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>₹<?php echo number_format((float) $payment['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars(payment_method_label($payment['payment_method']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($payment['transaction_id'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge text-bg-<?php echo $payment['payment_status'] === 'completed' ? 'success' : ($payment['payment_status'] === 'pending' ? 'warning text-dark' : 'secondary'); ?>"><?php echo htmlspecialchars(ucfirst($payment['payment_status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?php echo htmlspecialchars($payment['payment_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($payment['confirmed_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($payment['paid_at'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<section class="card p-3">
    <h3 class="h5">Payment History</h3>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Payment</th><th>Booking</th><th>User</th><th>Method</th><th>Previous</th><th>New</th><th>Staff</th><th>Time</th></tr></thead><tbody>
        <?php if (!$history): ?><tr><td colspan="8" class="text-muted text-center">No payment status changes recorded.</td></tr><?php endif; ?>
        <?php foreach ($history as $row): ?><tr><td>#<?php echo (int) $row['payment_id']; ?></td><td>#<?php echo (int) $row['booking_id']; ?></td><td><?php echo htmlspecialchars($row['user_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(payment_method_label($row['payment_method']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(ucfirst($row['previous_status']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(ucfirst($row['new_status']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($row['staff_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php include '../includes/footer.php'; ?>