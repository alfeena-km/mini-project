<?php
$staff_nav_items = [
    ['staff_dashboard.php', $root_path . 'staff_dashboard.php', 'fa-gauge', 'Dashboard'],
    ['bookings.php', $staff_path . 'bookings.php', 'fa-clipboard-list', 'Bookings'],
    ['slots.php', $staff_path . 'slots.php', 'fa-square-parking', 'Parking Slots'],
    ['check_in.php', $staff_path . 'check_in.php', 'fa-right-to-bracket', 'Check-In / Check-Out'],
    ['payments.php', $staff_path . 'payments.php', 'fa-money-bill-wave', 'Payments'],
    ['payment_history.php', $staff_path . 'payment_history.php', 'fa-clock-rotate-left', 'Payment History'],
    ['notifications.php', $staff_path . 'notifications.php', 'fa-bell', 'Notifications'],
    ['profile.php', $staff_path . 'profile.php', 'fa-user', 'Profile'],
];
?>
<div class="row g-4 staff-layout">
    <aside class="col-lg-3 col-xl-2">
        <button class="btn btn-outline-primary w-100 mb-2 d-lg-none text-start" type="button" data-bs-toggle="collapse" data-bs-target="#staffNavigation" aria-expanded="false" aria-controls="staffNavigation">
            <i class="fa-solid fa-bars me-2" aria-hidden="true"></i>Staff Menu · <?php echo htmlspecialchars($_SESSION['staff_location_name'] ?? 'Location not assigned', ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <div class="collapse d-lg-block" id="staffNavigation">
          <div class="staff-identity card p-3 mb-3">
              <div class="small text-uppercase text-muted fw-semibold">Staff</div>
              <strong><?php echo htmlspecialchars($_SESSION['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
              <div class="small text-muted mt-2"><i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars($_SESSION['staff_location_name'] ?? 'Location not assigned', ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <nav class="staff-sidebar list-group" aria-label="Staff navigation">
            <?php foreach ($staff_nav_items as [$page, $href, $icon, $label]): ?>
                <a class="list-group-item list-group-item-action<?php echo $current_page === $page ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $current_page === $page ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid <?php echo $icon; ?> me-2" aria-hidden="true"></i><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
            <form method="POST" action="<?php echo htmlspecialchars($root_path . 'logout.php', ENT_QUOTES, 'UTF-8'); ?>" class="list-group-item p-0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="list-group-item list-group-item-action w-100 text-start border-0"><i class="fa-solid fa-arrow-right-from-bracket me-2" aria-hidden="true"></i>Logout</button>
            </form>
                    </nav>
                </div>
    </aside>
    <main class="col-lg-9 col-xl-10">