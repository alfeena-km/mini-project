<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();
$staff_location_id = (int) $_SESSION['staff_location_id'];

$stmt = $pdo->prepare('SELECT u.name, u.email, u.phone, u.created_at, u.last_login, l.name AS location_name, l.address, u.account_status
    FROM users u JOIN locations l ON l.id = u.location_id
    WHERE u.id = ? AND u.role = ? AND u.account_status = ? AND u.location_id = ?');
$stmt->execute([$_SESSION['user_id'], 'staff', 'approved', $staff_location_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    http_response_code(403);
    exit('You are not authorized to view this staff profile.');
}

$page_title = 'Staff Profile';
include '../includes/header.php';
include '../includes/staff_sidebar.php';
?>
<h1 class="h3 mb-3">Profile</h1>
<section class="card p-4" aria-label="Staff account profile">
    <dl class="row mb-0">
        <dt class="col-sm-3">Name</dt><dd class="col-sm-9"><?php echo htmlspecialchars($profile['name'], ENT_QUOTES, 'UTF-8'); ?></dd>
        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?php echo htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8'); ?></dd>
        <dt class="col-sm-3">Phone</dt><dd class="col-sm-9"><?php echo htmlspecialchars($profile['phone'] ?? 'Not provided', ENT_QUOTES, 'UTF-8'); ?></dd>
        <dt class="col-sm-3">Role</dt><dd class="col-sm-9">Staff</dd>
        <dt class="col-sm-3">Assigned Location</dt><dd class="col-sm-9"><?php echo htmlspecialchars($profile['location_name'] . ' · ' . ($profile['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><small class="d-block text-muted">Managed by administrator</small></dd>
        <dt class="col-sm-3">Account status</dt><dd class="col-sm-9"><span class="badge text-bg-success">Approved</span></dd>
        <dt class="col-sm-3">Joined</dt><dd class="col-sm-9"><?php echo htmlspecialchars($profile['created_at'], ENT_QUOTES, 'UTF-8'); ?></dd>
        <dt class="col-sm-3">Last login</dt><dd class="col-sm-9"><?php echo htmlspecialchars($profile['last_login'] ?? 'Not recorded', ENT_QUOTES, 'UTF-8'); ?></dd>
    </dl>
</section>
</main>
</div>
<?php include '../includes/footer.php'; ?>