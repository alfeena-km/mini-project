<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$admin_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approval_action'])) {
    require_valid_csrf_post();
    $user_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['approval_action'] ?? '';
    $reason = trim($_POST['rejection_reason'] ?? '');

    if ($user_id && in_array($action, ['approve', 'reject'], true)) {
        $account = $pdo->prepare('SELECT id, name, email, role, account_status, location_id FROM users WHERE id = ?');
        $account->execute([$user_id]);
        $account_row = $account->fetch(PDO::FETCH_ASSOC);

        if ($account_row && in_array($account_row['role'], ['user', 'staff'], true) && $account_row['account_status'] !== 'approved') {
            $new_status = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare('UPDATE users SET account_status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?');
            $stmt->execute([$new_status, $_SESSION['user_id'], ($action === 'reject' ? $reason : null), $user_id]);

            $history = $pdo->prepare('INSERT INTO account_approval_history (user_id, admin_id, action, remarks, created_at) VALUES (?, ?, ?, ?, NOW())');
            $history->execute([$user_id, $_SESSION['user_id'], $new_status, $action === 'reject' ? $reason : 'Approved by administrator']);

            if ($action === 'approve') {
                $notification_title = 'Account approved';
                $notification_message = 'Your account has been approved by the administrator. You can now log in.';
            } else {
                $notification_title = 'Account rejected';
                $notification_message = $reason !== '' ? 'Your account registration has been rejected. Reason: ' . $reason : 'Your account registration has been rejected. Please contact the administrator.';
            }

            $pdo->prepare('INSERT INTO notifications (user_id, location_id, title, message, type, is_read) VALUES (?, ?, ?, ?, ?, 0)')
                ->execute([$user_id, $account_row['role'] === 'staff' ? $account_row['location_id'] : null, $notification_title, $notification_message, $action === 'approve' ? 'approval' : 'rejection']);

            $admin_message = $action === 'approve' ? 'Account approved successfully.' : 'Account rejected successfully.';
        } else {
            $admin_message = 'That account is no longer pending approval.';
        }
    } else {
        $admin_message = 'Invalid approval request.';
    }
}

$locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$location_filter = $_GET['location_id'] ?? 'all';
$selected_location_id = filter_var($location_filter, FILTER_VALIDATE_INT);
if ($location_filter !== 'all' && (!$selected_location_id || !in_array($selected_location_id, array_map('intval', array_column($locations, 'id')), true))) {
    $location_filter = 'all';
    $selected_location_id = null;
}
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
$staff_count_stmt = $selected_location_id
    ? $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='staff' AND location_id = ?")
    : $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='staff'");
$staff_count_stmt->execute($selected_location_id ? [$selected_location_id] : []);
$total_staff = $staff_count_stmt->fetchColumn();
$pending_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role='user' AND account_status='pending'")->fetchColumn();
$pending_staff_stmt = $selected_location_id
    ? $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='staff' AND account_status='pending' AND location_id = ?")
    : $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='staff' AND account_status='pending'");
$pending_staff_stmt->execute($selected_location_id ? [$selected_location_id] : []);
$pending_staff = $pending_staff_stmt->fetchColumn();
$total_locations = $pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn();
$total_slots_stmt = $selected_location_id ? $pdo->prepare('SELECT COUNT(*) FROM parking_slots WHERE location_id = ?') : $pdo->prepare('SELECT COUNT(*) FROM parking_slots');
$total_slots_stmt->execute($selected_location_id ? [$selected_location_id] : []);
$total_slots = $total_slots_stmt->fetchColumn();
$total_bookings_stmt = $selected_location_id
    ? $pdo->prepare('SELECT COUNT(*) FROM bookings b JOIN parking_slots ps ON ps.id = b.slot_id WHERE ps.location_id = ?')
    : $pdo->prepare('SELECT COUNT(*) FROM bookings');
$total_bookings_stmt->execute($selected_location_id ? [$selected_location_id] : []);
$total_bookings = $total_bookings_stmt->fetchColumn();
$payment_scope = $selected_location_id ? 'WHERE ps.location_id = ?' : '';
$payment_params = $selected_location_id ? [$selected_location_id] : [];
$payment_stats_stmt = $pdo->prepare("SELECT COUNT(p.id) AS total_payments,
    SUM(p.payment_status = 'pending') AS pending_payments,
    SUM(p.payment_status = 'completed') AS completed_payments,
    SUM(p.payment_method = 'upi') AS upi_payments,
    SUM(p.payment_method = 'cash_on_delivery') AS cash_payments,
    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount ELSE 0 END), 0) AS completed_total,
    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' AND p.paid_at >= CURDATE() THEN p.amount ELSE 0 END), 0) AS today_total
    FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN parking_slots ps ON ps.id = b.slot_id $payment_scope");
$payment_stats_stmt->execute($payment_params);
$payment_stats = $payment_stats_stmt->fetch(PDO::FETCH_ASSOC);

$pending_accounts = $pdo->query("SELECT id, name, email, role, account_status, created_at FROM users WHERE role IN ('user', 'staff') AND account_status='pending' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$trend_sql = "SELECT b.booking_date, COUNT(*) AS total FROM bookings b JOIN parking_slots ps ON ps.id = b.slot_id
    WHERE b.booking_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()";
$location_clause = '';
$location_where_clause = '';
if ($selected_location_id) {
    $location_clause = ' AND ps.location_id = ' . (int) $selected_location_id;
    $location_where_clause = 'WHERE ps.location_id = ' . (int) $selected_location_id;
}
$trend_rows = $pdo->query($trend_sql . $location_clause . ' GROUP BY b.booking_date ORDER BY b.booking_date')->fetchAll(PDO::FETCH_ASSOC);
$trend_map = array_column($trend_rows, 'total', 'booking_date');
$trend_labels = [];
$trend_values = [];
for ($days_ago = 6; $days_ago >= 0; $days_ago--) {
    $date = date('Y-m-d', strtotime('-' . $days_ago . ' days'));
    $trend_labels[] = date('M j', strtotime($date));
    $trend_values[] = (int) ($trend_map[$date] ?? 0);
}
$status_rows = $pdo->query("SELECT b.status, COUNT(*) AS total FROM bookings b JOIN parking_slots ps ON ps.id = b.slot_id $location_where_clause GROUP BY b.status")->fetchAll(PDO::FETCH_KEY_PAIR);
$unassigned_staff = $pdo->query("SELECT id, name, email, account_status FROM users WHERE role = 'staff' AND location_id IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$status_labels = ['Pending', 'Confirmed', 'Active', 'Completed', 'Cancelled', 'Rejected'];
$status_values = [
    (int) ($status_rows['pending'] ?? 0),
    (int) ($status_rows['confirmed'] ?? 0),
    (int) ($status_rows['active'] ?? 0),
    (int) ($status_rows['completed'] ?? 0),
    (int) ($status_rows['cancelled'] ?? 0),
    (int) ($status_rows['rejected'] ?? 0),
];

$page_title = "Admin Dashboard";
include '../includes/header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-gauge"></i> Admin Dashboard</h3>
<?php if ($admin_message): ?><div class="alert alert-info"><?php echo htmlspecialchars($admin_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

<form method="GET" class="card p-3 mb-4"><div class="row g-2 align-items-end"><div class="col-md-5"><label for="dashboardLocation" class="form-label">Dashboard location</label><select id="dashboardLocation" name="location_id" class="form-select"><option value="all" <?php echo !$selected_location_id ? 'selected' : ''; ?>>All Locations</option><?php foreach ($locations as $location): ?><option value="<?php echo (int) $location['id']; ?>" <?php echo $selected_location_id === (int) $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply</button></div><div class="col-md-5 text-md-end"><a class="btn btn-outline-primary" href="manage_staff.php">Staff Management</a></div></div></form>

<div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
    <div class="col"><div class="card p-3 text-center"><i class="fa-solid fa-users fa-2x text-primary mb-2"></i><h4><?php echo $total_users; ?></h4><p class="text-muted mb-0">Total Users</p></div></div>
    <div class="col"><div class="card p-3 text-center"><i class="fa-solid fa-user-tie fa-2x text-success mb-2"></i><h4><?php echo $total_staff; ?></h4><p class="text-muted mb-0">Total Staff</p></div></div>
    <div class="col"><div class="card p-3 text-center"><i class="fa-solid fa-hourglass-half fa-2x text-warning mb-2"></i><h4><?php echo $pending_users + $pending_staff; ?></h4><p class="text-muted mb-0">Pending Approvals</p></div></div>
    <div class="col"><div class="card p-3 text-center"><i class="fa-solid fa-map-location-dot fa-2x text-success mb-2"></i><h4><?php echo $total_locations; ?></h4><p class="text-muted mb-0">Locations</p></div></div>
    <div class="col"><div class="card p-3 text-center"><i class="fa-solid fa-square-parking fa-2x text-warning mb-2"></i><h4><?php echo $total_slots; ?></h4><p class="text-muted mb-0">Parking Slots</p></div></div>
    <div class="col"><div class="card p-3 text-center"><i class="fa-solid fa-clipboard-list fa-2x text-secondary mb-2"></i><h4><?php echo $total_bookings; ?></h4><p class="text-muted mb-0">Total Bookings</p></div></div>
</div>

<section class="mb-4" aria-labelledby="paymentOverviewHeading">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="h5 mb-0" id="paymentOverviewHeading">Payment Overview</h4>
        <a class="btn btn-sm btn-outline-primary" href="manage_payments.php">View payment records</a>
    </div>
    <div class="row row-cols-2 row-cols-lg-3 row-cols-xxl-6 g-3">
        <div class="col"><div class="card p-3 h-100"><small class="text-muted">Total payments</small><strong class="fs-5" data-admin-payment-metric="total_payments"><?php echo (int) $payment_stats['total_payments']; ?></strong></div></div>
        <div class="col"><div class="card p-3 h-100"><small class="text-muted">Pending</small><strong class="fs-5 text-warning-emphasis" data-admin-payment-metric="pending_payments"><?php echo (int) $payment_stats['pending_payments']; ?></strong></div></div>
        <div class="col"><div class="card p-3 h-100"><small class="text-muted">Completed</small><strong class="fs-5 text-success" data-admin-payment-metric="completed_payments"><?php echo (int) $payment_stats['completed_payments']; ?></strong></div></div>
        <div class="col"><div class="card p-3 h-100"><small class="text-muted">UPI payments</small><strong class="fs-5" data-admin-payment-metric="upi_payments"><?php echo (int) $payment_stats['upi_payments']; ?></strong></div></div>
        <div class="col"><div class="card p-3 h-100"><small class="text-muted">Cash payments</small><strong class="fs-5" data-admin-payment-metric="cash_payments"><?php echo (int) $payment_stats['cash_payments']; ?></strong></div></div>
        <div class="col"><div class="card p-3 h-100"><small class="text-muted">Completed total / today</small><strong class="fs-6">₹<span data-admin-payment-metric="completed_total"><?php echo number_format((float) $payment_stats['completed_total'], 2); ?></span> / ₹<span data-admin-payment-metric="today_total"><?php echo number_format((float) $payment_stats['today_total'], 2); ?></span></strong></div></div>
    </div>
</section>

<section class="card p-3 mb-4" aria-labelledby="unassignedStaffHeading">
    <div class="d-flex justify-content-between align-items-center gap-2"><div><h4 class="h5 mb-1" id="unassignedStaffHeading">Unassigned Staff</h4><p class="text-muted mb-0">Staff cannot access operational pages until assigned a location.</p></div><a class="btn btn-sm btn-outline-primary" href="manage_staff.php">Location Assignment</a></div>
    <?php if ($unassigned_staff): ?><div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>Staff</th><th>Email</th><th>Status</th><th>Location</th></tr></thead><tbody><?php foreach ($unassigned_staff as $staff): ?><tr><td><?php echo htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($staff['email'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(ucfirst($staff['account_status']), ENT_QUOTES, 'UTF-8'); ?></td><td>Not Assigned</td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="small text-muted mt-3 mb-0">All Staff accounts have a location assignment.</p><?php endif; ?>
</section>

<div class="card p-4 mb-4">
    <h5 class="mb-3"><i class="fa-solid fa-user-check me-2"></i>Pending Approvals</h5>
    <?php if (empty($pending_accounts)): ?>
        <div class="text-muted">No accounts are waiting for approval.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered align-middle bg-white mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Registration Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_accounts as $account): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge text-bg-light text-dark"><?php echo strtoupper(htmlspecialchars($account['role'], ENT_QUOTES, 'UTF-8')); ?></span></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($account['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge text-bg-warning text-dark">PENDING</span></td>
                            <td class="text-nowrap">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Approve this account?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="approval_action" value="approve">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $account['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success me-1">Approve</button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Reject this account?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="approval_action" value="reject">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $account['id']; ?>">
                                    <input type="text" name="rejection_reason" class="form-control form-control-sm d-inline-block mb-1" style="width:170px;" placeholder="Reason (optional)">
                                    <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8"><div class="card p-3"><h5>Bookings · Last 7 Days</h5><canvas id="bookingTrend" height="100" aria-label="Bookings during the last seven days"></canvas></div></div>
    <div class="col-lg-4"><div class="card p-3"><h5>Booking Status</h5><canvas id="bookingStatus" height="190" aria-label="Booking status distribution"></canvas></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const trendLabels = <?php echo json_encode($trend_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const trendValues = <?php echo json_encode($trend_values); ?>;
const statusValues = <?php echo json_encode($status_values); ?>;
new Chart(document.getElementById('bookingTrend'), {
    type: 'line',
    data: { labels: trendLabels, datasets: [{ label: 'Bookings', data: trendValues, borderColor: '#187b78', backgroundColor: 'rgba(24, 123, 120, .12)', fill: true, tension: .28 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
new Chart(document.getElementById('bookingStatus'), {
    type: 'doughnut',
    data: { labels: <?php echo json_encode($status_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, datasets: [{ data: statusValues, backgroundColor: ['#d69e2e', '#187b78', '#4e8c69', '#73817e', '#c94c4c', '#8c564b'] }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
async function refreshAdminPaymentMetrics() {
    try {
        const selectedLocation = <?php echo json_encode($selected_location_id); ?>;
        const endpoint = '../api/admin_payment_metrics.php' + (selectedLocation ? '?location_id=' + encodeURIComponent(selectedLocation) : '');
        const response = await fetch(endpoint, { cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const metrics = await response.json();
        document.querySelectorAll('[data-admin-payment-metric]').forEach(node => {
            const value = metrics[node.dataset.adminPaymentMetric];
            if (value === undefined) return;
            node.textContent = node.dataset.adminPaymentMetric.endsWith('_total') ? Number(value).toFixed(2) : value;
        });
    } catch (error) {
        console.error('Could not refresh admin payment metrics.', error);
    }
}
window.setInterval(refreshAdminPaymentMetrics, 15000);
</script>
<?php include '../includes/footer.php'; ?>
