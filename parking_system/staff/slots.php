<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();
$staff_location_id = (int) $_SESSION['staff_location_id'];

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['slot_id'], $_POST['status'])) {
    require_valid_csrf_post();
    $slot_id = filter_var($_POST['slot_id'], FILTER_VALIDATE_INT);
    $status = $_POST['status'];
    if (!$slot_id || !in_array($status, ['available', 'maintenance'], true)) {
        $error = 'Invalid parking slot status.';
    } else {
        $owner = $pdo->prepare('SELECT id FROM parking_slots WHERE id = ? AND location_id = ?');
        $owner->execute([$slot_id, $staff_location_id]);
        if (!$owner->fetchColumn()) {
            http_response_code(403);
            $error = 'You are not authorized to manage this location.';
        } else {
            $stmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM bookings b WHERE b.slot_id = ? AND b.status = 'active')");
            $stmt->execute([$slot_id]);
            if ($status === 'maintenance' && $stmt->fetchColumn()) {
                $error = 'An occupied slot cannot be set to maintenance.';
            } else {
                $stmt = $pdo->prepare('UPDATE parking_slots SET status = ? WHERE id = ? AND location_id = ?');
                $stmt->execute([$status, $slot_id, $staff_location_id]);
                $message = $stmt->rowCount() ? 'Slot status updated.' : 'Slot status was unchanged.';
            }
        }
    }
}

$filter = $_GET['status'] ?? 'all';
if (!in_array($filter, ['all', 'available', 'occupied', 'reserved', 'maintenance'], true)) {
    $filter = 'all';
}
$slots = $pdo->query("SELECT ps.id, ps.slot_number, ps.slot_type, ps.status AS configured_status,
        l.name AS location_name, l.address,
        CASE
            WHEN ps.status = 'maintenance' THEN 'maintenance'
            WHEN EXISTS (SELECT 1 FROM bookings active_booking WHERE active_booking.slot_id = ps.id AND active_booking.status = 'active') THEN 'occupied'
            WHEN EXISTS (SELECT 1 FROM bookings reserved_booking WHERE reserved_booking.slot_id = ps.id AND reserved_booking.status = 'confirmed' AND TIMESTAMP(reserved_booking.booking_date, reserved_booking.end_time) > NOW()) THEN 'reserved'
            ELSE 'available'
        END AS current_status,
        (SELECT CONCAT('#', current_booking.id, ' · ', current_booking.vehicle_number)
         FROM bookings current_booking WHERE current_booking.slot_id = ps.id AND (current_booking.status = 'active' OR (current_booking.status = 'confirmed' AND TIMESTAMP(current_booking.booking_date, current_booking.end_time) > NOW()))
         ORDER BY current_booking.status = 'active' DESC, current_booking.booking_date, current_booking.start_time LIMIT 1) AS current_booking
    FROM parking_slots ps JOIN locations l ON l.id = ps.location_id WHERE ps.location_id = $staff_location_id ORDER BY ps.slot_number")->fetchAll(PDO::FETCH_ASSOC);
if ($filter !== 'all') {
    $slots = array_values(array_filter($slots, static function ($slot) use ($filter) {
        return $slot['current_status'] === $filter;
    }));
}
$status_colors = ['available' => 'success', 'occupied' => 'danger', 'reserved' => 'primary', 'maintenance' => 'secondary'];

$page_title = 'Staff Parking Slots';
include '../includes/header.php';
include '../includes/staff_sidebar.php';
?>
<h1 class="h3 mb-3">Parking Slots</h1>
<?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<nav class="nav nav-pills mb-3" aria-label="Slot status filters"><?php foreach (['all' => 'All', 'available' => 'Available', 'occupied' => 'Occupied', 'reserved' => 'Reserved', 'maintenance' => 'Maintenance'] as $value => $label): ?><a class="nav-link<?php echo $filter === $value ? ' active' : ''; ?>" href="?status=<?php echo $value; ?>"><?php echo $label; ?></a><?php endforeach; ?></nav>
<div class="table-responsive"><table class="table table-bordered table-hover bg-white align-middle">
    <thead class="table-dark"><tr><th>Location</th><th>Floor</th><th>Slot</th><th>Vehicle Type</th><th>Current Status</th><th>Current Booking</th><th>Operational Action</th></tr></thead>
    <tbody><?php if (!$slots): ?><tr><td colspan="7" class="text-center text-muted py-4">No slots match this status.</td></tr><?php endif; ?>
    <?php foreach ($slots as $slot): ?><tr>
        <td><?php echo htmlspecialchars($slot['location_name'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars($slot['address'], ENT_QUOTES, 'UTF-8'); ?></small></td>
        <td>—</td><td><?php echo htmlspecialchars($slot['slot_number'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($slot['slot_type'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><span class="badge text-bg-<?php echo $status_colors[$slot['current_status']]; ?>"><?php echo ucfirst($slot['current_status']); ?></span></td>
        <td><?php echo htmlspecialchars($slot['current_booking'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php if (in_array($slot['configured_status'], ['available', 'maintenance'], true)): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="slot_id" value="<?php echo (int) $slot['id']; ?>"><input type="hidden" name="status" value="<?php echo $slot['configured_status'] === 'maintenance' ? 'available' : 'maintenance'; ?>"><button class="btn btn-sm btn-outline-<?php echo $slot['configured_status'] === 'maintenance' ? 'success' : 'secondary'; ?>" type="submit"><?php echo $slot['configured_status'] === 'maintenance' ? 'Set Available' : 'Set Maintenance'; ?></button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<p class="small text-muted">Floor is not stored in the current locations or slot schema.</p>
</main>
</div>
<?php include '../includes/footer.php'; ?>