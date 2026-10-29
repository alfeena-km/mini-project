<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$default_start = date('Y-m-01');
$default_end = date('Y-m-d');
$start_date = trim($_GET['start_date'] ?? $default_start);
$end_date = trim($_GET['end_date'] ?? $default_end);
$is_valid_date = static function ($value) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
$filter_error = '';
if (!$is_valid_date($start_date) || !$is_valid_date($end_date) || $start_date > $end_date) {
    $filter_error = 'Choose a valid date range with the start date on or before the end date.';
    $start_date = $default_start;
    $end_date = $default_end;
}
$locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$location_filter = $_GET['location_id'] ?? 'all';
$selected_location_id = filter_var($location_filter, FILTER_VALIDATE_INT);
if ($location_filter !== 'all' && (!$selected_location_id || !in_array($selected_location_id, array_map('intval', array_column($locations, 'id')), true))) {
    $location_filter = 'all';
    $selected_location_id = null;
}
$location_sql = $selected_location_id ? ' AND ps.location_id = ' . (int) $selected_location_id : '';

if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("SELECT b.booking_code, u.name AS user_name, u.email, l.name AS location_name,
            ps.slot_number, b.vehicle_number, b.booking_date, b.start_time, b.end_time,
            b.total_fee, b.status, b.payment_status,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.id AND p.payment_status = 'completed'), 0) AS amount_paid
        FROM bookings b
        JOIN users u ON u.id = b.user_id
        JOIN parking_slots ps ON ps.id = b.slot_id
        JOIN locations l ON l.id = ps.location_id
        WHERE b.booking_date BETWEEN ? AND ?$location_sql
        ORDER BY b.booking_date, b.start_time, b.id");
    $stmt->execute([$start_date, $end_date]);

    $safe_csv_value = static function ($value) {
        $value = (string) ($value ?? '');
        return preg_match('/^[\t\r\n ]*[=+\-@]/', $value) ? "'" . $value : $value;
    };

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parking-bookings-' . $start_date . '-to-' . $end_date . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Booking Code', 'User', 'Email', 'Location', 'Slot', 'Vehicle', 'Date', 'Start Time', 'End Time', 'Booking Fee', 'Amount Paid', 'Booking Status', 'Payment Status']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, array_map($safe_csv_value, array_values($row)));
    }
    fclose($output);
    exit;
}

$stmt = $pdo->prepare("SELECT l.name AS location_name, COUNT(DISTINCT b.id) AS total_bookings,
        (SELECT COALESCE(SUM(p.amount), 0)
         FROM payments p
         JOIN bookings pb ON pb.id = p.booking_id
         JOIN parking_slots pps ON pps.id = pb.slot_id
         WHERE pps.location_id = l.id AND p.payment_status = 'completed' AND pb.booking_date BETWEEN ? AND ?) AS revenue
    FROM locations l
    LEFT JOIN parking_slots ps ON ps.location_id = l.id
    LEFT JOIN bookings b ON b.slot_id = ps.id AND b.booking_date BETWEEN ? AND ?
    WHERE (? IS NULL OR l.id = ?)
    GROUP BY l.id, l.name
    ORDER BY revenue DESC, l.name");
$stmt->execute([$start_date, $end_date, $start_date, $end_date, $selected_location_id, $selected_location_id]);
$by_location = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT b.status, COUNT(*) AS cnt FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE b.booking_date BETWEEN ? AND ?$location_sql GROUP BY b.status");
$stmt->execute([$start_date, $end_date]);
$by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT ps.slot_type, COUNT(b.id) AS cnt
    FROM parking_slots ps
    LEFT JOIN bookings b ON b.slot_id = ps.id AND b.booking_date BETWEEN ? AND ?
    WHERE (? IS NULL OR ps.location_id = ?)
    GROUP BY ps.slot_type ORDER BY ps.slot_type");
$stmt->execute([$start_date, $end_date, $selected_location_id, $selected_location_id]);
$by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT b.booking_date, COALESCE(SUM(p.amount), 0) AS revenue
    FROM bookings b
    JOIN parking_slots ps ON ps.id = b.slot_id
    LEFT JOIN payments p ON p.booking_id = b.id AND p.payment_status = 'completed'
    WHERE b.booking_date BETWEEN ? AND ?$location_sql
    GROUP BY b.booking_date
    ORDER BY b.booking_date DESC");
$stmt->execute([$start_date, $end_date]);
$daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

$location_labels = array_column($by_location, 'location_name');
$location_bookings = array_map('intval', array_column($by_location, 'total_bookings'));
$location_revenue = array_map('floatval', array_column($by_location, 'revenue'));
$status_map = array_column($by_status, 'cnt', 'status');
$status_labels = ['Pending', 'Confirmed', 'Active', 'Completed', 'Cancelled', 'Rejected'];
$status_values = [
    (int) ($status_map['pending'] ?? 0),
    (int) ($status_map['confirmed'] ?? 0),
    (int) ($status_map['active'] ?? 0),
    (int) ($status_map['completed'] ?? 0),
    (int) ($status_map['cancelled'] ?? 0),
    (int) ($status_map['rejected'] ?? 0),
];
$type_labels = array_column($by_type, 'slot_type');
$type_values = array_map('intval', array_column($by_type, 'cnt'));
$daily = array_reverse($daily);
$daily_labels = array_column($daily, 'booking_date');
$daily_revenue = array_map('floatval', array_column($daily, 'revenue'));

$page_title = "Reports & Analytics";
include '../includes/header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</h3>

<?php if ($filter_error): ?><div class="alert alert-warning" role="alert"><?php echo htmlspecialchars($filter_error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<form method="GET" class="card p-3 mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-sm-4"><label for="start_date" class="form-label">From</label><input id="start_date" type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="col-sm-3"><label for="end_date" class="form-label">To</label><input id="end_date" type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="col-sm-3"><label for="reportLocation" class="form-label">Location</label><select id="reportLocation" name="location_id" class="form-select"><option value="all">All Locations</option><?php foreach ($locations as $location): ?><option value="<?php echo (int) $location['id']; ?>" <?php echo $selected_location_id === (int) $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-3 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter me-1"></i> Apply</button><button type="submit" name="export" value="csv" class="btn btn-outline-success"><i class="fa-solid fa-file-csv me-1"></i> Export CSV</button><button type="button" class="btn btn-outline-secondary" onclick="window.print()" aria-label="Print report"><i class="fa-solid fa-print"></i></button></div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-lg-8"><div class="card p-3"><h5>Successful Revenue by Day</h5><div style="height:260px"><canvas id="reportRevenue" aria-label="Successful revenue by booking date"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card p-3"><h5>Bookings by Status</h5><div style="height:260px"><canvas id="reportStatus" aria-label="Bookings by status"></canvas></div></div></div>
    <div class="col-lg-8"><div class="card p-3"><h5>Location Performance</h5><div style="height:320px"><canvas id="reportLocations" aria-label="Bookings and revenue by location"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card p-3"><h5>Bookings by Slot Type</h5><div style="height:320px"><canvas id="reportTypes" aria-label="Bookings by slot type"></canvas></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card p-3">
            <h5>Revenue & Bookings by Location</h5>
            <table class="table table-sm">
                <thead><tr><th>Location</th><th>Bookings</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($by_location as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['location_name']); ?></td>
                        <td><?php echo $row['total_bookings']; ?></td>
                        <td>₹<?php echo number_format($row['revenue'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card p-3">
            <h5>Bookings by Status</h5>
            <table class="table table-sm">
                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                <tbody>
                <?php foreach ($by_status as $row): ?>
                    <tr><td><?php echo ucfirst($row['status']); ?></td><td><?php echo $row['cnt']; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card p-3">
            <h5>Bookings by Vehicle/Slot Type</h5>
            <table class="table table-sm">
                <thead><tr><th>Type</th><th>Bookings</th></tr></thead>
                <tbody>
                <?php foreach ($by_type as $row): ?>
                    <tr><td><?php echo htmlspecialchars($row['slot_type']); ?></td><td><?php echo $row['cnt']; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card p-3">
            <h5>Recent Daily Revenue</h5>
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($daily as $row): ?>
                    <tr><td><?php echo $row['booking_date']; ?></td><td>₹<?php echo number_format($row['revenue'], 2); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const reportRevenueLabels = <?php echo json_encode($daily_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const reportRevenueValues = <?php echo json_encode($daily_revenue); ?>;
const reportStatusLabels = <?php echo json_encode($status_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const reportStatusValues = <?php echo json_encode($status_values); ?>;
const reportLocationLabels = <?php echo json_encode($location_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const reportLocationBookings = <?php echo json_encode($location_bookings); ?>;
const reportLocationRevenue = <?php echo json_encode($location_revenue); ?>;
const reportTypeLabels = <?php echo json_encode($type_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const reportTypeValues = <?php echo json_encode($type_values); ?>;

new Chart(document.getElementById('reportRevenue'), {
    type: 'bar',
    data: { labels: reportRevenueLabels, datasets: [{ label: 'Successful revenue', data: reportRevenueValues, backgroundColor: '#187b78' }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: value => '₹' + value } } } }
});
new Chart(document.getElementById('reportStatus'), {
    type: 'doughnut',
    data: { labels: reportStatusLabels, datasets: [{ data: reportStatusValues, backgroundColor: ['#187b78', '#73817e', '#c94c4c'] }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});
new Chart(document.getElementById('reportLocations'), {
    type: 'bar',
    data: { labels: reportLocationLabels, datasets: [
        { label: 'Bookings', data: reportLocationBookings, backgroundColor: '#187b78', xAxisID: 'xBookings' },
        { label: 'Successful revenue (₹)', data: reportLocationRevenue, backgroundColor: '#dc8b43', xAxisID: 'xRevenue' }
    ] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: {
        xBookings: { type: 'linear', position: 'bottom', beginAtZero: true, title: { display: true, text: 'Bookings' } },
        xRevenue: { type: 'linear', position: 'top', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Revenue (₹)' } }
    } }
});
new Chart(document.getElementById('reportTypes'), {
    type: 'doughnut',
    data: { labels: reportTypeLabels, datasets: [{ data: reportTypeValues, backgroundColor: ['#187b78', '#dc8b43', '#5b7fa3'] }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php include '../includes/footer.php'; ?>
