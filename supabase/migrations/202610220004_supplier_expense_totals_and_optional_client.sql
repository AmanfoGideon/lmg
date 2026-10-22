ALTER TABLE public.transactions
  ALTER COLUMN client_id DROP NOT NULL;

CREATE INDEX IF NOT EXISTS transactions_supplier_expense_idx
  ON public.transactions (supplier_id)
  WHERE type = 'expense' AND supplier_id IS NOT NULL;

CREATE OR REPLACE FUNCTION public.get_supplier_spending_totals()
RETURNS TABLE (supplier_id bigint, total_spent numeric)
LANGUAGE sql
STABLE
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
  SELECT s.id, coalesce(sum(t.amount), 0)::numeric
  FROM public.suppliers AS s
  LEFT JOIN public.transactions AS t
    ON t.supplier_id = s.id AND t.type = 'expense'
  WHERE public.is_active_member()
  GROUP BY s.id
$$;

REVOKE ALL ON FUNCTION public.get_supplier_spending_totals() FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.get_supplier_spending_totals() TO authenticated;

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
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING ERRCODE = '42501';
  END IF;
  IF p_client_id = 0 THEN
    p_client_id := NULL;
  END IF;
  IF p_type NOT IN ('income', 'expense', 'invoice', 'payment') THEN
    RAISE EXCEPTION 'Unsupported transaction type' USING ERRCODE = '22023';
  END IF;
  IF p_amount IS NULL OR p_amount <= 0 OR p_amount <> round(p_amount, 2) THEN
    RAISE EXCEPTION 'Transaction amount must be positive and have at most two decimals' USING ERRCODE = '22023';
  END IF;
  IF p_supplier_id IS NOT NULL AND p_type <> 'expense' THEN
    RAISE EXCEPTION 'Only expense transactions may be linked to a supplier' USING ERRCODE = '22023';
  END IF;
  IF p_supplier_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM public.suppliers WHERE id = p_supplier_id AND status = 'active'
  ) THEN
    RAISE EXCEPTION 'Select an active supplier' USING ERRCODE = '22023';
  END IF;
  IF p_client_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM public.clients WHERE id = p_client_id
  ) THEN
    RAISE EXCEPTION 'Selected client was not found. Choose a valid client or clear the selection.' USING ERRCODE = '22023';
  END IF;

  IF p_client_id IS NULL AND nullif(btrim(p_client_name), '') IS NULL THEN
    IF p_type <> 'expense' OR p_supplier_id IS NULL THEN
      RAISE EXCEPTION 'A client is required unless this is a supplier-linked expense' USING ERRCODE = '22023';
    END IF;
    INSERT INTO public.transactions (
      client_id, amount, type, payment_method, description, date, invoice_id, supplier_id
    ) VALUES (
      NULL, p_amount, p_type, p_payment_method, p_description, coalesce(p_date, current_date), p_invoice_id, p_supplier_id
    ) RETURNING id INTO v_id;
    INSERT INTO public.user_logs (user_id, action, details)
    VALUES (auth.uid(), 'transaction_create', p_supplier_id::text || '|' || p_type || '|' || p_amount::text);
    RETURN v_id;
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

REVOKE ALL ON FUNCTION public.create_transaction_with_supplier(bigint, text, numeric, text, text, text, date, bigint, bigint) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.create_transaction_with_supplier(bigint, text, numeric, text, text, text, date, bigint, bigint) TO authenticated;

CREATE OR REPLACE FUNCTION public.update_transaction_with_supplier(
  p_id bigint,
  p_client_id bigint,
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
  IF p_client_id = 0 THEN
    p_client_id := NULL;
  END IF;
  IF p_type NOT IN ('income', 'expense', 'invoice', 'payment') THEN
    RAISE EXCEPTION 'Unsupported transaction type' USING ERRCODE = '22023';
  END IF;
  IF p_amount IS NULL OR p_amount <= 0 OR p_amount <> round(p_amount, 2) THEN
    RAISE EXCEPTION 'Transaction amount must be positive and have at most two decimals' USING ERRCODE = '22023';
  END IF;
  IF p_supplier_id IS NOT NULL AND p_type <> 'expense' THEN
    RAISE EXCEPTION 'Only expense transactions may be linked to a supplier' USING ERRCODE = '22023';
  END IF;
  IF p_client_id IS NULL AND (p_type <> 'expense' OR p_supplier_id IS NULL) THEN
    RAISE EXCEPTION 'A client is required unless this is a supplier-linked expense' USING ERRCODE = '22023';
  END IF;
  IF p_client_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM public.clients WHERE id = p_client_id
  ) THEN
    RAISE EXCEPTION 'Selected client was not found. Choose a valid client or clear the selection.' USING ERRCODE = '22023';
  END IF;

  SELECT supplier_id INTO v_existing_supplier_id
  FROM public.transactions WHERE id = p_id FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Transaction not found' USING ERRCODE = '22023';
  END IF;
  IF p_supplier_id IS NOT NULL THEN
    SELECT status INTO v_supplier_status FROM public.suppliers WHERE id = p_supplier_id;
    IF NOT FOUND OR (v_supplier_status <> 'active' AND p_supplier_id IS DISTINCT FROM v_existing_supplier_id) THEN
      RAISE EXCEPTION 'Select an active supplier' USING ERRCODE = '22023';
    END IF;
  END IF;

  UPDATE public.transactions
  SET client_id = p_client_id,
      amount = p_amount,
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

REVOKE ALL ON FUNCTION public.update_transaction_with_supplier(bigint, bigint, numeric, text, text, text, date, bigint, bigint) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.update_transaction_with_supplier(bigint, bigint, numeric, text, text, text, date, bigint, bigint) TO authenticated;
