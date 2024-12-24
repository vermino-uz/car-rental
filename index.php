<?php
require_once 'config.php';
require_once 'botsdk/tg.php';

// Set timezone to Asia/Tashkent
date_default_timezone_set('Asia/Tashkent');

// Check if user is admin
function isAdmin($chat_id) {
    global $conn;
    $result = $conn->query("SELECT role FROM admin_settings WHERE telegram_id = $chat_id");
    return ($result && $result->num_rows > 0);
}

// Check if user is main admin
function isMainAdmin($chat_id) {
    global $conn;
    $result = $conn->query("SELECT role FROM admin_settings WHERE telegram_id = $chat_id AND role = 'admin'");
    return ($result && $result->num_rows > 0);
}

// Only allow /setup command for non-admins to set up the first admin
if ($text == '/setup') {
    // Check if main admin already exists
    $check = $conn->query("SELECT id FROM admin_settings WHERE role = 'admin'");
    if ($check && $check->num_rows > 0) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Asosiy admin allaqachon mavjud."
        ]);
    } else {
        // Set up main admin
        $conn->query("INSERT INTO admin_settings (telegram_id, full_name, role) VALUES ($chat_id, 'Admin', 'admin')");
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ Siz muvaffaqiyatli asosiy admin etib tayinlandingiz!\n\n" .
                     "Admin boshqaruvi uchun /admin buyrug'ini bering."
        ]);
    }
    exit;
}

// Check if user is admin before processing any other commands
if (!isAdmin($chat_id)) {
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "❌ Kechirasiz, bu bot faqat adminlar uchun.\n\n" .
                 "Agar siz admin bo'lsangiz, iltimos asosiy admin bilan bog'laning."
    ]);
    exit;
}

if($text == "/time") {
    #send current server time 
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "🕒 Server vaqti: " . date('d.m.Y H:i:s')
    ]);
}

// Add this function at the top of the file after requires
function checkExpiredRentals() {
    global $conn, $chat_id;
    
    // First check if the user is an admin
    $admin_check = $conn->query("SELECT id FROM admin_settings WHERE telegram_id = $chat_id AND notifications_enabled = TRUE");
    if (!$admin_check || $admin_check->num_rows === 0) {
        return;
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
            
            // Send notification
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
            
            // Update rental status to overdue
            $conn->query("UPDATE rentals SET status = 'overdue', notification_sent = TRUE WHERE id = {$rental['id']}");
            
            // Log notification
            $conn->query("INSERT INTO notifications (type, rental_id, message, send_at) 
                         VALUES ('rental_expired', {$rental['id']}, '" . $conn->real_escape_string($message) . "', NOW())");
        }
    }

    // Also check for rentals that will expire in 1 hour
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
            $message = "⚠️ <b>IJARA MUDDATI TUGAYAPTI</b>\n\n";
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
            
            // Send notification
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
            
            // Mark notification as sent
            $conn->query("UPDATE rentals SET notification_sent = TRUE WHERE id = {$rental['id']}");
            
            // Log notification
            $conn->query("INSERT INTO notifications (type, rental_id, message, send_at) 
                         VALUES ('rental_expiring', {$rental['id']}, '" . $conn->real_escape_string($message) . "', NOW())");
        }
    }
}

// Add this function at the top of the file after requires
function writeLog($message) {
    $log_file = __DIR__ . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// States for conversation flow
$states = [
    'IDLE',
    'AWAITING_CUSTOMER_NAME',
    'AWAITING_CUSTOMER_PHONE',
    'AWAITING_CAR_SELECTION',
    'AWAITING_DEPOSIT',
    'AWAITING_RENTAL_PERIOD',
    'CONFIRMING_RENTAL',
    'AWAITING_CAR_MODEL',
    'AWAITING_CAR_LICENSE',
    'AWAITING_CAR_MILEAGE'
];

// Command handlers
if ($text == '/start' || $text == '⬅️ Asosiy menyu') {
    $keyboard = [
        [
            ['text' => '🚗 Yangi ijara', 'web_app' => ['url'=>"https://vermino.uz/bots/orders/ibo/rent_form.php?user_id=$chat_id"]],
            
        ],
        [
            ['text' => '📊 Ijaralar ro\'yxati', 'callback_data' => 'rental_list'],
            // ['text' => '🚨 Shtraflar', 'callback_data' => 'violations'],
            ['text' => '📱 Mijozlar', 'callback_data' => 'customers']
        ],
        [
            ['text' => '🚗 Mashinalar holati', 'callback_data' => 'car_status'],
            ['text' => '➕ Mashina qo\'shish', 'callback_data' => 'add_car']
        ]
    ];
    
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "Assalomu alaykum, 715RentCar tizimiga xush kelibsiz!\n\nQuyidagi menyudan kerakli bo'limni tanlang:",
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);
    
    // Reset state to IDLE
    $conn->query("INSERT INTO user_states (user_id, state) VALUES ($from_id, 'IDLE') 
                 ON DUPLICATE KEY UPDATE state = 'IDLE', temp_data = NULL");
    
    // Check for expired rentals
    checkExpiredRentals();
}

// Add after the other command handlers
if ($text == '/clear') {
    if (isMainAdmin($chat_id)) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "⚠️ DIQQAT! Bu buyruq bazadagi barcha ma'lumotlarni o'chirib tashlaydi!\n\n" .
                     "Bu quyidagi ma'lumotlarni o'z ichiga oladi:\n" .
                     "- Mashinalar\n" .
                     "- Mijozlar\n" .
                     "- Ijaralar\n" .
                     "- Shtraflar\n" .
                     "- Xabarlar\n\n" .
                     "Davom etishni xohlaysizmi?",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "❌ Yo'q", 'callback_data' => 'clear_cancel'],
                        ['text' => "✅ Ha", 'callback_data' => 'clear_confirm']
                    ]
                ]
            ])
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Kechirasiz, bu buyruq faqat asosiy admin uchun."
        ]);
    }
}

// Add after the other command handlers
if ($text == '/setup') {
    // Check if main admin already exists
    $check = $conn->query("SELECT id FROM admin_settings WHERE role = 'admin'");
    if ($check && $check->num_rows > 0) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Asosiy admin allaqachon mavjud."
        ]);
    } else {
        // Set up main admin
        $conn->query("INSERT INTO admin_settings (telegram_id, full_name, role) VALUES ($chat_id, 'Admin', 'admin')");
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ Siz muvaffaqiyatli asosiy admin etib tayinlandingiz!\n\n" .
                     "Admin boshqaruvi uchun /admin buyrug'ini bering."
        ]);
    }
}

// Handle callback queries
if (isset($callback)) {
    $data = $callback->data;
    
    switch ($data) {
        case 'new_rental':
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "Mijozning to'liq ismini kiriting:",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
            $conn->query("INSERT INTO user_states (user_id, state) VALUES ($from_id, 'AWAITING_CUSTOMER_NAME') 
                         ON DUPLICATE KEY UPDATE state = 'AWAITING_CUSTOMER_NAME', temp_data = NULL");
            break;
            
        case 'rental_list':
            // Get active rentals
            $active_rentals = $conn->query("
                SELECT r.*, c.full_name, c.phone_number, cars.model, cars.license_plate 
                FROM rentals r 
                JOIN customers c ON r.customer_id = c.id 
                JOIN cars ON r.car_id = cars.id 
                WHERE r.status = 'active' 
                ORDER BY r.end_date ASC
            ");
            
            // Get completed rentals (last 30 days)
            $completed_rentals = $conn->query("
                SELECT r.*, c.full_name, c.phone_number, cars.model, cars.license_plate 
                FROM rentals r 
                JOIN customers c ON r.customer_id = c.id 
                JOIN cars ON r.car_id = cars.id 
                WHERE r.status = 'completed' 
                AND r.actual_return_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY r.actual_return_date DESC
            ");
            
            // Active rentals section
            $response = "📊 <b>Faol ijaralar:</b>\n\n";
            if ($active_rentals && $active_rentals->num_rows > 0) {
                while ($rental = $active_rentals->fetch_assoc()) {
                    $response .= "🚗 {$rental['model']} - {$rental['license_plate']}\n";
                    $response .= "👤 {$rental['full_name']}\n";
                    $response .= "📱 {$rental['phone_number']}\n";
                    $response .= "⏰ Tugash vaqti: " . date('d.m.Y H:i', strtotime($rental['end_date'])) . "\n";
                    $response .= "💰 Ijara: " . number_format($rental['rental_price']) . " so'm\n";
                    if ($rental['deposit_type'] === 'money') {
                        $response .= "🔐 Zalog: " . number_format($rental['deposit_amount']) . " so'm\n";
                    } else {
                        $response .= "🔐 Zalog: {$rental['deposit_items']}\n";
                    }
                    $response .= "\n";
                }
            } else {
                $response .= "Hozirda faol ijaralar mavjud emas.\n\n";
            }
            
           bot('deletemessage' [
               'chat_id' => $chat_id,
               'message_id' => $callback->message->message_id
           ]);
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '📜 Tugatilgan ijaralar', 'callback_data' => 'completed_rentals']],
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
            break;
            
        case 'completed_rentals':
            // Get completed rentals (last 30 days)
            $completed_rentals = $conn->query("
                SELECT r.*, c.full_name, c.phone_number, cars.model, cars.license_plate 
                FROM rentals r 
                JOIN customers c ON r.customer_id = c.id 
                JOIN cars ON r.car_id = cars.id 
                WHERE r.status = 'completed' 
                AND r.actual_return_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY r.actual_return_date DESC
            ");
            
            $response = "📜 <b>Tugatilgan ijaralar</b> (oxirgi 30 kun):\n\n";
            if ($completed_rentals && $completed_rentals->num_rows > 0) {
                while ($rental = $completed_rentals->fetch_assoc()) {
                    $response .= "🚗 {$rental['model']} - {$rental['license_plate']}\n";
                    $response .= "👤 {$rental['full_name']}\n";
                    $response .= "📱 {$rental['phone_number']}\n";
                    $response .= "📅 Ijara davri: " . date('d.m.Y H:i', strtotime($rental['start_date'])) . 
                                " - " . date('d.m.Y H:i', strtotime($rental['actual_return_date'])) . "\n";
                    $response .= "💰 Ijara: " . number_format($rental['rental_price']) . " so'm\n";
                    $response .= "📊 Probeg: " . number_format($rental['start_mileage']) . " → " . 
                                number_format($rental['end_mileage']) . " km\n";
                    if ($rental['deposit_type'] === 'money') {
                        $response .= "🔐 Zalog: " . number_format($rental['deposit_amount']) . " so'm\n";
                    } else {
                        $response .= "🔐 Zalog: {$rental['deposit_items']}\n";
                    }
                    $response .= "\n";
                }
            } else {
                $response .= "Oxirgi 30 kunda tugatilgan ijaralar mavjud emas.";
            }
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '📊 Faol ijaralar', 'callback_data' => 'rental_list']],
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
            break;
            
        case 'car_status':
            $cars = $conn->query("SELECT c.*, 
                (SELECT SUM(rental_price) FROM rentals WHERE car_id = c.id AND status = 'completed') as total_earnings,
                (SELECT COUNT(*) FROM rentals WHERE car_id = c.id AND status = 'completed') as total_rentals
                FROM cars c");
            $response = "🚗 Avtomobillar holati (Umumiy):\n\n";
            $keyboard = [];
            
            if ($cars && $cars->num_rows > 0) {
                while ($car = $cars->fetch_assoc()) {
                    $status = $car['status'] == 'rented' ? "🔴 Band" : "🟢 Bo'sh";
                    if ($car['status'] == 'rented') {
                        $rental = $conn->query("SELECT r.*, c.full_name, c.phone_number FROM rentals r 
                                              JOIN customers c ON r.customer_id = c.id 
                                              WHERE r.car_id = {$car['id']} AND r.status = 'active'")->fetch_assoc();
                        $customer_info = $rental ? "\n👤 Mijoz: {$rental['full_name']}\n📱 Tel: {$rental['phone_number']}\n⏰ Qaytarish: {$rental['end_date']}" : "";
                        
                        if ($rental) {
                            $keyboard[] = [['text' => "🔄 Qaytarish: {$car['model']} - {$car['license_plate']}", 
                                          'callback_data' => 'return_car_' . $car['id']]];
                        }
                    }
                    
                    $response .= "🚘 Rusumi: {$car['model']}\n";
                    $response .= "🔢 Davlat raqami: {$car['license_plate']}\n";
                    $response .= "📊 Probeg: " . number_format($car['current_mileage']) . " km\n";
                    $response .= "📍 Holati: $status$customer_info\n";
                    $response .= "💰 Jami daromad: " . ($car['total_earnings'] ? number_format($car['total_earnings']) : "0") . " so'm\n";
                    $response .= "🔄 Jami ijaralar: " . ($car['total_rentals'] ? number_format($car['total_rentals']) : "0") . " ta\n\n";
                }
            } else {
                $response .= "Hozircha avtomobillar mavjud emas.";
            }
            
            // Add total earnings for all cars
            $total_stats = $conn->query("SELECT 
                SUM(rental_price) as total_earnings,
                COUNT(*) as total_rentals
                FROM rentals 
                WHERE status = 'completed'")->fetch_assoc();
            
            if ($total_stats['total_earnings']) {
                $response .= "\n📈 <b>Umumiy statistika:</b>\n";
                $response .= "💰 Jami daromad: " . number_format($total_stats['total_earnings']) . " so'm\n";
                $response .= "🔄 Jami ijaralar: " . number_format($total_stats['total_rentals']) . " ta\n";
            }
            
            // Add toggle and back buttons
            $keyboard[] = [['text' => '📅 Oxirgi 30 kun', 'callback_data' => 'car_status_monthly']];
            $keyboard[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']];
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            break;
            
        case 'violations':
            $violations = $conn->query("
                SELECT v.*, r.car_id, c.full_name, c.phone_number, cars.model, cars.license_plate 
                FROM violations v 
                JOIN rentals r ON v.rental_id = r.id 
                JOIN customers c ON r.customer_id = c.id 
                JOIN cars ON r.car_id = cars.id 
                ORDER BY v.violation_date DESC
            ");
            
            $response = "🚨 Shtraflar ro'yxati:\n\n";
            if ($violations && $violations->num_rows > 0) {
                while ($violation = $violations->fetch_assoc()) {
                    $response .= "🚘 {$violation['model']} - {$violation['license_plate']}\n";
                    $response .= "👤 {$violation['full_name']}\n";
                    $response .= "📱 {$violation['phone_number']}\n";
                    $response .= "📅 Sana: {$violation['violation_date']}\n";
                    $response .= "💰 Summa: " . number_format($violation['fine_amount']) . " so'm\n";
                    if ($violation['description']) {
                        $response .= "📝 Izoh: {$violation['description']}\n";
                    }
                    $response .= "\n";
                }
            } else {
                $response .= "Shtraflar mavjud emas.";
            }
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
            break;
            
        case 'customers':
            $customers = $conn->query("
                SELECT c.*, COUNT(r.id) as rental_count 
                FROM customers c 
                LEFT JOIN rentals r ON c.id = r.customer_id 
                GROUP BY c.id 
                ORDER BY c.created_at DESC
            ");
            
            $response = "👥 Mijozlar ro'yxati:\n\n";
            if ($customers && $customers->num_rows > 0) {
                while ($customer = $customers->fetch_assoc()) {
                    $response .= "👤 {$customer['full_name']}\n";
                    $response .= "📱 {$customer['phone_number']}\n";
                    $response .= "🔄 Ijaralar soni: {$customer['rental_count']}\n\n";
                }
            } else {
                $response .= "Mijozlar mavjud emas.";
            }
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $response,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
            break;
            
        case 'back_to_main':
            $keyboard = [
                [
                    ['text' => '🚗 Yangi ijara', 'web_app' => ['url'=>"https://vermino.uz/bots/orders/ibo/rent_form.php?user_id=$chat_id"]],
                ],
                [
                    ['text' => '📊 Ijaralar ro\'yxati', 'callback_data' => 'rental_list'],
                    // ['text' => '🚨 Shtraflar', 'callback_data' => 'violations'],
                    ['text' => '📱 Mijozlar', 'callback_data' => 'customers']
                ],
                [
                    ['text' => '🚗 Mashinalar holati', 'callback_data' => 'car_status'],
                    ['text' => '➕ Mashina qo\'shish', 'callback_data' => 'add_car']
                ]
            ];
            
            bot('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $mid,
                'text' => "Asosiy menyu:",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            
            $conn->query("UPDATE user_states SET state = 'IDLE', temp_data = NULL WHERE user_id = $from_id");
            break;
            
        case 'add_car':
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "Yangi mashina qo'shish uchun ma'lumotlarni kiriting.\n\nMashina rusumini kiriting:",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
            $conn->query("UPDATE user_states SET state = 'AWAITING_CAR_MODEL', temp_data = NULL WHERE user_id = $from_id");
            break;
        
        case (preg_match('/^return_car_(\d+)$/', $data, $matches) ? true : false):
            $car_id = $matches[1];
            
            // Get rental and car info
            $rental_info = $conn->query("
                SELECT r.*, c.full_name, c.phone_number, cars.model, cars.license_plate, cars.current_mileage
                FROM rentals r
                JOIN customers c ON r.customer_id = c.id
                JOIN cars ON r.car_id = cars.id
                WHERE r.car_id = $car_id AND r.status = 'active'
            ")->fetch_assoc();
            
            if ($rental_info) {
                // Store car info in temp data
                $temp_data = [
                    'car_id' => $car_id,
                    'rental_id' => $rental_info['id'],
                    'start_mileage' => $rental_info['start_mileage'],
                    'current_mileage' => $rental_info['current_mileage']
                ];
                $escaped_temp_data = $conn->real_escape_string(json_encode($temp_data));
                $conn->query("UPDATE user_states SET state = 'AWAITING_RETURN_MILEAGE', temp_data = '$escaped_temp_data' WHERE user_id = $from_id");
                
                $message = "🔄 Mashina qaytarish:\n\n";
                $message .= "🚘 Mashina: {$rental_info['model']} - {$rental_info['license_plate']}\n";
                $message .= "👤 Mijoz: {$rental_info['full_name']}\n";
                $message .= "📱 Tel: {$rental_info['phone_number']}\n";
                $message .= "📊 Boshlang'ich probeg: " . number_format($rental_info['start_mileage']) . " km\n\n";
                $message .= "Joriy probegni kiriting (km):";
                
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => $message,
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '⬅️ Bekor qilish', 'callback_data' => 'car_status']]
                        ]
                    ])
                ]);
            }
            break;
        
        case 'car_status_monthly':
            $cars = $conn->query("SELECT c.*, 
                (SELECT SUM(rental_price) FROM rentals 
                 WHERE car_id = c.id 
                 AND status = 'completed'
                 AND actual_return_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as total_earnings,
                (SELECT COUNT(*) FROM rentals 
                 WHERE car_id = c.id 
                 AND status = 'completed'
                 AND actual_return_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as total_rentals
                FROM cars c");
            $response = "🚗 Avtomobillar holati (Oxirgi 30 kun):\n\n";
            $keyboard = [];
            
            if ($cars && $cars->num_rows > 0) {
                while ($car = $cars->fetch_assoc()) {
                    $status = $car['status'] == 'rented' ? "🔴 Band" : "🟢 Bo'sh";
                    if ($car['status'] == 'rented') {
                        $rental = $conn->query("SELECT r.*, c.full_name, c.phone_number FROM rentals r 
                                              JOIN customers c ON r.customer_id = c.id 
                                              WHERE r.car_id = {$car['id']} AND r.status = 'active'")->fetch_assoc();
                        $customer_info = $rental ? "\n👤 Mijoz: {$rental['full_name']}\n📱 Tel: {$rental['phone_number']}\n⏰ Qaytarish: {$rental['end_date']}" : "";
                        
                        if ($rental) {
                            $keyboard[] = [['text' => "🔄 Qaytarish: {$car['model']} - {$car['license_plate']}", 
                                          'callback_data' => 'return_car_' . $car['id']]];
                        }
                    }
                    
                    $response .= "🚘 Rusumi: {$car['model']}\n";
                    $response .= "🔢 Davlat raqami: {$car['license_plate']}\n";
                    $response .= "📊 Probeg: " . number_format($car['current_mileage']) . " km\n";
                    $response .= "📍 Holati: $status$customer_info\n";
                    $response .= "💰 Jami daromad: " . ($car['total_earnings'] ? number_format($car['total_earnings']) : "0") . " so'm\n";
                    $response .= "🔄 Jami ijaralar: " . ($car['total_rentals'] ? number_format($car['total_rentals']) : "0") . " ta\n\n";
                }
            } else {
                $response .= "Hozircha avtomobillar mavjud emas.";
            }
            
            // Add total earnings for all cars (monthly)
            $total_stats = $conn->query("SELECT 
                SUM(rental_price) as total_earnings,
                COUNT(*) as total_rentals
                FROM rentals 
                WHERE status = 'completed'
                AND actual_return_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc();
            
            if ($total_stats['total_earnings']) {
                $response .= "\n📈 <b>Umumiy statistika (Oxirgi 30 kun):</b>\n";
                $response .= "💰 Jami daromad: " . number_format($total_stats['total_earnings']) . " so'm\n";
                $response .= "🔄 Jami ijaralar: " . number_format($total_stats['total_rentals']) . " ta\n";
            }
            
            // Add toggle and back buttons
            $keyboard[] = [['text' => '📊 Umumiy statistika', 'callback_data' => 'car_status']];
            $keyboard[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']];
            
            bot('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $mid,
                'text' => $response,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            break;
        
        case 'clear_confirm':
            if (isMainAdmin($chat_id)) {
                // Disable foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                
                // Truncate all tables
                $tables = [
                    'rentals',
                    'customers',
                    'cars',
                    'violations',
                    'notifications',
                    'user_states'
                ];
                
                $success = true;
                foreach ($tables as $table) {
                    if (!$conn->query("TRUNCATE TABLE $table")) {
                        $success = false;
                        break;
                    }
                }
                
                // Re-enable foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                
                if ($success) {
                    bot('editMessageText', [
                        'chat_id' => $chat_id,
                        'message_id' => $mid,
                        'text' => "✅ Baza muvaffaqiyatli tozalandi!\n\n" .
                                 "Barcha ma'lumotlar o'chirib tashlandi.\n" .
                                 "Tizim yangi ma'lumotlar kiritishga tayyor.",
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                            ]
                        ])
                    ]);
                } else {
                    bot('editMessageText', [
                        'chat_id' => $chat_id,
                        'message_id' => $mid,
                        'text' => "❌ Xatolik yuz berdi!\n\n" .
                                 "Bazani tozalashda muammo yuzaga keldi.\n" .
                                 "Iltimos, qaytadan urinib ko'ring.",
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                            ]
                        ])
                    ]);
                }
            } else {
                bot('editMessageText', [
                    'chat_id' => $chat_id,
                    'message_id' => $mid,
                    'text' => "❌ Kechirasiz, bu buyruq faqat asosiy admin uchun.",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                        ]
                    ])
                ]);
            }
            break;

        case 'clear_cancel':
            bot('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $mid,
                'text' => "✅ Bekor qilindi!\n\nBazani tozalash bekor qilindi.",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
            break;
    }
    
    // Check for expired rentals
    checkExpiredRentals();
}

// Handle state-based conversations
$state_query = $conn->query("SELECT state, `temp_data` FROM `user_states` WHERE `user_id` = '$from_id';");
$current_state = $state_query && $state_query->num_rows > 0 ? $state_query->fetch_assoc() : ['state' => 'IDLE', 'temp_data' => null];

switch ($current_state['state']) {
    case 'AWAITING_CUSTOMER_NAME':
        if ($text && $text != '⬅️ Orqaga') {
            $escaped_text = $conn->real_escape_string($text);
            $conn->query("INSERT INTO customers (full_name) VALUES ('$escaped_text')");
            $customer_id = $conn->insert_id;
            $temp_data = json_encode(['customer_id' => $customer_id]);
            $conn->query("UPDATE user_states SET state = 'AWAITING_CUSTOMER_PHONE', temp_data = '$temp_data' WHERE user_id = $from_id");
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "Mijozning telefon raqamini kiriting (+998 formatida):",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
        }
        break;
        
    case 'AWAITING_CUSTOMER_PHONE':
        if ($text && $text != '⬅️ Orqaga') {
            if (preg_match('/^\+998\d{9}$/', $text)) {
                $temp_data = json_decode($current_state['temp_data'], true);
                $customer_id = $temp_data['customer_id'];
                $escaped_text = $conn->real_escape_string($text);
                
                $conn->query("UPDATE customers SET phone_number = '$escaped_text' WHERE id = $customer_id");
                
                // Get available cars
                $cars = $conn->query("SELECT * FROM cars WHERE status = 'available'");
                $keyboard = [];
                if ($cars && $cars->num_rows > 0) {
                    while ($car = $cars->fetch_assoc()) {
                        $keyboard[] = [[
                            'text' => $car['model'] . " - " . $car['license_plate'],
                            'web_app' => [
                                'url' => "https://vermino.uz/bots/orders/ibo/rent_form.php?user_id=$from_id&car_id=" . $car['id']
                            ]
                        ]];
                    }
                    $keyboard[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']];
                    
                    bot('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "Mavjud mashinalardan birini tanlang:",
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                    ]);
                } else {
                    bot('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "Kechirasiz, hozirda bo'sh mashinalar mavjud emas.",
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                            ]
                        ])
                    ]);
                }
                
                $conn->query("UPDATE user_states SET state = 'AWAITING_CAR_SELECTION' WHERE user_id = $from_id");
            } else {
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "Noto'g'ri format. Iltimos, raqamni +998 formatida kiriting.",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                        ]
                    ])
                ]);
            }
        }
        break;
        
    case 'AWAITING_CAR_MODEL':
        if ($text && $text != '⬅️ Orqaga') {
            $model = $conn->real_escape_string($text);
            $temp_data = json_encode(['model' => $model]);
            $conn->query("UPDATE user_states SET state = 'AWAITING_CAR_LICENSE', temp_data = '$temp_data' WHERE user_id = $from_id");
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "Davlat raqamini kiriting (masalan: 01X715XX):",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
        }
        break;
        
    case 'AWAITING_CAR_LICENSE':
        if ($text && $text != '⬅️ Orqaga') {
            $temp_data = json_decode($current_state['temp_data'], true);
            $model = $temp_data['model'];
            $license = $conn->real_escape_string($text);
            
            // Check if license plate already exists
            $check = $conn->query("SELECT id FROM cars WHERE license_plate = '$license'");
            if ($check && $check->num_rows > 0) {
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "Bu davlat raqami boshqa mashinaga biriktirilgan. Iltimos, boshqa raqam kiriting:",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                        ]
                    ])
                ]);
            } else {
                $temp_data['license'] = $license;
                $conn->query("UPDATE user_states SET state = 'AWAITING_CAR_MILEAGE', temp_data = '" . json_encode($temp_data) . "' WHERE user_id = $from_id");
                
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "Mashina probegini kiriting (km):",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                        ]
                    ])
                ]);
            }
        }
        break;
        
    case 'AWAITING_CAR_MILEAGE':
        if ($text && $text != '⬅️ Orqaga') {
            if (is_numeric($text)) {
                $temp_data = json_decode($current_state['temp_data'], true);
                $model = $temp_data['model'];
                $license = $temp_data['license'];
                $mileage = intval($text);
                
                // Add new car
                $conn->query("INSERT INTO cars (model, license_plate, current_mileage, status) 
                             VALUES ('$model', '$license', $mileage, 'available')");
                
                // Reset state
                $conn->query("UPDATE user_states SET state = 'IDLE', temp_data = NULL WHERE user_id = $from_id");
                
                $keyboard = [
                    [
                        ['text' => ' Yana mashina qo\'shish', 'callback_data' => 'add_car'],
                        ['text' => '⬅️ Asosiy menyu', 'callback_data' => 'back_to_main']
                    ]
                ];
                
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "✅ Yangi mashina muvaffaqiyatli qo'shildi:\n\n" .
                             "🚘 Rusumi: $model\n" .
                             "🔢 Davlat raqami: $license\n" .
                             "📊 Probeg: " . number_format($mileage) . " km",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
            } else {
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "Noto'g'ri format. Iltimos, probegni raqamlarda kiriting:",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']]
                        ]
                    ])
                ]);
            }
        }
        break;

        
    // Add new state for handling return mileage
    case 'AWAITING_RETURN_MILEAGE':
        // Log initial input
        writeLog("[Return Mileage] Received input: " . print_r($text, true));
        writeLog("[Return Mileage] Current state data: " . print_r($current_state, true));
        
        if ($text) {
            // Remove any commas and spaces from the input
            $clean_text = str_replace([',', ' '], '', $text);
            writeLog("[Return Mileage] Cleaned input: " . $clean_text);
            
            if (is_numeric($clean_text)) {
                writeLog("[Return Mileage] Input is numeric");
                
                $temp_data = json_decode($current_state['temp_data'], true);
                writeLog("[Return Mileage] Decoded temp_data: " . print_r($temp_data, true));
                
                if (!$temp_data) {
                    writeLog("[Return Mileage] ERROR: temp_data is null or invalid");
                    bot('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "❌ Xatolik yuz berdi. Iltimos, qaytadan urinib ko'ring.\n\nTexnik ma'lumot: temp_data topilmadi",
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '🚗 Mashinalar holati', 'callback_data' => 'car_status']]
                            ]
                        ])
                    ]);
                    break;
                }

                $return_mileage = floatval($clean_text);
                $start_mileage = floatval(str_replace([',', ' '], '', $temp_data['start_mileage']));
                
                writeLog("[Return Mileage] Return mileage: " . $return_mileage);
                writeLog("[Return Mileage] Start mileage: " . $start_mileage);
                
                if ($return_mileage < $start_mileage) {
                    writeLog("[Return Mileage] ERROR: Return mileage is less than start mileage");
                    bot('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "❌ Xato! Qaytarish probegi boshlang'ich probegdan kam bo'lishi mumkin emas.\n\nBoshlang'ich probeg: " . number_format($start_mileage) . " km\nKiritilgan probeg: " . number_format($return_mileage) . " km",
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '⬅️ Bekor qilish', 'callback_data' => 'car_status']]
                            ]
                        ])
                    ]);
                    break;
                }
                
                // Update rental status and car mileage
                $rental_id = $temp_data['rental_id'];
                $car_id = $temp_data['car_id'];
                $current_time = date('Y-m-d H:i:s');
                
        
                
                // Update rental record
                $update_rental_query = "UPDATE `rentals` SET status='completed', `end_mileage`='$return_mileage', `actual_return_date`='$current_time' WHERE id='$rental_id';";

                $update_rental = $conn->query($update_rental_query);

                if (!$update_rental) {
                    bot('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "❌ Ijarani yangilashda xatolik yuz berdi. Iltimos, qaytadan urinib ko'ring.\n\nTexnik ma'lumot: " . $conn->error,
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '⬅️ Bekor qilish', 'callback_data' => 'car_status']]
                            ]
                        ])
                    ]);
                    break;
                }

                // Update car record
                $update_car_query = "UPDATE cars SET status='available', current_mileage=$return_mileage WHERE id=$car_id";

                $update_car = $conn->query($update_car_query);

                if (!$update_car) {
                    // Rollback rental update since car update failed
                    $conn->query("UPDATE rentals SET status='active', return_mileage=NULL, actual_return_date=NULL WHERE id=$rental_id");
                    
                    bot('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "❌ Mashinani yangilashda xatolik yuz berdi. Iltimos, qaytadan urinib ko'ring.\n\nTexnik ma'lumot: " . $conn->error,
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '⬅️ Bekor qilish', 'callback_data' => 'car_status']]
                            ]
                        ])
                    ]);
                    break;
                }

                // If both updates were successful
                if ($update_rental && $update_car) {
                    writeLog("[Return Mileage] All updates successful");
                    
                    // Reset user state
                    $state_reset = $conn->query("UPDATE user_states SET state='IDLE', temp_data=NULL WHERE user_id=$from_id");
                    writeLog("[Return Mileage] State reset result: " . ($state_reset ? "Success" : "Failed - " . $conn->error));
                    
                    // Get updated car info
                    $car_info = $conn->query("SELECT model, license_plate, current_mileage FROM cars WHERE id=$car_id")->fetch_assoc();
                    
                    // Calculate distance traveled
                    $distance_traveled = $return_mileage - $start_mileage;
                    
                    if ($car_info) {
                        bot('sendMessage', [
                            'chat_id' => $chat_id,
                            'text' => "✅ Mashina muvaffaqiyatli qaytarildi!\n\n" .
                                     "🚘 Mashina: {$car_info['model']} - {$car_info['license_plate']}\n" .
                                     "📊 Boshlang'ich probeg: " . number_format($start_mileage) . " km\n" .
                                     "📊 Yakuniy probeg: " . number_format($return_mileage) . " km\n" .
                                     "🛣 Bosib o'tilgan masofa: " . number_format($distance_traveled) . " km\n" .
                                     "📍 Holati: 🟢 Bo'sh",
                            'reply_markup' => json_encode([
                                'inline_keyboard' => [
                                    [['text' => '🚗 Mashinalar holati', 'callback_data' => 'car_status']],
                                    [['text' => '📋 Asosiy menyu', 'callback_data' => 'main_menu']]
                                ]
                            ])
                        ]);
                        
                        // Show updated car status
                        $cars = $conn->query("SELECT * FROM cars ORDER BY status DESC");
                        if ($cars && $cars->num_rows > 0) {
                            $response = "🚗 Avtomobillar yangilangan holati:\n\n";
                            $keyboard = [];
                            
                            while ($car = $cars->fetch_assoc()) {
                                $status = $car['status'] == 'rented' ? "🔴 Band" : "🟢 Bo'sh";
                                $response .= "🚘 Rusumi: {$car['model']}\n";
                                $response .= "🔢 Davlat raqami: {$car['license_plate']}\n";
                                $response .= "📊 Probeg: " . number_format($car['current_mileage']) . " km\n";
                                $response .= "📍 Holati: $status\n\n";
                                
                                if ($car['status'] == 'rented') {
                                    $rental = $conn->query("SELECT r.*, c.full_name, c.phone_number 
                                                          FROM rentals r 
                                                          JOIN customers c ON r.customer_id = c.id 
                                                          WHERE r.car_id = {$car['id']} 
                                                          AND r.status = 'active'")->fetch_assoc();
                                    if ($rental) {
                                        $response .= "👤 Mijoz: {$rental['full_name']}\n";
                                        $response .= "📱 Tel: {$rental['phone_number']}\n";
                                        $response .= "⏰ Qaytarish: {$rental['end_date']}\n\n";
                                        
                                        $keyboard[] = [['text' => "🔄 Qaytarish: {$car['model']} - {$car['license_plate']}", 
                                                      'callback_data' => 'return_car_' . $car['id']]];
                                    }
                                }
                            }
                            
                            $keyboard[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']];
                            
                            bot('sendMessage', [
                                'chat_id' => $chat_id,
                                'text' => $response,
                                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                            ]);
                        }
                    }
                } else {
                    writeLog("[Return Mileage] ERROR: One or both updates failed");
                    bot('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => "❌ Ma'lumotlarni saqlashda xatolik yuz berdi. Iltimos, qaytadan urinib ko'ring.",
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '⬅️ Bekor qilish', 'callback_data' => 'car_status']]
                            ]
                        ])
                    ]);
                }
            } else {
                writeLog("[Return Mileage] ERROR: Input is not numeric after cleaning: " . $clean_text);
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "❌ Xato! Iltimos, faqat raqam kiriting.\n\nMisol: 507100",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '⬅️ Bekor qilish', 'callback_data' => 'car_status']]
                        ]
                    ])
                ]);
            }
        } else {
            writeLog("[Return Mileage] ERROR: Empty input received");
        }
        break;
}

// Function to send rental expiration SMS
function sendExpirationSMS() {
    global $conn;
    
    $expiring_rentals = $conn->query("
        SELECT r.*, c.phone_number, c.full_name 
        FROM rentals r 
        JOIN customers c ON r.customer_id = c.id 
        WHERE r.status = 'active' AND r.end_date <= NOW()
    ");
    
    if ($expiring_rentals && $expiring_rentals->num_rows > 0) {
        while ($rental = $expiring_rentals->fetch_assoc()) {
            $message = "\"715RentCar\" dan olgan avtomobilingiz uchun belgilangan vaqt tugadi. Iltimos +998 50 715 00 00 telefoniga qo\'ng\'iroq qilib avtomobilni qaytarishingizni so\'raymiz.";
            
            // Add to SMS queue
            $conn->query("INSERT INTO sms_notifications (rental_id, message, send_at) VALUES ({$rental['id']}, '$message', NOW())");
        }
    }
}

// Add admin commands
if ($text == '/admin') {
    if (isMainAdmin($chat_id)) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "👨‍💼 Admin boshqaruvi:\n\n" .
                     "Admin qo'shish uchun /addadmin buyrug'ini bering va yangi adminning telegram ID raqamini kiriting.\n" .
                     "Masalan: /addadmin 123456789\n\n" .
                     "Admin o'chirish uchun /deladmin buyrug'ini bering.\n\n" .
                     "Adminlar ro'yxatini ko'rish uchun /admins buyrug'ini bering.",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                ]
            ])
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Kechirasiz, bu buyruq faqat asosiy admin uchun."
        ]);
    }
}

// Add admin command
if (preg_match('/^\/addadmin\s+(\d+)$/', $text, $matches)) {
    if (isMainAdmin($chat_id)) {
        $new_admin_id = $matches[1];
        
        // Check if already admin
        $check = $conn->query("SELECT id FROM admin_settings WHERE telegram_id = $new_admin_id");
        if ($check && $check->num_rows > 0) {
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Bu foydalanuvchi allaqachon admin hisoblanadi."
            ]);
        } else {
            // Add new admin
            $conn->query("INSERT INTO admin_settings (telegram_id, full_name, role) VALUES ($new_admin_id, 'Manager', 'manager')");
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ Yangi admin muvaffaqiyatli qo'shildi!\n\nTelegram ID: $new_admin_id"
            ]);
            
            // Notify new admin
            bot('sendMessage', [
                'chat_id' => $new_admin_id,
                'text' => "🎉 Tabriklaymiz! Siz 715RentCar tizimida admin etib tayinlandingiz.\n\n" .
                         "Tizimdan foydalanish uchun /start buyrug'ini bering."
            ]);
        }
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Kechirasiz, bu buyruq faqat asosiy admin uchun."
        ]);
    }
}

// List admins command
if ($text == '/admins') {
    if (isMainAdmin($chat_id)) {
        $admins = $conn->query("SELECT * FROM admin_settings ORDER BY role DESC");
        
        if ($admins && $admins->num_rows > 0) {
            $response = "👨‍💼 Adminlar ro'yxati:\n\n";
            while ($admin = $admins->fetch_assoc()) {
                $role = $admin['role'] == 'admin' ? '👑 Asosiy admin' : '👤 Manager';
                $response .= "$role\n";
                $response .= "🆔 ID: {$admin['telegram_id']}\n";
                $response .= "📝 Ism: {$admin['full_name']}\n\n";
            }
        } else {
            $response = "Adminlar ro'yxati bo'sh.";
        }
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $response,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                ]
            ])
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Kechirasiz, bu buyruq faqat asosiy admin uchun."
        ]);
    }
}

// Delete admin command
if ($text == '/deladmin') {
    if (isMainAdmin($chat_id)) {
        $admins = $conn->query("SELECT * FROM admin_settings WHERE role = 'manager'");
        
        if ($admins && $admins->num_rows > 0) {
            $keyboard = [];
            while ($admin = $admins->fetch_assoc()) {
                $keyboard[] = [['text' => "❌ {$admin['full_name']} - {$admin['telegram_id']}", 
                              'callback_data' => 'del_admin_' . $admin['telegram_id']]];
            }
            $keyboard[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'back_to_main']];
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "O'chirish uchun adminni tanlang:",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        } else {
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "O'chirish uchun managerlar mavjud emas.",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                    ]
                ])
            ]);
        }
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Kechirasiz, bu buyruq faqat asosiy admin uchun."
        ]);
    }
}

// Handle admin deletion callback
if (preg_match('/^del_admin_(\d+)$/', $data, $matches)) {
    if (isMainAdmin($chat_id)) {
        $admin_id = $matches[1];
        
        // Delete admin
        $conn->query("DELETE FROM admin_settings WHERE telegram_id = $admin_id AND role = 'manager'");
        
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $mid,
            'text' => "✅ Admin muvaffaqiyatli o'chirildi!\n\nTelegram ID: $admin_id",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '📋 Asosiy menyu', 'callback_data' => 'back_to_main']]
                ]
            ])
        ]);
        
        // Notify removed admin
        bot('sendMessage', [
            'chat_id' => $admin_id,
            'text' => "❌ Sizning admin huquqlaringiz bekor qilindi."
        ]);
    }
}

// Run SMS check every time the bot is accessed
sendExpirationSMS();
