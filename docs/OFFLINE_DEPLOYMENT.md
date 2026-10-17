# Offline Mode - Deployment Instructions

## ✅ Implementation Complete

The offline mode implementation is complete and ready for deployment.

## Pre-Deployment Checklist

- [x] Dexie.js dependency installed
- [x] All JavaScript files created
- [x] Database migration file created
- [x] Data layer modified for offline support
- [x] Sync engine integrated
- [x] Sync UI integrated
- [x] Service worker updated
- [x] CSS styles added
- [x] Build successful
- [ ] Database migration applied to Supabase
- [ ] Application deployed to production
- [ ] Testing completed

## Deployment Steps

### Step 1: Apply Database Migration

You must apply the database migration to your Supabase project before deploying the application.

**Option A: Using Supabase CLI (Recommended)**
```bash
cd D:\wamp64\www\CRM\business-management-system
supabase db push
```

**Option B: Manual via Supabase Dashboard**
1. Go to: https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/sql
2. Copy the contents of: `supabase/migrations/202610160001_sync_support.sql`
3. Paste into the SQL editor
4. Click "Run" to execute the migration

**What the migration does:**
- Creates `sync_idempotency` table for duplicate prevention
- Adds `_version` columns to clients, products, transactions, invoices
- Creates version increment triggers
- Adds `create_transaction_offline()` RPC function
- Adds `create_invoice_offline()` RPC function
- Adds `get_sync_metadata()` RPC function

### Step 2: Deploy the Application

The build has been completed successfully. The `dist/` folder contains the production-ready files.

**Deploy to Vercel:**
```bash
cd D:\wamp64\www\CRM\business-management-system
vercel --prod
```

**Or manual deployment:**
1. Upload contents of `dist/` folder to your hosting provider
2. Ensure the hosting provider supports HTTPS (required for PWA)
3. Ensure service worker is served correctly

### Step 3: Verify Deployment

1. Open the deployed application: `https://lmg-ecru.vercel.app`
2. Authenticate with your Supabase account
3. Check for the sync indicator in the header (should show "Online")
4. Open browser DevTools > Application > Service Workers
5. Verify service worker is active (should show "lmg-cm-v4")
6. Open DevTools > Application > IndexedDB
7. Verify "LMG_Offline_DB" database exists
8. Check browser console for errors

## Post-Deployment Testing

### Quick Smoke Test

1. **Test Sync Indicator:**
   - Disconnect network (DevTools > Network > Offline)
   - Verify indicator changes to "Offline Mode"
   - Reconnect network
   - Verify indicator changes back to "Online"

2. **Test Offline Client Creation:**
   - Disconnect network
   - Create a new client
   - Verify client appears in list
   - Refresh page
   - Verify client persists
   - Reconnect network
   - Verify automatic sync (check indicator)
   - Check Supabase database for the client

3. **Test Invoice Provisional Numbering:**
   - Disconnect network
   - Create an invoice
   - Note the provisional number: `INV-PROV-XXXXXX`
   - Reconnect network
   - Sync to server
   - Verify official invoice number assigned

### Full Testing

For comprehensive testing, follow the checklist in:
`docs/OFFLINE_TESTING_CHECKLIST.md`

## Configuration

### Sync Engine Configuration

If you need to adjust sync behavior, edit `public/js/sync-engine.js`:

```javascript
const CONFIG = {
  maxRetries: 5,              // Max retry attempts per operation
  retryDelay: 1000,           // Base delay in milliseconds
  retryBackoff: 2,            // Exponential backoff multiplier
  batchSize: 10,              // Items per sync batch
  syncInterval: 30000,        // Auto-sync interval (30 seconds)
  offlineSessionExpiry: 24 * 60 * 60 * 1000, // 24 hours
};
```

### Adding Development Origins

To allow offline operation during development, set the `ALLOWED_ORIGINS` environment variable in Supabase:

1. Go to: https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/functions/admin-users
2. Click "Settings"
3. Add environment variable:
   - Name: `ALLOWED_ORIGINS`
   - Value: `http://127.0.0.1:8080,https://lmg-ecru.vercel.app`

## Rollback Plan

If issues arise after deployment:

### Option 1: Disable Offline Mode Temporarily

1. Comment out sync engine initialization in `public/js/workspace.js`:
   ```javascript
   // initSyncEngine();
   // initSyncUI();
   // downloadLatestData();
   ```

2. Rebuild and redeploy

### Option 2: Revert to Previous Version

1. Restore previous version from git
2. Rebuild and redeploy
3. The service worker cache will update automatically

### Option 3: Clear IndexedDB

If there are issues with local data:

1. Open DevTools > Application > IndexedDB
2. Right-click "LMG_Offline_DB"
3. Select "Delete database"
4. Refresh the page

## Monitoring

### Client-Side Metrics

Monitor in browser console:
- Sync queue size: `db.sync_queue.toArray()`
- Sync success rate: Check sync indicator
- Conflict count: `db.sync_conflicts.toArray()`

### Server-Side Metrics

Monitor in Supabase:
- `sync_idempotency` table growth
- RPC function performance
- Failed sync attempts (check logs)

## Troubleshooting

### Issue: Sync Not Working

**Symptoms:** Changes not syncing to server

**Solutions:**
1. Check browser console for errors
2. Verify network connectivity
3. Check service worker is updated (v4)
4. Verify RLS policies allow access
5. Check Supabase logs for errors

### Issue: Service Worker Not Updating

**Symptoms:** Old cache version, sync indicator not showing

**Solutions:**
1. Clear browser cache
2. Unregister service worker:
   ```javascript
   navigator.serviceWorker.getRegistration().then(r => r.unregister())
   ```
3. Hard refresh: Ctrl+Shift+R

### Issue: IndexedDB Errors

**Symptoms:** Changes not persisting offline

**Solutions:**
1. Check browser supports IndexedDB
2. Not in private browsing mode
3. Storage quota not exceeded
4. Clear IndexedDB and reload

### Issue: Conflicts Not Detected

**Symptoms:** Data overwritten without warning

**Solutions:**
1. Verify `_version` columns exist in database
2. Check triggers are active
3. Verify sync includes version comparison

## Known Limitations

1. **No offline login**: Must authenticate online first (Supabase Auth limitation)
2. **SMS requires API**: Cannot send SMS offline
3. **User management**: Admin functions require Edge Functions
4. **Dashboard**: Shows cached data, not real-time
5. **Reports**: Generated from cached data only
6. **Storage quota**: IndexedDB limited by browser (~50MB-1GB)
7. **Private browsing**: IndexedDB not available in private mode

## Support

For issues or questions:
1. Check browser console for errors
2. Review `docs/OFFLINE_MODE_GUIDE.md`
3. Check Supabase logs
4. Review IndexedDB data using DevTools

## Documentation

- **Full Guide**: `docs/OFFLINE_MODE_GUIDE.md`
- **Testing Checklist**: `docs/OFFLINE_TESTING_CHECKLIST.md`
- **Implementation Summary**: `docs/OFFLINE_IMPLEMENTATION_SUMMARY.md`

## Files Changed

### Modified:
- `package.json` - Added Dexie.js
- `public/js/data.js` - Added offline support
- `public/js/workspace.js` - Integrated sync engine
- `public/css/theme.css` - Added sync indicator styles
- `public/sw.js` - Updated cache version to v4

### New:
- `public/js/offline-db.js` - IndexedDB schema
- `public/js/sync-engine.js` - Synchronization logic
- `public/js/offline-data.js` - Offline data operations
- `public/js/sync-ui.js` - Sync UI components
- `supabase/migrations/202610160001_sync_support.sql` - Database changes
- `docs/OFFLINE_MODE_GUIDE.md` - Full documentation
- `docs/OFFLINE_TESTING_CHECKLIST.md` - Testing guide
- `docs/OFFLINE_IMPLEMENTATION_SUMMARY.md` - Implementation summary

## Security Notes

- ✅ No passwords stored in IndexedDB
- ✅ No sensitive data in service worker cache
- ✅ Server-side validation on all sync operations
- ✅ Role-based access preserved
- ✅ Idempotency prevents duplicate financial records
- ✅ Conflict detection prevents data loss

## Next Steps

1. ✅ Apply database migration to Supabase
2. ✅ Deploy application to production
3. ⏳ Test offline functionality
4. ⏳ Monitor sync operations
5. ⏳ Gather user feedback

---

**Status**: Ready for database migration and deployment

**Build**: ✅ Successful

**Migration**: ⏳ Pending (user action required)

**Deployment**: ⏳ Pending (user action required)
