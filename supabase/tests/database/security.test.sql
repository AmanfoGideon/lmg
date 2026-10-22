begin;

select plan(34);

select ok((select relrowsecurity from pg_class where oid = 'public.profiles'::regclass), 'profiles has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.clients'::regclass), 'clients has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.transactions'::regclass), 'transactions has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.invoices'::regclass), 'invoices has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.invoice_items'::regclass), 'invoice_items has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.products'::regclass), 'products has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.client_interactions'::regclass), 'client_interactions has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.user_logs'::regclass), 'user_logs has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.sms_messages'::regclass), 'sms_messages has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.suppliers'::regclass), 'suppliers has RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.supplier_bills'::regclass), 'supplier bills have RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.supplier_payments'::regclass), 'supplier payments have RLS enabled');
select ok((select relrowsecurity from pg_class where oid = 'public.supplier_payment_allocations'::regclass), 'supplier allocations have RLS enabled');
select ok(exists (select 1 from pg_trigger where tgrelid = 'public.transactions'::regclass and tgname = 'transactions_guard_invoice_payment' and not tgisinternal), 'invoice payment rows are protected from edits and deletion');
select ok(exists (select 1 from pg_trigger where tgrelid = 'public.invoices'::regclass and tgname = 'invoices_guard_payment_status' and not tgisinternal), 'invoice status and paid details are database-protected');
select ok((select confdeltype = 'r' from pg_constraint where conrelid = 'public.transactions'::regclass and conname = 'transactions_invoice_id_fkey'), 'invoice deletion cannot cascade into transaction history');
select ok(not (select attnotnull from pg_attribute where attrelid = 'public.transactions'::regclass and attname = 'client_id'), 'transaction client references can be null where business rules allow');
select ok(has_function_privilege('authenticated', 'public.get_supplier_spending_totals()', 'EXECUTE'), 'authenticated users can read RLS-filtered supplier spending totals');
select ok(not has_function_privilege('anon', 'public.get_supplier_spending_totals()', 'EXECUTE'), 'anonymous users cannot read supplier spending totals');
select ok(exists (select 1 from pg_indexes where schemaname = 'public' and indexname = 'transactions_supplier_expense_idx'), 'supplier expense aggregation has a partial lookup index');

select ok(not has_function_privilege('anon', 'public.create_invoice(bigint, text, date, date, numeric, text, jsonb)', 'EXECUTE'), 'anonymous users cannot create invoices');
select ok(has_function_privilege('authenticated', 'public.create_invoice(bigint, text, date, date, numeric, text, jsonb)', 'EXECUTE'), 'authenticated members can create invoices');
select ok(has_function_privilege('authenticated', 'public.update_transaction(bigint, numeric, text, text, text, date, bigint)', 'EXECUTE'), 'authenticated members can update transactions through the validated RPC');
select ok(not has_function_privilege('authenticated', 'public.claim_sms_messages(integer, integer)', 'EXECUTE'), 'authenticated users cannot claim SMS dispatch jobs');
select ok(has_function_privilege('service_role', 'public.claim_sms_messages(integer, integer)', 'EXECUTE'), 'the service role can claim SMS dispatch jobs');
select ok(not has_function_privilege('authenticated', 'public.reset_business_identity_sequences()', 'EXECUTE'), 'authenticated users cannot reset identity sequences');
select ok(has_function_privilege('service_role', 'public.reset_business_identity_sequences()', 'EXECUTE'), 'the service role can reset identity sequences for migration');
select ok(not has_column_privilege('authenticated', 'public.profiles', 'role', 'UPDATE'), 'users cannot change their own application role');
select ok(not has_column_privilege('authenticated', 'public.profiles', 'status', 'UPDATE'), 'users cannot change their own account status');
select ok(not has_column_privilege('authenticated', 'public.profiles', 'email', 'UPDATE'), 'users cannot change email outside Supabase Auth');
select ok(has_column_privilege('authenticated', 'public.profiles', 'full_name', 'UPDATE'), 'users can update their own display name');
select ok(exists (select 1 from pg_policies where schemaname = 'public' and tablename = 'profiles' and policyname = 'profiles_update_self'), 'profile updates are protected by an own-row RLS policy');
select ok(has_function_privilege('authenticated', 'public.is_admin()', 'EXECUTE'), 'authenticated clients can use the database-backed admin check');
select ok(not has_function_privilege('anon', 'public.is_admin()', 'EXECUTE'), 'anonymous clients cannot use the admin check');

select * from finish();
rollback;
