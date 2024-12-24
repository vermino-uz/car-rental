<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';
require_once 'botsdk/tg.php';

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$cars = $conn->query("SELECT * FROM cars WHERE status = 'available' ORDER BY model ASC");

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
    
    $conn->begin_transaction();
    try {
        $conn->query("INSERT INTO customers (full_name, phone_number) VALUES ('$customer_name', '$customer_phone')");
        $customer_id = $conn->insert_id;
        
        $conn->query("UPDATE cars SET status = 'rented', current_mileage = $start_mileage WHERE id = $car_id");
        
        $conn->query("INSERT INTO rentals (car_id, customer_id, start_mileage, rental_price, 
                     deposit_type, deposit_amount, deposit_items,
                     start_date, end_date, start_condition, status) 
                     VALUES ($car_id, $customer_id, $start_mileage, $rental_price,
                     '$deposit_type', $deposit_amount, '$deposit_items',
                     '$start_date', '$end_date', '$start_condition', 'active')");
        
        $car = $conn->query("SELECT * FROM cars WHERE id = $car_id")->fetch_assoc();
        
        $conn->commit();
        
        $message = "✅ Yangi ijara rasmiylashtirildi:\n\n";
        $message .= "🚘 Mashina: {$car['model']} - {$car['license_plate']}\n";
        $message .= "👤 Mijoz: $customer_name\n";
        $message .= "📱 Tel: $customer_phone\n";
        $message .= "📅 Sana: " . date('d.m.Y H:i', strtotime($start_date)) . " - " . date('d.m.Y H:i', strtotime($end_date)) . "\n";
        $message .= "💰 Ijara: " . number_format($rental_price) . " so'm\n";
        
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mashina ijarasi</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <style>
        html {
            height: 100%;
            overflow: hidden;
        }
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #2C3E50;
            background-image: url('logo.png');
            background-repeat: repeat;
            background-size: cover;
            background-attachment: fixed;
            margin: 0;
            padding: 20px;
            height: 100%;
            overflow-y: auto;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }
        .container {
            background: rgba(0, 0, 0, 0.7);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 400px;
            margin: 20px 0;
            backdrop-filter: blur(10px);
        }
        h2 {
            text-align: center;
            color: #2C3E50;
            margin-bottom: 20px;
            font-size: 28px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
            font-weight: 700;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border: 2px solid #2C3E50;
            border-radius: 12px;
            box-sizing: border-box;
            font-size: 16px;
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-family: 'Montserrat', sans-serif;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #34495E;
            box-shadow: 0 0 8px rgba(52, 73, 94, 0.6);
            background-color: rgba(255, 255, 255, 0.2);
        }
        input::placeholder, select::placeholder, textarea::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }
        .checkbox-container {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
        }
        .checkbox-option {
            position: relative;
            width: 48%;
        }
        .checkbox-option input[type="radio"] {
            display: none;
        }
        .checkbox-option label {
            display: block;
            background-color: rgba(44, 62, 80, 0.2);
            color: #2C3E50;
            text-align: center;
            padding: 15px 10px;
            border: 2px solid #2C3E50;
            border-radius: 12px;
            cursor: pointer;
        }
        .checkbox-option input[type="radio"]:checked + label {
            background-color: #2C3E50;
            color: #fff;
            box-shadow: 0 0 10px rgba(44, 62, 80, 0.5);
        }
        .submit-btn {
            width: 100%;
            padding: 15px;
            margin-top: 20px;
            background-color: #2C3E50;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .submit-btn:hover {
            background-color: #34495E;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Mashina ijarasi</h2>
        <form method="POST" id="rentalForm">
            <select name="car_id" required onchange="updateMileage(this.value)">
                <option value="">Mashinani tanlang</option>
                <?php while($car = $cars->fetch_assoc()): ?>
                    <option value="<?= $car['id'] ?>" data-mileage="<?= $car['current_mileage'] ?>">
                        <?= $car['model'] ?> - <?= $car['license_plate'] ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <input type="number" name="start_mileage" placeholder="Probeg (km)" required>
            <textarea name="start_condition" placeholder="Mashina holati" rows="3"></textarea>
            <input type="text" name="customer_name" placeholder="Mijoz ismi" required>
            <input type="tel" name="customer_phone" placeholder="+998" required>
            <input type="datetime-local" name="start_date" required>
            <input type="datetime-local" name="end_date" required>
            <input type="number" name="rental_price" placeholder="Ijara narxi (so'm)" required>

            <div class="checkbox-container">
                <div class="checkbox-option">
                    <input type="radio" id="money_deposit" name="deposit_type" value="money" checked>
                    <label for="money_deposit">Pul</label>
                </div>
                <div class="checkbox-option">
                    <input type="radio" id="goods_deposit" name="deposit_type" value="goods">
                    <label for="goods_deposit">Buyum</label>
                </div>
            </div>

            <div id="money_deposit_field">
                <input type="number" name="deposit_amount" placeholder="Zalog summasi (so'm)">
            </div>

            <div id="goods_deposit_field" style="display:none">
                <textarea name="deposit_items" placeholder="Zalog buyumlar"></textarea>
            </div>

            <button type="submit" class="submit-btn">Ijarani rasmiylashtirish</button>
        </form>
    </div>

    <script>
        let tg = window.Telegram.WebApp;
        tg.expand();

        // Hide keyboard on any touch/click
        document.addEventListener('touchstart', function() {
            document.activeElement.blur();
        });

        function updateMileage(carId) {
            const select = document.querySelector('select[name="car_id"]');
            const option = select.options[select.selectedIndex];
            if(option) {
                document.querySelector('input[name="start_mileage"]').value = option.dataset.mileage;
            }
        }

        document.querySelectorAll('input[name="deposit_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('money_deposit_field').style.display = 
                    this.value === 'money' ? 'block' : 'none';
                document.getElementById('goods_deposit_field').style.display = 
                    this.value === 'goods' ? 'block' : 'none';
            });
        });

        document.querySelector('input[name="customer_phone"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if(value.length > 0 && !value.startsWith('998')) {
                value = '998' + value;
            }
            if(value.length > 12) {
                value = value.slice(0, 12);
            }
            e.target.value = '+' + value;
        });
    </script>
</body>
</html>