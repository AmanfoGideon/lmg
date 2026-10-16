-- Development-only examples. Do not run against production.
insert into public.clients (id, full_name, phone, email, address)
values (900000001, 'Development Sample Client', null, null, 'Local development only')
on conflict (id) do nothing;

insert into public.products (id, name, sku, price, stock, min_stock)
values (900000001, 'Development Sample Product', 'DEV-SAMPLE-001', 10.00, 5, 1)
on conflict (id) do nothing;