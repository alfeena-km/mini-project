<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$user_id = (int) $_SESSION['user_id'];
$errors = [];
$editing = null;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = 'Your session could not validate this request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $vehicle_id = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if (!$vehicle_id) {
                $errors[] = 'Invalid vehicle.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM vehicles WHERE id = ? AND user_id = ?');
                $stmt->execute([$vehicle_id, $user_id]);
                $_SESSION['flash'] = $stmt->rowCount() ? 'Vehicle removed.' : 'Vehicle not found.';
                header('Location: vehicles.php');
                exit;
            }
        }

        if ($action === 'save') {
            $vehicle_number = strtoupper(trim($_POST['vehicle_number'] ?? ''));
            $vehicle_type = $_POST['vehicle_type'] ?? '';
            $vehicle_model = trim($_POST['vehicle_model'] ?? '');

            if (!preg_match('/^[A-Z0-9 -]{4,20}$/', $vehicle_number)) {
                $errors[] = 'Enter a valid vehicle number using 4 to 20 letters, numbers, spaces, or hyphens.';
            }
            if (!in_array($vehicle_type, ['Car', 'Bike', 'EV'], true)) {
                $errors[] = 'Choose a valid vehicle type.';
            }
            if (strlen($vehicle_model) > 100) {
                $errors[] = 'Vehicle model must be 100 characters or fewer.';
            }
            if ($vehicle_id) {
                $stmt = $pdo->prepare('SELECT id, vehicle_number, vehicle_type, vehicle_model FROM vehicles WHERE id = ? AND user_id = ?');
                $stmt->execute([$vehicle_id, $user_id]);
                $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$editing) {
                    $errors[] = 'Vehicle not found.';
                }
            }

            if (!$errors) {
                try {
                    if ($vehicle_id) {
                        $stmt = $pdo->prepare('UPDATE vehicles SET vehicle_number = ?, vehicle_type = ?, vehicle_model = ? WHERE id = ? AND user_id = ?');
                        $stmt->execute([$vehicle_number, $vehicle_type, $vehicle_model ?: null, $vehicle_id, $user_id]);
                        $_SESSION['flash'] = 'Vehicle updated.';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO vehicles (user_id, vehicle_number, vehicle_type, vehicle_model) VALUES (?, ?, ?, ?)');
                        $stmt->execute([$user_id, $vehicle_number, $vehicle_type, $vehicle_model ?: null]);
                        $_SESSION['flash'] = 'Vehicle added.';
                    }
                    header('Location: vehicles.php');
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errors[] = 'That vehicle number is already in your account.';
                    } else {
                        error_log('Vehicle save failed: ' . $e->getMessage());
                        $errors[] = 'Unable to save this vehicle right now.';
                    }
                }
            }
        }
    }
}

$edit_id = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($edit_id) {
    $stmt = $pdo->prepare('SELECT id, vehicle_number, vehicle_type, vehicle_model FROM vehicles WHERE id = ? AND user_id = ?');
    $stmt->execute([$edit_id, $user_id]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmt = $pdo->prepare('SELECT id, vehicle_number, vehicle_type, vehicle_model, created_at FROM vehicles WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user_id]);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$page_title = 'My Vehicles';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">My Vehicles</h2>
        <p class="text-muted mb-0">Manage vehicles used for parking bookings.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-primary"><i class="fa-solid fa-magnifying-glass me-1"></i> Find Parking</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-success" role="status"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endforeach; ?>

<div class="row g-4 align-items-start">
    <div class="col-lg-5">
        <div class="card p-4">
            <h5 class="mb-3"><?php echo $editing ? 'Edit Vehicle' : 'Add Vehicle'; ?></h5>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save">
                <?php if ($editing): ?><input type="hidden" name="vehicle_id" value="<?php echo (int) $editing['id']; ?>"><?php endif; ?>
                <div class="mb-3">
                    <label for="vehicle_number" class="form-label">Vehicle number</label>
                    <input id="vehicle_number" name="vehicle_number" class="form-control" maxlength="20" required value="<?php echo htmlspecialchars($_POST['vehicle_number'] ?? ($editing['vehicle_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                    <label for="vehicle_type" class="form-label">Vehicle type</label>
                    <select id="vehicle_type" name="vehicle_type" class="form-select" required>
                        <option value="">Select type</option>
                        <?php foreach (['Car', 'Bike', 'EV'] as $type): ?>
                            <?php $selected_type = $_POST['vehicle_type'] ?? ($editing['vehicle_type'] ?? ''); ?>
                            <option value="<?php echo $type; ?>" <?php echo $selected_type === $type ? 'selected' : ''; ?>><?php echo $type === 'EV' ? 'Electric vehicle' : $type; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="vehicle_model" class="form-label">Model <span class="text-muted">(optional)</span></label>
                    <input id="vehicle_model" name="vehicle_model" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($_POST['vehicle_model'] ?? ($editing['vehicle_model'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i> <?php echo $editing ? 'Save Changes' : 'Add Vehicle'; ?></button>
                <?php if ($editing): ?><a href="vehicles.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-4">
            <h5 class="mb-3">Saved Vehicles</h5>
            <?php if (!$vehicles): ?>
                <p class="text-muted mb-0">No vehicles added yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Vehicle</th><th>Type</th><th>Model</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($vehicle['vehicle_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($vehicle['vehicle_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($vehicle['vehicle_model'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-success" href="dashboard.php?vehicle_id=<?php echo (int) $vehicle['id']; ?>" aria-label="Book with this vehicle" title="Find a matching parking slot"><i class="fa-solid fa-square-parking"></i><span class="d-none d-xl-inline ms-1">Book</span></a>
                                    <a class="btn btn-sm btn-outline-primary" href="vehicles.php?edit=<?php echo (int) $vehicle['id']; ?>" aria-label="Edit vehicle"><i class="fa-solid fa-pen"></i></a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this vehicle?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete vehicle"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>