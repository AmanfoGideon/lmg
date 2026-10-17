# Offline Mode Implementation Guide

## Overview

This document describes the complete offline mode implementation for the LMG Creative Media Business Management System. The system now supports full offline operation for all major modules with automatic synchronization when connectivity is restored.

## Architecture

### Components

1. **IndexedDB Storage** (`public/js/offline-db.js`)
   - Local database using Dexie.js
   - Stores cached server data and pending changes
   - Tracks sync status and version information

2. **Sync Engine** (`public/js/sync-engine.js`)
   - Manages synchronization queue
   - Handles retry logic with exponential backoff
   - Processes offline changes when online
   - Detects and resolves conflicts

3. **Offline Data Layer** (`public/js/offline-data.js`)
   - Wraps API calls for offline operation
   - Generates UUIDs for local records
   - Queues operations for sync

4. **Sync UI** (`public/js/sync-ui.js`)
   - Connection status indicator
   - Pending sync count
   - Manual sync button
   - Sync status modal

5. **Database Migration** (`supabase/migrations/202610160001_sync_support.sql`)
   - Idempotency tracking table
   - Version columns for conflict detection
   - RPC functions for safe offline operations

## Supported Offline Operations

| Module | Create | Read | Update | Delete | Notes |
|--------|--------|------|--------|--------|-------|
| Clients | ✅ | ✅ | ✅ | ✅ | Full CRUD offline |
| Products | ✅ | ✅ | ✅ | ✅ | Full CRUD offline |
| Transactions | ✅ | ✅ | ✅ | ✅ | Idempotent with duplicate prevention |
| Invoices | ✅ | ✅ | ✅ | ✅ | Provisional numbering, idempotent |
| Client Interactions | ✅ | ✅ | ❌ | ❌ | Create-only offline |
| Dashboard | ⚠️ | ⚠️ | ❌ | ❌ | Cached data only |
| Reports | ⚠️ | ⚠️ | ❌ | ❌ | Cached data only |
| SMS | ❌ | ❌ | ❌ | ❌ | Requires external API |
| Users | ❌ | ❌ | ❌ | ❌ | Requires admin Edge Function |
| Backup | ❌ | ❌ | ❌ | ❌ | Requires admin Edge Function |

## Key Features

### 1. Idempotency and Duplicate Prevention

All financial operations (transactions, invoices) use idempotency keys to prevent duplicate records if a request is retried after network failure.

**How it works:**
- Each offline operation generates a unique idempotency key
- Server checks if key was already processed
- If already processed, returns existing record instead of creating duplicate
- `sync_idempotency` table tracks all processed operations

### 2. Conflict Detection and Resolution

**Version-based conflict detection:**
- Each record has a `_version` column
- Local updates track server version
- On sync, compare versions
- If server version changed, create conflict record

**Conflict handling:**
- Non-financial records: Last-write-wins (with warning)
- Financial records: Server version preserved, manual review required
- Deleted records: Conflict if modified offline

### 3. Provisional Invoice Numbers

When creating invoices offline:
- Generate provisional number: `INV-PROV-{timestamp}`
- Display provisional number in UI
- On sync, server generates official invoice number
- Local record updated with official number
- Audit trail maintained

### 4. Automatic Synchronization

**Triggers:**
- Browser `online` event
- Periodic check (every 30 seconds)
- Manual sync button
- Application startup

**Retry logic:**
- Exponential backoff: 1s, 2s, 4s, 8s, 16s
- Max 5 retries per operation
- Failed operations marked for manual review
- Batch processing (10 items per batch)

### 5. Connection Status

**Status indicators:**
- Online: Green, fully operational
- Offline: Yellow, local-only mode
- Syncing: Blue, uploading changes
- Synced: Green, all changes uploaded
- Partial: Yellow, some changes pending
- Error: Red, sync failed

## Database Schema Changes

### New Tables

#### `sync_idempotency`
Tracks processed operations for idempotency.

```sql
- id: bigint (primary key)
- idempotency_key: text (unique)
- table_name: text
- operation: text
- user_id: uuid (references auth.users)
- result_record_id: bigint
- created_at: timestamptz
- processed_at: timestamptz
```

### Modified Tables

Added `_version` column to:
- `clients`
- `products`
- `transactions`
- `invoices`

Version increments on each update, enabling conflict detection.

### New Functions

#### `create_transaction_offline(p_idempotency_key, ...)`
Idempotent transaction creation. Returns existing record if key already processed.

#### `create_invoice_offline(p_idempotency_key, p_provisional_number, ...)`
Idempotent invoice creation with provisional number handling.

#### `get_sync_metadata(p_user_id)`
Returns sync metadata (last sync time, pending operations).

## IndexedDB Schema

### Stores

- `sync_metadata`: Sync state and metadata
- `clients`: Local client records
- `products`: Local product records
- `transactions`: Local transaction records
- `invoices`: Local invoice records
- `invoice_items`: Local invoice line items
- `client_interactions`: Local interaction notes
- `sync_queue`: Pending operations queue
- `sync_conflicts`: Conflict records
- `offline_session`: Offline session management

### Record Metadata

Each synced record includes:
- `_sync_status`: "synced", "pending", "conflict", "failed"
- `_synced_at`: Last sync timestamp
- `_version`: Server version for conflict detection
- `server_id`: Server primary key (null for new records)

## Security Considerations

### Authentication
- **No offline login**: Supabase Auth requires online validation
- **Session persistence**: Token stored by Supabase client
- **Offline session expiry**: 24 hours (configurable)
- **No password storage**: Never store passwords in IndexedDB

### Authorization
- **Server-side validation**: All sync operations verified on server
- **Role preservation**: Offline operations respect user role
- **No privilege elevation**: Cannot gain permissions offline

### Data Protection
- **No sensitive caching**: Auth/API traffic never cached
- **Local encryption**: Browser provides IndexedDB encryption
- **Clear on logout**: Session-specific data cleared on sign out

## Deployment Steps

### 1. Install Dependencies

```bash
cd D:\wamp64\www\CRM\business-management-system
npm install
```

### 2. Apply Database Migration

Using Supabase CLI:
```bash
supabase db push
```

Or manually via Supabase Dashboard:
1. Go to: https://supabase.com/dashboard/project/pscquoxeieesqfvdsddg/sql
2. Copy contents of `supabase/migrations/202610160001_sync_support.sql`
3. Execute the SQL

### 3. Build and Deploy

```bash
npm run build
```

Deploy the `dist/` folder to your hosting provider.

### 4. Service Worker Update

The service worker cache version has been updated from `lmg-cm-v3` to `lmg-cm-v4`. Users will automatically receive the update on their next visit.

## Testing Guide

### Test Scenarios

#### 1. Basic Offline Operation
1. Open application online
2. Authenticate
3. Disconnect network (dev tools > offline mode)
4. Create a client
5. Verify client appears in list
6. Refresh page
7. Verify client persists
8. Reconnect network
9. Verify automatic sync
10. Check server database for client

#### 2. Transaction Duplicate Prevention
1. Create transaction offline
2. Start sync
3. Disconnect network during sync
4. Reconnect and retry
5. Verify only one transaction created

#### 3. Invoice Provisional Numbering
1. Create invoice offline
2. Note provisional number: `INV-PROV-123456`
3. Sync to server
4. Verify official invoice number assigned
5. Verify local record updated

#### 4. Conflict Detection
1. Create client A on device 1
2. On device 2, modify client A
3. On device 1, modify client A offline
4. Sync device 1
5. Verify conflict created
6. Resolve conflict manually

#### 5. Multi-Module Offline
1. Disconnect network
2. Create client
3. Create product
4. Create transaction
5. Create invoice
6. Add client interaction
7. Reconnect network
8. Verify all sync
9. Verify no duplicates

#### 6. Session Persistence
1. Authenticate online
2. Close browser
3. Disconnect network
4. Open browser
5. Navigate to cached pages
6. Verify accessible (cannot make new changes)

#### 7. Large Dataset
1. Download latest data
2. Verify offline search works
3. Verify pagination works
4. Verify performance acceptable

### Automated Tests

To be implemented in `public/js/offline.test.js`:
- IndexedDB operations
- Sync queue management
- Idempotency key generation
- Conflict detection logic
- Connection status detection

## Configuration

### Sync Engine Configuration

Edit `public/js/sync-engine.js`:

```javascript
const CONFIG = {
  maxRetries: 5,              // Max retry attempts
  retryDelay: 1000,           // Base delay (ms)
  retryBackoff: 2,            // Exponential multiplier
  batchSize: 10,              // Items per batch
  syncInterval: 30000,        // Auto-sync interval (ms)
  offlineSessionExpiry: 24 * 60 * 60 * 1000, // 24 hours
};
```

### IndexedDB Schema

Edit `public/js/offline-db.js` to add new tables or modify schema.

## Troubleshooting

### Sync Not Working

**Check:**
1. Browser console for errors
2. Network tab for failed requests
3. IndexedDB for queued operations
4. Server for RLS policy issues

**Common causes:**
- CORS misconfiguration
- RLS policy blocking access
- Service worker not updated
- IndexedDB quota exceeded

### Data Not Persisting

**Check:**
1. Browser supports IndexedDB
2. Not in private browsing mode
3. Storage quota not exceeded
4. No IndexedDB errors in console

### Conflicts Not Detected

**Check:**
1. `_version` column exists on tables
2. Triggers are active
3. Sync includes version comparison
4. Server version updated on changes

### Service Worker Issues

**Check:**
1. Service worker registered: `navigator.serviceWorker.getRegistration()`
2. Cache version updated
3. Clear cache and reload: `navigator.serviceWorker.getRegistration().then(r => r.unregister())`

## Known Limitations

1. **No offline login**: Must authenticate online first
2. **SMS requires API**: Cannot send SMS offline
3. **User management**: Admin functions require Edge Functions
4. **Backup**: Requires admin access
5. **Dashboard**: Shows cached data, not real-time
6. **Reports**: Generated from cached data only
7. **Storage quota**: IndexedDB limited by browser (~50MB-1GB)
8. **Private browsing**: IndexedDB not available in private mode

## Future Enhancements

1. **Background Sync API**: Use Service Worker Background Sync for browsers that support it
2. **Offline Dashboard**: Calculate dashboard from local data
3. **Conflict Resolution UI**: Interactive conflict resolution interface
4. **Selective Sync**: Allow users to choose which changes to sync
5. **Compression**: Compress large datasets in IndexedDB
6. **Encryption**: Client-side encryption for sensitive data
7. **Offline Analytics**: Track offline usage patterns
8. **Incremental Sync**: Only sync changed records, not full datasets

## Performance Considerations

### IndexedDB Performance
- Use indexes for frequently queried fields
- Batch operations where possible
- Limit dataset size (pagination)
- Clean up old data periodically

### Sync Performance
- Process in batches (default: 10 items)
- Throttle sync requests (default: 30s interval)
- Use exponential backoff for retries
- Parallel non-dependent operations

### Caching Strategy
- Stale-while-revalidate for static assets
- Network-first for navigations
- Never cache auth/API traffic
- Cache versioning for updates

## Monitoring

### Client-Side Metrics
Track in browser console:
- Sync queue size
- Sync success/failure rate
- Average sync duration
- Conflict count
- Offline time duration

### Server-Side Metrics
Track in Supabase:
- `sync_idempotency` table growth
- RPC function performance
- Conflict rate
- Failed sync attempts

## Support

For issues or questions:
1. Check browser console for errors
2. Review this documentation
3. Check Supabase logs
4. Review IndexedDB data using DevTools

## Version History

- **v1.0** (2026-10-16): Initial offline mode implementation
  - IndexedDB storage with Dexie.js
  - Sync engine with retry logic
  - Idempotency for financial operations
  - Conflict detection and resolution
  - Provisional invoice numbering
  - Sync UI indicators
  - Service worker enhancement
