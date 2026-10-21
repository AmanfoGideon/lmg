# LMG Creative Media — Technical Documentation

## Overview
- Stack: PHP 8+, MySQL/MariaDB, Bootstrap 5, Chart.js, Service Worker (PWA)
- Modules: Dashboard, Invoices, Transactions, Clients, Products, SMS, Reports, Backup
- Standards: Prepared statements, CSRF protection, secure sessions, JSON interop, accessibility hints

## Supabase Migration Status
- A parallel static Supabase application is under `public/`; configure its public URL/key in the ignored `public/js/config.js` and serve `public/` as the static document root.
- PostgreSQL schema/RLS/RPC migration, Supabase Auth client, Edge Functions, and an isolated data-import utility are present. They have not been deployed or tested against a Supabase project.
- The PHP/MariaDB application remains the active rollback path. Do not remove it until the legacy database is exported and verified, data has been imported, and all Supabase workflows and permission tests pass.
- See `docs/MIGRATION_CHECKLIST.md` for feature mapping and current blockers, and `docs/SUPABASE_DEPLOYMENT.md` for setup and cutover steps.

## Configuration
- Configure database and provider values in the process environment, root `.env`, or `includes.env` (keep these files private):
  - DB_HOST, DB_USER, DB_PASSWORD, DB_NAME
  - CURRENCY_SYMBOL (e.g., $, €, ₦, £, ₵)
  - SMSONLINE_API_KEY, SMSONLINE_HOST, SMSONLINE_SENDER, DEFAULT_DIAL_CODE
- Database host defaults to `localhost`; database username and name must be configured. No credentials are embedded in application code. Charset: `utf8mb4`.

## Security
- Sessions hardened: HttpOnly, Secure (on HTTPS), SameSite=Lax
- CSRF protection: token issued on login and verified on all POST forms
- Content Security Policy set in `includes/auth_check.php`
- Passwords stored with `password_hash` and verified with `password_verify`
- All user-visible strings escaped with `htmlspecialchars`

## Currency and Localization
- Server-side formatter `fmtCurrency(amount)` renders standardized amounts
- Client-side `window.currencySymbol` injected for consistent UI formatting
- To change currency globally: set `CURRENCY_SYMBOL` in `includes.env`

## JSON Interoperability
- Pages support JSON responses using `?format=json`:
  - `/public/dashboard.php?format=json` — summary, charts, activities
  - `/public/reports.php?format=json&group=daily|weekly|monthly&start=YYYY-MM-DD&end=YYYY-MM-DD`
  - `/public/invoices.php?format=json` — recent invoices list
  - `/public/transactions.php?format=json` — recent transactions list
  - `/public/clients.php?format=json&q=<search>&limit=<10|25|50|100>&page=<n>`
- All JSON responses use `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, `JSON_NUMERIC_CHECK`

## Accessibility
- Alerts use `role="alert"` and `aria-live="polite"`
- Action buttons include helpful `aria-label`s where applicable
- Pages declare `<html lang="en">` and responsive meta viewport

## Backup and Recovery
- Manual CSV export per table: `backup.php` (admin-only)
- Full ZIP backup of all tables: submit `export_all` on `backup.php`
  - Requires PHP `ZipArchive` extension enabled
- CSVs contain defined columns per table for portability

## SMS Integration
- Provider: SMSOnline Ghana via Zenoph Notify SDK
- Connectivity check action provided on `/public/sms.php`
- Phone normalization via `ghana_number_formatter()`
- Message logging into `sms_messages` table when present

## Database
- Core tables: users, clients, transactions, invoices, invoice_items, products, user_logs, audit_events, sms_messages, client_interactions
- Invoice integrity update: after taking a database backup, apply `migrations/20261020_invoice_integrity.sql` before deploying the PHP invoice code. It adds nullable unique request keys for new invoices and payment transactions; existing rows and invoice numbers are retained. `db.sql` contains the same fields for new installations. The optional Supabase build also needs `supabase/migrations/202610200001_invoice_integrity.sql` after its existing migrations; that deployment remains unvalidated per the migration checklist.
- Invoice amounts use integer cents for server-side calculation and are stored as exact decimal strings. Tax remains the existing fixed amount; this application has no configurable rate or discount rules. Invoice status is derived from linked payment transactions. Linked invoice payments cannot be edited or deleted through Transactions; the app has no refund/reversal or cancellation workflow yet.
- Invoice numbers retain `INV-YYYYMMDD-{id}` and are finalized from the database auto-increment ID in the same transaction as invoice/items. Payment request keys prevent form retries from duplicating income transactions. Receipts remain transaction-backed and use the transaction ID, with no separate receipt record.
- Recommended indexes (if not already present):
  - transactions(type, date), transactions(client_id), transactions(invoice_id)
  - invoices(client_id, invoice_date, status)
  - sms_messages(created_at, status)
  - clients(full_name), products(name, sku)

## Audit Trail
- Apply `migrations/20261020_audit_events.sql` before deploying the PHP changes. `db.sql` also creates the additive table for new installations.
- Administrators can review filtered and paginated events at `/public/audit_logs.php`; access is checked against the current active `admin` role on every request.
- The server creates audit records. Existing `log_action()` calls continue writing to `user_logs` and are mirrored to structured `audit_events`; authentication, transaction, client, invoice, user, and receipt paths add structured context, with before/after values for supported updates.
- Audit records have no user/entity foreign keys, so deleting related records does not cascade into event history. The application exposes no audit edit/delete controls. Database administrators can still alter records; this is not immutable or tamper-proof storage.
- Audit CSV export respects active filters and is capped at 10,000 rows. Backup exports include `audit_events`. No records are automatically purged; define and approve a retention period before adding archival or purge automation.
- Event times are stored in UTC and shown in the PHP server's configured timezone. IP addresses and user-agent strings are included for investigation and should be handled under the organization's privacy policy.
- Critical transaction create/update/delete and invoice/payment changes write their audit rows inside the same MySQL transaction as the business update. Other legacy modules use the central logger after the operation; apply the migration before using those paths with this release.

## Performance Notes
- Dashboard 12-month and calendar-year charts aggregate transactions in one date-bounded query rather than one query per month. The weekly chart and other dashboard totals retain their existing query paths.

## PWA
- Manifest and service worker registered across pages
- Install button present and guarded by `beforeinstallprompt`
- Maskable icons for adaptive launchers

## Coding Standards
- Use prepared statements for SQL; avoid concatenation
- Escape outputs with `htmlspecialchars`
- Keep business logic in PHP; keep DOM updates safe (no unsafe `innerHTML`)
- Prefer shared helpers (currency, CSRF, env) loaded through includes

## Testing and Validation
- Invoice calculation unit tests: `php tests/php/invoice_calculation_test.php`.
- Invoice PHP/JavaScript syntax checks: `php -l <file>` and `node --check <file>`; apply the database migrations and run a non-production MariaDB/Supabase integration suite before deployment.
- Login: CSRF check and credential verification
- Forms: CSRF token required; sanitized inputs
- Charts: data integrity verified server-side; client uses immutable datasets
- JSON endpoints: verify with browser devtools or curl

## Deployment Notes
- Serve over HTTPS to enable secure cookie and PWA installability
- Ensure `ZipArchive` is enabled for full backups
- Configure `includes.env` and restart PHP service for changes to apply

