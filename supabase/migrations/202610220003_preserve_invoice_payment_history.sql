ALTER TABLE public.transactions
  DROP CONSTRAINT IF EXISTS transactions_invoice_id_fkey,
  ADD CONSTRAINT transactions_invoice_id_fkey
    FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE RESTRICT;

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
  IF EXISTS (
    SELECT 1 FROM public.transactions
    WHERE invoice_id = p_invoice_id AND type = 'payment'
  ) THEN
    RAISE EXCEPTION 'Invoices with recorded payments cannot be deleted; cancel or archive the invoice instead'
      USING ERRCODE = '23514';
  END IF;

  DELETE FROM public.invoices WHERE id = p_invoice_id;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES (auth.uid(), 'invoice_delete', v_invoice.invoice_number);
  RETURN true;
END;
$$;

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
  v_requested_items jsonb;
  v_existing_items jsonb;
  v_has_payments boolean;
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

  SELECT coalesce(jsonb_agg(jsonb_build_object('name', item_name, 'qty', quantity, 'price', price)
    ORDER BY item_name, quantity, price), '[]'::jsonb)
  INTO v_requested_items
  FROM (
    SELECT btrim(value ->> 'name') AS item_name,
      (value ->> 'qty')::integer AS quantity,
      (value ->> 'price')::numeric(10,2) AS price
    FROM jsonb_array_elements(p_items)
  ) requested;

  SELECT EXISTS (
    SELECT 1 FROM public.transactions WHERE invoice_id = p_invoice_id AND type = 'payment'
  ) INTO v_has_payments;

  IF v_has_payments THEN
    SELECT coalesce(jsonb_agg(jsonb_build_object('name', item_name, 'qty', quantity, 'price', price)
      ORDER BY item_name, quantity, price), '[]'::jsonb)
    INTO v_existing_items
    FROM public.invoice_items WHERE invoice_id = p_invoice_id;
    IF p_client_id IS DISTINCT FROM v_invoice.client_id
       OR p_invoice_date IS DISTINCT FROM v_invoice.invoice_date
       OR coalesce(p_tax, 0) IS DISTINCT FROM v_invoice.tax
       OR v_subtotal + coalesce(p_tax, 0) IS DISTINCT FROM v_invoice.total
       OR v_requested_items IS DISTINCT FROM v_existing_items THEN
      RAISE EXCEPTION 'Invoice financial details cannot be changed after payments are recorded'
        USING ERRCODE = '23514';
    END IF;
  END IF;

  UPDATE public.invoices
  SET client_id=p_client_id, invoice_date=p_invoice_date, due_date=p_due_date,
      subtotal=v_subtotal, tax=coalesce(p_tax,0), total=v_subtotal+coalesce(p_tax,0), notes=p_notes
  WHERE id=p_invoice_id;
  IF NOT v_has_payments THEN
    DELETE FROM public.invoice_items WHERE invoice_id=p_invoice_id;
    FOR v_item IN SELECT value FROM jsonb_array_elements(p_items)
    LOOP
      v_name := btrim(v_item ->> 'name');
      v_quantity := (v_item ->> 'qty')::integer;
      v_price := (v_item ->> 'price')::numeric(10,2);
      INSERT INTO public.invoice_items (invoice_id,item_name,quantity,price,total)
      VALUES (p_invoice_id,v_name,v_quantity,v_price,v_quantity*v_price);
    END LOOP;
  END IF;
  INSERT INTO public.user_logs (user_id,action,details)
  VALUES (auth.uid(),'invoice_update',v_invoice.invoice_number);
  RETURN true;
END;
$$;

REVOKE ALL ON FUNCTION public.delete_invoice(bigint) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.delete_invoice(bigint) TO authenticated;
REVOKE ALL ON FUNCTION public.update_unpaid_invoice(bigint,bigint,date,date,numeric,text,jsonb) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.update_unpaid_invoice(bigint,bigint,date,date,numeric,text,jsonb) TO authenticated;

CREATE OR REPLACE FUNCTION public.guard_invoice_payment_transaction()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_client_id bigint;
  v_total numeric(10,2);
  v_paid numeric(14,2);
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.type = 'payment' AND OLD.invoice_id IS NOT NULL THEN
      RAISE EXCEPTION 'Invoice payments are preserved; record a reversal instead' USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'UPDATE' THEN
    IF (OLD.type = 'payment' AND OLD.invoice_id IS NOT NULL)
       OR (NEW.type = 'payment' AND NEW.invoice_id IS NOT NULL) THEN
      RAISE EXCEPTION 'Invoice payments cannot be edited; record a reversal instead' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF NEW.type <> 'payment' OR NEW.invoice_id IS NULL THEN
    RETURN NEW;
  END IF;
  IF NEW.amount <= 0 OR NEW.request_key IS NULL OR NEW.request_key !~ '^[0-9a-f]{32}$' THEN
    RAISE EXCEPTION 'Invoice payments require a positive amount and a valid request key' USING ERRCODE = '22023';
  END IF;
  SELECT client_id, total INTO v_client_id, v_total
  FROM public.invoices WHERE id = NEW.invoice_id FOR UPDATE;
  IF NOT FOUND OR v_client_id IS NULL OR v_client_id <> NEW.client_id THEN
    RAISE EXCEPTION 'Payment client does not match the invoice' USING ERRCODE = '22023';
  END IF;
  SELECT coalesce(sum(amount), 0) INTO v_paid
  FROM public.transactions WHERE invoice_id = NEW.invoice_id AND type = 'payment';
  IF NEW.amount > greatest(0, coalesce(v_total, 0) - v_paid) THEN
    RAISE EXCEPTION 'Payment exceeds the invoice balance due' USING ERRCODE = '22023';
  END IF;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS transactions_guard_invoice_payment ON public.transactions;
CREATE TRIGGER transactions_guard_invoice_payment
  BEFORE INSERT OR UPDATE OR DELETE ON public.transactions
  FOR EACH ROW EXECUTE FUNCTION public.guard_invoice_payment_transaction();
REVOKE ALL ON FUNCTION public.guard_invoice_payment_transaction() FROM PUBLIC, anon, authenticated;

CREATE OR REPLACE FUNCTION public.guard_invoice_payment_status()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_total numeric(10,2);
  v_paid numeric(14,2);
  v_expected text;
BEGIN
  IF TG_OP = 'INSERT' AND NEW.status IS DISTINCT FROM 'unpaid' THEN
    RAISE EXCEPTION 'Invoice status is calculated from recorded payments' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'UPDATE' THEN
    IF (NEW.invoice_number IS DISTINCT FROM OLD.invoice_number
        OR NEW.client_id IS DISTINCT FROM OLD.client_id
        OR NEW.invoice_date IS DISTINCT FROM OLD.invoice_date
        OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
        OR NEW.tax IS DISTINCT FROM OLD.tax
        OR NEW.total IS DISTINCT FROM OLD.total)
       AND EXISTS (SELECT 1 FROM public.transactions WHERE invoice_id=OLD.id AND type='payment') THEN
      RAISE EXCEPTION 'Invoices with recorded payments cannot be financially edited' USING ERRCODE = '23514';
    END IF;
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status THEN
    SELECT total INTO v_total FROM public.invoices WHERE id = NEW.id;
    SELECT coalesce(sum(amount), 0) INTO v_paid
    FROM public.transactions WHERE invoice_id = NEW.id AND type = 'payment';
    v_expected := CASE WHEN v_paid <= 0 THEN 'unpaid' WHEN v_paid < coalesce(v_total, 0) THEN 'partial' ELSE 'paid' END;
    IF NEW.status IS DISTINCT FROM v_expected THEN
      RAISE EXCEPTION 'Invoice status is calculated from recorded payments' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS invoices_guard_payment_status ON public.invoices;
CREATE TRIGGER invoices_guard_payment_status
  BEFORE INSERT OR UPDATE ON public.invoices
  FOR EACH ROW EXECUTE FUNCTION public.guard_invoice_payment_status();
REVOKE ALL ON FUNCTION public.guard_invoice_payment_status() FROM PUBLIC, anon, authenticated;

CREATE OR REPLACE FUNCTION public.guard_invoice_item_edit()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_invoice_id bigint;
BEGIN
  IF TG_OP = 'UPDATE' AND (
      EXISTS (SELECT 1 FROM public.transactions WHERE invoice_id=OLD.invoice_id AND type='payment')
      OR EXISTS (SELECT 1 FROM public.transactions WHERE invoice_id=NEW.invoice_id AND type='payment')) THEN
    RAISE EXCEPTION 'Invoice items cannot be changed after a payment is recorded' USING ERRCODE = '23514';
  END IF;
  v_invoice_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END;
  IF EXISTS (SELECT 1 FROM public.transactions WHERE invoice_id=v_invoice_id AND type='payment') THEN
    RAISE EXCEPTION 'Invoice items cannot be changed after a payment is recorded' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS invoice_items_guard_payment_history ON public.invoice_items;
CREATE TRIGGER invoice_items_guard_payment_history
  BEFORE INSERT OR UPDATE OR DELETE ON public.invoice_items
  FOR EACH ROW EXECUTE FUNCTION public.guard_invoice_item_edit();
REVOKE ALL ON FUNCTION public.guard_invoice_item_edit() FROM PUBLIC, anon, authenticated;
