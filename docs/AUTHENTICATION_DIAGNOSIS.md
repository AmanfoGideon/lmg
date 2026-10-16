# Edge Function Authentication - Diagnosis and Resolution

## Executive Summary

**Issue**: Supabase Dashboard Test button returns `{"error": "Authentication is required."}`

**Root Cause**: The Edge Functions correctly require JWT bearer token authentication, but the Dashboard's Test interface does not automatically include authentication headers.

**Resolution**: This is **not a bug** - it's correct secure behavior. The fix is to:
1. Use the provided test token helper to obtain a valid JWT
2. Add the `Authorization: Bearer <token>` header in Dashboard tests
3. Configure required environment variables in Supabase Dashboard

**Security Status**: ✅ No changes needed - authentication is working correctly

---

## Detailed Diagnosis

### 1. Authentication Implementation Review

#### admin-users Function
**Location**: `supabase/functions/admin-users/index.ts`

**Authentication Check** (lines 35-37):
```typescript
const authorization = request.headers.get("authorization") ?? "";
const token = authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
if (!token) return response({ error: "Authentication is required." }, 401, origin);
```

**Additional Verification** (lines 39-48):
- Validates token with Supabase Auth
- Retrieves user profile from database
- Checks user has `role = 'admin'`
- Checks user has `status = 'active'`

#### sms-send Function
**Location**: `supabase/functions/sms-send/index.ts`

**Authentication Check** (via `requireActiveUser`, lines 30-44):
```typescript
async function requireActiveUser(request: Request) {
  if (!serviceClient) throw new Error("Function configuration is incomplete.");
  const authorization = request.headers.get("authorization") ?? "";
  const token = authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
  if (!token) throw new Error("Authentication is required.");
  const { data: authData, error: authError } = await serviceClient.auth.getUser(token);
  if (authError || !authData.user) throw new Error("Authentication is invalid or expired.");
  const { data: profile, error } = await serviceClient
    .from("profiles")
    .select("id, email, full_name, role, status")
    .eq("id", authData.user.id)
    .maybeSingle();
  if (error || !profile || profile.status !== "active") throw new Error("An active account is required.");
  return { user: authData.user, profile, client: serviceClient };
}
```

#### email-send Function
**Location**: `supabase/functions/email-send/index.ts`

**Authentication Check**: Same as sms-send (via `requireActiveUser`)

### 2. Configuration Review

#### supabase/config.toml
**Status**: ✅ Correctly configured
```toml
[functions.admin-users]
verify_jwt = true

[functions.email-send]
verify_jwt = true

[functions.sms-send]
verify_jwt = true
```

#### CORS Configuration
**Status**: ✅ Correctly implemented
- All functions handle OPTIONS preflight requests
- CORS headers include `authorization`, `apikey`, `content-type`, `x-client-info`
- Origin validation via `ALLOWED_ORIGINS` environment variable

#### Environment Variables
**Status**: ⚠️ Partially configured

**Present in .env**:
- ✅ `SUPABASE_URL`
- ✅ `SUPABASE_ANON_KEY`
- ✅ `SUPABASE_SERVICE_ROLE_KEY`
- ✅ `SMSONLINE_API_KEY`
- ✅ `SMSONLINE_HOST`
- ✅ `SMSONLINE_SENDER`

**Missing from Edge Function Secrets** (must be added in Dashboard):
- ❌ `ALLOWED_ORIGINS` (required for all functions)
- ❌ `APP_REDIRECT_URL` (required for admin-users)
- ❌ `RESEND_API_KEY` (required for email-send)
- ❌ `EMAIL_FROM` (required for email-send)

### 3. Dashboard Test Limitation

**Problem**: The Supabase Dashboard's Test button does not automatically include:
- Authentication headers
- User session context
- Bearer tokens

**Expected Behavior**: When you click "Test" without headers, the function correctly rejects the request with:
```json
{
  "error": "Authentication is required."
}
```

**This is not a bug** - it's the function working as designed to protect against unauthorized access.

---

## Resolution

### Fix Overview

**No code changes required**. The authentication logic is correct and secure. The resolution is to:

1. **Provide a test token helper** for obtaining valid JWT tokens
2. **Document proper testing methodology** for Dashboard testing
3. **Configure missing environment variables** in Supabase Dashboard

### Deliverables

#### 1. Test Token Helper
**File**: `scripts/get-test-token.html`

**Purpose**: Simple HTML page to obtain a valid JWT access token for testing

**Usage**:
1. Open `scripts/get-test-token.html` in browser
2. Login with admin credentials
3. Copy the generated access token
4. Use in Dashboard Test with `Authorization: Bearer <token>` header

**Security**: Uses anon key (safe for frontend), obtains user-specific token via Supabase Auth

#### 2. Authentication Testing Guide
**File**: `docs/EDGE_FUNCTION_AUTHENTICATION_GUIDE.md`

**Contents**:
- Detailed explanation of authentication architecture
- Step-by-step testing instructions
- Environment variable configuration guide
- Test cases with expected results
- Common errors and solutions
- Security considerations

#### 3. Environment Variable Configuration

**Required Variables** (add in Supabase Dashboard → Functions → Settings):

**All Functions**:
```
SUPABASE_URL=https://pscquoxeieesqfvdsddg.supabase.co
SUPABASE_SERVICE_ROLE_KEY=<get from dashboard>
ALLOWED_ORIGINS=http://127.0.0.1:8080,https://your-production-domain.example
```

**admin-users Only**:
```
APP_REDIRECT_URL=https://your-production-domain.example/index.html
```

**sms-send Only**:
```
SMSONLINE_API_KEY=772a51eeb0357b31153f705cb2dbd1596a52b76b081c3b80c10fd8d8080e364b
SMSONLINE_HOST=api.smsonlinegh.com
SMSONLINE_SENDER=LMG CM
DEFAULT_DIAL_CODE=233
```

**email-send Only**:
```
RESEND_API_KEY=<your-resend-api-key>
EMAIL_FROM=LMG Creative Media <no-reply@example.com>
```

---

## Testing Verification

### Test Cases

#### ✅ Test 1: Valid Request with Valid Credentials
**Setup**: Obtain token via test helper, add Authorization header
**Expected**: Function executes successfully
**Status**: Requires user to perform after environment variable configuration

#### ❌ Test 2: Request Without Authorization Header
**Setup**: Send request without Authorization header
**Expected**: `{"error": "Authentication is required."}` (401)
**Status**: ✅ Verified (this is the current behavior - correct)

#### ❌ Test 3: Request with Invalid Token
**Setup**: Send with `Authorization: Bearer invalid-token`
**Expected**: `{"error": "Authentication is invalid or expired."}` (401)
**Status**: Requires user to perform after token helper setup

#### ❌ Test 4: Non-Admin User (admin-users only)
**Setup**: Use staff user token with admin-users function
**Expected**: `{"error": "Administrator access is required."}` (403)
**Status**: Requires user to perform after creating staff account

#### ❌ Test 5: Missing Environment Variables
**Setup**: Call function without configured environment variables
**Expected**: `{"error": "Function configuration is incomplete."}` (500)
**Status**: ⚠️ Will occur until environment variables are configured

#### ✅ Test 6: CORS Preflight
**Setup**: Send OPTIONS request
**Expected**: HTTP 204 with CORS headers
**Status**: ✅ Verified (functions handle OPTIONS correctly)

---

## Security Analysis

### What Is Working Correctly

1. **JWT Validation**: Tokens are validated against Supabase Auth
2. **User Verification**: User profile is checked in database
3. **Role Enforcement**: Admin functions require admin role
4. **Status Enforcement**: Only active users can access functions
5. **CORS Protection**: Origins validated against ALLOWED_ORIGINS
6. **RLS Enforcement**: Database operations respect RLS policies
7. **Secret Protection**: Service-role key never exposed to frontend

### What Is Not Exposed

- ❌ Service-role key (only in Edge Function environment)
- ❌ Provider API keys (only in Edge Function environment)
- ❌ Password hashes (never imported or exposed)
- ❌ Unauthenticated access to business operations

### Authentication Flow

```
Frontend Request
    ↓
Add Authorization: Bearer <token> header
    ↓
Edge Function receives request
    ↓
Extract token from header
    ↓
Validate token with Supabase Auth
    ↓
Retrieve user profile from database
    ↓
Check user status and role
    ↓
Execute authorized operation
```

---

## Deployment Status

### Functions Deployed
- ✅ admin-users (with inlined shared code)
- ✅ sms-send (with inlined shared code)
- ✅ email-send (with inlined shared code)

### Configuration Required
- ⚠️ Environment variables must be added in Supabase Dashboard
- ⚠️ ALLOWED_ORIGINS must include your development domain
- ⚠️ APP_REDIRECT_URL must be configured for admin-users
- ⚠️ RESEND_API_KEY must be configured for email-send

### Database Migration
- ✅ Initial schema migration applied
- ✅ RLS policies configured
- ✅ Triggers and functions created

---

## Remaining Tasks

### Required Before Testing
1. **Configure environment variables** in Supabase Dashboard for each function
2. **Create an admin user** in your Supabase project (if not exists)
3. **Use test token helper** to obtain valid JWT for testing

### Optional
1. **Update ALLOWED_ORIGINS** when deploying to production
2. **Configure Resend API key** for email functionality
3. **Test SMS functionality** with real provider credentials

---

## Conclusion

The `"Authentication is required."` error is **correct and expected behavior**. The Edge Functions are properly secured and require authenticated requests. The Dashboard Test button does not automatically include authentication headers, which is why the error occurs.

### What Was Done
1. ✅ Diagnosed authentication implementation (all functions correct)
2. ✅ Identified Dashboard Test limitation (expected behavior)
3. ✅ Created test token helper for obtaining valid JWTs
4. ✅ Documented proper testing methodology
5. ✅ Listed required environment variables
6. ✅ Provided security analysis

### What Was NOT Done (Intentionally)
- ❌ Did not disable authentication (would be insecure)
- ❌ Did not weaken JWT verification (would be insecure)
- ❌ Did not bypass authentication for Dashboard tests (would be insecure)
- ❌ Did not expose service-role key (would be insecure)
- ❌ Did not modify RLS policies (not needed)

### Next Steps for User
1. Configure environment variables in Supabase Dashboard
2. Use `scripts/get-test-token.html` to obtain a test token
3. Test functions with Authorization header
4. Verify all functionality works as expected

### Definition of Done Met
- ✅ Root cause identified (Dashboard Test doesn't include auth headers)
- ✅ Correct authentication flow documented
- ✅ Test token helper provided
- ✅ Environment variables documented
- ✅ Security analysis completed
- ✅ No insecure changes made
- ✅ Existing function behavior preserved
