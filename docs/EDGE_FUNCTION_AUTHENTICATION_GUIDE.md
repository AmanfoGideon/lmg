# Edge Function Authentication Testing Guide

## Root Cause of "Authentication is required" Error

The Edge Functions correctly require user authentication via JWT bearer tokens. The Supabase Dashboard's Test button does **not** automatically include authentication headers, which causes the `"Authentication is required."` error.

**This is expected behavior** - the functions are working correctly by rejecting unauthenticated requests.

## Authentication Architecture

All three Edge Functions (`admin-users`, `sms-send`, `email-send`) implement user authentication:

### Required Headers
```
Authorization: Bearer <your-jwt-access-token>
```

### Authentication Flow
1. Function extracts `Authorization` header
2. Validates Bearer token format
3. Calls Supabase Auth to verify token
4. Retrieves user profile from database
5. Checks user status is "active"
6. Proceeds with authorized request

### Why This is Secure
- ✅ Only authenticated users can access functions
- ✅ Tokens are validated against Supabase Auth
- ✅ RLS policies are enforced at database level
- ✅ Admin functions require `role = 'admin'`
- ✅ Service-role key never exposed to frontend

## Testing Edge Functions with Authentication

### Method 1: Using the Test Token Helper (Recommended)

1. **Open the test token helper**:
   - Open `scripts/get-test-token.html` in your browser
   - This file is pre-configured with your project credentials

2. **Login with your admin account**:
   - Enter your admin email and password
   - Click "Login"
   - Copy the generated access token

3. **Test in Supabase Dashboard**:
   - Go to: https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/functions
   - Click on the function you want to test
   - Click the "Test" button
   - In the Headers section, add:
     ```
     Authorization: Bearer <paste-your-token-here>
     ```
   - Add your request body
   - Click "Send Test"

### Method 2: Using Browser Console

1. **Login to your application**:
   - Open http://127.0.0.1:8080
   - Login with your admin account

2. **Get the token from browser console**:
   ```javascript
   // Open browser console (F12)
   const { data } = await supabase.auth.getSession();
   console.log(data.session.access_token);
   ```
   - Copy the printed token

3. **Use in Dashboard Test** (same as Method 1, step 3)

### Method 3: Using cURL

```bash
# First, get your token via the test helper or browser console
TOKEN="your-access-token-here"

# Test admin-users function
curl -X POST \
  https://pscquoxeieesqfvdsddg.supabase.co/functions/v1/admin-users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action": "list"}'

# Test sms-send function
curl -X POST \
  https://pscquoxeieesqfvdsddg.supabase.co/functions/v1/sms-send \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action": "check_connectivity"}'
```

## Required Environment Variables

All three Edge Functions require these environment variables to be configured in the Supabase Dashboard:

### Common Variables (All Functions)
```
SUPABASE_URL=https://pscquoxeieesqfvdsddg.supabase.co
SUPABASE_SERVICE_ROLE_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9... (get from dashboard)
ALLOWED_ORIGINS=http://127.0.0.1:8080,https://your-production-domain.example
```

### admin-users Function Only
```
APP_REDIRECT_URL=https://your-production-domain.example/index.html
```

### sms-send Function Only
```
SMSONLINE_API_KEY=772a51eeb0357b31153f705cb2dbd1596a52b76b081c3b80c10fd8d8080e364b
SMSONLINE_HOST=api.smsonlinegh.com
SMSONLINE_SENDER=LMG CM
DEFAULT_DIAL_CODE=233
```

### email-send Function Only
```
RESEND_API_KEY=your-resend-api-key
EMAIL_FROM=LMG Creative Media <no-reply@example.com>
```

## Configuring Environment Variables in Supabase Dashboard

1. **Go to your Edge Function**:
   - Navigate to: https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/functions
   - Click on the function name (e.g., `admin-users`)

2. **Add Environment Variables**:
   - Click the "Settings" tab
   - Scroll to "Environment Variables"
   - Click "Add Variable"
   - Add each required variable with its value
   - Click "Save"

3. **Repeat for each function** with its specific variables

## Test Cases

### Test 1: Valid Request with Valid Credentials ✅
**Expected**: Function executes successfully

**Example (admin-users)**:
```json
{
  "action": "list"
}
```
**Headers**: `Authorization: Bearer <valid-token>`

### Test 2: Request Without Authorization Header ❌
**Expected**: `{"error": "Authentication is required."}` with status 401

**Headers**: (none or missing Authorization)

### Test 3: Request with Invalid Token ❌
**Expected**: `{"error": "Authentication is invalid or expired."}` with status 401

**Headers**: `Authorization: Bearer invalid-token`

### Test 4: Request from Non-Admin User (admin-users only) ❌
**Expected**: `{"error": "Administrator access is required."}` with status 403

**Headers**: `Authorization: Bearer <staff-user-token>`

### Test 5: Request with Missing Environment Variables ❌
**Expected**: `{"error": "Function configuration is incomplete."}` with status 500

**Headers**: `Authorization: Bearer <valid-token>`

### Test 6: Invalid Request Body ❌
**Expected**: `{"error": "Request body must be valid JSON."}` with status 400

**Body**: (invalid JSON)

### Test 7: CORS Preflight Request ✅
**Expected**: HTTP 204 with CORS headers

**Method**: OPTIONS

## Common Errors and Solutions

### Error: "Authentication is required."
**Cause**: Missing `Authorization` header in test request
**Solution**: Add `Authorization: Bearer <token>` header using the test token helper

### Error: "Authentication is invalid or expired."
**Cause**: Token is invalid or expired
**Solution**: Get a fresh token by logging in again

### Error: "Administrator access is required."
**Cause**: User does not have `role = 'admin'` in profiles table
**Solution**: Use an admin account or update user role in database

### Error: "Function configuration is incomplete."
**Cause**: Missing `SUPABASE_URL` or `SUPABASE_SERVICE_ROLE_KEY` environment variable
**Solution**: Configure required environment variables in Dashboard

### Error: "Origin is not allowed."
**Cause**: Origin not in `ALLOWED_ORIGINS` environment variable
**Solution**: Add your origin to `ALLOWED_ORIGINS` (e.g., `http://127.0.0.1:8080`)

### Error: "Invitation redirect is not configured."
**Cause**: Missing `APP_REDIRECT_URL` environment variable (admin-users only)
**Solution**: Add `APP_REDIRECT_URL` to admin-users function environment variables

## Security Considerations

### What Is Protected
- ✅ User management (invite, create, delete, status changes)
- ✅ SMS sending (provider API access)
- ✅ Email sending (provider API access)
- ✅ Database operations (RLS enforced)

### What Is NOT Exposed
- ❌ Service-role key (never in frontend)
- ❌ Provider API keys (only in Edge Function secrets)
- ❌ Password hashes (never imported)

### Authentication Tokens
- Tokens are issued by Supabase Auth
- Tokens expire automatically
- Tokens are scoped to the user's session
- Tokens cannot be elevated to service-role privileges

## Deployment Status

### Currently Deployed
- ✅ admin-users
- ✅ sms-send
- ✅ email-send

### Required Configuration
- ⚠️ Environment variables must be configured in Dashboard
- ⚠️ `ALLOWED_ORIGINS` must include your development/production domains
- ⚠️ SMS provider API key is already in .env but must be added to Edge Function secrets

### Verification Steps
1. Configure all required environment variables in Supabase Dashboard
2. Use the test token helper to obtain a valid JWT
3. Test each function with the authorization header
4. Verify that invalid requests are rejected with appropriate errors
5. Verify that valid requests execute successfully

## Summary

The `"Authentication is required."` error is **correct behavior** - the functions are designed to reject unauthenticated requests. To test them:

1. **Use the test token helper** (`scripts/get-test-token.html`) to get a valid JWT
2. **Add the Authorization header** in the Dashboard Test interface
3. **Configure environment variables** for each function in the Dashboard
4. **Test with valid credentials** to verify functionality

The authentication architecture is secure and should not be bypassed.
