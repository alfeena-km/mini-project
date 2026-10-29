<?php
require_once 'config.php';
require_once 'includes/auth_check.php';
require_user();

$locations = $pdo->query("SELECT * FROM locations WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT id, vehicle_number, vehicle_type FROM vehicles WHERE user_id = ? ORDER BY vehicle_number');
$stmt->execute([$_SESSION['user_id']]);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$requested_vehicle_id = filter_var($_GET['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
$selected_vehicle_id = null;
$selected_vehicle = null;
if ($requested_vehicle_id) {
    foreach ($vehicles as $vehicle) {
        if ((int) $vehicle['id'] === $requested_vehicle_id) {
            $selected_vehicle_id = $requested_vehicle_id;
            $selected_vehicle = $vehicle;
            break;
        }
    }
}
$csrf_token = csrf_token();

$results = [];
$searched = false;
$search_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searched = true;
    $location_id = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
    $date = trim($_POST['date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $time_pattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    if (!$location_id || !$date_value || $date_value->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
        $search_error = 'Choose an active location and a valid date that is today or later.';
    } elseif (!preg_match($time_pattern, $start_time) || !preg_match($time_pattern, $end_time) || $end_time <= $start_time) {
        $search_error = 'Choose valid times. The end time must be later than the start time.';
    } elseif ($date === date('Y-m-d') && $start_time <= date('H:i')) {
        $search_error = 'For today, choose a start time that has not passed.';
    }

    if ($search_error === '') {
        // Exclude slots occupied by overlapping confirmed bookings.
        $slot_type_filter = $selected_vehicle ? ' AND ps.slot_type = ?' : '';
        $sql = "SELECT ps.*, l.name AS location_name, l.address
                FROM parking_slots ps
                JOIN locations l ON ps.location_id = l.id
                WHERE ps.location_id = ?
                  AND l.status = 'active'
                  AND ps.status = 'available'
              $slot_type_filter
                  AND ps.id NOT IN (
                      SELECT slot_id FROM bookings
                      WHERE booking_date = ?
                        AND status IN ('confirmed', 'active')
                        AND (start_time < ? AND end_time > ?)
                  )
                ORDER BY ps.slot_number";
        $stmt = $pdo->prepare($sql);
        $search_params = [$location_id];
        if ($selected_vehicle) {
            $search_params[] = $selected_vehicle['vehicle_type'];
        }
        array_push($search_params, $date, $end_time, $start_time);
        $stmt->execute($search_params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $_SESSION['search'] = compact('date', 'start_time', 'end_time');
    }
}

$page_title = "Search & Book";
include 'includes/header.php';
?>

<style>
    .search-hero {
        background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
        border-radius: 18px;
        padding: 36px 40px;
        color: #fff;
        margin-bottom: -55px;
        position: relative;
        overflow: hidden;
    }
    .search-hero::before {
        content: "";
        position: absolute;
        width: 220px; height: 220px;
        background: rgba(255,255,255,0.08);
        border-radius: 50%;
        top: -90px; right: -50px;
    }
    .search-card {
        border-radius: 16px;
        padding: 28px;
        position: relative;
        z-index: 2;
        box-shadow: 0 12px 32px rgba(0,0,0,0.12);
        margin-bottom: 40px;
    }
    .search-card .form-label {
        font-weight: 600;
        font-size: 0.85rem;
        color: #555;
    }
    .search-card .input-group-text { background: #f4f6f9; border-right: none; }
    .search-card .form-control, .search-card .form-select { border-left: none; }
    .search-card .input-group:focus-within { box-shadow: 0 0 0 0.2rem rgba(13,110,253,.15); border-radius: 8px; }
    .search-card .btn-primary {
        font-weight: 600;
        border-radius: 8px;
    }
    .slot-result-card {
        border-radius: 14px;
        border: none;
        box-shadow: 0 6px 18px rgba(0,0,0,0.08);
        transition: transform 0.15s ease;
    }
    .slot-result-card:hover { transform: translateY(-4px); }
    .slot-type-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        background: #eef4ff;
        color: #0d6efd;
    }
    .slot-price {
        font-size: 1.3rem;
        font-weight: 700;
        color: #198754;
    }
</style>

<div class="search-hero">
    <h3 class="mb-1"><i class="fa-solid fa-magnifying-glass"></i> Search Parking Slots</h3>
    <p class="mb-0" style="opacity:0.9;">Pick a location, date, and time to see what's available right now<?php echo $selected_vehicle_id ? ' for your selected vehicle' : ''; ?>.</p>
</div>

<div class="card search-card">
    <form method="POST" class="row g-3 align-items-end" id="parkingSearchForm">
        <div class="col-md-4">
            <label class="form-label">LOCATION</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-location-dot text-primary"></i></span>
                <select name="location_id" class="form-select" required>
                    <option value="">-- Select Location --</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['id']; ?>" <?php echo (isset($_POST['location_id']) && $_POST['location_id'] == $loc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($loc['name'] . ' - ' . $loc['city']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">DATE</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-calendar-days text-primary"></i></span>
                <input type="date" id="searchDate" name="date" class="form-control" required min="<?php echo date('Y-m-d'); ?>"
                       value="<?php echo htmlspecialchars($_POST['date'] ?? date('Y-m-d')); ?>">
            </div>
        </div>
        <div class="col-md-2">
            <label class="form-label">START TIME (AM/PM)</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-clock text-primary"></i></span>
                <input type="time" id="searchStart" name="start_time" class="form-control" lang="en-US" required
                       value="<?php echo htmlspecialchars($_POST['start_time'] ?? ''); ?>">
            </div>
        </div>
        <div class="col-md-2">
            <label class="form-label">END TIME (AM/PM)</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-clock text-primary"></i></span>
                <input type="time" id="searchEnd" name="end_time" class="form-control" lang="en-US" required
                       value="<?php echo htmlspecialchars($_POST['end_time'] ?? ''); ?>">
            </div>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary w-100 py-2"><i class="fa-solid fa-magnifying-glass"></i></button>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if ($search_error): ?><div class="alert alert-warning" role="alert"><?php echo htmlspecialchars($search_error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="fa-solid fa-square-parking text-primary"></i> Available Slots</h5>
        <?php if (!empty($results)): ?>
            <span class="text-muted small"><?php echo count($results); ?> slot(s) found</span>
        <?php endif; ?>
    </div>

    <?php if (empty($results)): ?>
        <div class="card p-5 text-center">
            <i class="fa-solid fa-circle-exclamation text-warning mb-3" style="font-size:40px;"></i>
                <h5><?php echo $selected_vehicle ? 'No matching slots available' : 'No slots available'; ?></h5>
                <p class="text-muted mb-0"><?php echo $selected_vehicle ? 'No ' . htmlspecialchars($selected_vehicle['vehicle_type'], ENT_QUOTES, 'UTF-8') . ' slots are free for this vehicle and time. Try another location, date, or time.' : 'Try a different location, date, or time range.'; ?></p>
                <?php if ($selected_vehicle): ?><a class="btn btn-outline-primary mx-auto mt-3" style="width:fit-content" href="vehicles.php">Choose another vehicle</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-3 g-4">
            <?php foreach ($results as $slot): ?>
                <div class="col">
                    <div class="card slot-result-card p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="mb-0">Slot <?php echo htmlspecialchars($slot['slot_number']); ?></h5>
                            <span class="slot-type-badge"><?php echo htmlspecialchars($slot['slot_type']); ?></span>
                        </div>
                        <p class="text-muted mb-2"><i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars($slot['location_name']); ?></p>
                        <p class="mb-3"><span class="slot-price">₹<?php echo number_format($slot['price_per_hour'], 2); ?></span> <small class="text-muted">/ hour</small></p>
                        <form method="POST" action="book_slot.php">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($_SESSION['search']['date']); ?>">
                            <input type="hidden" name="start_time" value="<?php echo htmlspecialchars($_SESSION['search']['start_time']); ?>">
                            <input type="hidden" name="end_time" value="<?php echo htmlspecialchars($_SESSION['search']['end_time']); ?>">
                            <?php $matching_vehicles = array_filter($vehicles, fn($vehicle) => $vehicle['vehicle_type'] === $slot['slot_type']); ?>
                            <?php if ($matching_vehicles): ?>
                                <div class="input-group mb-2">
                                    <span class="input-group-text bg-white"><i class="fa-solid fa-car text-muted"></i></span>
                                    <select name="vehicle_number" class="form-select" required>
                                        <option value="">Select your vehicle</option>
                                        <?php foreach ($matching_vehicles as $vehicle): ?>
                                            <?php $vehicle_selected = (int) $vehicle['id'] === $selected_vehicle_id || (!$selected_vehicle_id && ($_POST['vehicle_number'] ?? '') === $vehicle['vehicle_number']); ?>
                                            <option value="<?php echo htmlspecialchars($vehicle['vehicle_number'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $vehicle_selected ? 'selected' : ''; ?>><?php echo htmlspecialchars($vehicle['vehicle_number'] . ' · ' . $vehicle['vehicle_type'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="form-label small mb-1" for="paymentMethod<?php echo (int) $slot['id']; ?>">Payment method</label>
                                <select id="paymentMethod<?php echo (int) $slot['id']; ?>" name="payment_method" class="form-select mb-2" required>
                                    <option value="upi">UPI</option>
                                    <option value="cash_on_delivery">Cash on Delivery</option>
                                </select>
                                <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-check me-1"></i> Book Now</button>
                            <?php else: ?>
                                <p class="small text-muted">No matching vehicle saved for this slot.</p>
                                <a class="btn btn-outline-primary w-100" href="vehicles.php">Manage Vehicles</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
const parkingSearchForm = document.getElementById('parkingSearchForm');
const searchDate = document.getElementById('searchDate');
const searchStart = document.getElementById('searchStart');
const searchEnd = document.getElementById('searchEnd');
function localDateString() {
    const now = new Date();
    return [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
}
function updateSearchTimeLimits() {
    const today = localDateString();
    searchStart.min = searchDate.value === today ? new Date().toTimeString().slice(0, 5) : '';
    searchEnd.min = searchStart.value || '';
}
searchDate.addEventListener('change', updateSearchTimeLimits);
searchStart.addEventListener('change', () => {
    searchStart.setCustomValidity('');
    updateSearchTimeLimits();
});
searchDate.addEventListener('change', () => searchDate.setCustomValidity(''));
searchEnd.addEventListener('change', () => searchEnd.setCustomValidity(''));
parkingSearchForm.addEventListener('submit', event => {
    updateSearchTimeLimits();
    if (searchDate.value === localDateString() && searchStart.value <= new Date().toTimeString().slice(0, 5)) {
        event.preventDefault();
        searchStart.setCustomValidity('Choose a start time in the future.');
        searchStart.reportValidity();
    } else if (searchEnd.value <= searchStart.value) {
        event.preventDefault();
        searchEnd.setCustomValidity('End time must be later than start time.');
        searchEnd.reportValidity();
    } else {
        searchStart.setCustomValidity('');
        searchEnd.setCustomValidity('');
    }
});
searchStart.addEventListener('input', () => searchStart.setCustomValidity(''));
searchEnd.addEventListener('input', () => searchEnd.setCustomValidity(''));
updateSearchTimeLimits();
</script>
<?php include 'includes/footer.php'; ?>