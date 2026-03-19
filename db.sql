-- =======================================
--  BUSINESS PWA SYSTEM - FULL DATABASE
-- =======================================

-- Create Database (match includes/db.php)
CREATE DATABASE IF NOT EXISTS lmg_cm 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_general_ci;

-- Select database
USE lmg_cm;

-- =======================================
-- 1) USERS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(255) NOT NULL,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin', 'staff') DEFAULT 'staff',
  status ENUM('active', 'inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_username ON users(username);

-- Optional: add missing profile fields if not present
-- (Run safely; ignores if columns already exist)
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login DATETIME NULL;
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);

-- Default Admin: username = admin, password = admin123
INSERT INTO users (full_name, username, password, role) VALUES (
  'System Administrator',
  'admin',
  '$2y$10$C6UzMDM.H6dfI/f/IKcEeO3uZQ0V4NmBGG99nmH8Qq7E6QxO3fYyG',
  'admin'
);

-- =======================================
-- 2) CLIENTS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(255) NOT NULL,
  phone VARCHAR(50),
  email VARCHAR(255),
  address TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_clients_name ON clients(full_name);

-- =======================================
-- 3) TRANSACTIONS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  type ENUM('income', 'expense', 'invoice', 'payment') NOT NULL,
  description TEXT,
  date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

CREATE INDEX idx_transactions_client ON transactions(client_id);
CREATE INDEX idx_transactions_type ON transactions(type);
CREATE INDEX idx_transactions_date ON transactions(date);

-- =======================================
-- 4) INVOICES TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(50) NOT NULL,
  client_id INT,
  invoice_date DATE NOT NULL,
  due_date DATE,
  subtotal DECIMAL(10,2),
  tax DECIMAL(10,2),
  total DECIMAL(10,2),
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_invoice_number ON invoices(invoice_number);
CREATE INDEX idx_invoice_client ON invoices(client_id);

-- Track invoice payment status for pending counts
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS status ENUM('unpaid','partial','paid') DEFAULT 'unpaid';

-- =======================================
-- 5) INVOICE ITEMS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  quantity INT DEFAULT 1,
  price DECIMAL(10,2) NOT NULL,
  total DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

CREATE INDEX idx_invoice_items_invoice ON invoice_items(invoice_id);

-- =======================================
-- 6) PRODUCTS TABLE (Inventory)
-- =======================================
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  sku VARCHAR(100) UNIQUE,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  min_stock INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_products_name ON products(name);
CREATE INDEX idx_products_stock ON products(stock);

-- =======================================
-- 7) Enhance Transactions: Payment Method
-- =======================================
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_method ENUM('cash','mobile','card') NULL;

-- Link payments to invoices
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS invoice_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_transactions_invoice ON transactions(invoice_id);
ALTER TABLE transactions ADD CONSTRAINT IF NOT EXISTS fk_transactions_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL;

-- =======================================
-- 9) Client Interactions (CRM notes)
-- =======================================
CREATE TABLE IF NOT EXISTS client_interactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  note TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- Optional branches support
ALTER TABLE users ADD COLUMN IF NOT EXISTS branch VARCHAR(100) NULL;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS branch VARCHAR(100) NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS branch VARCHAR(100) NULL;

-- =======================================
-- 10) SMS Messages Log
-- =======================================
CREATE TABLE IF NOT EXISTS sms_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NULL,
  phone VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  provider VARCHAR(50) NOT NULL DEFAULT 'twilio',
  provider_message_id VARCHAR(100) NULL,
  error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_sms_phone ON sms_messages(phone);
CREATE INDEX IF NOT EXISTS idx_sms_status ON sms_messages(status);

-- =======================================
-- 8) User Activity Logs
-- =======================================
CREATE TABLE IF NOT EXISTS user_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(100) NOT NULL,
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =======================================
-- 6) OPTIONAL: SAMPLE DATA
-- =======================================

INSERT INTO clients (full_name, phone, email, address) VALUES
('John Doe', '0244000000', 'john@example.com', 'Accra'),
('Jane Smith', '0244111111', 'jane@example.com', 'Kumasi');

INSERT INTO transactions (client_id, amount, type, description, date) VALUES
(1, 500.00, 'income', 'Website design payment', CURDATE()),
(2, 200.00, 'expense', 'Internet bill', CURDATE()),
(1, 1200.00, 'invoice', 'Logo & Branding Invoice', CURDATE());

-- Notes:
-- - Dashboard aggregates monthly income/expense from `transactions`.
-- - Weekly overview sums last 7 days by type.
-- - Pending invoices are computed using `invoices.status <> 'paid'`.
-- - Invoice flow:
--   1) Create invoice in `invoices` with auto number INV-YYYYMMDD-XXXX
--   2) Add line items to `invoice_items` (name, qty, price, total)
--   3) Update invoice `status` (unpaid/partial/paid) for dashboard counts
--   4) Use `invoice_view.php` to print and change status

