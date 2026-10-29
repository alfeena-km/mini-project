<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

$message = '';
$error = '';
$active_locations = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$location_filter = $_GET['location_id'] ?? 'all';
$selected_location_id = filter_var($location_filter, FILTER_VALIDATE_INT);
if ($location_filter !== 'all' && (!$selected_location_id || !in_array($selected_location_id, array_map('intval', array_column($all_locations, 'id')), true))) {
    $location_filter = 'all';
    $selected_location_id = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_post();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_staff') {
            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $location_id = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$location_id || strlen($password) < 6 || $password !== $confirm_password) {
                throw new RuntimeException('Enter valid staff details, an active location, and matching passwords of at least six characters.');
            }
            if ($phone !== '' && !preg_match('/^[0-9]{10}$/', $phone)) {
                throw new RuntimeException('Enter a valid 10-digit phone number.');
            }

            $pdo->beginTransaction();
            $location_stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ? AND status = 'active' FOR UPDATE");
            $location_stmt->execute([$location_id]);
            $location_name = (string) $location_stmt->fetchColumn();
            if ($location_name === '') {
                throw new RuntimeException('This parking location is currently unavailable.');
            }
            $existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $existing->execute([$email]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('An account with this email already exists.');
            }
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, account_status, location_id) VALUES (?, ?, ?, ?, 'staff', 'pending', ?)");
            $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $location_id]);
            $pdo->commit();
            $message = 'Staff account created for ' . $location_name . '. It is pending admin approval.';
        } elseif ($action === 'assign_location') {
            $staff_id = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
            $raw_location_id = $_POST['location_id'] ?? '';
            $location_id = $raw_location_id === '' ? null : filter_var($raw_location_id, FILTER_VALIDATE_INT);
            if (!$staff_id || ($raw_location_id !== '' && !$location_id)) {
                throw new RuntimeException('Select a valid staff member and parking location.');
            }

            $pdo->beginTransaction();
            $staff_stmt = $pdo->prepare("SELECT id, name, location_id FROM users WHERE id = ? AND role = 'staff' FOR UPDATE");
            $staff_stmt->execute([$staff_id]);
            $staff = $staff_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$staff) {
                throw new RuntimeException('Staff account not found.');
            }
            $old_location_name = 'Not Assigned';
            if ($staff['location_id'] !== null) {
                $old_location = $pdo->prepare('SELECT name FROM locations WHERE id = ?');
                $old_location->execute([$staff['location_id']]);
                $old_location_name = $old_location->fetchColumn() ?: 'Not Assigned';
            }
            $new_location_name = 'Not Assigned';
            if ($location_id !== null) {
                $location_stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ? AND status = 'active' FOR UPDATE");
                $location_stmt->execute([$location_id]);
                $new_location_name = (string) $location_stmt->fetchColumn();
                if ($new_location_name === '') {
                    throw new RuntimeException('This parking location is currently unavailable.');
                }
            }
            $stmt = $pdo->prepare("UPDATE users SET location_id = ? WHERE id = ? AND role = 'staff'");
            $stmt->execute([$location_id, $staff_id]);
            if ($location_id !== null) {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, location_id, title, message, type) VALUES (?, ?, 'Location assigned', ?, 'system')");
                $stmt->execute([$staff_id, $location_id, 'Your assigned parking location changed from ' . $old_location_name . ' to ' . $new_location_name . '.']);
            }
            $pdo->commit();
            $message = $staff['name'] . ': ' . $old_location_name . ' → ' . $new_location_name . '.';
        } elseif ($action === 'set_status') {
            $staff_id = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
            $new_status = $_POST['account_status'] ?? '';
            if (!$staff_id || !in_array($new_status, ['approved', 'rejected', 'blocked'], true)) {
                throw new RuntimeException('Invalid staff account action.');
            }
            $stmt = $pdo->prepare("UPDATE users SET account_status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = NULL WHERE id = ? AND role = 'staff'");
            $stmt->execute([$new_status, $_SESSION['user_id'], $staff_id]);
            if (!$stmt->rowCount()) {
                throw new RuntimeException('Staff account was not found or the status is unchanged.');
            }
            $staff_stmt = $pdo->prepare('SELECT name, location_id FROM users WHERE id = ?');
            $staff_stmt->execute([$staff_id]);
            $staff = $staff_stmt->fetch(PDO::FETCH_ASSOC);
            if ($staff) {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, location_id, title, message, type) VALUES (?, ?, ?, ?, 'system')");
                $stmt->execute([$staff_id, $staff['location_id'], 'Staff account updated', 'Your account status is now ' . $new_status . '.']);
            }
            $message = 'Staff account status updated.';
        } else {
            throw new RuntimeException('Invalid staff management action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            error_log('Staff management update failed: ' . $e->getMessage());
            $error = 'Unable to update staff management data right now.';
        }
    }
}

$staff_sql = "SELECT u.id, u.name, u.email, u.phone, u.account_status, u.location_id, u.last_login,
        l.name AS location_name
    FROM users u LEFT JOIN locations l ON l.id = u.location_id
    WHERE u.role = 'staff'";
$staff_params = [];
if ($selected_location_id) {
    $staff_sql .= ' AND u.location_id = ?';
    $staff_params[] = $selected_location_id;
}
$staff_sql .= ' ORDER BY (u.location_id IS NULL) DESC, u.name';
$staff_stmt = $pdo->prepare($staff_sql);
$staff_stmt->execute($staff_params);
$staff_members = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Staff Management';
include '../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3 mb-0">Staff Management</h1></div>
<?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

<form method="GET" class="card p-3 mb-3"><div class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label" for="staffLocationFilter">Filter staff by location</label><select id="staffLocationFilter" name="location_id" class="form-select"><option value="all">All staff / locations</option><?php foreach ($all_locations as $location): ?><option value="<?php echo (int) $location['id']; ?>" <?php echo $selected_location_id === (int) $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filter</button></div></div></form>

<section class="card p-3 mb-4" aria-labelledby="createStaffHeading">
    <h2 class="h5" id="createStaffHeading">Create Staff Account</h2>
    <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create_staff">
        <div class="col-md-4"><label class="form-label" for="staffName">Name</label><input id="staffName" name="name" class="form-control" required maxlength="100"></div>
        <div class="col-md-4"><label class="form-label" for="staffEmail">Email</label><input id="staffEmail" name="email" type="email" class="form-control" required maxlength="150"></div>
        <div class="col-md-4"><label class="form-label" for="staffPhone">Phone</label><input id="staffPhone" name="phone" class="form-control" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"></div>
        <div class="col-md-3"><label class="form-label" for="staffPassword">Password</label><input id="staffPassword" name="password" type="password" class="form-control" minlength="6" required></div>
        <div class="col-md-3"><label class="form-label" for="staffConfirm">Confirm Password</label><input id="staffConfirm" name="confirm_password" type="password" class="form-control" minlength="6" required></div>
        <div class="col-md-4"><label class="form-label" for="newStaffLocation">Parking Location</label><select id="newStaffLocation" name="location_id" class="form-select" required><option value="">Select Parking Location</option><?php foreach ($active_locations as $location): ?><option value="<?php echo (int) $location['id']; ?>"><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Create Staff</button></div>
    </form>
</section>

<div class="table-responsive"><table class="table table-bordered table-hover bg-white align-middle">
    <thead class="table-dark"><tr><th>Staff</th><th>Phone</th><th>Assigned Location</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$staff_members): ?><tr><td colspan="6" class="text-center text-muted py-4">No staff accounts have been registered.</td></tr><?php endif; ?>
    <?php foreach ($staff_members as $staff): ?>
        <tr>
            <td><?php echo htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted"><?php echo htmlspecialchars($staff['email'], ENT_QUOTES, 'UTF-8'); ?></small></td>
            <td><?php echo htmlspecialchars($staff['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><strong><?php echo htmlspecialchars($staff['location_name'] ?? 'Not Assigned', ENT_QUOTES, 'UTF-8'); ?></strong>
                <form method="POST" class="d-flex gap-2 mt-2"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="assign_location"><input type="hidden" name="staff_id" value="<?php echo (int) $staff['id']; ?>">
                    <select class="form-select form-select-sm" name="location_id" aria-label="Assign parking location"><option value="">Not Assigned</option><?php foreach ($active_locations as $location): ?><option value="<?php echo (int) $location['id']; ?>" <?php echo (int) $staff['location_id'] === (int) $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Assign</button>
                </form>
            </td>
            <td><span class="badge text-bg-<?php echo $staff['account_status'] === 'approved' ? 'success' : ($staff['account_status'] === 'pending' ? 'warning text-dark' : 'secondary'); ?>"><?php echo htmlspecialchars(ucfirst($staff['account_status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td><?php echo htmlspecialchars($staff['last_login'] ?? 'Never', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><div class="d-flex flex-wrap gap-1">
                <?php if ($staff['account_status'] !== 'approved'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="staff_id" value="<?php echo (int) $staff['id']; ?>"><input type="hidden" name="account_status" value="approved"><button class="btn btn-sm btn-success">Approve</button></form><?php endif; ?>
                <?php if ($staff['account_status'] === 'pending'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="staff_id" value="<?php echo (int) $staff['id']; ?>"><input type="hidden" name="account_status" value="rejected"><button class="btn btn-sm btn-outline-danger">Reject</button></form><?php elseif ($staff['account_status'] === 'approved'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="staff_id" value="<?php echo (int) $staff['id']; ?>"><input type="hidden" name="account_status" value="blocked"><button class="btn btn-sm btn-outline-danger">Block</button></form><?php endif; ?>
                <?php if ($staff['account_status'] === 'blocked' || $staff['account_status'] === 'rejected'): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="staff_id" value="<?php echo (int) $staff['id']; ?>"><input type="hidden" name="account_status" value="approved"><button class="btn btn-sm btn-outline-success">Unblock / Approve</button></form><?php endif; ?>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php include '../includes/footer.php'; ?>