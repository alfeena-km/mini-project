<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_slot'])) {
    require_valid_csrf_post();
    $stmt = $pdo->prepare("INSERT INTO parking_slots (location_id, slot_number, slot_type, price_per_hour, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['location_id'], $_POST['slot_number'], $_POST['slot_type'], $_POST['price_per_hour'], $_POST['status']]);
    $message = "Slot added successfully.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_slot_status'], $_POST['slot_id'])) {
    require_valid_csrf_post();
    $slot_id = filter_var($_POST['slot_id'], FILTER_VALIDATE_INT);
    $status = $_POST['set_slot_status'];
    if ($slot_id && in_array($status, ['available', 'maintenance'], true)) {
        $stmt = $pdo->prepare('UPDATE parking_slots SET status = ? WHERE id = ?');
        $stmt->execute([$status, $slot_id]);
        $message = 'Slot status updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_slot_id'])) {
    require_valid_csrf_post();
    $stmt = $pdo->prepare("UPDATE parking_slots SET location_id=?, slot_number=?, slot_type=?, price_per_hour=?, status=? WHERE id=?");
    $stmt->execute([$_POST['location_id'], $_POST['slot_number'], $_POST['slot_type'], $_POST['price_per_hour'], $_POST['status'], $_POST['edit_slot_id']]);
    $message = "Slot updated.";
}

$locations = $pdo->query("SELECT * FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$location_filter = $_GET['location_id'] ?? 'all';
$selected_location_id = filter_var($location_filter, FILTER_VALIDATE_INT);
if ($location_filter !== 'all' && (!$selected_location_id || !in_array($selected_location_id, array_map('intval', array_column($locations, 'id')), true))) {
    $location_filter = 'all';
    $selected_location_id = null;
}
$slots_sql = "SELECT ps.*, l.name AS location_name FROM parking_slots ps JOIN locations l ON ps.location_id = l.id";
if ($selected_location_id) {
    $slots_sql .= ' WHERE ps.location_id = ' . (int) $selected_location_id;
}
$slots = $pdo->query($slots_sql . ' ORDER BY ps.id DESC')->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Slots";
include '../includes/header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-square-parking"></i> Manage Parking Slots</h3>
<?php if ($message): ?><div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<form method="GET" class="card p-3 mb-3"><div class="row g-2 align-items-end"><div class="col-md-5"><label for="slotLocationFilter" class="form-label">Filter slots by location</label><select id="slotLocationFilter" name="location_id" class="form-select"><option value="all">All Locations</option><?php foreach ($locations as $loc): ?><option value="<?php echo (int) $loc['id']; ?>" <?php echo $selected_location_id === (int) $loc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filter</button></div></div></form>

<div class="card p-4 mb-4">
    <h5>Add New Slot</h5>
    <form method="POST" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-3">
            <select name="location_id" class="form-select" required>
                <option value="">-- Location --</option>
                <?php foreach ($locations as $loc): if ($loc['status'] !== 'active') continue; ?>
                    <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><input type="text" name="slot_number" class="form-control" placeholder="Slot No. e.g A1" required></div>
        <div class="col-md-2">
            <select name="slot_type" class="form-select">
                <option value="Car">Car</option>
                <option value="Bike">Bike</option>
                <option value="EV">EV</option>
            </select>
        </div>
        <div class="col-md-2"><input type="number" step="0.01" name="price_per_hour" class="form-control" placeholder="Price/hr" required></div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="available">Available</option>
                <option value="maintenance">Maintenance</option>
            </select>
        </div>
        <div class="col-md-1"><button type="submit" name="add_slot" class="btn btn-primary w-100">Add</button></div>
    </form>
</div>

<table class="table table-bordered bg-white">
<thead class="table-dark"><tr><th>ID</th><th>Location</th><th>Slot No.</th><th>Type</th><th>Price/hr</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($slots as $slot): ?>
<tr>
    <td><?php echo $slot['id']; ?></td>
    <td><?php echo htmlspecialchars($slot['location_name']); ?></td>
    <td><?php echo htmlspecialchars($slot['slot_number']); ?></td>
    <td><?php echo htmlspecialchars($slot['slot_type']); ?></td>
    <td>₹<?php echo number_format($slot['price_per_hour'], 2); ?></td>
    <td><?php echo ucfirst($slot['status']); ?></td>
    <td>
        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editSlot<?php echo $slot['id']; ?>">Edit</button>
        <form method="POST" class="d-inline" onsubmit="return confirm('Change this slot status?');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="slot_id" value="<?php echo (int) $slot['id']; ?>">
            <input type="hidden" name="set_slot_status" value="<?php echo $slot['status'] === 'available' ? 'maintenance' : 'available'; ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?php echo $slot['status'] === 'available' ? 'danger' : 'success'; ?>"><?php echo $slot['status'] === 'available' ? 'Set Maintenance' : 'Set Available'; ?></button>
        </form>
    </td>
</tr>
<div class="modal fade" id="editSlot<?php echo $slot['id']; ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="modal-header"><h5 class="modal-title">Edit Slot</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="edit_slot_id" value="<?php echo $slot['id']; ?>">
        <div class="mb-2"><label class="form-label">Location</label>
            <select name="location_id" class="form-select" required>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['id']; ?>" <?php echo $loc['id']==$slot['location_id']?'selected':''; ?>><?php echo htmlspecialchars($loc['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2"><label class="form-label">Slot Number</label><input type="text" name="slot_number" class="form-control" value="<?php echo htmlspecialchars($slot['slot_number']); ?>" required></div>
        <div class="mb-2"><label class="form-label">Type</label>
            <select name="slot_type" class="form-select">
                <?php foreach (['Car','Bike','EV'] as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $t==$slot['slot_type']?'selected':''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2"><label class="form-label">Price/hr</label><input type="number" step="0.01" name="price_per_hour" class="form-control" value="<?php echo $slot['price_per_hour']; ?>" required></div>
        <div class="mb-2"><label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="available" <?php echo $slot['status']=='available'?'selected':''; ?>>Available</option>
                <option value="maintenance" <?php echo $slot['status']=='maintenance'?'selected':''; ?>>Maintenance</option>
            </select>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
</tbody>
</table>

<?php include '../includes/footer.php'; ?>
