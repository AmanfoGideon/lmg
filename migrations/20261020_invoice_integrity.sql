-- Additive protection for invoice and payment retries. Existing rows remain NULL.
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS request_key CHAR(32) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_request_key ON invoices(request_key);

ALTER TABLE transactions ADD COLUMN IF NOT EXISTS request_key CHAR(32) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_transactions_request_key ON transactions(request_key);
