-- =======================================
-- SUPPLIER MANAGEMENT AND ACCOUNTS PAYABLE
-- =======================================

-- =======================================
-- 1) SUPPLIERS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_name VARCHAR(255) NOT NULL,
  contact_person VARCHAR(255),
  phone VARCHAR(50),
  email VARCHAR(255),
  address TEXT,
  category VARCHAR(100),
  tax_id VARCHAR(100),
  notes TEXT,
  status ENUM('active', 'inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_by INT NULL
);

CREATE INDEX idx_suppliers_business_name ON suppliers(business_name);
CREATE INDEX idx_suppliers_status ON suppliers(status);
CREATE INDEX idx_suppliers_category ON suppliers(category);

-- =======================================
-- 2) LINK SUPPLIERS TO TRANSACTIONS
-- =======================================
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS supplier_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_transactions_supplier ON transactions(supplier_id);
ALTER TABLE transactions ADD CONSTRAINT IF NOT EXISTS fk_transactions_supplier
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;

-- =======================================
-- 3) SUPPLIER BILLS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS supplier_bills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NOT NULL,
  bill_number VARCHAR(100) NOT NULL,
  bill_date DATE NOT NULL,
  due_date DATE,
  description TEXT,
  category VARCHAR(100),
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  payment_terms VARCHAR(100),
  status ENUM('unpaid', 'partial', 'paid', 'overdue', 'cancelled') DEFAULT 'unpaid',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_by INT NULL,
  UNIQUE KEY uq_supplier_bill_number (supplier_id, bill_number),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
);

CREATE INDEX idx_supplier_bills_supplier ON supplier_bills(supplier_id);
CREATE INDEX idx_supplier_bills_status ON supplier_bills(status);
CREATE INDEX idx_supplier_bills_date ON supplier_bills(bill_date);
CREATE INDEX idx_supplier_bills_due_date ON supplier_bills(due_date);

-- =======================================
-- 4) SUPPLIER PAYMENTS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS supplier_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NOT NULL,
  payment_number VARCHAR(100) NOT NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method ENUM('cash', 'mobile', 'card', 'bank_transfer', 'cheque') NOT NULL,
  reference_number VARCHAR(100),
  description TEXT,
  notes TEXT,
  status ENUM('pending', 'allocated', 'unallocated', 'cancelled') DEFAULT 'unallocated',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_by INT NULL,
  UNIQUE KEY uq_supplier_payment_number (supplier_id, payment_number),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
);

CREATE INDEX idx_supplier_payments_supplier ON supplier_payments(supplier_id);
CREATE INDEX idx_supplier_payments_status ON supplier_payments(status);
CREATE INDEX idx_supplier_payments_date ON supplier_payments(payment_date);

-- =======================================
-- 5) SUPPLIER PAYMENT ALLOCATIONS TABLE
-- =======================================
CREATE TABLE IF NOT EXISTS supplier_payment_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  bill_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  allocated_by INT NULL,
  FOREIGN KEY (payment_id) REFERENCES supplier_payments(id) ON DELETE CASCADE,
  FOREIGN KEY (bill_id) REFERENCES supplier_bills(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_payment_bill_allocation (payment_id, bill_id)
);

CREATE INDEX idx_payment_allocations_payment ON supplier_payment_allocations(payment_id);
CREATE INDEX idx_payment_allocations_bill ON supplier_payment_allocations(bill_id);

-- =======================================
-- 6) ADD FOREIGN KEY FOR CREATED_BY/UPDATED_BY
-- =======================================
ALTER TABLE suppliers ADD CONSTRAINT IF NOT EXISTS fk_suppliers_created_by
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE suppliers ADD CONSTRAINT IF NOT EXISTS fk_suppliers_updated_by
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE supplier_bills ADD CONSTRAINT IF NOT EXISTS fk_supplier_bills_created_by
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE supplier_bills ADD CONSTRAINT IF NOT EXISTS fk_supplier_bills_updated_by
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE supplier_payments ADD CONSTRAINT IF NOT EXISTS fk_supplier_payments_created_by
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE supplier_payments ADD CONSTRAINT IF NOT EXISTS fk_supplier_payments_updated_by
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE supplier_payment_allocations ADD CONSTRAINT IF NOT EXISTS fk_payment_allocations_allocated_by
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL;
