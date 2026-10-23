-- Prevent invoice deletion from cascading into posted payment transactions.
ALTER TABLE transactions DROP FOREIGN KEY IF EXISTS fk_transactions_invoice;
ALTER TABLE transactions
  ADD CONSTRAINT fk_transactions_invoice
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT;
