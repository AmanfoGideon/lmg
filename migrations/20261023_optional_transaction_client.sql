-- Supplier-linked expenses can be recorded without inventing a customer.
ALTER TABLE transactions MODIFY COLUMN client_id INT NULL;
