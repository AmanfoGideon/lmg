-- Remove restrictions on deleting and editing invoices with payments
-- This aligns Supabase with the PHP application changes

-- Drop the invoice delete guard trigger (from invoice_integrity migration)
DROP TRIGGER IF EXISTS invoices_guard_delete ON public.invoices;
DROP FUNCTION IF EXISTS public.guard_invoice_delete();

-- Drop the invoice payment status guard trigger (from invoice_integrity migration)
DROP TRIGGER IF EXISTS invoices_guard_payment_status ON public.invoices;
DROP FUNCTION IF EXISTS public.guard_invoice_payment_status();

-- Drop the invoice item edit guard trigger (from invoice_integrity migration)
DROP TRIGGER IF EXISTS invoice_items_guard_payment_history ON public.invoice_items;
DROP FUNCTION IF EXISTS public.guard_invoice_item_edit();

-- Drop the invoice payment transaction guard trigger (from invoice_integrity migration)
DROP TRIGGER IF EXISTS transactions_guard_invoice_payment ON public.transactions;
DROP FUNCTION IF EXISTS public.guard_invoice_payment_transaction();

-- Recreate delete_invoice function without payment restrictions
CREATE OR REPLACE FUNCTION public.delete_invoice(p_invoice_id bigint)
RETURNS boolean
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_invoice public.invoices%ROWTYPE;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;

  SELECT * INTO v_invoice
  FROM public.invoices
  WHERE id = p_invoice_id
  FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Invoice not found' USING ERRCODE = '22023';
  END IF;

  DELETE FROM public.invoices WHERE id = p_invoice_id;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES (auth.uid(), 'invoice_delete', v_invoice.invoice_number);
  RETURN true;
END;
$$;

REVOKE ALL ON FUNCTION public.delete_invoice(bigint) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.delete_invoice(bigint) TO authenticated;

-- Recreate update_unpaid_invoice function without payment restrictions
CREATE OR REPLACE FUNCTION public.update_unpaid_invoice(
  p_invoice_id bigint,
  p_client_id bigint,
  p_invoice_date date,
  p_due_date date,
  p_tax numeric,
  p_notes text,
  p_items jsonb
)
RETURNS boolean
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_invoice public.invoices%ROWTYPE;
  v_item jsonb;
  v_name text;
  v_quantity integer;
  v_price numeric(10,2);
  v_subtotal numeric(10,2) := 0;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF p_invoice_id IS NULL OR p_client_id IS NULL OR p_invoice_date IS NULL
     OR coalesce(p_tax, 0) < 0 OR coalesce(p_tax, 0) <> round(coalesce(p_tax,0),2)
     OR p_items IS NULL OR jsonb_typeof(p_items) <> 'array' THEN
    RAISE EXCEPTION 'Invoice details and at least one line item are required' USING ERRCODE = '22023';
  END IF;
  IF jsonb_array_length(p_items) = 0 THEN
    RAISE EXCEPTION 'At least one invoice item is required' USING ERRCODE = '22023';
  END IF;

  SELECT * INTO v_invoice FROM public.invoices WHERE id = p_invoice_id FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Invoice not found' USING ERRCODE = '22023';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM public.clients WHERE id=p_client_id) THEN
    RAISE EXCEPTION 'Client not found' USING ERRCODE = '22023';
  END IF;

  FOR v_item IN SELECT value FROM jsonb_array_elements(p_items)
  LOOP
    v_name := nullif(btrim(v_item ->> 'name'), '');
    v_quantity := (v_item ->> 'qty')::integer;
    v_price := (v_item ->> 'price')::numeric(10,2);
    IF v_name IS NULL OR v_quantity IS NULL OR v_quantity <= 0 OR v_price IS NULL OR v_price < 0
       OR (v_item ->> 'price')::numeric <> v_price THEN
      RAISE EXCEPTION 'Invoice item name, positive quantity, and nonnegative price are required' USING ERRCODE = '22023';
    END IF;
    v_subtotal := v_subtotal + (v_quantity * v_price);
  END LOOP;

  UPDATE public.invoices
  SET client_id=p_client_id, invoice_date=p_invoice_date, due_date=p_due_date,
      subtotal=v_subtotal, tax=coalesce(p_tax,0), total=v_subtotal+coalesce(p_tax,0), notes=p_notes
  WHERE id=p_invoice_id;
  DELETE FROM public.invoice_items WHERE invoice_id=p_invoice_id;
  FOR v_item IN SELECT value FROM jsonb_array_elements(p_items)
  LOOP
    v_name := btrim(v_item ->> 'name');
    v_quantity := (v_item ->> 'qty')::integer;
    v_price := (v_item ->> 'price')::numeric(10,2);
    INSERT INTO public.invoice_items (invoice_id,item_name,quantity,price,total)
    VALUES (p_invoice_id,v_name,v_quantity,v_price,v_quantity*v_price);
  END LOOP;
  INSERT INTO public.user_logs (user_id,action,details)
  VALUES (auth.uid(),'invoice_update',v_invoice.invoice_number);
  RETURN true;
END;
$$;

REVOKE ALL ON FUNCTION public.update_unpaid_invoice(bigint,bigint,date,date,numeric,text,jsonb) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.update_unpaid_invoice(bigint,bigint,date,date,numeric,text,jsonb) TO authenticated;

-- Update foreign key constraint to cascade delete payments when invoice is deleted
-- This ensures that when an invoice is deleted, its linked payments are also deleted
ALTER TABLE public.transactions DROP CONSTRAINT IF EXISTS transactions_invoice_id_fkey;
ALTER TABLE public.transactions
  ADD CONSTRAINT transactions_invoice_id_fkey
  FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;
