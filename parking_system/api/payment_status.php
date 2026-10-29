<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
require_user();

header('Content-Type: application/json; charset=utf-8');
$stmt = $pdo->prepare("SELECT b.id AS booking_id, b.payment_status AS booking_payment_status,
        p.payment_method, p.payment_status AS recorded_payment_status
    FROM bookings b
    LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id AND p2.user_id = b.user_id)
    WHERE b.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$payments = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $payments[(string) $row['booking_id']] = [
        'method' => $row['payment_method'],
        'status' => $row['recorded_payment_status'] ?? $row['booking_payment_status'],
    ];
}
echo json_encode(['payments' => $payments], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);