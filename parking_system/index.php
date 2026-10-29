<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    $redirect = 'dashboard.php';
    if ($_SESSION['role'] === 'admin') {
        $redirect = 'admin/admin_dashboard.php';
    } elseif ($_SESSION['role'] === 'staff') {
        $redirect = 'staff_dashboard.php';
    }

    header("Location: " . $redirect);
    exit;
}

// Live counts for the stats strip
$total_locations = $pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn();
$total_slots = $pdo->query("SELECT COUNT(*) FROM parking_slots WHERE status='available'")->fetchColumn();
$total_bookings = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();

$page_title = "Home";
include 'includes/header.php';
?>

<style>
    .hero {
        --hero-ink: #f7fbff;
        background: linear-gradient(118deg, #123b43 0%, #14615d 55%, #348b75 100%);
        border-radius: 20px;
        padding: clamp(32px, 6vw, 68px) clamp(24px, 5vw, 58px);
        color: #fff;
        position: relative;
        overflow: hidden;
        margin-bottom: 58px;
        isolation: isolate;
    }
    .hero::before {
        content: "";
        position: absolute;
        inset: 0;
        z-index: -1;
        opacity: .22;
        background-image: linear-gradient(rgba(255,255,255,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.08) 1px, transparent 1px);
        background-size: 42px 42px;
        mask-image: linear-gradient(90deg, transparent, #000 75%);
    }
    .hero-copy { position: relative; z-index: 2; }
    .hero-kicker {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 8px 12px;
        border: 1px solid rgba(255,255,255,.3);
        border-radius: 999px;
        background: rgba(7,38,39,.28);
        color: #e4f5df;
        font-size: .82rem;
        font-weight: 700;
    }
    .hero h1 { max-width: 680px; line-height: 1.06; }
    .hero-copy > p { max-width: 570px; color: rgba(255,255,255,.82); }
    .hero-actions { display: flex; flex-wrap: wrap; gap: 12px; }
    .hero-actions .btn {
        min-height: 50px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        padding: 12px 20px;
        border-radius: 9px;
        font-weight: 700;
        text-decoration: none;
        transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .hero-actions .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,.18); }
    .hero-actions .btn-light { color: #164c48; }
    .hero-actions .btn-outline-light { border-width: 1px; }
    .hero-scene {
        height: 310px;
        max-width: 440px;
        margin: 8px auto 0;
        position: relative;
        overflow: hidden;
        border-radius: 18px;
        background: linear-gradient(180deg, rgba(221,244,226,.12), rgba(8,41,43,.34));
        border: 1px solid rgba(255,255,255,.2);
        box-shadow: inset 0 1px rgba(255,255,255,.12), 0 18px 44px rgba(4,32,32,.22);
    }
    .scene-glow {
        position: absolute;
        width: 210px;
        height: 210px;
        top: 20px;
        right: 24px;
        border-radius: 50%;
        background: rgba(198,237,148,.17);
        filter: blur(2px);
    }
    .scene-label {
        position: absolute;
        top: 18px;
        left: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: rgba(255,255,255,.84);
        font-size: .76rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .scene-label i { color: #c6ed94; }
    .parking-bay {
        position: absolute;
        right: 28px;
        bottom: 50px;
        width: 126px;
        height: 148px;
        border: 3px solid rgba(228,245,223,.9);
        border-bottom: 0;
        transform: perspective(220px) rotateX(14deg) skewX(-8deg);
    }
    .parking-bay::before, .parking-bay::after {
        content: "";
        position: absolute;
        top: 18px;
        bottom: 0;
        width: 2px;
        border-left: 2px dashed rgba(228,245,223,.48);
    }
    .parking-bay::before { left: 24px; }
    .parking-bay::after { right: 24px; }
    .bay-p { position: absolute; inset: 38px 0 auto; color: #d9f2bf; font-size: 38px; text-align: center; opacity: .92; }
    .road-line {
        position: absolute;
        left: 24px;
        right: 24px;
        bottom: 48px;
        height: 2px;
        background: repeating-linear-gradient(90deg, rgba(255,255,255,.55) 0 26px, transparent 26px 44px);
        opacity: .5;
    }
    .scene-car {
        position: absolute;
        z-index: 3;
        left: 30px;
        bottom: 58px;
        width: 118px;
        height: 66px;
        color: #e9f7de;
        filter: drop-shadow(0 13px 8px rgba(0,0,0,.24));
        animation: park-arrive 5.4s cubic-bezier(.4,0,.2,1) infinite;
    }
    .scene-car i { display: block; font-size: 100px; line-height: .7; transform: scaleX(-1); }
    .scene-car::after {
        content: "";
        position: absolute;
        width: 19px;
        height: 19px;
        left: 7px;
        bottom: -2px;
        border: 5px solid #173b3a;
        border-radius: 50%;
        background: #bfdc9b;
        box-shadow: 77px 0 0 -5px #bfdc9b, 77px 0 0 0 #173b3a;
    }
    .scene-spark {
        position: absolute;
        color: #d6f39e;
        animation: spark-float 2.8s ease-in-out infinite;
    }
    .spark-one { top: 82px; right: 72px; font-size: 18px; }
    .spark-two { top: 125px; right: 196px; font-size: 12px; animation-delay: .6s; }
    @keyframes park-arrive {
        0%, 12% { transform: translateX(-12px) translateY(0) rotate(-1deg); opacity: .35; }
        30%, 64% { transform: translateX(110px) translateY(-5px) rotate(0); opacity: 1; }
        76%, 100% { transform: translateX(110px) translateY(-5px) rotate(0); opacity: .25; }
    }
    @keyframes spark-float {
        0%, 100% { transform: translateY(0) scale(.8); opacity: .45; }
        50% { transform: translateY(-9px) scale(1.1); opacity: 1; }
    }
    .stats-strip {
        margin-top: -70px;
        margin-bottom: 50px;
        position: relative;
        z-index: 2;
    }
    .stat-card {
        background: #fff;
        border-radius: 14px;
        padding: 22px;
        text-align: center;
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    }
    .stat-card h3 { font-weight: 700; color: #16776e; margin-bottom: 2px; }
    .feature-card {
        border-radius: 14px;
        padding: 28px 22px;
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .feature-card:hover { transform: translateY(-6px); box-shadow: 0 12px 28px rgba(0,0,0,0.1); }
    .feature-icon {
        width: 56px; height: 56px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 24px;
        margin-bottom: 16px;
        color: #fff;
    }
    .step-circle {
        width: 46px; height: 46px;
        border-radius: 50%;
        background: #16776e;
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700;
        flex-shrink: 0;
    }
    .cta-banner {
        background: #143d42;
        color: #fff;
        border-radius: 20px;
        padding: 50px;
        text-align: center;
        margin: 60px 0 30px;
    }
    .cta-banner .btn { border-radius: 9px; padding: 12px 22px; font-weight: 700; }
    @media (max-width: 767.98px) {
        .hero { margin-bottom: 42px; }
        .hero-scene { height: 250px; }
        .parking-bay { transform: scale(.82) perspective(220px) rotateX(14deg) skewX(-8deg); transform-origin: bottom right; right: 18px; }
        .scene-car { animation-duration: 5.4s; }
        .stats-strip { margin-top: -52px; }
        .cta-banner { padding: 34px 22px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .scene-car, .scene-spark { animation: none; }
        .scene-car { transform: translateX(110px) translateY(-5px); }
        .hero-actions .btn, .feature-card { transition: none; }
        .hero-actions .btn:hover, .feature-card:hover { transform: none; }
    }
</style>

<!-- Hero -->
<div class="hero">
    <div class="row align-items-center">
        <div class="col-lg-7">
            <div class="hero-copy">
                <span class="hero-kicker mb-3"><i class="fa-solid fa-location-dot"></i> Smart parking, simplified</span>
                <h1 class="display-5 fw-bold mb-3">Find your space. Park with confidence.</h1>
                <p class="lead mb-4">Choose a location and time, check live slot availability, and reserve parking before you arrive.</p>
                <div class="hero-actions">
                    <button type="button" class="btn btn-light" onclick="window.location.href='register.php'">Create an account <i class="fa-solid fa-arrow-right"></i></button>
                    <button type="button" class="btn btn-outline-light" onclick="window.location.href='login.php'"><i class="fa-solid fa-arrow-right-to-bracket"></i> Sign in</button>
                </div>
            </div>
        </div>
        <div class="col-lg-5 text-center mt-4 mt-lg-0">
            <div class="hero-scene" role="img" aria-label="Animated car arriving at an available parking space">
                <div class="scene-glow"></div>
                <div class="scene-label"><i class="fa-solid fa-circle-check"></i> Space reserved</div>
                <div class="parking-bay"><span class="bay-p">P</span></div>
                <div class="road-line"></div>
                <div class="scene-spark spark-one"><i class="fa-solid fa-sparkles"></i></div>
                <div class="scene-spark spark-two"><i class="fa-solid fa-star"></i></div>
                <div class="scene-car" aria-hidden="true"><i class="fa-solid fa-car-side"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="row stats-strip g-3">
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <h3><?php echo $total_locations; ?>+</h3>
            <small class="text-muted">Parking Locations</small>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <h3><?php echo $total_slots; ?></h3>
            <small class="text-muted">Slots in service</small>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <h3><?php echo $total_bookings; ?>+</h3>
            <small class="text-muted">Slots Booked So Far</small>
        </div>
    </div>
</div>

<!-- Features -->
<div class="text-center mb-4">
    <h2 class="fw-bold">Why Choose ParkEasy?</h2>
    <p class="text-muted">Everything you need to park stress-free.</p>
</div>
<div class="row g-4 mb-3">
    <div class="col-md-3 col-sm-6">
        <div class="card feature-card">
            <div class="feature-icon" style="background:#0d6efd;"><i class="fa-solid fa-magnifying-glass-location"></i></div>
            <h5>Search by Location</h5>
            <p class="text-muted mb-0">Find parking near your destination by location, date, and time in one search.</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card feature-card">
            <div class="feature-icon" style="background:#198754;"><i class="fa-solid fa-clock"></i></div>
            <h5>Real-Time Availability</h5>
            <p class="text-muted mb-0">See only the slots that are actually free for your chosen date and time.</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card feature-card">
            <div class="feature-icon" style="background:#fd7e14;"><i class="fa-solid fa-square-check"></i></div>
            <h5>Instant Booking</h5>
            <p class="text-muted mb-0">Reserve your slot in a few clicks and get an instant confirmation.</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card feature-card">
            <div class="feature-icon" style="background:#6f42c1;"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <h5>Transparent Fees</h5>
            <p class="text-muted mb-0">Fees are calculated automatically based on your exact parking duration.</p>
        </div>
    </div>
</div>

<!-- How it works -->
<div class="card p-4 p-md-5 mt-4">
    <h3 class="text-center fw-bold mb-4">How It Works</h3>
    <div class="row g-4">
        <div class="col-md-4 d-flex align-items-start gap-3">
            <div class="step-circle">1</div>
            <div>
                <h6 class="fw-bold mb-1">Search</h6>
                <p class="text-muted mb-0">Choose a location, date, and time to see available slots.</p>
            </div>
        </div>
        <div class="col-md-4 d-flex align-items-start gap-3">
            <div class="step-circle">2</div>
            <div>
                <h6 class="fw-bold mb-1">Book</h6>
                <p class="text-muted mb-0">Pick a slot, enter your vehicle number, and confirm your booking.</p>
            </div>
        </div>
        <div class="col-md-4 d-flex align-items-start gap-3">
            <div class="step-circle">3</div>
            <div>
                <h6 class="fw-bold mb-1">Park & Go</h6>
                <p class="text-muted mb-0">Arrive at your reserved slot — manage or cancel anytime from your account.</p>
            </div>
        </div>
    </div>
</div>

<!-- CTA -->
<div class="cta-banner">
    <h2 class="fw-bold mb-3">Ready to skip the parking hassle?</h2>
    <p class="mb-4" style="opacity:0.85;">Create a free account and book your first parking slot in under a minute.</p>
            <button type="button" class="btn btn-light btn-lg" onclick="window.location.href='register.php'"><i class="fa-solid fa-user-plus me-2"></i>Create Free Account</button>
</div>

<?php include 'includes/footer.php'; ?>