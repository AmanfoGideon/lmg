CREATE OR REPLACE FUNCTION public.recalculate_supplier_bill_status(p_bill_id bigint)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_total numeric(10, 2);
  v_allocated numeric(10, 2);
  v_status text;
  v_due_date date;
  v_bill_status text;
BEGIN
  SELECT total, due_date, status INTO v_total, v_due_date, v_bill_status
  FROM public.supplier_bills
  WHERE id = p_bill_id
  FOR UPDATE;

  IF NOT FOUND OR v_bill_status = 'cancelled' THEN
    RETURN;
  END IF;

  SELECT COALESCE(SUM(a.amount), 0) INTO v_allocated
  FROM public.supplier_payment_allocations AS a
  JOIN public.supplier_payments AS p ON p.id = a.payment_id
  WHERE a.bill_id = p_bill_id
    AND a.reversed_at IS NULL
    AND p.status <> 'cancelled';

  IF v_allocated >= v_total THEN
    v_status := 'paid';
  ELSIF v_allocated > 0 THEN
    v_status := 'partial';
  ELSE
    v_status := 'unpaid';
  END IF;

  IF v_status IN ('unpaid', 'partial')
     AND v_due_date IS NOT NULL
     AND v_due_date < current_date THEN
    v_status := 'overdue';
  END IF;

  UPDATE public.supplier_bills
  SET status = v_status
  WHERE id = p_bill_id;
END;
$$;

DO $$
DECLARE
  v_bill_id bigint;
BEGIN
  FOR v_bill_id IN SELECT id FROM public.supplier_bills LOOP
    PERFORM public.recalculate_supplier_bill_status(v_bill_id);
  END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION public.sync_supplier_payment_bill_status()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_bill_id bigint;
BEGIN
  IF OLD.status IS DISTINCT FROM NEW.status THEN
    FOR v_bill_id IN
      SELECT DISTINCT bill_id
      FROM public.supplier_payment_allocations
      WHERE payment_id = NEW.id
        AND reversed_at IS NULL
    LOOP
      PERFORM public.recalculate_supplier_bill_status(v_bill_id);
    END LOOP;
  END IF;
  RETURN NULL;
END;
$$;

REVOKE ALL ON FUNCTION public.sync_supplier_payment_bill_status() FROM PUBLIC, anon, authenticated;

CREATE TRIGGER supplier_payments_sync_bill_status
  AFTER UPDATE OF status ON public.supplier_payments
  FOR EACH ROW EXECUTE FUNCTION public.sync_supplier_payment_bill_status();

CREATE OR REPLACE FUNCTION public.get_supplier_outstanding_balance(p_supplier_id bigint)
RETURNS numeric(10, 2)
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_outstanding numeric(10, 2);
BEGIN
  SELECT COALESCE(SUM(GREATEST(sb.total - COALESCE(allocations.allocated, 0), 0)), 0)
  INTO v_outstanding
  FROM public.supplier_bills AS sb
  LEFT JOIN LATERAL (
    SELECT COALESCE(SUM(a.amount), 0) AS allocated
    FROM public.supplier_payment_allocations AS a
    JOIN public.supplier_payments AS p ON p.id = a.payment_id
    WHERE a.bill_id = sb.id
      AND a.reversed_at IS NULL
      AND p.status <> 'cancelled'
  ) AS allocations ON true
  WHERE sb.supplier_id = p_supplier_id
    AND sb.status <> 'cancelled';

  RETURN v_outstanding;
END;
$$;

REVOKE ALL ON FUNCTION public.get_supplier_outstanding_balance(bigint) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.get_supplier_outstanding_balance(bigint) TO authenticated;
