ALTER TABLE public.supplier_payment_allocations
  ADD COLUMN IF NOT EXISTS reversed_at timestamptz,
  ADD COLUMN IF NOT EXISTS reversed_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS reversal_reason text;

ALTER TABLE public.supplier_payment_allocations
  DROP CONSTRAINT IF EXISTS supplier_payment_allocations_payment_id_bill_id_key,
  DROP CONSTRAINT IF EXISTS supplier_payment_allocations_payment_id_fkey,
  ADD CONSTRAINT supplier_payment_allocations_payment_id_fkey
    FOREIGN KEY (payment_id) REFERENCES public.supplier_payments(id) ON DELETE RESTRICT;

ALTER TABLE public.supplier_payments
  DROP CONSTRAINT IF EXISTS supplier_payments_status_check,
  ADD CONSTRAINT supplier_payments_status_check
    CHECK (status IN ('pending', 'allocated', 'partially_allocated', 'unallocated', 'cancelled'));

DROP POLICY IF EXISTS "Suppliers can be deleted by admins only" ON public.suppliers;
DROP POLICY IF EXISTS "Supplier bills can be deleted by admins only" ON public.supplier_bills;
DROP POLICY IF EXISTS "Supplier payments can be deleted by admins only" ON public.supplier_payments;
DROP POLICY IF EXISTS "Payment allocations can be deleted by admins only" ON public.supplier_payment_allocations;

DROP POLICY IF EXISTS "Suppliers can be updated by active members or creators" ON public.suppliers;
CREATE POLICY "Suppliers can be updated by active members"
  ON public.suppliers FOR UPDATE TO authenticated
  USING (public.is_active_member())
  WITH CHECK (public.is_active_member() AND updated_by = auth.uid());

DROP POLICY IF EXISTS "Supplier bills can be updated by active members or creators" ON public.supplier_bills;
CREATE POLICY "Supplier bills can be updated by active members"
  ON public.supplier_bills FOR UPDATE TO authenticated
  USING (public.is_active_member())
  WITH CHECK (public.is_active_member() AND updated_by = auth.uid());

DROP POLICY IF EXISTS "Supplier payments can be updated by active members or creators" ON public.supplier_payments;
CREATE POLICY "Supplier payments can be updated by active members"
  ON public.supplier_payments FOR UPDATE TO authenticated
  USING (public.is_active_member())
  WITH CHECK (public.is_active_member() AND updated_by = auth.uid());

DROP POLICY IF EXISTS "Payment allocations can be created by active members" ON public.supplier_payment_allocations;
CREATE POLICY "Payment allocations can be created by active members"
  ON public.supplier_payment_allocations FOR INSERT TO authenticated
  WITH CHECK (
    public.is_active_member()
    AND allocated_by = auth.uid()
    AND EXISTS (
      SELECT 1 FROM public.supplier_payments p
      JOIN public.supplier_bills b ON b.supplier_id = p.supplier_id
      WHERE p.id = payment_id AND b.id = bill_id
        AND p.status <> 'cancelled' AND b.status <> 'cancelled'
    )
  );

DROP POLICY IF EXISTS "Payment allocations are viewable by all active members" ON public.supplier_payment_allocations;
CREATE POLICY "Payment allocations are viewable by active members"
  ON public.supplier_payment_allocations FOR SELECT TO authenticated
  USING (public.is_active_member());

CREATE POLICY "Payment allocation reversals can be recorded by active members"
  ON public.supplier_payment_allocations FOR UPDATE TO authenticated
  USING (public.is_active_member() AND reversed_at IS NULL)
  WITH CHECK (public.is_active_member() AND reversed_by = auth.uid() AND reversed_at IS NOT NULL AND reversal_reason IS NOT NULL);

CREATE OR REPLACE FUNCTION public.guard_supplier_payment_allocation()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
  v_payment_supplier bigint;
  v_payment_amount numeric(10, 2);
  v_payment_status text;
  v_bill_supplier bigint;
  v_bill_total numeric(10, 2);
  v_bill_status text;
  v_payment_allocated numeric(10, 2);
  v_bill_allocated numeric(10, 2);
BEGIN
  IF NOT public.is_active_member() THEN
    RAISE EXCEPTION 'An active authenticated account is required' USING errcode = '42501';
  END IF;

  IF TG_OP = 'INSERT' THEN
    SELECT supplier_id, amount, status INTO v_payment_supplier, v_payment_amount, v_payment_status
    FROM public.supplier_payments WHERE id = NEW.payment_id FOR UPDATE;
    SELECT supplier_id, total, status INTO v_bill_supplier, v_bill_total, v_bill_status
    FROM public.supplier_bills WHERE id = NEW.bill_id FOR UPDATE;

    IF v_payment_supplier IS NULL OR v_bill_supplier IS NULL OR v_payment_supplier <> v_bill_supplier THEN
      RAISE EXCEPTION 'Payment and bill must belong to the same supplier' USING errcode = '22023';
    END IF;
    IF v_payment_status = 'cancelled' OR v_bill_status = 'cancelled' OR NEW.amount <= 0 THEN
      RAISE EXCEPTION 'Allocation requires an active payment, bill, and positive amount' USING errcode = '22023';
    END IF;

    SELECT COALESCE(SUM(amount), 0) INTO v_payment_allocated
    FROM public.supplier_payment_allocations
    WHERE payment_id = NEW.payment_id AND reversed_at IS NULL;
    SELECT COALESCE(SUM(amount), 0) INTO v_bill_allocated
    FROM public.supplier_payment_allocations
    WHERE bill_id = NEW.bill_id AND reversed_at IS NULL;
    IF v_payment_allocated + NEW.amount > v_payment_amount OR v_bill_allocated + NEW.amount > v_bill_total THEN
      RAISE EXCEPTION 'Allocation exceeds the available payment or bill balance' USING errcode = '22023';
    END IF;
    IF NEW.allocated_by IS DISTINCT FROM auth.uid() THEN
      RAISE EXCEPTION 'Allocation actor must match the authenticated user' USING errcode = '42501';
    END IF;
    RETURN NEW;
  END IF;

  IF OLD.reversed_at IS NOT NULL OR NEW.reversed_at IS NULL
     OR NEW.payment_id IS DISTINCT FROM OLD.payment_id
     OR NEW.bill_id IS DISTINCT FROM OLD.bill_id
     OR NEW.amount IS DISTINCT FROM OLD.amount
     OR NEW.allocated_at IS DISTINCT FROM OLD.allocated_at
     OR NEW.allocated_by IS DISTINCT FROM OLD.allocated_by
     OR NEW.reversed_by IS DISTINCT FROM auth.uid()
     OR NULLIF(BTRIM(NEW.reversal_reason), '') IS NULL THEN
    RAISE EXCEPTION 'Allocations are immutable; corrections must be recorded as reversals' USING errcode = '22023';
  END IF;
  RETURN NEW;
END;
$$;

REVOKE ALL ON FUNCTION public.guard_supplier_payment_allocation() FROM PUBLIC, anon, authenticated;

CREATE TRIGGER supplier_payment_allocations_guard
  BEFORE INSERT OR UPDATE ON public.supplier_payment_allocations
  FOR EACH ROW EXECUTE FUNCTION public.guard_supplier_payment_allocation();
