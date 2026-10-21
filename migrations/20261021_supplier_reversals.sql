ALTER TABLE supplier_payment_allocations
  ADD COLUMN IF NOT EXISTS reversed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS reversed_by INT NULL,
  ADD COLUMN IF NOT EXISTS reversal_reason VARCHAR(500) NULL;

UPDATE supplier_payments SET status = 'unallocated' WHERE status IS NULL;

ALTER TABLE supplier_payments
  MODIFY COLUMN status ENUM('pending', 'allocated', 'partially_allocated', 'unallocated', 'cancelled') NOT NULL DEFAULT 'unallocated';

SET @supplier_allocation_unique := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'supplier_payment_allocations'
    AND index_name = 'uq_payment_bill_allocation'
);
SET @supplier_allocation_sql := IF(@supplier_allocation_unique > 0,
  'ALTER TABLE supplier_payment_allocations DROP INDEX uq_payment_bill_allocation', 'SELECT 1');
PREPARE supplier_allocation_stmt FROM @supplier_allocation_sql;
EXECUTE supplier_allocation_stmt;
DEALLOCATE PREPARE supplier_allocation_stmt;

SET @supplier_payment_fk := (
  SELECT constraint_name FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE() AND table_name = 'supplier_payment_allocations'
    AND column_name = 'payment_id' AND referenced_table_name = 'supplier_payments'
  LIMIT 1
);
SET @supplier_fk_sql := IF(@supplier_payment_fk IS NULL, 'SELECT 1',
  CONCAT('ALTER TABLE supplier_payment_allocations DROP FOREIGN KEY `', REPLACE(@supplier_payment_fk, '`', '``'), '`'));
PREPARE supplier_fk_stmt FROM @supplier_fk_sql;
EXECUTE supplier_fk_stmt;
DEALLOCATE PREPARE supplier_fk_stmt;

ALTER TABLE supplier_payment_allocations
  ADD CONSTRAINT fk_supplier_allocations_payment
  FOREIGN KEY (payment_id) REFERENCES supplier_payments(id) ON DELETE RESTRICT;
