<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
$csrf_token = csrf_token();
$errors = [];
$success = false;
$assigned_location_name = '';
$active_locations = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session could not validate this request. Please try again.';
    }
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $role = in_array($_POST['role'] ?? '', ['user', 'staff'], true) ? $_POST['role'] : 'user';
    $location_id = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);

    if ($name === '' || $email === '' || $password === '') {
        $errors[] = "Please fill in all required fields.";
    }
    if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Enter a 10-digit phone number.';
    }
    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    if ($role === 'staff' && !$location_id) {
        $errors[] = 'Please select a parking location.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            if ($role === 'staff') {
                $location_stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ? AND status = 'active' FOR UPDATE");
                $location_stmt->execute([$location_id]);
                $assigned_location_name = (string) $location_stmt->fetchColumn();
                if ($assigned_location_name === '') {
                    $pdo->rollBack();
                    $errors[] = 'This parking location is currently unavailable.';
                }
            }

            if (empty($errors)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    $errors[] = "An account with this email already exists.";
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, account_status, location_id) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
                    $stmt->execute([$name, $email, $phone, $hashed, $role, $role === 'staff' ? $location_id : null]);
                    $pdo->commit();
                    $success = true;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Registration failed: ' . $e->getMessage());
            $errors[] = 'Unable to create your account right now. Please try again.';
        }
    }
}

$page_title = "Register";
include 'includes/header.php';
?>

<style>
    .auth-wrap {
        min-height: 75vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px 0;
    }
    .auth-card {
        display: flex;
        width: 100%;
        max-width: 950px;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        background: #fff;
    }
    .auth-side {
        flex: 1 1 40%;
        background: linear-gradient(160deg, #0d6efd 0%, #0a58ca 100%);
        color: #fff;
        padding: 50px 36px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    .auth-side::before {
        content: "";
        position: absolute;
        width: 260px; height: 260px;
        background: rgba(255,255,255,0.08);
        border-radius: 50%;
        bottom: -110px; right: -80px;
    }
    .auth-side i.fa-square-parking {
        font-size: 70px;
        margin-bottom: 18px;
        position: relative; z-index: 1;
    }
    .auth-side h4, .auth-side p, .auth-side ul { position: relative; z-index: 1; }
    .auth-side ul { list-style: none; padding-left: 0; margin-top: 18px; }
    .auth-side ul li { margin-bottom: 10px; opacity: 0.9; }
    .auth-side ul li i { width: 20px; }
    .auth-form-side {
        flex: 1 1 60%;
        padding: 50px 44px;
    }
    .auth-form-side .form-control {
        padding: 10px 14px;
        border-radius: 8px;
    }
    .auth-form-side .btn-primary {
        padding: 10px;
        border-radius: 8px;
        font-weight: 600;
    }
    @media (max-width: 768px) {
        .auth-card { flex-direction: column; }
    }
</style>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-side">
            <i class="fa-solid fa-square-parking"></i>
            <h4 class="fw-bold">Join ParkEasy Today</h4>
            <p style="opacity:0.9;">Create a free account and start booking parking slots in seconds, wherever you go.</p>
            <ul>
                <li><i class="fa-solid fa-check"></i>&nbsp; Free to sign up</li>
                <li><i class="fa-solid fa-check"></i>&nbsp; Book & manage slots easily</li>
                <li><i class="fa-solid fa-check"></i>&nbsp; Track your booking history</li>
            </ul>
        </div>
        <div class="auth-form-side">
            <?php if ($success): ?>
                <div class="text-center py-4">
                    <i class="fa-solid fa-circle-check text-success mb-3" style="font-size:56px;"></i>
                    <h4 class="fw-bold">Registration Successful!</h4>
                    <?php if ($role === 'staff'): ?>
                        <p class="text-muted">Staff account created successfully.</p>
                        <p><strong>Assigned Location:</strong> <?php echo htmlspecialchars($assigned_location_name, ENT_QUOTES, 'UTF-8'); ?><br><strong>Account Status:</strong> Pending Admin Approval</p>
                    <?php else: ?>
                        <p class="text-muted">Registration successful. Your account is currently pending administrator approval. You will be able to log in after approval.</p>
                    <?php endif; ?>
                    <a href="login.php" class="btn btn-primary px-4">Back to Login <i class="fa-solid fa-arrow-right ms-1"></i></a>
                </div>
            <?php else: ?>
                <h3 class="fw-bold mb-1">Create an Account</h3>
                <p class="text-muted mb-4">Fill in your details to get started</p>

                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger py-2"><i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($err); ?></div>
                <?php endforeach; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Register as</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleUser" value="user" <?php echo (($_POST['role'] ?? 'user') === 'user') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="roleUser">User</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleStaff" value="staff" <?php echo (($_POST['role'] ?? 'user') === 'staff') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="roleStaff">Staff</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 <?php echo (($_POST['role'] ?? 'user') === 'staff') ? '' : 'd-none'; ?>" id="staffLocationGroup">
                        <label class="form-label fw-semibold" for="staffLocation">Parking Location</label>
                        <select id="staffLocation" name="location_id" class="form-select" <?php echo (($_POST['role'] ?? 'user') === 'staff') ? 'required' : ''; ?>>
                            <option value="">Select Parking Location</option>
                            <?php foreach ($active_locations as $location): ?>
                                <option value="<?php echo (int) $location['id']; ?>" <?php echo (string) ($_POST['location_id'] ?? '') === (string) $location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Full Name</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-user text-muted"></i></span>
                                <input type="text" name="name" class="form-control" placeholder="Your name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-phone text-muted"></i></span>
                                <input type="tel" name="phone" class="form-control" placeholder="Optional" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" title="Enter exactly 10 digits." value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fa-solid fa-envelope text-muted"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="you@example.com" maxlength="100" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-lock text-muted"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Min. 6 characters" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Confirm Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-lock text-muted"></i></span>
                                <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-2">Register <i class="fa-solid fa-user-plus ms-1"></i></button>
                </form>
                <p class="mt-4 text-center mb-0">Already have an account? <a href="login.php" class="fw-semibold text-decoration-none">Login</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const staffRoleOption = document.getElementById('roleStaff');
const userRoleOption = document.getElementById('roleUser');
const staffLocationGroup = document.getElementById('staffLocationGroup');
const staffLocationSelect = document.getElementById('staffLocation');
function updateStaffLocationRequirement() {
    if (!staffRoleOption || !staffLocationGroup || !staffLocationSelect) return;
    const staffSelected = staffRoleOption.checked;
    staffLocationGroup.classList.toggle('d-none', !staffSelected);
    staffLocationSelect.required = staffSelected;
}
staffRoleOption?.addEventListener('change', updateStaffLocationRequirement);
userRoleOption?.addEventListener('change', updateStaffLocationRequirement);
updateStaffLocationRequirement();
</script>

<?php include 'includes/footer.php'; ?>
