GRANT USAGE ON SCHEMA public TO authenticated;

GRANT SELECT, INSERT, UPDATE ON TABLE
  public.suppliers,
  public.supplier_bills,
  public.supplier_payments,
  public.supplier_payment_allocations
TO authenticated;

GRANT USAGE, SELECT ON SEQUENCE
  public.suppliers_id_seq,
  public.supplier_bills_id_seq,
  public.supplier_payments_id_seq,
  public.supplier_payment_allocations_id_seq
TO authenticated;
