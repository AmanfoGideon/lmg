-- Update foreign key constraint to cascade delete payments when invoice is deleted
-- This ensures that when an invoice is deleted, its linked payments are also deleted
-- instead of being orphaned with invoice_id set to NULL

-- Drop existing foreign key constraint
ALTER TABLE transactions DROP FOREIGN KEY IF EXISTS fk_transactions_invoice;

-- Recreate with CASCADE DELETE
ALTER TABLE transactions ADD CONSTRAINT fk_transactions_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE;
