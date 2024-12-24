-- Create database
CREATE DATABASE IF NOT EXISTS car_rental CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE car_rental;

-- Cars table
CREATE TABLE cars (
    id INT PRIMARY KEY AUTO_INCREMENT,
    model VARCHAR(100) NOT NULL,
    license_plate VARCHAR(20) NOT NULL UNIQUE,
    current_mileage INT NOT NULL,
    status ENUM('available', 'rented') DEFAULT 'available',
    condition_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Customers table
CREATE TABLE customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    full_name VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Admin settings table
CREATE TABLE admin_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    telegram_id BIGINT NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager') NOT NULL DEFAULT 'manager',
    notifications_enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rentals table
CREATE TABLE rentals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    car_id INT NOT NULL,
    customer_id INT NOT NULL,
    start_mileage INT NOT NULL,
    end_mileage INT,
    rental_price DECIMAL(10,2) NOT NULL,
    deposit_type ENUM('money', 'goods') NOT NULL,
    deposit_amount DECIMAL(10,2) DEFAULT 0,
    deposit_items TEXT,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    actual_return_date DATETIME,
    start_condition TEXT,
    end_condition TEXT,
    status ENUM('active', 'completed', 'overdue') DEFAULT 'active',
    notification_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

-- Traffic violations table
CREATE TABLE violations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rental_id INT NOT NULL,
    violation_date DATETIME NOT NULL,
    fine_amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    violator_type ENUM('customer', 'other') DEFAULT 'customer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rental_id) REFERENCES rentals(id)
);

-- Notifications table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type ENUM('rental_expiring', 'rental_expired', 'violation', 'system') NOT NULL,
    rental_id INT,
    message TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    send_at DATETIME NOT NULL,
    sent_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rental_id) REFERENCES rentals(id)
); 