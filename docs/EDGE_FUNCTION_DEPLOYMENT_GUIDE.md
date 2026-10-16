# Edge Function Deployment Guide

## Issue: "Failed to send a request to the Edge Function"

This error occurs because the Supabase Edge Functions have not been deployed to your project yet. The application requires 3 Edge Functions to be deployed and configured.

## Required Edge Functions

### 1. admin-users
**Purpose**: User management (invite, create, delete, status changes)
**Security**: Uses Auth Admin API with service-role key

### 2. sms-send
**Purpose**: Send SMS messages to clients
**Security**: Uses SMS provider API key

### 3. email-send
**Purpose**: Send invoice reminder emails
**Security**: Uses Resend API key

## Deployment Options

### Option 1: Using Supabase CLI (Recommended)

1. **Install Supabase CLI**
   - Download from: https://supabase.com/docs/guides/cli
   - For Windows: Use winget or download the executable
   ```bash
   winget install Supabase.SupabaseCLI
   ```

2. **Login to Supabase**
   ```bash
   supabase login
   ```

3. **Link to your project**
   ```bash
   cd D:\wamp64\www\CRM\business-management-system
   supabase link --project-ref pscquoxeieesqfvdsddg
   ```

4. **Deploy all Edge Functions**
   ```bash
   supabase functions deploy
   ```

5. **Set environment variables** (via Supabase Dashboard or CLI)
   - Go to: https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/functions
   - For each function, click "Settings" and add the required environment variables

### Option 2: Manual Deployment via Dashboard

1. **Go to Supabase Dashboard**
   - Navigate to: https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/functions

2. **Deploy each function manually**
   - Click "New Function"
   - Copy the code from `supabase/functions/[function-name]/index.ts`
   - Paste into the editor
   - Save and deploy

3. **Configure environment variables** for each function

## Required Environment Variables

### For admin-users Edge Function

```
SUPABASE_URL=https://pscquoxeieesqfvdsddg.supabase.co
SUPABASE_SERVICE_ROLE_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9... (get from dashboard)
ALLOWED_ORIGINS=http://127.0.0.1:8080,https://your-production-domain.example
APP_REDIRECT_URL=https://your-production-domain.example/index.html
```

### For sms-send Edge Function

```
SUPABASE_URL=https://pscquoxeieesqfvdsddg.supabase.co
SUPABASE_SERVICE_ROLE_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9... (get from dashboard)
SMSONLINE_API_KEY=your-sms-provider-api-key
SMSONLINE_HOST=api.smsonlinegh.com
SMSONLINE_SENDER=your-registered-sender
DEFAULT_DIAL_CODE=233
ALLOWED_ORIGINS=http://127.0.0.1:8080,https://your-production-domain.example
```

### For email-send Edge Function

```
SUPABASE_URL=https://pscquoxeieesqfvdsddg.supabase.co
SUPABASE_SERVICE_ROLE_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9... (get from dashboard)
RESEND_API_KEY=your-resend-api-key
EMAIL_FROM=LMG Creative Media <no-reply@example.com>
ALLOWED_ORIGINS=http://127.0.0.1:8080,https://your-production-domain.example
```

## Getting API Keys

1. **Project URL**: Already configured in your config.js
2. **Anon Key**: Get from https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/settings/api
   - Format: JWT token starting with `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...`
   - Update `public/js/config.js` with the correct key
3. **Service Role Key**: Get from the same dashboard page
   - Keep this secret - never commit to git
   - Used only in Edge Functions

## CORS Configuration

The `ALLOWED_ORIGINS` environment variable must include:
- Your local development URL: `http://127.0.0.1:8080`
- Your production domain (when deployed)

Separate multiple origins with commas.

## Testing After Deployment

1. **Test admin-users**
   - Navigate to Users page
   - Try to invite a user
   - Check browser console for errors

2. **Test sms-send** (requires provider API key)
   - Navigate to SMS page
   - Try to send a test message
   - Check browser console for errors

3. **Test email-send** (requires Resend API key)
   - Navigate to Invoices page
   - Try to send an invoice email
   - Check browser console for errors

## Temporary Workaround

If you cannot deploy Edge Functions immediately, you can:

1. **Disable user management features** - Comment out the Users navigation link
2. **Disable SMS features** - Comment out the SMS navigation link
3. **Disable email features** - Comment out email reminder buttons

This will allow you to use the core CRM features (clients, products, transactions, invoices, reports, dashboard) without Edge Functions.

## Important Security Notes

- Never expose the service-role key in frontend code
- Never commit real API keys to git
- Use environment variables for all secrets
- Keep the ALLOWED_ORIGINS configuration strict
- Only deploy to your actual Supabase project

## Troubleshooting

### Error: "Origin is not allowed"
- Add your origin to `ALLOWED_ORIGINS` environment variable
- Check for typos in the origin URL
- Ensure CORS is properly configured

### Error: "Function configuration is incomplete"
- Check that `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY` are set
- Verify the keys are correct

### Error: "Administrator access is required"
- Ensure the current user has `role = 'admin'` in the profiles table
- Check that the user status is `active`

### Error: "Invitation redirect is not configured"
- Set `APP_REDIRECT_URL` environment variable
- Should point to your application's login page
