# LMG Creative Media — User Guide

## Getting Started
- Navigate to the login page and sign in with your credentials
- Install as an app via the “Install App” button (where available)
- The application works offline for selected actions (PWA support)

## Supabase Migration Preview
- The static Supabase build is `public/index.html` and `public/app.html`; the existing PHP app remains the active system until migration validation is complete.
- Supabase login uses invited, verified email accounts. Legacy username/password hashes are not transferred; each migrated account must set a new password.
- The static build is not production-ready until its public project URL/key are configured, the database is migrated, Edge Function secrets are set, and the tests in the migration checklist pass.

## Dashboard
- Overview cards show Income, Expenses, Profit, Pending Invoices
- Period filter: Week, Month, Year — affects stats and charts
- Weekly trend sparkline and top clients list
- Notifications list includes stock alerts and invoice statuses

## Clients
- Add, edit, delete client records
- Search and paginate results; export CSV/Excel; print views
- Import CSV: autodetects delimiters and maps common columns
- Interaction notes: add and review recent notes per client

## Invoices
- Create invoices with items, quantities, prices, tax
- Auto-generate invoice numbers; update invoice statuses
- View invoices and print; send reminders via SMS

## Transactions
- Record income, expenses, invoices, and payments
- Link payments to invoices to auto-update invoice status
- Generate receipt for income or payment transactions

## Products
- Manage product catalog: name, SKU, price, stock, min stock
- Low stock alert card displays items at or below threshold
- Inline editing for fast updates and deletion

## SMS
- Send single or bulk SMS to clients or custom numbers
- Schedule messages with UTC offset and queue offline via PWA
- Review SMS logs with search and pagination
- Connectivity check for the SMS provider integration

## Reports
- Financial summaries grouped daily, weekly, or monthly
- Export CSV; print friendly views
- Bars compare income and expenses; totals shown at top

## Backup
- Export CSV per table from Backup page (admin-only)
- Full backup: download ZIP containing all tables as CSV

## Settings and Security
- CSRF protection: all forms include invisible tokens
- Sessions: secured with HttpOnly, SameSite=Lax; Secure on HTTPS
- Passwords: hashed; only admins can manage users
- Currency symbol: set globally via `includes.env` CURRENCY_SYMBOL

## Accessibility
- Alerts use `role="alert"` with polite announcements
- Buttons include `aria-label` where helpful
- Responsive layout adjusts across desktop, tablet, and mobile

## Tips
- Use the search and limit controls to navigate large datasets
- For interoperability, access JSON with `?format=json` on key pages
- Keep `includes.env` up to date and secure; do not share API keys

