-- Soft-cancel invoices with financial history while preserving receipts,
-- payment transactions, invoice items, and the invoice number.
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS cancelled_by INT NULL,
  ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL;

CREATE INDEX IF NOT EXISTS idx_invoices_cancelled_at ON invoices(cancelled_at);
