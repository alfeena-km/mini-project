<?php
// Include config.php BEFORE this file wherever it is used.
require_once __DIR__ . '/functions.php';

function get_login_redirect_path() {
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    return (strpos($script_name, '/admin/') !== false || strpos($script_name, '/staff/') !== false || strpos($script_name, '/api/') !== false) ? '../login.php' : 'login.php';
}

function get_dashboard_redirect_path($role) {
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $in_subdirectory = strpos($script_name, '/admin/') !== false || strpos($script_name, '/staff/') !== false || strpos($script_name, '/api/') !== false;
    $root = $in_subdirectory ? '../' : '';

    if ($role === 'admin') {
        return $root . 'admin/admin_dashboard.php';
    }

    if ($role === 'staff') {
        return $root . 'staff_dashboard.php';
    }

    return $root . 'dashboard.php';
}

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . get_login_redirect_path());
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT u.id, u.name, u.role, u.account_status, u.location_id,
            l.name AS assigned_location_name, l.status AS assigned_location_status
        FROM users u LEFT JOIN locations l ON l.id = u.location_id WHERE u.id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !in_array($user['role'], ['admin', 'staff', 'user'], true)) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ' . get_login_redirect_path());
        exit;
    }

    if (!in_array($user['account_status'] ?? 'pending', ['approved'], true)) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ' . get_login_redirect_path());
        exit;
    }

    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['staff_location_id'] = $user['role'] === 'staff' && $user['location_id'] !== null ? (int) $user['location_id'] : null;
    $_SESSION['staff_location_name'] = $user['role'] === 'staff' ? $user['assigned_location_name'] : null;
    $_SESSION['staff_location_status'] = $user['role'] === 'staff' ? $user['assigned_location_status'] : null;
    expire_unpaid_bookings($pdo);
}

function require_admin() {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: ' . get_dashboard_redirect_path($_SESSION['role'] ?? 'user'));
        exit;
    }
}

function require_staff($allow_unassigned = false) {
    require_login();
    if ($_SESSION['role'] !== 'staff') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
            http_response_code(403);
            exit('Unauthorized');
        }
        header('Location: ' . get_dashboard_redirect_path($_SESSION['role'] ?? 'user'));
        exit;
    }

    if (empty($_SESSION['staff_location_id']) || ($_SESSION['staff_location_status'] ?? null) !== 'active') {
        if ($allow_unassigned) {
            return;
        }
        http_response_code(403);
        exit(empty($_SESSION['staff_location_id'])
            ? 'Your account has not been assigned to a parking location. Please contact the administrator.'
            : 'Your assigned parking location is not active. Please contact the administrator.');
    }
}

function require_user() {
    require_login();
    if ($_SESSION['role'] !== 'user') {
        header('Location: ' . get_dashboard_redirect_path($_SESSION['role'] ?? 'user'));
        exit;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token'], $token)
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_valid_csrf_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Your session could not validate this request. Please return and try again.');
    }
}
