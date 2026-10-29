<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
$location_id = filter_var($_GET['location_id'] ?? null, FILTER_VALIDATE_INT);
if (!$location_id || !$pdo->query('SELECT 1 FROM locations WHERE id = ' . (int) $location_id)->fetchColumn()) {
    $location_id = null;
}
$location_clause = $location_id ? 'WHERE ps.location_id = ' . (int) $location_id : '';
$stats = $pdo->query("SELECT COUNT(p.id) AS total_payments,
    SUM(p.payment_status = 'pending') AS pending_payments,
    SUM(p.payment_status = 'completed') AS completed_payments,
    SUM(p.payment_method = 'upi') AS upi_payments,
    SUM(p.payment_method = 'cash_on_delivery') AS cash_payments,
    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount ELSE 0 END), 0) AS completed_total,
    COALESCE(SUM(CASE WHEN p.payment_status = 'completed' AND p.paid_at >= CURDATE() THEN p.amount ELSE 0 END), 0) AS today_total
    FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN parking_slots ps ON ps.id = b.slot_id $location_clause")->fetch(PDO::FETCH_ASSOC);
echo json_encode($stats, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);