-- Keep the optional Supabase application aligned with the active PHP invoice rules.
-- Existing invoices/payments are retained; only new invoice-linked payments require request keys.
ALTER TABLE public.transactions ADD COLUMN IF NOT EXISTS request_key text;
CREATE UNIQUE INDEX IF NOT EXISTS transactions_request_key_uidx
  ON public.transactions (request_key) WHERE request_key IS NOT NULL;

CREATE OR REPLACE FUNCTION public.create_invoice_idempotent(
  p_idempotency_key text,
  p_client_id bigint,
  p_client_name text,
  p_invoice_date date,
  p_due_date date,
  p_tax numeric,
  p_notes text,
  p_items jsonb
)
RETURNS bigint
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_user_id uuid := auth.uid();
  v_existing public.sync_idempotency%ROWTYPE;
  v_invoice_id bigint;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF p_idempotency_key IS NULL OR p_idempotency_key !~ '^[0-9a-f]{32}$' THEN
    RAISE EXCEPTION 'A valid invoice request key is required' USING ERRCODE = '22023';
  END IF;

  INSERT INTO public.sync_idempotency (idempotency_key, table_name, operation, user_id)
  VALUES (p_idempotency_key, 'invoices', 'create', v_user_id)
  ON CONFLICT (idempotency_key) DO NOTHING;
  SELECT * INTO v_existing FROM public.sync_idempotency WHERE idempotency_key = p_idempotency_key FOR UPDATE;
  IF NOT FOUND OR v_existing.user_id <> v_user_id OR v_existing.table_name <> 'invoices' OR v_existing.operation <> 'create' THEN
    RAISE EXCEPTION 'Invoice request key is unavailable' USING ERRCODE = '22023';
  END IF;
  IF v_existing.processed_at IS NOT NULL THEN
    RETURN v_existing.result_record_id;
  END IF;

  v_invoice_id := public.create_invoice(p_client_id, p_client_name, p_invoice_date, p_due_date, p_tax, p_notes, p_items);
  UPDATE public.sync_idempotency SET result_record_id = v_invoice_id, processed_at = now()
  WHERE id = v_existing.id;
  RETURN v_invoice_id;
END;
$$;
REVOKE ALL ON FUNCTION public.create_invoice_idempotent(text, bigint, text, date, date, numeric, text, jsonb) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.create_invoice_idempotent(text, bigint, text, date, date, numeric, text, jsonb) TO authenticated;

CREATE OR REPLACE FUNCTION public.create_invoice_offline(
  p_idempotency_key text,
  p_client_id bigint,
  p_client_name text,
  p_invoice_date date,
  p_due_date date,
  p_tax numeric,
  p_notes text,
  p_items jsonb,
  p_provisional_number text DEFAULT NULL
)
RETURNS jsonb
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_user_id uuid := auth.uid();
  v_existing public.sync_idempotency%ROWTYPE;
  v_invoice_id bigint;
  v_result jsonb;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF nullif(btrim(p_idempotency_key), '') IS NULL THEN
    RAISE EXCEPTION 'An invoice sync key is required' USING ERRCODE = '22023';
  END IF;

  INSERT INTO public.sync_idempotency (idempotency_key, table_name, operation, user_id)
  VALUES (p_idempotency_key, 'invoices', 'create', v_user_id)
  ON CONFLICT (idempotency_key) DO NOTHING;
  SELECT * INTO v_existing FROM public.sync_idempotency WHERE idempotency_key = p_idempotency_key FOR UPDATE;
  IF NOT FOUND OR v_existing.user_id <> v_user_id OR v_existing.table_name <> 'invoices' OR v_existing.operation <> 'create' THEN
    RAISE EXCEPTION 'Invoice sync key is unavailable' USING ERRCODE = '22023';
  END IF;
  IF v_existing.processed_at IS NOT NULL THEN
    SELECT jsonb_build_object('id', id, 'invoice_number', invoice_number, 'client_id', client_id,
      'invoice_date', invoice_date, 'due_date', due_date, 'subtotal', subtotal, 'tax', tax,
      'total', total, 'notes', notes, 'status', status, 'created_at', created_at,
      '_version', _version, 'sync_status', 'already_exists') INTO v_result
    FROM public.invoices WHERE id = v_existing.result_record_id;
    RETURN v_result;
  END IF;

  v_invoice_id := public.create_invoice(p_client_id, p_client_name, p_invoice_date, p_due_date, p_tax, p_notes, p_items);
  UPDATE public.sync_idempotency SET result_record_id = v_invoice_id, processed_at = now()
  WHERE id = v_existing.id;
  SELECT jsonb_build_object('id', id, 'invoice_number', invoice_number, 'client_id', client_id,
    'invoice_date', invoice_date, 'due_date', due_date, 'subtotal', subtotal, 'tax', tax,
    'total', total, 'notes', notes, 'status', status, 'sync_status', 'created', 'created_at', created_at,
    '_version', _version) INTO v_result
  FROM public.invoices WHERE id = v_invoice_id;
  RETURN v_result;
END;
$$;
REVOKE ALL ON FUNCTION public.create_invoice_offline(text, bigint, text, date, date, numeric, text, jsonb, text) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.create_invoice_offline(text, bigint, text, date, date, numeric, text, jsonb, text) TO authenticated;

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
        OR NEW.due_date IS DISTINCT FROM OLD.due_date
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

CREATE OR REPLACE FUNCTION public.guard_invoice_delete()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM public.transactions WHERE invoice_id=OLD.id AND type='payment') THEN
    RAISE EXCEPTION 'Invoices with recorded payments cannot be deleted' USING ERRCODE = '23514';
  END IF;
  RETURN OLD;
END;
$$;
CREATE TRIGGER invoices_guard_delete
  BEFORE DELETE ON public.invoices
  FOR EACH ROW EXECUTE FUNCTION public.guard_invoice_delete();
REVOKE ALL ON FUNCTION public.guard_invoice_delete() FROM public, anon, authenticated;

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
CREATE TRIGGER invoice_items_guard_payment_history
  BEFORE INSERT OR UPDATE OR DELETE ON public.invoice_items
  FOR EACH ROW EXECUTE FUNCTION public.guard_invoice_item_edit();
REVOKE ALL ON FUNCTION public.guard_invoice_item_edit() FROM public, anon, authenticated;

CREATE TRIGGER invoices_guard_payment_status
  BEFORE INSERT OR UPDATE ON public.invoices
  FOR EACH ROW EXECUTE FUNCTION public.guard_invoice_payment_status();
REVOKE ALL ON FUNCTION public.guard_invoice_payment_status() FROM public, anon, authenticated;

-- Reconcile stored legacy statuses from the existing payment ledger without altering amounts.
UPDATE public.invoices i
SET status = CASE WHEN totals.paid <= 0 THEN 'unpaid'
                  WHEN totals.paid < coalesce(i.total, 0) THEN 'partial'
                  ELSE 'paid' END
FROM (
  SELECT inv.id, coalesce(sum(tx.amount), 0) AS paid
  FROM public.invoices inv
  LEFT JOIN public.transactions tx ON tx.invoice_id = inv.id AND tx.type = 'payment'
  GROUP BY inv.id
) totals
WHERE i.id = totals.id;

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

CREATE TRIGGER transactions_guard_invoice_payment
  BEFORE INSERT OR UPDATE OR DELETE ON public.transactions
  FOR EACH ROW EXECUTE FUNCTION public.guard_invoice_payment_transaction();
REVOKE ALL ON FUNCTION public.guard_invoice_payment_transaction() FROM public, anon, authenticated;

-- The old signature cannot safely accept linked payments without an idempotency key.
CREATE OR REPLACE FUNCTION public.record_invoice_payment(
  p_invoice_id bigint,
  p_amount numeric,
  p_payment_method text,
  p_description text,
  p_date date
)
RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
BEGIN
  RAISE EXCEPTION 'Invoice payments require a request key; use the invoice payment form' USING ERRCODE = '22023';
END;
$$;

CREATE OR REPLACE FUNCTION public.record_invoice_payment(
  p_invoice_id bigint,
  p_amount numeric,
  p_payment_method text,
  p_description text,
  p_date date,
  p_request_key text
)
RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_client_id bigint;
  v_total numeric(10,2);
  v_paid numeric(14,2);
  v_existing public.transactions%ROWTYPE;
  v_transaction_id bigint;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF p_amount IS NULL OR p_amount <= 0 OR p_amount <> round(p_amount, 2) THEN
    RAISE EXCEPTION 'Payment amount must be positive and have at most two decimals' USING ERRCODE = '22023';
  END IF;
  IF p_payment_method IS NOT NULL AND p_payment_method NOT IN ('cash', 'mobile', 'card') THEN
    RAISE EXCEPTION 'Unsupported payment method' USING ERRCODE = '22023';
  END IF;
  IF p_request_key IS NULL OR p_request_key !~ '^[0-9a-f]{32}$' THEN
    RAISE EXCEPTION 'A valid payment request key is required' USING ERRCODE = '22023';
  END IF;

  SELECT * INTO v_existing FROM public.transactions WHERE request_key = p_request_key;
  IF FOUND THEN
    IF v_existing.type IS DISTINCT FROM 'payment'
        OR v_existing.invoice_id IS DISTINCT FROM p_invoice_id
        OR v_existing.amount IS DISTINCT FROM p_amount THEN
      RAISE EXCEPTION 'Payment request key was already used for different details' USING ERRCODE = '22023';
    END IF;
    RETURN v_existing.id;
  END IF;

  SELECT client_id, total INTO v_client_id, v_total
  FROM public.invoices WHERE id = p_invoice_id FOR UPDATE;
  IF NOT FOUND OR v_client_id IS NULL THEN
    RAISE EXCEPTION 'Invoice not found or has no client' USING ERRCODE = '22023';
  END IF;
  SELECT * INTO v_existing FROM public.transactions WHERE request_key = p_request_key;
  IF FOUND THEN
    IF v_existing.type IS DISTINCT FROM 'payment'
        OR v_existing.invoice_id IS DISTINCT FROM p_invoice_id
        OR v_existing.amount IS DISTINCT FROM p_amount THEN
      RAISE EXCEPTION 'Payment request key was already used for different details' USING ERRCODE = '22023';
    END IF;
    RETURN v_existing.id;
  END IF;
  SELECT coalesce(sum(amount), 0) INTO v_paid
  FROM public.transactions WHERE invoice_id = p_invoice_id AND type = 'payment';
  IF p_amount > greatest(0, coalesce(v_total, 0) - v_paid) THEN
    RAISE EXCEPTION 'Payment exceeds the invoice balance due' USING ERRCODE = '22023';
  END IF;

  INSERT INTO public.transactions (client_id, amount, type, payment_method, description, date, invoice_id, request_key)
  VALUES (v_client_id, p_amount, 'payment', p_payment_method, p_description, coalesce(p_date, current_date), p_invoice_id, p_request_key)
  RETURNING id INTO v_transaction_id;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES ((SELECT auth.uid()), 'invoice_payment', p_invoice_id::text || '|' || p_amount::text);
  RETURN v_transaction_id;
END;
$$;

REVOKE ALL ON FUNCTION public.record_invoice_payment(bigint, numeric, text, text, date, text) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.record_invoice_payment(bigint, numeric, text, text, date, text) TO authenticated;
REVOKE ALL ON FUNCTION public.record_invoice_payment(bigint, numeric, text, text, date) FROM public, anon, authenticated;

CREATE OR REPLACE FUNCTION public.create_transaction(
  p_client_id bigint,
  p_client_name text,
  p_amount numeric,
  p_type text,
  p_payment_method text,
  p_description text,
  p_date date,
  p_invoice_id bigint DEFAULT NULL
)
RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_client_id bigint := p_client_id;
  v_id bigint;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF p_type NOT IN ('income', 'expense', 'invoice', 'payment') THEN
    RAISE EXCEPTION 'Unsupported transaction type' USING ERRCODE = '22023';
  END IF;
  IF p_type = 'payment' AND p_invoice_id IS NOT NULL THEN
    RAISE EXCEPTION 'Use the invoice payment form to record a linked payment' USING ERRCODE = '22023';
  END IF;
  IF v_client_id IS NULL THEN
    IF nullif(btrim(p_client_name), '') IS NULL THEN
      RAISE EXCEPTION 'A client is required' USING ERRCODE = '22023';
    END IF;
    SELECT id INTO v_client_id FROM public.clients
    WHERE lower(full_name) = lower(btrim(p_client_name)) ORDER BY id LIMIT 1;
    IF v_client_id IS NULL THEN
      INSERT INTO public.clients (full_name) VALUES (btrim(p_client_name)) RETURNING id INTO v_client_id;
    END IF;
  END IF;
  INSERT INTO public.transactions (client_id, amount, type, payment_method, description, date, invoice_id)
  VALUES (v_client_id, p_amount, p_type, p_payment_method, p_description, coalesce(p_date, current_date), p_invoice_id)
  RETURNING id INTO v_id;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES ((SELECT auth.uid()), 'transaction_create', v_client_id::text || '|' || p_type || '|' || p_amount::text);
  RETURN v_id;
END;
$$;

REVOKE ALL ON FUNCTION public.create_transaction(bigint, text, numeric, text, text, text, date, bigint) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.create_transaction(bigint, text, numeric, text, text, text, date, bigint) TO authenticated;

CREATE OR REPLACE FUNCTION public.create_transaction_offline(
  p_idempotency_key text,
  p_client_id bigint,
  p_client_name text,
  p_amount numeric,
  p_type text,
  p_payment_method text,
  p_description text,
  p_date date,
  p_invoice_id bigint DEFAULT NULL
)
RETURNS jsonb
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_existing_id bigint;
  v_result jsonb;
  v_user_id uuid := auth.uid();
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF p_type = 'payment' THEN
    RAISE EXCEPTION 'Payments cannot be confirmed from an offline operation' USING ERRCODE = '22023';
  END IF;
  SELECT result_record_id INTO v_existing_id
  FROM public.sync_idempotency
  WHERE idempotency_key = p_idempotency_key AND user_id = v_user_id
    AND table_name = 'transactions' AND operation = 'create' AND processed_at IS NOT NULL;
  IF v_existing_id IS NOT NULL THEN
    SELECT jsonb_build_object('id', id, 'client_id', client_id, 'amount', amount, 'type', type,
      'payment_method', payment_method, 'description', description, 'date', date,
      'invoice_id', invoice_id, 'created_at', created_at, '_version', _version,
      'status', 'already_exists') INTO v_result
    FROM public.transactions WHERE id = v_existing_id;
    RETURN v_result;
  END IF;
  INSERT INTO public.sync_idempotency (idempotency_key, table_name, operation, user_id)
  VALUES (p_idempotency_key, 'transactions', 'create', v_user_id);
  v_result := jsonb_build_object('id', public.create_transaction(
    p_client_id, p_client_name, p_amount, p_type, p_payment_method, p_description, p_date, p_invoice_id
  ), 'status', 'created');
  UPDATE public.sync_idempotency
  SET result_record_id = (v_result ->> 'id')::bigint, processed_at = now()
  WHERE idempotency_key = p_idempotency_key AND user_id = v_user_id;
  RETURN v_result;
END;
$$;
REVOKE ALL ON FUNCTION public.create_transaction_offline(text, bigint, text, numeric, text, text, text, date, bigint) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.create_transaction_offline(text, bigint, text, numeric, text, text, text, date, bigint) TO authenticated;
