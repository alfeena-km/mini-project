<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_staff();

header('Content-Type: application/json; charset=utf-8');
$staff_location_id = (int) $_SESSION['staff_location_id'];
$stats = $pdo->query("SELECT
    (SELECT COUNT(*) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.booking_date=CURDATE()) AS today_bookings,
    (SELECT COUNT(*) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.status='active') AS active_bookings,
    (SELECT COUNT(*) FROM parking_slots ps WHERE ps.location_id=$staff_location_id AND ps.status='available'
        AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.slot_id = ps.id AND b.status = 'active')
        AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.slot_id = ps.id AND b.status = 'confirmed'
            AND TIMESTAMP(b.booking_date, b.end_time) > NOW())) AS available_slots,
    (SELECT COUNT(DISTINCT b.slot_id) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.status='active') AS occupied_slots,
    (SELECT COUNT(DISTINCT b.slot_id) FROM bookings b JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND b.status='confirmed' AND TIMESTAMP(b.booking_date,b.end_time)>NOW()) AS reserved_slots,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='pending') AS pending_payments,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='completed') AS completed_payments,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='completed' AND p.payment_method='cash_on_delivery') AS cash_payments,
    (SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN parking_slots ps ON ps.id=b.slot_id WHERE ps.location_id=$staff_location_id AND p.payment_status='completed' AND p.payment_method='upi') AS upi_payments")->fetch(PDO::FETCH_ASSOC);
echo json_encode($stats, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);