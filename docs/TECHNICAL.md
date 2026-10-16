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
- Environment file: `includes.env` supports keys:
  - DB_HOST, DB_USER, DB_PASSWORD, DB_NAME
  - CURRENCY_SYMBOL (e.g., $, €, ₦, £, ₵)
  - SMSONLINE_API_KEY, SMSONLINE_HOST, SMSONLINE_SENDER, DEFAULT_DIAL_CODE
- Database config loads from env with fallback defaults. Charset: `utf8mb4`.

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
- Core tables: users, clients, transactions, invoices, invoice_items, products, user_logs, sms_messages, client_interactions
- Recommended indexes (if not already present):
  - transactions(type, date), transactions(client_id), transactions(invoice_id)
  - invoices(client_id, invoice_date, status)
  - sms_messages(created_at, status)
  - clients(full_name), products(name, sku)

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
- Login: CSRF check and credential verification
- Forms: CSRF token required; sanitized inputs
- Charts: data integrity verified server-side; client uses immutable datasets
- JSON endpoints: verify with browser devtools or curl

## Deployment Notes
- Serve over HTTPS to enable secure cookie and PWA installability
- Ensure `ZipArchive` is enabled for full backups
- Configure `includes.env` and restart PHP service for changes to apply

