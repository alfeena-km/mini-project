<?php require_once __DIR__ . '/auth_check.php'; ?>
<?php
  $is_admin_page = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
  $is_staff_page = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/staff/') !== false;
  $root_path = ($is_admin_page || $is_staff_page) ? '../' : '';
  $admin_path = $is_admin_page ? '' : 'admin/';
  $staff_path = $is_staff_page ? '' : 'staff/';
  $current_page = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(isset($page_title) ? $page_title . ' - Parking Slot Booking System' : 'Parking Slot Booking System', ENT_QUOTES, 'UTF-8'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?php echo $root_path; ?>assets/css/app.css" rel="stylesheet">
<style>
    body { background-color: #f4f6f9; }
    .navbar-brand { font-weight: 700; }
    .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-radius: 10px; }
    .status-confirmed { color: #198754; font-weight: 600; }
    .status-cancelled { color: #dc3545; font-weight: 600; }
    .status-completed { color: #6c757d; font-weight: 600; }
    footer { padding: 20px 0; text-align: center; color: #888; margin-top: 40px; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="<?php echo isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? $admin_path . 'admin_dashboard.php' : (isset($_SESSION['role']) && $_SESSION['role'] === 'staff' ? $root_path . 'staff_dashboard.php' : (isset($_SESSION['user_id']) ? $root_path . 'dashboard.php' : $root_path . 'index.php')); ?>">
        <i class="fa-solid fa-square-parking"></i> ParkEasy
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav ms-auto">
        <?php if (isset($_SESSION['user_id'])): ?>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'admin_dashboard.php' ? ' active' : ''; ?>" href="<?php echo $admin_path; ?>admin_dashboard.php"<?php echo $current_page === 'admin_dashboard.php' ? ' aria-current="page"' : ''; ?>>Dashboard</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'manage_users.php' ? ' active' : ''; ?>" href="<?php echo $admin_path; ?>manage_users.php"<?php echo $current_page === 'manage_users.php' ? ' aria-current="page"' : ''; ?>>Users</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'manage_staff.php' ? ' active' : ''; ?>" href="<?php echo $admin_path; ?>manage_staff.php"<?php echo $current_page === 'manage_staff.php' ? ' aria-current="page"' : ''; ?>>Staff</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'manage_locations.php' ? ' active' : ''; ?>" href="<?php echo $admin_path; ?>manage_locations.php"<?php echo $current_page === 'manage_locations.php' ? ' aria-current="page"' : ''; ?>>Locations</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'manage_slots.php' ? ' active' : ''; ?>" href="<?php echo $admin_path; ?>manage_slots.php"<?php echo $current_page === 'manage_slots.php' ? ' aria-current="page"' : ''; ?>>Slots</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'reports.php' ? ' active' : ''; ?>" href="<?php echo $admin_path; ?>reports.php"<?php echo $current_page === 'reports.php' ? ' aria-current="page"' : ''; ?>>Reports</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'notifications.php' ? ' active' : ''; ?>" href="<?php echo $admin_path; ?>notifications.php"<?php echo $current_page === 'notifications.php' ? ' aria-current="page"' : ''; ?>>Notifications</a></li>
                <li class="nav-item">
                  <form method="POST" action="<?php echo $root_path; ?>logout.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="nav-link border-0 bg-transparent">Logout (<?php echo htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8'); ?>)</button>
                  </form>
                </li>
            <?php elseif ($_SESSION['role'] === 'staff'): ?>
              <li class="nav-item"><a class="nav-link<?php echo $current_page === 'staff_dashboard.php' ? ' active' : ''; ?>" href="<?php echo $root_path; ?>staff_dashboard.php">Dashboard</a></li>
                <li class="nav-item">
                  <form method="POST" action="<?php echo $root_path; ?>logout.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="nav-link border-0 bg-transparent">Logout (<?php echo htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8'); ?>)</button>
                  </form>
                </li>
            <?php else: ?>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'dashboard.php' ? ' active' : ''; ?>" href="<?php echo $root_path; ?>dashboard.php">Search & Book</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'my_bookings.php' ? ' active' : ''; ?>" href="<?php echo $root_path; ?>my_bookings.php">My Bookings</a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'vehicles.php' ? ' active' : ''; ?>" href="<?php echo $root_path; ?>vehicles.php">Vehicles</a></li>
                <?php
                  $unread_stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
                  $unread_stmt->execute([$_SESSION['user_id']]);
                  $unread_notifications = (int) $unread_stmt->fetchColumn();
                ?>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'notifications.php' ? ' active' : ''; ?>" href="<?php echo $root_path; ?>notifications.php"<?php echo $current_page === 'notifications.php' ? ' aria-current="page"' : ''; ?>>Notifications<?php if ($unread_notifications): ?> <span class="badge text-bg-danger"><?php echo $unread_notifications; ?></span><?php endif; ?></a></li>
                <li class="nav-item"><a class="nav-link<?php echo $current_page === 'profile.php' ? ' active' : ''; ?>" href="<?php echo $root_path; ?>profile.php"><?php echo 'Profile'; ?></a></li>
                <li class="nav-item">
                  <form method="POST" action="<?php echo $root_path; ?>logout.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="nav-link border-0 bg-transparent">Logout (<?php echo htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8'); ?>)</button>
                  </form>
                </li>
            <?php endif; ?>
        <?php else: ?>
            <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
            <li class="nav-item"><a class="nav-link" href="register.php">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
