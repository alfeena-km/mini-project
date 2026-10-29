<?php

function format_time_ampm($time) {
    $parsed = DateTimeImmutable::createFromFormat('!H:i:s', (string) $time)
        ?: DateTimeImmutable::createFromFormat('!H:i', (string) $time);

    return $parsed ? $parsed->format('g:i A') : (string) $time;
}

function payment_method_label($method) {
    $labels = [
        'upi' => 'UPI',
        'UPI' => 'UPI',
        'cash_on_delivery' => 'Cash on Delivery',
        'Cash' => 'Cash on Delivery',
        'Card' => 'Card (legacy)',
        'Net Banking' => 'Net Banking (legacy)',
    ];

    return $labels[$method] ?? ($method !== '' ? ucfirst((string) $method) : 'Not selected');
}

function expire_unpaid_bookings(PDO $pdo) {
    try {
        $stale_check = $pdo->query("SELECT id FROM bookings
            WHERE status = 'confirmed' AND payment_status = 'pending'
              AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                            AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = bookings.id)
            LIMIT 1");
        if (!$stale_check->fetchColumn()) {
            return;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->query("SELECT id, user_id, booking_date, start_time, end_time FROM bookings
            WHERE status = 'confirmed' AND payment_status = 'pending'
              AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                            AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = bookings.id)
            FOR UPDATE");
        $expired_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cancel = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status = 'confirmed' AND payment_status = 'pending'");
        $history = $pdo->prepare("INSERT INTO booking_history (booking_id, user_id, action, old_date, old_start_time, old_end_time, remarks)
            VALUES (?, ?, 'cancelled', ?, ?, ?, 'Unpaid booking expired after 15 minutes')");
        $notification = $pdo->prepare("INSERT INTO notifications (user_id, booking_id, title, message, type)
            VALUES (?, ?, 'Booking expired', 'Your unpaid reservation expired after 15 minutes. The parking slot is available again.', 'cancellation')");

        foreach ($expired_bookings as $booking) {
            $cancel->execute([$booking['id']]);
            if ($cancel->rowCount()) {
                $history->execute([$booking['id'], $booking['user_id'], $booking['booking_date'], $booking['start_time'], $booking['end_time']]);
                $notification->execute([$booking['user_id'], $booking['id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Pending booking expiry failed: ' . $e->getMessage());
    }
}
