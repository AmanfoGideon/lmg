import Dexie, { Table } from "dexie";

/**
 * Offline IndexedDB Database
 * Stores local copies of data for offline operation and pending sync operations
 */
export const db = new Dexie("LMG_Offline_DB");

// Define database schema
db.version(1).stores({
  // Sync state and metadata
  sync_metadata: "key",

  // Local copies of server data (for offline viewing)
  clients: "id, full_name, email, phone, _synced_at, _sync_status",
  products: "id, name, sku, _synced_at, _sync_status",
  transactions: "id, client_id, type, date, _synced_at, _sync_status",
  invoices: "id, invoice_number, client_id, invoice_date, _synced_at, _sync_status",
  invoice_items: "id, invoice_id, _synced_at, _sync_status",
  client_interactions: "id, client_id, _synced_at, _sync_status",

  // Pending sync queue (operations to upload when online)
  sync_queue: "++id, table_name, record_id, operation, created_at, retry_count, status",

  // Conflict records (when sync detects conflicts)
  sync_conflicts: "++id, table_name, record_id, detected_at, status",

  // Offline session management
  offline_session: "user_id, token, created_at, expires_at",
});

// Database upgrade migration (for future schema changes)
db.version(2).stores({
  // Add version columns for conflict detection
  clients: "id, full_name, email, phone, _synced_at, _sync_status, _version",
  products: "id, name, sku, _synced_at, _sync_status, _version",
  transactions: "id, client_id, type, date, _synced_at, _sync_status, _version",
  invoices: "id, invoice_number, client_id, invoice_date, _synced_at, _sync_status, _version",
}).upgrade(async (tx) => {
  // Add _version field to existing records
  const tables = ["clients", "products", "transactions", "invoices"];
  for (const tableName of tables) {
    const table = tx.table(tableName);
    await table.toCollection().modify((record) => {
      record._version = 1;
    });
  }
});

/**
 * Sync status constants
 */
export const SyncStatus = {
  SYNCED: "synced",
  PENDING: "pending",
  CONFLICT: "conflict",
  FAILED: "failed",
};

/**
 * Sync operation types
 */
export const SyncOperation = {
  CREATE: "create",
  UPDATE: "update",
  DELETE: "delete",
};

/**
 * Queue status constants
 */
export const QueueStatus = {
  PENDING: "pending",
  PROCESSING: "processing",
  SUCCESS: "success",
  FAILED: "failed",
  CONFLICT: "conflict",
};

export default db;
