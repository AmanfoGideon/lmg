-- Restore authenticated access to the request-key payment RPC and add safe invoice deletion.
DO $$
BEGIN
  IF to_regprocedure('public.record_invoice_payment(bigint,numeric,text,text,date,text)') IS NULL THEN
    RAISE EXCEPTION 'Apply the invoice integrity migration before invoice actions.';
  END IF;
  EXECUTE 'REVOKE ALL ON FUNCTION public.record_invoice_payment(bigint, numeric, text, text, date, text) FROM PUBLIC, anon';
  EXECUTE 'GRANT EXECUTE ON FUNCTION public.record_invoice_payment(bigint, numeric, text, text, date, text) TO authenticated';
END;
$$;

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
    RAISE EXCEPTION 'Invoices with recorded payments cannot be deleted' USING ERRCODE = '23514';
  END IF;

  DELETE FROM public.invoices WHERE id = p_invoice_id;
  INSERT INTO public.user_logs (user_id, action, details)
  VALUES (auth.uid(), 'invoice_delete', v_invoice.invoice_number);
  RETURN true;
END;
$$;

REVOKE ALL ON FUNCTION public.delete_invoice(bigint) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.delete_invoice(bigint) TO authenticated;
