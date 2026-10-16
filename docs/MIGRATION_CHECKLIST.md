# Supabase Migration Checklist

## Audit Snapshot

- Audit status: complete for the checked-in application source.
- Current runtime: PHP 8.2, MySQL/MariaDB, Bootstrap 5, vanilla JavaScript, Chart.js, DataTables, and PWA service workers.
- Source backup: `../business-management-system-original-20261014.zip` was created from clean `HEAD` before migration edits.
- Database backup: blocked. The configured PHP connection failed against the running local MariaDB service. No row counts, data export, or production-data validation can be claimed until valid read-only connection credentials are supplied and a dump is verified.
- Destructive actions: none. Existing PHP/MySQL code and the database have not been removed or altered.
- Runtime SQL inventory: no stored procedures, database functions, or triggers were found in the checked-in schema. No Composer manifest is present. A vendored Zenoph Notify PHP SDK supplies SMS integration.
- Migration implementation status: a new static Supabase app, versioned PostgreSQL schema/RPC/RLS migration, Edge Functions, and dry-run-first importer have been added alongside the legacy application. Nothing is deployed or connected to production data.
- Local validation: edited JavaScript modules pass Node syntax checks; TypeScript Edge Functions pass editor diagnostics and the Node TypeScript loader/parser where supported; structural checks confirm 9 unique business tables and 9 RLS enable statements. Edge Function dependencies reduced from 4 to 3 (admin-backup replaced with direct CSV export). PostgreSQL DDL, pgTAP, browser flows, external providers, and data import have not been executed.
- Tooling blockers: Supabase CLI, `psql`, Deno, Docker, and a PostgreSQL runtime are unavailable. The checked-in MySQL configuration fails to connect to the running local database services.

## Existing Database Inventory

The checked-in MySQL schema defines nine tables. It contains no explicit triggers, stored procedures, or views.

| Existing table | Columns/relationships and behavior | Supabase target |
| --- | --- | --- |
| `users` | Integer auto-increment PK; full name, unique username, password hash, `admin`/`staff` role, active/inactive status, timestamps, optional email/phone/last login/branch. | `auth.users` for credentials plus `public.profiles` for legacy ID, username, contact fields, status, branch, and application role. Password hashes are not imported as usable passwords. |
| `clients` | Integer PK; name, phone, email, address, creation timestamp; client-name index. | `public.clients`, identity PK and name index. |
| `transactions` | Client FK with cascade delete; decimal amount; income/expense/invoice/payment enum; date; optional payment method, invoice FK with set-null, branch. | `public.transactions`, checks for enums/amount, FKs and reporting indexes. |
| `invoices` | Unique invoice number; nullable client FK with set-null; date/due date, subtotal/tax/total, notes, unpaid/partial/paid status, branch. | `public.invoices`, identity PK, checks, unique/indexed invoice number, preserved FK behavior. |
| `invoice_items` | Invoice FK with cascade delete; name, quantity, price, line total. | `public.invoice_items`, positive quantity and nonnegative money checks. |
| `products` | Unique nullable SKU; price, stock/minimum stock, creation timestamp; name/stock indexes. | `public.products`, checks and indexes. |
| `client_interactions` | Client FK with cascade delete; note and creation timestamp. | `public.client_interactions`, client/timestamp index. |
| `user_logs` | User FK with cascade delete; action/details/timestamp. | `public.user_logs`, actor UUID FK to profiles; authenticated inserts limited to self. |
| `sms_messages` | Nullable client FK with set-null; phone/message, queued/sent/failed status, provider IDs/errors; SMS indexes. | `public.sms_messages`, plus the runtime queue/retry fields found in `sms_dispatcher.php`: sending status, schedule time/offset, retry count, next retry, last attempt. |

The checked-in SQL contains hardcoded sample inserts and a default admin hash. These are not a safe production seed. Database-specific legacy data, live indexes, and any uncommitted schema changes remain unverified until the database can be queried and exported.

## PHP And Endpoint Responsibility Inventory

| Source | Current responsibility | Supabase replacement |
| --- | --- | --- |
| `index.php` | Login screen, PHP session/CSRF, username/password hash verification, redirects. | Static login page using Supabase Auth; verified email-based sign-in and recovery. |
| `public/auth_login.php` | JSON username/password session endpoint; appears redundant with the login form. | Remove only after login migration and call-site verification. |
| `public/logout.php` | Destroys PHP session/cookie. | `supabase.auth.signOut()` and protected-route redirect. |
| `includes/auth_check.php` | Session start, CSRF token, security headers, authenticated redirect. | Auth state listener, protected-page guard, database RLS; host-level security headers. |
| `includes/db.php` | Loads environment/config and opens mysqli using fallback credentials. | Supabase browser client with URL and publishable/anon key; no service-role key in the browser. |
| `includes/log.php` | Inserts current actor action into `user_logs`. | RLS-limited insert or RPC; authenticated `actor_id` derived from `auth.uid()`. |
| `includes/layout/header.php`, `sidebar.php` | Shared navigation, user/role display, currency and DataTables scripts. | Shared static shell, profile-backed role, auth-aware navigation, public currency config. |
| `public/dashboard.php`, `public/js/dashboard.js` | Period totals, weekly trends, monthly chart, top clients, recent activity, stock alerts. | Authorized Supabase selects/views or SQL RPC aggregates; Chart.js behavior retained. |
| `public/clients.php` | Client CRUD, search/pagination, CSV import, CSV/Excel/print export, SMS, notes. | RLS CRUD, browser search/pagination and CSV generation; transactional CSV upsert; SMS Edge Function. |
| `public/clients_interactions.php` | Adds a client note and activity log. | Direct RLS insert or an RPC; retain client history. |
| `public/transactions.php` | Create/update/delete, client auto-create, invoice-linked payments/status recomputation, receipt links. | CRUD plus Postgres payment-status trigger/RPC; validate client/invoice relationship server-side. |
| `public/receipt_view.php` | Joins transaction/client and renders print receipt. | Authenticated query and print-friendly static page. |
| `public/invoices.php` | Lists invoices and clients; creates invoice header/status changes. | RLS queries; status update; invoice number generation in Postgres. |
| `public/invoice_create.php` | Resolves/creates client, calculates line items/tax, creates invoice/items, decrements product stock by matching name. | Atomic Postgres RPC; validate/recalculate totals server-side; preserve stock decrement behavior. |
| `public/invoice_view.php` | Invoice detail/print, payment capture/status, SMS reminder, email reminder. | RLS invoice query; atomic payment RPC; SMS and email Edge Functions. |
| `public/products.php` | Product CRUD and low-stock alerts. | RLS CRUD and stock aggregate. |
| `public/reports.php` | Daily/weekly/monthly financial summaries, date/branch filters, CSV and print. | Authorized SQL aggregate/view or client aggregation; browser CSV and print. |
| `public/users.php` | Admin creates users, sets role/status/branch, deletes/disables accounts. | Admin-only Edge Function using Auth Admin API and profile metadata; RLS-protected profile reads. |
| `public/profile.php` | Profile update and local password hash replacement. | User-owned profile update; Supabase Auth password update and recovery. |
| `public/backup.php` | Admin-only per-table CSV and ZIP export. | Admin-checked `admin-backup` Edge Function returns CSV/ZIP; database-native backups remain a Supabase project operation. |
| `public/sms.php`, `includes/sms.php` | Single/bulk SMS (max 200), client selection, Ghana phone normalization, scheduling, provider connectivity, paginated logs. | Authenticated Edge Functions calling SMSOnline; RLS-protected logs and configuration secrets held in function secrets. |
| `includes/sms_dispatcher.php` | Due queue processing, provider sends, retry count/backoff, optional schedule-offset enforcement. | Scheduled Supabase Edge Function/Cron with idempotent row claiming and retry policy. |
| `public/js/app.js`, `api.js`, `client.js`, `invoices.js`, `transactions.js`, `ui.js` | Login fetch to PHP endpoint, stale API wrapper/demo scaffolding, shared UI behavior; some feature scripts are empty or unused. | Supabase ES modules; retain existing UI behavior after call-site verification. |

The PHP tree also includes the vendored `zenoph.notify-2.25.08-php` SDK and example scripts. It is an external PHP-only provider client, not a Composer-installed dependency. SMSOnline is the only identified external business integration. Email currently uses PHP `mail()` with optional SMTP sender settings; there is no payment gateway, PDF engine, QR verification, or general-purpose file upload. Client CSV import is the only uploaded file workflow. PDF-like outputs are browser print views; Excel export is HTML labeled as `.xls`.

## Migration Progress

| Workstream | Status | Verification gate |
| --- | --- | --- |
| Source and feature audit | Complete | **Statically verified**: Checked-in PHP, SQL, UI scripts, docs, and integration paths inspected. |
| Original source backup | Complete | **Statically verified**: ZIP archive created outside the project from clean `HEAD`. |
| MySQL data export and verified backup | Blocked | **Runtime blocked**: Current configured mysqli connection fails; obtain working DB access, export, checksum, and row counts before production data operations. |
| PostgreSQL schema, constraints, functions, and indexes | Implemented; not applied | **Runtime blocked**: Apply migration to Supabase and validate Postgres execution/schema before import. |
| Auth/profile migration and password reset strategy | Implemented; not provisioned | **Runtime blocked**: Map each legacy account to verified email/Auth UUID; force password setup because legacy hashes cannot be imported through the browser client. |
| RLS and role boundary tests | Implemented; not run | **Runtime blocked**: `supabase/tests/database/security.test.sql` checks RLS enablement and grants; also test actual staff/admin/anonymous row access. |
| Dashboard, clients, interactions, products | Implemented in static app; not live-tested | **Runtime blocked**: Verify CRUD, search/pagination, CSV import/export, metrics, and low-stock workflows against configured Supabase. |
| Invoices, transactions, receipts | Implemented in static app/RPCs; not live-tested | **Runtime blocked**: Verify atomic invoice/items/stock updates and payment status on create/update/delete, including rollback and concurrent payments. |
| Reports and exports | Implemented in static app; not live-tested | **Runtime blocked**: Verify date/branch filters, totals, CSV/print output parity, and admin-only export function. |
| SMS and email integrations | Edge Functions implemented; not configured/tested | **Runtime blocked**: Configure credentials; verify provider protocol, delivery status, schedules, retries, failure handling, and log entries. |
| Admin, profile, backup, PWA | Implemented in static app; not live-tested | **Runtime blocked**: Verify invites, recovery, role checks, private export, mobile layout, and static/offline behavior. CSV export now uses direct client (Edge Function removed). |
| Migration importer | Implemented; not run | **Runtime blocked**: Create verified MySQL dump and verified-email map, dry-run against a fresh Supabase project, then compare counts/totals before applying. |
| PHP/MySQL cleanup | Pending and blocked | **Runtime blocked**: Keep rollback system until all replacements, tests, deployment, data validation, and backups are verified. |

## Edge Function Migration

- Status: **Statically verified and implemented**
- Changes: Reduced Edge Function dependencies from 4 to 3
- Replaced: `admin-backup` Edge Function with direct Supabase client CSV export
- Remaining: `admin-users`, `sms-send`, `email-send` (require privileged credentials)
- Documentation: See `docs/EDGE_FUNCTION_MIGRATION_REPORT.md` for detailed analysis
- Security: All privileged operations remain in trusted backend; no credentials exposed to frontend

## Required Migration Gates And Risks

1. Do not treat `db.sql` sample rows as a production backup. Obtain a verified dump from the live MySQL/MariaDB database first.
2. The current DB connection uses legacy fallback credentials from `includes/db.php`, and `includes.env` is Git-tracked. Keep both out of new frontend configuration. Rotate any real database/provider credentials committed to source control, then remove them only after cutover.
3. Legacy users have usernames and password hashes but may not have verified emails. Resolve account ownership and collect verified emails before provisioning Auth accounts. Never copy plaintext passwords or expose the service-role key.
4. Deploy and test Supabase migrations, RLS, Edge Functions, Cron, Auth redirect/recovery settings, and storage only if an actual file-storage need is added. There is no current general file upload to move to Storage.
5. Keep the PHP application available as rollback until the new workflows pass against a copied dataset and production deployment is verified.
6. Static parser checks and test source are not successful database/role tests; those gates remain open until run against a Supabase project.