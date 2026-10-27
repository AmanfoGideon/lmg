-- Performance Optimization Indexes
-- Add indexes to improve query performance for common operations

-- Index for payment method filtering and sorting
CREATE INDEX IF NOT EXISTS idx_transactions_payment_method ON transactions(payment_method);

-- Index for description search
CREATE INDEX IF NOT EXISTS idx_transactions_description ON transactions(description(255));

-- Composite index for date + type queries (common in reports)
CREATE INDEX IF NOT EXISTS idx_transactions_date_type ON transactions(date, type);

-- Index for amount-based queries
CREATE INDEX IF NOT EXISTS idx_transactions_amount ON transactions(amount);

-- Index for supplier_id in transactions (already in supplier migration, ensuring it exists)
CREATE INDEX IF NOT EXISTS idx_transactions_supplier ON transactions(supplier_id);

-- Index for created_at for recent activity queries
CREATE INDEX IF NOT EXISTS idx_transactions_created_at ON transactions(created_at);

-- Invoice indexes for performance
CREATE INDEX IF NOT EXISTS idx_invoices_status_date ON invoices(status, invoice_date);
CREATE INDEX IF NOT EXISTS idx_invoices_client_status ON invoices(client_id, status);

-- Supplier indexes for performance
CREATE INDEX IF NOT EXISTS idx_suppliers_status ON suppliers(status);
CREATE INDEX IF NOT EXISTS idx_suppliers_business_name ON suppliers(business_name);

-- Audit events index for recent audit queries
CREATE INDEX IF NOT EXISTS idx_audit_events_created_at ON audit_events(created_at);
