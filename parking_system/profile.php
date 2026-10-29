<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$user_id = (int) $_SESSION['user_id'];
$errors = [];
$message = $_SESSION['profile_flash'] ?? '';
unset($_SESSION['profile_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session could not validate this request. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || strlen($name) > 100) {
            $errors[] = 'Name is required and must be 100 characters or fewer.';
        }
        if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($phone !== '' && !preg_match('/^[0-9]{10}$/', $phone)) {
            $errors[] = 'Enter a 10-digit phone number.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ? AND role = ?');
                $stmt->execute([$name, $email, $phone ?: null, $user_id, 'user']);
                $_SESSION['name'] = $name;
                $_SESSION['profile_flash'] = 'Profile updated successfully.';
                header('Location: profile.php');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'That email address is already registered.';
                } else {
                    error_log('Profile update failed: ' . $e->getMessage());
                    $errors[] = 'Unable to update your profile right now.';
                }
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT name, email, phone FROM users WHERE id = ? AND role = ?');
$stmt->execute([$user_id, 'user']);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    http_response_code(404);
    exit('Profile not found.');
}

$page_title = 'My Profile';
include 'includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card p-4">
            <h2 class="h3 mb-1">My Profile</h2>
            <p class="text-muted mb-4">Update your account details.</p>
            <?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endforeach; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3"><label for="name" class="form-label">Full name</label><input id="name" name="name" class="form-control" maxlength="100" required value="<?php echo htmlspecialchars($_POST['name'] ?? $profile['name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="mb-3"><label for="email" class="form-label">Email</label><input id="email" name="email" type="email" class="form-control" maxlength="100" required value="<?php echo htmlspecialchars($_POST['email'] ?? $profile['email'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="mb-3"><label for="phone" class="form-label">Phone</label><input id="phone" name="phone" type="tel" class="form-control" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" title="Enter exactly 10 digits." value="<?php echo htmlspecialchars($_POST['phone'] ?? ($profile['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                <button type="submit" class="btn btn-primary">Save Profile</button>
            </form>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>