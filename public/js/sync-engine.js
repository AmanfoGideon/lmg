import { db, SyncStatus, SyncOperation, QueueStatus } from "./offline-db.js";
import { getSupabase } from "./supabase.js";

/**
 * Synchronization Engine
 * Handles offline-to-online data synchronization with retry logic and conflict detection
 */

// Configuration
const CONFIG = {
  maxRetries: 5,
  retryDelay: 1000, // Base delay in ms
  retryBackoff: 2, // Exponential backoff multiplier
  batchSize: 10, // Process 10 items per batch
  syncInterval: 30000, // Check for sync every 30 seconds
  offlineSessionExpiry: 24 * 60 * 60 * 1000, // 24 hours
};

// State
let isSyncing = false;
let syncTimer = null;
let isOnline = navigator.onLine;

/**
 * Initialize the sync engine
 */
export function initSyncEngine() {
  // Listen for online/offline events
  window.addEventListener("online", handleOnline);
  window.addEventListener("offline", handleOffline);

  // Check connectivity periodically
  setInterval(checkConnectivity, 60000);

  // Start periodic sync if online
  if (isOnline) {
    startPeriodicSync();
  }

  // Attempt to sync on load
  if (isOnline) {
    attemptSync();
  }
}

/**
 * Handle coming back online
 */
async function handleOnline() {
  isOnline = true;
  console.log("[Sync] Connection restored");
  updateSyncStatusUI("online");
  startPeriodicSync();
  await attemptSync();
}

/**
 * Handle going offline
 */
function handleOffline() {
  isOnline = false;
  console.log("[Sync] Connection lost");
  updateSyncStatusUI("offline");
  stopPeriodicSync();
}

/**
 * Check if we can reach the server
 */
async function checkConnectivity() {
  try {
    const supabase = getSupabase();
    // Simple ping to check connectivity
    await supabase.from("clients").select("id", { count: "exact", head: true });
    if (!isOnline) {
      handleOnline();
    }
  } catch (error) {
    if (isOnline) {
      handleOffline();
    }
  }
}

/**
 * Start periodic sync
 */
function startPeriodicSync() {
  if (syncTimer) return;
  syncTimer = setInterval(attemptSync, CONFIG.syncInterval);
}

/**
 * Stop periodic sync
 */
function stopPeriodicSync() {
  if (syncTimer) {
    clearInterval(syncTimer);
    syncTimer = null;
  }
}

/**
 * Attempt to synchronize pending changes
 */
export async function attemptSync() {
  if (isSyncing || !isOnline) return;

  isSyncing = true;
  updateSyncStatusUI("syncing");

  try {
    const pendingQueue = db.sync_queue.where("status").equals(QueueStatus.PENDING);
    const totalPendingCount = await pendingQueue.count();

    if (totalPendingCount === 0) {
      console.log("[Sync] No pending changes to sync");
      updateSyncStatusUI("synced");
      return;
    }

    const eligibleAt = Date.now();
    const isRetryReady = (item) => {
      if (!item.next_retry_at) return true;
      const nextRetryAt = Date.parse(item.next_retry_at);
      return !Number.isFinite(nextRetryAt) || nextRetryAt <= eligibleAt;
    };
    const pendingCount = await pendingQueue.filter(isRetryReady).count();
    if (pendingCount === 0) {
      updateSyncStatusUI("partial");
      return;
    }

    console.log(`[Sync] Syncing ${pendingCount} pending changes`);

    // Process in batches
    let processed = 0;
    let failed = 0;
    let attempted = 0;

    while (attempted < pendingCount) {
      const batch = await db.sync_queue
        .where("status")
        .equals(QueueStatus.PENDING)
        .filter(isRetryReady)
        .limit(CONFIG.batchSize)
        .toArray();
      if (batch.length === 0) break;

      for (const item of batch) {
        try {
          await processSyncItem(item);
          processed++;
        } catch (error) {
          console.error("[Sync] Failed to process item:", item.id, error);
          await handleSyncFailure(item, error);
          failed++;
        }
        attempted++;
      }
    }

    console.log(`[Sync] Sync complete: ${processed} processed, ${failed} failed`);

    if (failed > 0) {
      updateSyncStatusUI("partial");
    } else {
      updateSyncStatusUI("synced");
    }

    // Dispatch event for UI updates
    window.dispatchEvent(new CustomEvent("sync-complete", { detail: { processed, failed } }));
  } catch (error) {
    console.error("[Sync] Sync failed:", error);
    updateSyncStatusUI("error");
  } finally {
    isSyncing = false;
  }
}

/**
 * Process a single sync item
 */
async function processSyncItem(item) {
  await db.sync_queue.update(item.id, { status: QueueStatus.PROCESSING });

  const supabase = getSupabase();
  let result;

  switch (item.table_name) {
    case "clients":
      result = await syncClient(supabase, item);
      break;
    case "products":
      result = await syncProduct(supabase, item);
      break;
    case "transactions":
      result = await syncTransaction(supabase, item);
      break;
    case "invoices":
      result = await syncInvoice(supabase, item);
      break;
    case "invoice_items":
      result = await syncInvoiceItem(supabase, item);
      break;
    case "client_interactions":
      result = await syncClientInteraction(supabase, item);
      break;
    default:
      throw new Error(`Unknown table: ${item.table_name}`);
  }

  // Update local record with server response
  if (result && item.record_id) {
    await updateLocalRecord(item.table_name, item.record_id, result);
  }

  // Mark queue item as success
  await db.sync_queue.update(item.id, {
    status: QueueStatus.SUCCESS,
    processed_at: new Date().toISOString(),
  });

  // Delete from queue after successful sync
  await db.sync_queue.delete(item.id);
}

/**
 * Sync a client record
 */
async function syncClient(supabase, item) {
  const data = item.payload;

  if (item.operation === SyncOperation.CREATE) {
    // Check for duplicate by email/phone
    const { data: existing } = await supabase
      .from("clients")
      .select("id")
      .or(`email.eq.${data.email},phone.eq.${data.phone}`)
      .maybeSingle();

    if (existing) {
      // Conflict: record already exists
      await createConflict(item, existing.id, "Record already exists on server");
      throw new Error("Client already exists");
    }

    const { data: result, error } = await supabase
      .from("clients")
      .insert(data)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.UPDATE) {
    const { data: existing } = await supabase
      .from("clients")
      .select("id, updated_at")
      .eq("id", item.server_id)
      .single();

    if (!existing) {
      // Record was deleted on server
      await createConflict(item, null, "Record deleted on server");
      throw new Error("Record deleted on server");
    }

    // Check for version conflict
    if (item.server_version && existing.updated_at !== item.server_version) {
      await createConflict(item, item.server_id, "Record modified on server");
      throw new Error("Version conflict");
    }

    const { data: result, error } = await supabase
      .from("clients")
      .update(data)
      .eq("id", item.server_id)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.DELETE) {
    const { error } = await supabase
      .from("clients")
      .delete()
      .eq("id", item.server_id);

    if (error) throw error;
    return null;
  }

  throw new Error(`Unknown operation: ${item.operation}`);
}

/**
 * Sync a product record
 */
async function syncProduct(supabase, item) {
  const data = item.payload;

  if (item.operation === SyncOperation.CREATE) {
    const { data: existing } = await supabase
      .from("products")
      .select("id")
      .eq("sku", data.sku)
      .maybeSingle();

    if (existing) {
      await createConflict(item, existing.id, "SKU already exists on server");
      throw new Error("Product SKU already exists");
    }

    const { data: result, error } = await supabase
      .from("products")
      .insert(data)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.UPDATE) {
    const { data: existing } = await supabase
      .from("products")
      .select("id, updated_at")
      .eq("id", item.server_id)
      .single();

    if (!existing) {
      await createConflict(item, null, "Record deleted on server");
      throw new Error("Record deleted on server");
    }

    if (item.server_version && existing.updated_at !== item.server_version) {
      await createConflict(item, item.server_id, "Record modified on server");
      throw new Error("Version conflict");
    }

    const { data: result, error } = await supabase
      .from("products")
      .update(data)
      .eq("id", item.server_id)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.DELETE) {
    const { error } = await supabase
      .from("products")
      .delete()
      .eq("id", item.server_id);

    if (error) throw error;
    return null;
  }

  throw new Error(`Unknown operation: ${item.operation}`);
}

/**
 * Sync a transaction record with idempotency
 */
async function syncTransaction(supabase, item) {
  const data = item.payload;
  const idempotencyKey = item.idempotency_key;

  if (item.operation === SyncOperation.CREATE) {
    if (data.type === "payment") {
      const error = new Error("Payments entered offline were not synchronized. Confirm the payment online before recording it.");
      error.code = "FINANCIAL_REVIEW_REQUIRED";
      throw error;
    }
    // Use RPC function with idempotency key
    const { data: result, error } = await supabase.rpc("create_transaction_offline", {
      p_idempotency_key: idempotencyKey,
      p_client_id: data.client_id,
      p_client_name: data.client_name,
      p_amount: data.amount,
      p_type: data.type,
      p_payment_method: data.payment_method,
      p_description: data.description,
      p_date: data.date,
      p_invoice_id: data.invoice_id,
    });

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.UPDATE) {
    const { data: existing } = await supabase
      .from("transactions")
      .select("id, updated_at")
      .eq("id", item.server_id)
      .single();

    if (!existing) {
      await createConflict(item, null, "Record deleted on server");
      throw new Error("Record deleted on server");
    }

    if (item.server_version && existing.updated_at !== item.server_version) {
      // For financial records, always preserve server version
      await createConflict(item, item.server_id, "Financial record modified on server");
      throw new Error("Version conflict - financial record");
    }

    const { data: result, error } = await supabase
      .from("transactions")
      .update(data)
      .eq("id", item.server_id)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.DELETE) {
    const { error } = await supabase
      .from("transactions")
      .delete()
      .eq("id", item.server_id);

    if (error) throw error;
    return null;
  }

  throw new Error(`Unknown operation: ${item.operation}`);
}

/**
 * Sync an invoice record with provisional number handling
 */
async function syncInvoice(supabase, item) {
  const data = item.payload;
  const idempotencyKey = item.idempotency_key;

  if (item.operation === SyncOperation.CREATE) {
    // Use RPC function with idempotency key
    const { data: result, error } = await supabase.rpc("create_invoice_offline", {
      p_idempotency_key: idempotencyKey,
      p_client_id: data.client_id,
      p_client_name: data.client_name,
      p_invoice_date: data.invoice_date,
      p_due_date: data.due_date,
      p_tax: data.tax,
      p_notes: data.notes,
      p_items: data.items,
      p_provisional_number: data.provisional_number,
    });

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.UPDATE) {
    const { data: existing } = await supabase
      .from("invoices")
      .select("id, updated_at, status")
      .eq("id", item.server_id)
      .single();

    if (!existing) {
      await createConflict(item, null, "Record deleted on server");
      throw new Error("Record deleted on server");
    }

    // Don't allow modifying paid/partial invoices offline
    if (existing.status !== "unpaid") {
      await createConflict(item, item.server_id, "Invoice already paid/partial");
      throw new Error("Cannot modify paid invoice");
    }

    if (item.server_version && existing.updated_at !== item.server_version) {
      await createConflict(item, item.server_id, "Invoice modified on server");
      throw new Error("Version conflict");
    }

    const { data: result, error } = await supabase
      .from("invoices")
      .update(data)
      .eq("id", item.server_id)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.DELETE) {
    // Only allow deleting unpaid invoices
    const { data: existing } = await supabase
      .from("invoices")
      .select("status")
      .eq("id", item.server_id)
      .single();

    if (existing && existing.status !== "unpaid") {
      await createConflict(item, item.server_id, "Cannot delete paid invoice");
      throw new Error("Cannot delete paid invoice");
    }

    const { error } = await supabase
      .from("invoices")
      .delete()
      .eq("id", item.server_id);

    if (error) throw error;
    return null;
  }

  throw new Error(`Unknown operation: ${item.operation}`);
}

/**
 * Sync an invoice item
 */
async function syncInvoiceItem(supabase, item) {
  const data = item.payload;

  if (item.operation === SyncOperation.CREATE) {
    const { data: result, error } = await supabase
      .from("invoice_items")
      .insert(data)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.UPDATE) {
    const { data: result, error } = await supabase
      .from("invoice_items")
      .update(data)
      .eq("id", item.server_id)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  if (item.operation === SyncOperation.DELETE) {
    const { error } = await supabase
      .from("invoice_items")
      .delete()
      .eq("id", item.server_id);

    if (error) throw error;
    return null;
  }

  throw new Error(`Unknown operation: ${item.operation}`);
}

/**
 * Sync a client interaction
 */
async function syncClientInteraction(supabase, item) {
  const data = item.payload;

  if (item.operation === SyncOperation.CREATE) {
    const { data: result, error } = await supabase
      .from("client_interactions")
      .insert(data)
      .select()
      .single();

    if (error) throw error;
    return result;
  }

  throw new Error(`Unknown operation: ${item.operation}`);
}

/**
 * Handle sync failure with retry logic
 */
async function handleSyncFailure(item, error) {
  if (error.code === "FINANCIAL_REVIEW_REQUIRED") {
    await db.sync_queue.update(item.id, {
      status: QueueStatus.FAILED,
      retry_count: item.retry_count || 0,
      last_error: error.message,
      last_attempt_at: new Date().toISOString(),
    });
    await createConflict(item, null, error.message);
    return;
  }
  const retryCount = (item.retry_count || 0) + 1;

  if (retryCount >= CONFIG.maxRetries) {
    // Max retries reached, mark as failed for manual review
    await db.sync_queue.update(item.id, {
      status: QueueStatus.FAILED,
      retry_count: retryCount,
      last_error: error.message,
      last_attempt_at: new Date().toISOString(),
    });
  } else {
    // Exponential backoff
    const delay = CONFIG.retryDelay * Math.pow(CONFIG.retryBackoff, retryCount - 1);
    const jitter = Math.random() * delay * 0.2;
    await db.sync_queue.update(item.id, {
      status: QueueStatus.PENDING,
      retry_count: retryCount,
      last_error: error.message,
      last_attempt_at: new Date().toISOString(),
      next_retry_at: new Date(Date.now() + delay + jitter).toISOString(),
    });
  }
}

/**
 * Create a conflict record
 */
async function createConflict(item, serverId, reason) {
  await db.sync_conflicts.add({
    table_name: item.table_name,
    record_id: item.record_id,
    server_id: serverId,
    operation: item.operation,
    payload: item.payload,
    reason: reason,
    detected_at: new Date().toISOString(),
    status: "pending_review",
  });
}

/**
 * Update local record after successful sync
 */
async function updateLocalRecord(tableName, localId, serverData) {
  const table = db[tableName];
  const record = await table.get(localId);

  if (record) {
    await table.update(localId, {
      ...serverData,
      _sync_status: SyncStatus.SYNCED,
      _synced_at: new Date().toISOString(),
      _version: serverData.updated_at || serverData.created_at,
    });
  }
}

/**
 * Add an operation to the sync queue
 */
export async function queueSyncOperation(tableName, operation, payload, options = {}) {
  const id = await db.sync_queue.add({
    table_name: tableName,
    record_id: options.localId,
    server_id: options.serverId,
    server_version: options.serverVersion,
    operation: operation,
    payload: payload,
    idempotency_key: options.idempotencyKey || generateIdempotencyKey(),
    created_at: new Date().toISOString(),
    retry_count: 0,
    status: QueueStatus.PENDING,
  });

  // Update local record sync status
  if (options.localId) {
    const table = db[tableName];
    await table.update(options.localId, {
      _sync_status: SyncStatus.PENDING,
    });
  }

  // Trigger sync if online
  if (isOnline) {
    attemptSync();
  }

  return id;
}

/**
 * Generate a unique idempotency key
 */
function generateIdempotencyKey() {
  return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Get pending sync count
 */
export async function getPendingSyncCount() {
  return await db.sync_queue.where("status").equals(QueueStatus.PENDING).count();
}

/**
 * Get sync status
 */
export function getSyncStatus() {
  if (!isOnline) return "offline";
  if (isSyncing) return "syncing";
  return "online";
}

/**
 * Update sync status UI
 */
function updateSyncStatusUI(status) {
  const indicator = document.getElementById("syncStatusIndicator");
  if (!indicator) return;

  indicator.className = "sync-indicator sync-indicator--" + status;

  const text = document.getElementById("syncStatusText");
  if (text) {
    const statusMap = {
      online: "Online",
      offline: "Offline Mode",
      syncing: "Synchronizing...",
      synced: "All Changes Synced",
      partial: "Some Changes Pending",
      error: "Sync Failed",
    };
    text.textContent = statusMap[status] || status;
  }

  const count = document.getElementById("syncPendingCount");
  if (count) {
    getPendingSyncCount().then((pending) => {
      count.textContent = pending > 0 ? `(${pending})` : "";
    });
  }
}

/**
 * Download latest data from server for offline use
 */
export async function downloadLatestData() {
  if (!isOnline) return;

  const supabase = getSupabase();

  // Download clients
  const { data: clients } = await supabase.from("clients").select("*");
  if (clients) {
    await db.clients.clear();
    for (const client of clients) {
      await db.clients.put({
        ...client,
        _sync_status: SyncStatus.SYNCED,
        _synced_at: new Date().toISOString(),
        _version: client.updated_at || client.created_at,
      });
    }
  }

  // Download products
  const { data: products } = await supabase.from("products").select("*");
  if (products) {
    await db.products.clear();
    for (const product of products) {
      await db.products.put({
        ...product,
        _sync_status: SyncStatus.SYNCED,
        _synced_at: new Date().toISOString(),
        _version: product.updated_at || product.created_at,
      });
    }
  }

  // Download transactions (last 100)
  const { data: transactions } = await supabase
    .from("transactions")
    .select("*")
    .order("date", { ascending: false })
    .limit(100);
  if (transactions) {
    await db.transactions.clear();
    for (const tx of transactions) {
      await db.transactions.put({
        ...tx,
        _sync_status: SyncStatus.SYNCED,
        _synced_at: new Date().toISOString(),
        _version: tx.created_at,
      });
    }
  }

  // Download invoices (last 50)
  const { data: invoices } = await supabase
    .from("invoices")
    .select("*")
    .order("invoice_date", { ascending: false })
    .limit(50);
  if (invoices) {
    await db.invoices.clear();
    for (const invoice of invoices) {
      await db.invoices.put({
        ...invoice,
        _sync_status: SyncStatus.SYNCED,
        _synced_at: new Date().toISOString(),
        _version: invoice.created_at,
      });
    }
  }

  console.log("[Sync] Latest data downloaded");
}
