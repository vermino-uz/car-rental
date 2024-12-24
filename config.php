<?php

$api_key = '7601792213:AAGTZjoJEs_Wv1CICjFHq9EBMmAwFJJzaZM';

define('DB_HOST', 'localhost');
define('DB_USER', 'car_rental');
define('DB_PASS', 'car_rental');
define('DB_NAME', 'car_rental');

// SMS Gateway configuration
define('SMS_API_KEY', 'your_sms_api_key');
define('SMS_SENDER_ID', '715RentCar');

// Database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?> 