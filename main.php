<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';
require_once 'botsdk/tg.php';
// Get parameters from Telegram
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Get all available cars
$cars = $conn->query("SELECT * FROM cars WHERE status = 'available' ORDER BY model ASC");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $car_id = intval($_POST['car_id']);
    $customer_name = $conn->real_escape_string($_POST['customer_name']);
    $customer_phone = $conn->real_escape_string($_POST['customer_phone']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $rental_price = floatval($_POST['rental_price']);
    $deposit_type = $_POST['deposit_type'];
    $deposit_amount = $deposit_type === 'money' ? floatval($_POST['deposit_amount']) : 0;
    $deposit_items = $deposit_type === 'goods' ? $conn->real_escape_string($_POST['deposit_items']) : '';
    $start_mileage = intval($_POST['start_mileage']);
    $start_condition = $conn->real_escape_string($_POST['start_condition']);
    
    // Begin transaction
    $conn->begin_transaction();
    try {
        // Create customer
        $conn->query("INSERT INTO customers (full_name, phone_number) VALUES ('$customer_name', '$customer_phone')");
        $customer_id = $conn->insert_id;
        
        // Update car status and mileage
        $conn->query("UPDATE cars SET status = 'rented', current_mileage = $start_mileage WHERE id = $car_id");
        
        // Create rental record with deposit info
        $conn->query("INSERT INTO rentals (car_id, customer_id, start_mileage, rental_price, 
                     deposit_type, deposit_amount, deposit_items,
                     start_date, end_date, start_condition, status) 
                     VALUES ($car_id, $customer_id, $start_mileage, $rental_price,
                     '$deposit_type', $deposit_amount, '$deposit_items',
                     '$start_date', '$end_date', '$start_condition', 'active')");
        
        // Get car details for message
        $car = $conn->query("SELECT * FROM cars WHERE id = $car_id")->fetch_assoc();
        
        $conn->commit();
        
        // Send confirmation message to Telegram
        $message = "✅ Yangi ijara rasmiylashtirildi:\n\n";
        $message .= "🚘 Mashina: {$car['model']} - {$car['license_plate']}\n";
        $message .= "👤 Mijoz: $customer_name\n";
        $message .= "�� Tel: $customer_phone\n";
        $message .= "📅 Sana: " . date('d.m.Y H:i', strtotime($start_date)) . " - " . date('d.m.Y H:i', strtotime($end_date)) . "\n";
        $message .= "💰 Ijara: " . number_format($rental_price) . " so'm\n";
        
        // Different deposit message based on type
        if ($deposit_type === 'money') {
            $message .= "🔐 Zalog: " . number_format($deposit_amount) . " so'm\n";
        } else {
            $message .= "🔐 Zalog: $deposit_items\n";
        }
        
        $message .= "📊 Probeg: " . number_format($start_mileage) . " km\n";
        if ($start_condition) {
            $message .= "📝 Holati: $start_condition\n";
        }
        
        bot('sendMessage', [
            'chat_id' => $user_id,
            'text' => $message
        ]);
        
        echo "<script>window.close();</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mashina ijarasi - 715RentCar</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="assets/css/rent_form.css">
</head>
<body>
    <form method="POST" id="rentalForm" novalidate>
        <div class="section">
            <div class="section-title">Mashina ma'lumotlari</div>
            <select class="input-field" id="car_id" name="car_id" required onchange="updateMileage(this.value)">
                <option value="">Mashinani tanlang</option>
                <?php while($car = $cars->fetch_assoc()): ?>
                    <option value="<?= $car['id'] ?>" data-mileage="<?= $car['current_mileage'] ?>">
                        <?= $car['model'] ?> - <?= $car['license_plate'] ?>
                    </option>
                <?php endwhile; ?>
            </select>
            
            <input type="number" class="input-field" id="start_mileage" name="start_mileage" placeholder="Boshlang'ich probeg (km)" required>
            <textarea class="input-field" id="start_condition" name="start_condition" placeholder="Mashina holati"></textarea>
        </div>

        <div class="section">
            <div class="section-title">Mijoz ma'lumotlari</div>
            <input type="text" class="input-field" id="customer_name" name="customer_name" placeholder="To'liq ism" required>
            <input type="tel" class="input-field" id="customer_phone" name="customer_phone" placeholder="+998 90 123 45 67" pattern="\+998\d{9}" required>
            <div class="hint">Telefon raqamini +998 formatida kiriting</div>
        </div>

        <div class="section">
            <div class="section-title">Ijara ma'lumotlari</div>
            <input type="datetime-local" class="input-field" id="start_date" name="start_date" required>
            <input type="datetime-local" class="input-field" id="end_date" name="end_date" required>
            <input type="number" class="input-field" id="rental_price" name="rental_price" placeholder="Ijara narxi (so'm)" required>
        </div>

        <div class="section">
            <div class="section-title">Zalog ma'lumotlari</div>
            <div class="radio-group">
                <label class="radio-option">
                    <input type="radio" name="deposit_type" value="money" checked onchange="toggleDepositFields()">
                    <span>Pul</span>
                </label>
                <label class="radio-option">
                    <input type="radio" name="deposit_type" value="goods" onchange="toggleDepositFields()">
                    <span>Buyum</span>
                </label>
            </div>

            <div id="money_deposit">
                <input type="number" class="input-field" id="deposit_amount" name="deposit_amount" placeholder="Zalog summasi (so'm)">
            </div>

            <div id="goods_deposit" style="display: none;">
                <textarea class="input-field" id="deposit_items" name="deposit_items" placeholder="Zalog buyumlar"></textarea>
            </div>
        </div>
    </form>

    <script src="assets/js/rent_form.js"></script>
</body>
</html>