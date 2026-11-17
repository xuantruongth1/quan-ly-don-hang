CREATE DATABASE IF NOT EXISTS order_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE order_manager;

CREATE TABLE IF NOT EXISTS customers (
 customer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(100) NOT NULL,
 phone VARCHAR(20) NOT NULL,
 email VARCHAR(100) NULL,
 address VARCHAR(255) NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_customer_phone (phone),
 INDEX idx_customer_full_name (full_name),
 INDEX idx_customer_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
 user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(50) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 full_name VARCHAR(100) NOT NULL,
 email VARCHAR(100) NULL UNIQUE,
 role ENUM('admin','staff','customer') NOT NULL DEFAULT 'staff',
 customer_id INT UNSIGNED NULL UNIQUE,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
 product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sku VARCHAR(40) NOT NULL UNIQUE,
 product_name VARCHAR(150) NOT NULL,
 price DECIMAL(12,2) NOT NULL,
 stock_quantity INT NOT NULL DEFAULT 0,
 description TEXT NULL,
 image VARCHAR(255) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CHECK (price >= 0), CHECK (stock_quantity >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
 order_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_code VARCHAR(32) NOT NULL UNIQUE,
 customer_id INT UNSIGNED NOT NULL,
 created_by INT UNSIGNED NOT NULL,
 recipient_name VARCHAR(100) NOT NULL,
 recipient_phone VARCHAR(20) NOT NULL,
 shipping_address VARCHAR(255) NOT NULL,
 status ENUM('pending','confirmed','shipping','delivery_failed','returning','returned','lost','completed','cancelled') NOT NULL DEFAULT 'pending',
 assigned_to INT UNSIGNED NULL,
 internal_note TEXT NULL,
 actual_shipping_fee DECIMAL(12,2) NULL,
 cod_received_amount DECIMAL(12,2) NULL,
 lost_at DATETIME NULL,
 lost_by INT UNSIGNED NULL,
 delivery_method ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
 carrier VARCHAR(100) NULL,
 tracking_code VARCHAR(100) NULL,
 expected_delivery_date DATE NULL,
 shipped_at DATETIME NULL,
 delivered_at DATETIME NULL,
 last_failure_reason VARCHAR(500) NULL,
 returned_at DATETIME NULL,
 returned_by INT UNSIGNED NULL,
 items_total DECIMAL(12,2) NOT NULL,
 discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
 total_amount DECIMAL(12,2) NOT NULL,
 payment_method ENUM('cash','transfer','cod') NOT NULL DEFAULT 'cod',
 payment_status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
 paid_at DATETIME NULL,
 paid_by INT UNSIGNED NULL,
 completed_at DATETIME NULL,
 note TEXT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
 FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 FOREIGN KEY (paid_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 FOREIGN KEY (returned_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE RESTRICT,
 FOREIGN KEY (lost_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_orders_status_created (status, created_at),
 INDEX idx_orders_completed (completed_at), INDEX idx_orders_paid (paid_at),
 INDEX idx_orders_tracking (tracking_code),
 CHECK (items_total >= 0), CHECK (discount_amount >= 0 AND discount_amount <= items_total),
 CHECK (shipping_fee >= 0), CHECK (total_amount = items_total - discount_amount + shipping_fee)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
 order_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 product_name_snapshot VARCHAR(150) NOT NULL,
 sku_snapshot VARCHAR(40) NULL,
 quantity INT NOT NULL,
 received_quantity INT NULL,
 restocked_quantity INT NULL,
 unit_price DECIMAL(12,2) NOT NULL,
 subtotal DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
 FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE RESTRICT,
 FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
 UNIQUE KEY uq_order_product (order_id, product_id),
 CHECK (quantity > 0), CHECK (unit_price >= 0),
 CONSTRAINT ck_received CHECK (received_quantity >= 0 AND received_quantity <= quantity),
 CONSTRAINT ck_restocked CHECK (restocked_quantity >= 0 AND restocked_quantity <= received_quantity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_status_history (
 history_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id INT UNSIGNED NOT NULL,
 from_status ENUM('pending','confirmed','shipping','delivery_failed','returning','returned','lost','completed','cancelled') NULL,
 to_status ENUM('pending','confirmed','shipping','delivery_failed','returning','returned','lost','completed','cancelled') NOT NULL,
 event_type VARCHAR(32) NOT NULL DEFAULT 'status',
 changed_by INT UNSIGNED NOT NULL,
 reason TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE RESTRICT,
 FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE RESTRICT,
 INDEX idx_history_order (order_id, history_id)
) ENGINE=InnoDB;
