# Supabase Deployment Guide

## Project Setup

1. Create a Supabase project and keep its database backup/export available before importing any live data.
2. Install the Supabase CLI and Docker if local validation is required. This workstation does not currently have the CLI, Docker, or `psql` available.
3. Copy `.env.example` to a private local environment file and fill in only the values required by the task. Do not commit populated environment files.
4. Set `supabaseUrl`, `supabaseAnonKey`, and the public currency symbol in `public/js/config.js`. The publishable/anon key is not a secret; database policies must remain enabled.
5. From the project root, run `supabase start`, `supabase db reset`, and `supabase test db` after installing the CLI. Review and apply migrations to a non-production Supabase project first.

## Auth And Authorization

- Self-service sign-up is disabled. Provision staff/admin accounts with the trusted `admin-users` Edge Function after it is deployed and verified.
- Configure Auth email confirmation, SMTP, site URL, and allowed redirect URLs for the real deployment host. Password reset redirects to `public/app.html?recovery=1`.
- Legacy users with username/password hashes must receive an individually verified email address and set a new password through Supabase Auth. Do not import old password hashes or plaintext passwords.
- The application role is stored in `public.profiles`; the browser reads it for navigation only. Every protected operation remains guarded by RLS or a trusted Edge Function that checks the profile in the database.
- The service-role key belongs only in Supabase Edge Function secrets or a private one-time migration environment. Never add it to static files, browser configuration, or source control.

## Database And Data Import

- Apply `supabase/migrations/202610140001_initial_schema.sql` before importing records.
- The migration preserves the nine business tables and their main relationships, and adds RLS, profile-role helpers, invoice/payment RPCs, and queue fields found in runtime code.
- Export the live MySQL/MariaDB database with a verified dump before importing. The current local PHP DB connection fails, so there is no validated source dump or row-count baseline yet.
- Build a private mapping from each legacy integer user ID to a provisioned Supabase Auth UUID and verified email. The import must omit password hashes and preserve legacy IDs in `profiles.legacy_user_id`.
- Import business data in FK order, retain existing IDs, compare source and target row counts and financial totals, then reset identity sequences. Never run `supabase/seed.sql` on production.

## Edge Functions And Integrations

Deploy `admin-users`, `admin-backup`, `email-send`, `sms-send`, and `sms-dispatcher` only after their implementations and tests are complete. From the repository root, deploy with `supabase functions deploy <function-name>` after setting the function secrets with `supabase secrets set`; required names are listed in `.env.example`. The SMSOnline adapter follows the vendored Zenoph SDK's v5 XML/auth protocol, but still requires provider-credential testing before replacing PHP delivery.

The old system has no general file upload, so no Storage bucket is required for feature parity. Client CSV import is parsed in the browser and writes validated rows through authorized database operations. If invoice attachments are later required, create a private bucket with user/role-specific policies before enabling uploads.

## Scheduled SMS

After deploying and testing the dispatcher, enable `pg_cron`, `pg_net`, and Vault. Store the function URL and a dedicated random value equal to `SMS_DISPATCH_SECRET` in Vault, then schedule an invocation such as:

```sql
select cron.schedule(
	'lmg-sms-dispatch-every-minute',
	'* * * * *',
	$$
		select net.http_post(
			url := (select decrypted_secret from vault.decrypted_secrets where name = 'sms_dispatch_url'),
			headers := jsonb_build_object(
				'Content-Type', 'application/json',
				'x-cron-secret', (select decrypted_secret from vault.decrypted_secrets where name = 'sms_dispatch_secret')
			),
			body := '{}'::jsonb
		);
	$$
);
```

Do not use the service-role key as a Cron URL secret. Verify idempotent claiming, retries, UTC scheduling, and provider outcomes before enabling it for production.

## Cutover And Rollback

Run `npm install` and `npm run build` to produce the static application in `dist/`; the build excludes PHP endpoints. `npm run dev` serves the static source during development. Keep the existing PHP/MariaDB system available until authentication, all module CRUD, invoice/payment integrity, reports, exports, SMS/email, RLS boundaries, and mobile/PWA workflows pass against a copied dataset. Cut over DNS/static hosting only after a verified import and tested rollback point. Remove PHP, MySQL credentials, and the vendored PHP SDK only after that gate; none have been removed in this migration pass.