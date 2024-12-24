<?php
require_once 'config.php';
require_once 'botsdk/tg.php';

// Function to send notifications to all admins
function notifyAdmins($message) {
    global $conn;
    $admins = $conn->query("SELECT telegram_id FROM admin_settings WHERE notifications_enabled = TRUE");
    
    if ($admins) {
        while ($admin = $admins->fetch_assoc()) {
            bot('sendMessage', [
                'chat_id' => $admin['telegram_id'],
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
        }
    }
}

// Check for rentals that will expire in 1 hour
$soon_expiring = $conn->query("
    SELECT r.*, c.full_name as customer_name, c.phone_number, 
           cars.model, cars.license_plate
    FROM rentals r
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ON r.car_id = cars.id
    WHERE r.status = 'active' 
    AND r.notification_sent = FALSE
    AND r.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR)
");

if ($soon_expiring && $soon_expiring->num_rows > 0) {
    while ($rental = $soon_expiring->fetch_assoc()) {
        // Create notification message
        $message = "⚠��� <b>IJARA MUDDATI TUGAYAPTI</b>\n\n";
        $message .= "🚘 Mashina: {$rental['model']} - {$rental['license_plate']}\n";
        $message .= "👤 Mijoz: {$rental['customer_name']}\n";
        $message .= "📱 Tel: {$rental['phone_number']}\n";
        $message .= "⏰ Tugash vaqti: " . date('d.m.Y H:i', strtotime($rental['end_date'])) . "\n";
        $message .= "💰 Ijara: " . number_format($rental['rental_price']) . " so'm\n";
        
        if ($rental['deposit_type'] === 'money') {
            $message .= "🔐 Zalog: " . number_format($rental['deposit_amount']) . " so'm\n";
        } else {
            $message .= "🔐 Zalog: {$rental['deposit_items']}\n";
        }
        
        // Send notification to admins
        notifyAdmins($message);
        
        // Mark notification as sent
        $conn->query("UPDATE rentals SET notification_sent = TRUE WHERE id = {$rental['id']}");
        
        // Log notification
        $conn->query("INSERT INTO notifications (type, rental_id, message, send_at) 
                     VALUES ('rental_expiring', {$rental['id']}, '" . $conn->real_escape_string($message) . "', NOW())");
    }
}

// Check for expired rentals
$expired = $conn->query("
    SELECT r.*, c.full_name as customer_name, c.phone_number, 
           cars.model, cars.license_plate
    FROM rentals r
    JOIN customers c ON r.customer_id = c.id
    JOIN cars ON r.car_id = cars.id
    WHERE r.status = 'active'
    AND r.end_date < NOW()
    AND r.notification_sent = FALSE
");

if ($expired && $expired->num_rows > 0) {
    while ($rental = $expired->fetch_assoc()) {
        // Create notification message
        $message = "🚨 <b>IJARA MUDDATI TUGADI</b>\n\n";
        $message .= "🚘 Mashina: {$rental['model']} - {$rental['license_plate']}\n";
        $message .= "👤 Mijoz: {$rental['customer_name']}\n";
        $message .= "📱 Tel: {$rental['phone_number']}\n";
        $message .= "⏰ Tugagan vaqt: " . date('d.m.Y H:i', strtotime($rental['end_date'])) . "\n";
        $message .= "⏱ Kechikish: " . ceil((time() - strtotime($rental['end_date'])) / 3600) . " soat\n";
        $message .= "💰 Ijara: " . number_format($rental['rental_price']) . " so'm\n";
        
        if ($rental['deposit_type'] === 'money') {
            $message .= "🔐 Zalog: " . number_format($rental['deposit_amount']) . " so'm\n";
        } else {
            $message .= "🔐 Zalog: {$rental['deposit_items']}\n";
        }
        
        // Send notification to admins
        notifyAdmins($message);
        
        // Update rental status to overdue
        $conn->query("UPDATE rentals SET status = 'overdue', notification_sent = TRUE WHERE id = {$rental['id']}");
        
        // Log notification
        $conn->query("INSERT INTO notifications (type, rental_id, message, send_at) 
                     VALUES ('rental_expired', {$rental['id']}, '" . $conn->real_escape_string($message) . "', NOW())");
    }
}

// Optional: Clean up old notifications
$conn->query("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"); 