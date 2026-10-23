ALTER TABLE public.invoices
  ADD COLUMN IF NOT EXISTS cancelled_at timestamptz,
  ADD COLUMN IF NOT EXISTS cancelled_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS cancellation_reason text;

CREATE INDEX IF NOT EXISTS invoices_cancelled_at_idx
  ON public.invoices (cancelled_at) WHERE cancelled_at IS NOT NULL;

CREATE OR REPLACE FUNCTION public.cancel_invoice(p_invoice_id bigint, p_reason text)
RETURNS boolean
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_invoice public.invoices%ROWTYPE;
  v_reason text := nullif(btrim(p_reason), '');
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING errcode = '42501';
  END IF;
  IF p_invoice_id IS NULL OR v_reason IS NULL OR length(v_reason) > 500 THEN
    RAISE EXCEPTION 'A cancellation reason of up to 500 characters is required' USING errcode = '22023';
  END IF;

  SELECT * INTO v_invoice
  FROM public.invoices
  WHERE id = p_invoice_id
  FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Invoice not found' USING errcode = '22023';
  END IF;
  IF v_invoice.cancelled_at IS NOT NULL THEN
    RAISE EXCEPTION 'Invoice is already cancelled' USING errcode = '22023';
  END IF;
  IF EXISTS (
    SELECT 1 FROM public.invoice_items ii JOIN public.products p ON lower(p.name) = lower(ii.item_name)
    WHERE ii.invoice_id = p_invoice_id
  ) THEN
    RAISE EXCEPTION 'This invoice contains inventory-linked items. Reconcile stock before cancelling it' USING errcode = '23514';
  END IF;

  UPDATE public.invoices
  SET cancelled_at = now(), cancelled_by = auth.uid(), cancellation_reason = v_reason
  WHERE id = p_invoice_id;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES (auth.uid(), 'invoice_cancel', v_invoice.invoice_number || '|' || v_reason);
  RETURN true;
END;
$$;

REVOKE ALL ON FUNCTION public.cancel_invoice(bigint, text) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.cancel_invoice(bigint, text) TO authenticated;

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
    RAISE EXCEPTION 'An active authenticated account is required' USING errcode = '42501';
  END IF;
  SELECT * INTO v_invoice FROM public.invoices WHERE id = p_invoice_id FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Invoice not found' USING errcode = '22023'; END IF;
  IF v_invoice.cancelled_at IS NOT NULL THEN
    RAISE EXCEPTION 'Cancelled invoices are preserved in financial history' USING errcode = '23514';
  END IF;
  IF EXISTS (SELECT 1 FROM public.transactions WHERE invoice_id = p_invoice_id) THEN
    RAISE EXCEPTION 'Invoices with linked financial history cannot be deleted; cancel the invoice instead' USING errcode = '23514';
  END IF;
  IF EXISTS (
    SELECT 1 FROM public.invoice_items ii JOIN public.products p ON lower(p.name) = lower(ii.item_name)
    WHERE ii.invoice_id = p_invoice_id
  ) THEN
    RAISE EXCEPTION 'Invoices with inventory-linked items cannot be deleted until stock is reconciled' USING errcode = '23514';
  END IF;
  DELETE FROM public.invoices WHERE id = p_invoice_id;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES (auth.uid(), 'invoice_delete', v_invoice.invoice_number);
  RETURN true;
END;
$$;

REVOKE ALL ON FUNCTION public.delete_invoice(bigint) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.delete_invoice(bigint) TO authenticated;

-- All invoice writes go through validated, transactional RPCs.
REVOKE UPDATE, DELETE ON public.invoices FROM authenticated;

CREATE OR REPLACE FUNCTION public.prevent_cancelled_invoice_mutation()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
BEGIN
  IF OLD.cancelled_at IS NOT NULL THEN
    RAISE EXCEPTION 'Cancelled invoices are immutable financial history' USING errcode = '23514';
  END IF;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS prevent_cancelled_invoice_mutation ON public.invoices;
CREATE TRIGGER prevent_cancelled_invoice_mutation
BEFORE UPDATE ON public.invoices
FOR EACH ROW EXECUTE FUNCTION public.prevent_cancelled_invoice_mutation();

-- Keep cancelled invoices in history while excluding them from the pending count.
DO $$
DECLARE
  v_definition text := pg_get_functiondef('public.get_dashboard(text)'::regprocedure);
  v_pending_expression text := '(select count(*) from public.invoices where status <> ''paid'')';
BEGIN
  IF position(v_pending_expression in v_definition) = 0 THEN
    RAISE EXCEPTION 'Could not locate the pending invoice count in get_dashboard';
  END IF;
  v_definition := replace(v_definition, v_pending_expression,
    '(select count(*) from public.invoices where status <> ''paid'' and cancelled_at is null)');
  EXECUTE v_definition;
END;
$$;
