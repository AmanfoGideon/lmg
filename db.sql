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
  client_id INT NULL,
  amount DECIMAL(10,2) NOT NULL,
  type ENUM('income', 'expense', 'invoice', 'payment') NOT NULL,
  description TEXT,
  date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  request_key CHAR(32) NULL,
  UNIQUE KEY uq_transactions_request_key (request_key),
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
  request_key CHAR(32) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_invoice_number ON invoices(invoice_number);
CREATE UNIQUE INDEX uq_invoices_request_key ON invoices(request_key);
CREATE INDEX idx_invoice_client ON invoices(client_id);

-- Track invoice payment status for pending counts
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS status ENUM('unpaid','partial','paid') DEFAULT 'unpaid';
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS cancelled_by INT NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL;
CREATE INDEX IF NOT EXISTS idx_invoices_cancelled_at ON invoices(cancelled_at);

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
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
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
ALTER TABLE transactions ADD CONSTRAINT IF NOT EXISTS fk_transactions_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT;

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

-- Structured, append-only activity history. References are snapshots without
-- foreign keys so audit records remain after an associated user/entity is deleted.
CREATE TABLE IF NOT EXISTS audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(32) NOT NULL,
  actor_user_id INT NULL,
  actor_name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
  actor_role_snapshot VARCHAR(100) NOT NULL DEFAULT '',
  action VARCHAR(120) NOT NULL,
  module VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id VARCHAR(100) NULL,
  description VARCHAR(500) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'success',
  old_values JSON NULL,
  new_values JSON NULL,
  metadata JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  request_id VARCHAR(64) NOT NULL,
  origin VARCHAR(40) NOT NULL DEFAULT 'web',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_audit_event_uuid (event_uuid),
  KEY idx_audit_created_id (created_at, id),
  KEY idx_audit_actor_created (actor_user_id, created_at),
  KEY idx_audit_action_created (action, created_at),
  KEY idx_audit_module_created (module, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_status_created (status, created_at),
  KEY idx_audit_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

