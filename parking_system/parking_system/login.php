<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
$csrf_token = csrf_token();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session could not validate this request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $stmt = $pdo->prepare("SELECT id, name, email, password, role, account_status, location_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Invalid email or password.';
            } elseif (!in_array($user['role'], ['admin', 'staff', 'user'], true)) {
                $error = 'This account role is not valid.';
            } elseif (in_array($user['account_status'], ['rejected', 'blocked'], true)) {
                $error = $user['account_status'] === 'rejected'
                    ? 'Your account has been rejected. Please contact the administrator.'
                    : 'This account has been blocked. Please contact the administrator.';
            } elseif ($user['account_status'] === 'pending') {
                $error = 'Your account is waiting for administrator approval.';
            } elseif ($user['account_status'] !== 'approved') {
                $error = 'This account status is not valid. Please contact the administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

                if ($user['role'] === 'admin') {
                    header("Location: admin/admin_dashboard.php");
                } elseif ($user['role'] === 'staff') {
                    header("Location: staff_dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit;
            }
        }
    }
}

$page_title = "Login";
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
        max-width: 900px;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        background: #fff;
    }
    .auth-side {
        flex: 1 1 42%;
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
        top: -100px; left: -80px;
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
        flex: 1 1 58%;
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
            <h4 class="fw-bold">Welcome back to ParkEasy</h4>
            <p style="opacity:0.9;">Log in to search slots, manage your bookings, and park without the hassle.</p>
            <ul>
                <li><i class="fa-solid fa-check"></i>&nbsp; Real-time slot availability</li>
                <li><i class="fa-solid fa-check"></i>&nbsp; Instant booking confirmation</li>
                <li><i class="fa-solid fa-check"></i>&nbsp; Manage bookings anytime</li>
            </ul>
        </div>
        <div class="auth-form-side">
            <h3 class="fw-bold mb-1">Login</h3>
            <p class="text-muted mb-4">Enter your credentials to continue</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fa-solid fa-envelope text-muted"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fa-solid fa-lock text-muted"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-2">Login <i class="fa-solid fa-arrow-right-to-bracket ms-1"></i></button>
            </form>

            <p class="mt-4 text-center mb-0">Don't have an account? <a href="register.php" class="fw-semibold text-decoration-none">Register</a></p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>