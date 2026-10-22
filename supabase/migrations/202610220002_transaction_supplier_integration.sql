CREATE OR REPLACE FUNCTION public.create_supplier_record(
  p_business_name text,
  p_contact_person text DEFAULT NULL,
  p_phone text DEFAULT NULL,
  p_email text DEFAULT NULL,
  p_address text DEFAULT NULL,
  p_category text DEFAULT NULL,
  p_notes text DEFAULT NULL
)
RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_name text := nullif(btrim(p_business_name), '');
  v_id bigint;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF v_name IS NULL OR length(v_name) > 255 THEN
    RAISE EXCEPTION 'Enter a supplier name of 1 to 255 characters' USING ERRCODE = '22023';
  END IF;
  IF nullif(btrim(p_email), '') IS NOT NULL AND btrim(p_email) !~* '^[A-Z0-9._%+''-]+@[A-Z0-9.-]+\.[A-Z]{2,}$' THEN
    RAISE EXCEPTION 'Enter a valid supplier email address' USING ERRCODE = '22023';
  END IF;

  PERFORM pg_advisory_xact_lock(hashtextextended(lower(v_name), 0));
  IF EXISTS (SELECT 1 FROM public.suppliers WHERE lower(btrim(business_name)) = lower(v_name)) THEN
    RAISE EXCEPTION 'A supplier with this name already exists' USING ERRCODE = '23505';
  END IF;

  INSERT INTO public.suppliers (
    business_name, contact_person, phone, email, address, category, notes,
    status, created_by, updated_by
  ) VALUES (
    v_name, nullif(btrim(p_contact_person), ''), nullif(btrim(p_phone), ''),
    nullif(btrim(p_email), ''), nullif(btrim(p_address), ''), nullif(btrim(p_category), ''),
    nullif(btrim(p_notes), ''), 'active', auth.uid(), auth.uid()
  ) RETURNING id INTO v_id;

  INSERT INTO public.user_logs (user_id, action, details)
  VALUES (auth.uid(), 'supplier_create', v_name || '|' || v_id::text);
  RETURN v_id;
END;
$$;

REVOKE ALL ON FUNCTION public.create_supplier_record(text, text, text, text, text, text, text) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.create_supplier_record(text, text, text, text, text, text, text) TO authenticated;

CREATE OR REPLACE FUNCTION public.enforce_transaction_supplier_link()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_supplier_status text;
BEGIN
  IF NEW.supplier_id IS NULL THEN
    RETURN NEW;
  END IF;
  IF NEW.type <> 'expense' THEN
    RAISE EXCEPTION 'Only expense transactions may be linked to a supplier' USING ERRCODE = '22023';
  END IF;

  SELECT status INTO v_supplier_status FROM public.suppliers WHERE id = NEW.supplier_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Supplier not found' USING ERRCODE = '22023';
  END IF;
  IF v_supplier_status <> 'active' THEN
    IF TG_OP = 'INSERT' OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id THEN
      RAISE EXCEPTION 'Select an active supplier' USING ERRCODE = '22023';
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

REVOKE ALL ON FUNCTION public.enforce_transaction_supplier_link() FROM public, anon, authenticated;
DROP TRIGGER IF EXISTS transactions_enforce_supplier_link ON public.transactions;
CREATE TRIGGER transactions_enforce_supplier_link
  BEFORE INSERT OR UPDATE OF supplier_id, type ON public.transactions
  FOR EACH ROW EXECUTE FUNCTION public.enforce_transaction_supplier_link();

CREATE OR REPLACE FUNCTION public.create_transaction_with_supplier(
  p_client_id bigint,
  p_client_name text,
  p_amount numeric,
  p_type text,
  p_payment_method text,
  p_description text,
  p_date date,
  p_invoice_id bigint,
  p_supplier_id bigint
)
RETURNS bigint
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_id bigint;
BEGIN
  IF p_supplier_id IS NOT NULL AND p_type <> 'expense' THEN
    RAISE EXCEPTION 'Only expense transactions may be linked to a supplier' USING ERRCODE = '22023';
  END IF;
  IF p_supplier_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM public.suppliers WHERE id = p_supplier_id AND status = 'active'
  ) THEN
    RAISE EXCEPTION 'Select an active supplier' USING ERRCODE = '22023';
  END IF;

  v_id := public.create_transaction(
    p_client_id, p_client_name, p_amount, p_type, p_payment_method,
    p_description, p_date, p_invoice_id
  );
  IF p_supplier_id IS NOT NULL THEN
    UPDATE public.transactions SET supplier_id = p_supplier_id WHERE id = v_id;
  END IF;
  RETURN v_id;
END;
$$;

REVOKE ALL ON FUNCTION public.create_transaction_with_supplier(bigint, text, numeric, text, text, text, date, bigint, bigint) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.create_transaction_with_supplier(bigint, text, numeric, text, text, text, date, bigint, bigint) TO authenticated;

CREATE OR REPLACE FUNCTION public.update_transaction_with_supplier(
  p_id bigint,
  p_amount numeric,
  p_type text,
  p_payment_method text,
  p_description text,
  p_date date,
  p_invoice_id bigint,
  p_supplier_id bigint
)
RETURNS public.transactions
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_existing_supplier_id bigint;
  v_supplier_status text;
  v_result public.transactions%ROWTYPE;
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  SELECT supplier_id INTO v_existing_supplier_id
  FROM public.transactions WHERE id = p_id FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Transaction not found' USING ERRCODE = '22023';
  END IF;
  IF p_supplier_id IS NOT NULL AND p_type <> 'expense' THEN
    RAISE EXCEPTION 'Only expense transactions may be linked to a supplier' USING ERRCODE = '22023';
  END IF;
  IF p_supplier_id IS NOT NULL THEN
    SELECT status INTO v_supplier_status FROM public.suppliers WHERE id = p_supplier_id;
    IF NOT FOUND OR (v_supplier_status <> 'active' AND p_supplier_id IS DISTINCT FROM v_existing_supplier_id) THEN
      RAISE EXCEPTION 'Select an active supplier' USING ERRCODE = '22023';
    END IF;
  END IF;
  IF p_type NOT IN ('income', 'expense', 'invoice', 'payment') THEN
    RAISE EXCEPTION 'Unsupported transaction type' USING ERRCODE = '22023';
  END IF;
  UPDATE public.transactions
  SET amount = p_amount,
      type = p_type,
      payment_method = p_payment_method,
      description = p_description,
      date = coalesce(p_date, current_date),
      invoice_id = p_invoice_id,
      supplier_id = p_supplier_id
  WHERE id = p_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Transaction not found' USING ERRCODE = '22023';
  END IF;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES (auth.uid(), 'transaction_update', p_id::text || '|' || p_type || '|' || p_amount::text);
  SELECT * INTO v_result FROM public.transactions WHERE id = p_id;
  RETURN v_result;
END;
$$;

REVOKE ALL ON FUNCTION public.update_transaction_with_supplier(bigint, numeric, text, text, text, date, bigint, bigint) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.update_transaction_with_supplier(bigint, numeric, text, text, text, date, bigint, bigint) TO authenticated;

CREATE OR REPLACE FUNCTION public.create_transaction_with_supplier_offline(
  p_idempotency_key text,
  p_client_id bigint,
  p_client_name text,
  p_amount numeric,
  p_type text,
  p_payment_method text,
  p_description text,
  p_date date,
  p_invoice_id bigint,
  p_supplier_id bigint
)
RETURNS jsonb
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_existing_id bigint;
  v_transaction public.transactions%ROWTYPE;
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
    SELECT * INTO v_transaction FROM public.transactions WHERE id = v_existing_id;
    RETURN to_jsonb(v_transaction) || jsonb_build_object('status', 'already_exists');
  END IF;

  INSERT INTO public.sync_idempotency (idempotency_key, table_name, operation, user_id)
  VALUES (p_idempotency_key, 'transactions', 'create', v_user_id);
  v_existing_id := public.create_transaction_with_supplier(
    p_client_id, p_client_name, p_amount, p_type, p_payment_method,
    p_description, p_date, p_invoice_id, p_supplier_id
  );
  SELECT * INTO v_transaction FROM public.transactions WHERE id = v_existing_id;
  v_result := to_jsonb(v_transaction) || jsonb_build_object('status', 'created');
  UPDATE public.sync_idempotency
  SET result_record_id = v_existing_id, processed_at = now()
  WHERE idempotency_key = p_idempotency_key AND user_id = v_user_id;
  RETURN v_result;
END;
$$;

REVOKE ALL ON FUNCTION public.create_transaction_with_supplier_offline(text, bigint, text, numeric, text, text, text, date, bigint, bigint) FROM public, anon;
GRANT EXECUTE ON FUNCTION public.create_transaction_with_supplier_offline(text, bigint, text, numeric, text, text, text, date, bigint, bigint) TO authenticated;
