<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$message = '';

// Add location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_location'])) {
  require_valid_csrf_post();
  $stmt = $pdo->prepare("INSERT INTO locations (name, address, city) VALUES (?, ?, ?)");
    $stmt->execute([$_POST['name'], $_POST['address'], $_POST['city']]);
    $message = "Location added successfully.";
}

// Change location availability without cascading into slots or booking records.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_location_status'], $_POST['location_id'])) {
  require_valid_csrf_post();
  $location_id = filter_var($_POST['location_id'], FILTER_VALIDATE_INT);
  $status = $_POST['set_location_status'];
  if ($location_id && in_array($status, ['active', 'inactive'], true)) {
    $stmt = $pdo->prepare('UPDATE locations SET status = ? WHERE id = ?');
    $stmt->execute([$status, $location_id]);
    $message = $status === 'active' ? 'Location activated.' : 'Location deactivated.';
  }
}

// Edit location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_location_id'])) {
  require_valid_csrf_post();
    $stmt = $pdo->prepare("UPDATE locations SET name=?, address=?, city=? WHERE id=?");
    $stmt->execute([$_POST['name'], $_POST['address'], $_POST['city'], $_POST['edit_location_id']]);
    $message = "Location updated.";
}

$locations = $pdo->query("SELECT l.*, COUNT(DISTINCT ps.id) AS slot_count,
    COUNT(DISTINCT staff.id) AS staff_count,
    GROUP_CONCAT(DISTINCT staff.name ORDER BY staff.name SEPARATOR ', ') AS staff_names
  FROM locations l
  LEFT JOIN parking_slots ps ON ps.location_id = l.id
  LEFT JOIN users staff ON staff.location_id = l.id AND staff.role = 'staff'
  GROUP BY l.id
  ORDER BY l.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Locations";
include '../includes/header.php';
?>

<h3 class="mb-4"><i class="fa-solid fa-map-location-dot"></i> Manage Parking Locations</h3>
<?php if ($message): ?><div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="card p-4 mb-4">
    <h5>Add New Location</h5>
    <form method="POST" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="col-md-4"><input type="text" name="name" class="form-control" placeholder="Location Name" required></div>
        <div class="col-md-4"><input type="text" name="address" class="form-control" placeholder="Address" required></div>
        <div class="col-md-3"><input type="text" name="city" class="form-control" placeholder="City" required></div>
        <div class="col-md-1"><button type="submit" name="add_location" class="btn btn-primary w-100">Add</button></div>
    </form>
</div>

<table class="table table-bordered bg-white">
<thead class="table-dark"><tr><th>ID</th><th>Name</th><th>Address</th><th>City</th><th>Assigned Staff</th><th>Slots</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($locations as $loc): ?>
<tr>
    <td><?php echo $loc['id']; ?></td>
    <td><?php echo htmlspecialchars($loc['name']); ?></td>
    <td><?php echo htmlspecialchars($loc['address']); ?></td>
    <td><?php echo htmlspecialchars($loc['city']); ?></td>
    <td><?php echo htmlspecialchars($loc['staff_names'] ?: '—', ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo (int) $loc['staff_count']; ?> staff</small></td>
    <td><?php echo (int) $loc['slot_count']; ?></td>
    <td><?php echo htmlspecialchars(ucfirst($loc['status'])); ?></td>
    <td>
        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $loc['id']; ?>">Edit</button>
      <form method="POST" class="d-inline" onsubmit="return confirm('Change this location status?');">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="location_id" value="<?php echo (int) $loc['id']; ?>">
        <input type="hidden" name="set_location_status" value="<?php echo $loc['status'] === 'active' ? 'inactive' : 'active'; ?>">
        <button type="submit" class="btn btn-sm btn-outline-<?php echo $loc['status'] === 'active' ? 'danger' : 'success'; ?>"><?php echo $loc['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
      </form>
    </td>
</tr>
<div class="modal fade" id="editModal<?php echo $loc['id']; ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="modal-header"><h5 class="modal-title">Edit Location</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="edit_location_id" value="<?php echo $loc['id']; ?>">
        <div class="mb-2"><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($loc['name']); ?>" required></div>
        <div class="mb-2"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($loc['address']); ?>" required></div>
        <div class="mb-2"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($loc['city']); ?>" required></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
</tbody>
</table>

<?php include '../includes/footer.php'; ?>
