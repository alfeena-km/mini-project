<?php
require_once 'config.php';
require_once 'includes/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
	http_response_code(403);
	exit('Invalid logout request.');
}

$cookie = session_get_cookie_params();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
	setcookie(session_name(), '', [
		'expires' => time() - 42000,
		'path' => $cookie['path'],
		'domain' => $cookie['domain'],
		'secure' => $cookie['secure'],
		'httponly' => $cookie['httponly'],
		'samesite' => $cookie['samesite'] ?? 'Lax',
	]);
}
session_destroy();
header("Location: login.php");
exit;
