import { db, SyncStatus, SyncOperation } from "./offline-db.js";
import { queueSyncOperation, getSyncStatus } from "./sync-engine.js";

/**
 * Offline Data Layer
 * Wraps API calls to provide offline-first data access
 */

/**
 * Generate a UUID for local records (RFC 4122 v4)
 */
function generateUUID() {
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === "x" ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

/**
 * Check if we're online
 */
function isOnline() {
  return getSyncStatus() !== "offline";
}

/**
 * CLIENTS - Offline Support
 */

export async function saveClientOffline(client, id = null) {
  const table = db.clients;

  if (id) {
    // Update existing
    const existing = await table.get(id);
    if (!existing) throw new Error("Client not found");

    const updated = {
      ...existing,
      ...client,
      _sync_status: SyncStatus.PENDING,
      _synced_at: new Date().toISOString(),
    };

    await table.update(id, updated);

    // Queue sync if online
    if (isOnline()) {
      await queueSyncOperation("clients", SyncOperation.UPDATE, client, {
        localId: id,
        serverId: existing.server_id || id,
        serverVersion: existing._version,
      });
    }

    return updated;
  } else {
    // Create new
    const localId = generateUUID();
    const newClient = {
      id: localId,
      ...client,
      server_id: null,
      _sync_status: SyncStatus.PENDING,
      _synced_at: new Date().toISOString(),
      _version: 1,
      created_at: new Date().toISOString(),
    };

    await table.add(newClient);

    // Queue sync if online
    if (isOnline()) {
      await queueSyncOperation("clients", SyncOperation.CREATE, client, {
        localId: localId,
      });
    }

    return newClient;
  }
}

export async function deleteClientOffline(id) {
  const table = db.clients;
  const existing = await table.get(id);

  if (!existing) throw new Error("Client not found");

  if (existing.server_id) {
    // Mark for deletion sync
    await table.update(id, { _sync_status: SyncStatus.PENDING });

    if (isOnline()) {
      await queueSyncOperation("clients", SyncOperation.DELETE, {}, {
        localId: id,
        serverId: existing.server_id,
        serverVersion: existing._version,
      });
    }
  } else {
    // Local-only record, delete immediately
    await table.delete(id);
  }
}

export async function listClientsOffline(params) {
  const query = (params && params.query) || "";
  const limit = (params && params.limit) || 25;
  const page = (params && params.page) || 1;

  const table = db.clients;
  let collection = table.toCollection();

  // Filter by search query
  if (query) {
    const lowerQuery = query.toLowerCase();
    collection = collection.filter((client) => {
      return (
        (client.full_name || "").toLowerCase().includes(lowerQuery) ||
        (client.phone || "").toLowerCase().includes(lowerQuery) ||
        (client.email || "").toLowerCase().includes(lowerQuery)
      );
    });
  }

  const all = await collection.toArray();
  const total = all.length;
  const offset = Math.max(0, (page - 1) * limit);
  const items = all.slice(offset, offset + limit);

  return { items, total };
}

/**
 * PRODUCTS - Offline Support
 */

export async function saveProductOffline(product, id = null) {
  const table = db.products;

  if (id) {
    const existing = await table.get(id);
    if (!existing) throw new Error("Product not found");

    const updated = {
      ...existing,
      ...product,
      _sync_status: SyncStatus.PENDING,
      _synced_at: new Date().toISOString(),
    };

    await table.update(id, updated);

    if (isOnline()) {
      await queueSyncOperation("products", SyncOperation.UPDATE, product, {
        localId: id,
        serverId: existing.server_id || id,
        serverVersion: existing._version,
      });
    }

    return updated;
  } else {
    const localId = generateUUID();
    const newProduct = {
      id: localId,
      ...product,
      server_id: null,
      _sync_status: SyncStatus.PENDING,
      _synced_at: new Date().toISOString(),
      _version: 1,
      created_at: new Date().toISOString(),
    };

    await table.add(newProduct);

    if (isOnline()) {
      await queueSyncOperation("products", SyncOperation.CREATE, product, {
        localId: localId,
      });
    }

    return newProduct;
  }
}

export async function deleteProductOffline(id) {
  const table = db.products;
  const existing = await table.get(id);

  if (!existing) throw new Error("Product not found");

  if (existing.server_id) {
    await table.update(id, { _sync_status: SyncStatus.PENDING });

    if (isOnline()) {
      await queueSyncOperation("products", SyncOperation.DELETE, {}, {
        localId: id,
        serverId: existing.server_id,
        serverVersion: existing._version,
      });
    }
  } else {
    await table.delete(id);
  }
}

export async function listProductsOffline() {
  const table = db.products;
  return await table.toArray();
}

/**
 * TRANSACTIONS - Offline Support
 */

export async function createTransactionOffline(transaction) {
  const table = db.transactions;
  const localId = generateUUID();
  const idempotencyKey = `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

  const newTransaction = {
    id: localId,
    ...transaction,
    server_id: null,
    _sync_status: SyncStatus.PENDING,
    _synced_at: new Date().toISOString(),
    _version: 1,
    created_at: new Date().toISOString(),
  };

  await table.add(newTransaction);

  if (isOnline()) {
    await queueSyncOperation("transactions", SyncOperation.CREATE, transaction, {
      localId: localId,
      idempotencyKey: idempotencyKey,
    });
  }

  return newTransaction;
}

export async function updateTransactionOffline(transaction, id) {
  const table = db.transactions;
  const existing = await table.get(id);

  if (!existing) throw new Error("Transaction not found");

  const updated = {
    ...existing,
    ...transaction,
    _sync_status: SyncStatus.PENDING,
    _synced_at: new Date().toISOString(),
  };

  await table.update(id, updated);

  if (isOnline()) {
    await queueSyncOperation("transactions", SyncOperation.UPDATE, transaction, {
      localId: id,
      serverId: existing.server_id || id,
      serverVersion: existing._version,
    });
  }

  return updated;
}

export async function deleteTransactionOffline(id) {
  const table = db.transactions;
  const existing = await table.get(id);

  if (!existing) throw new Error("Transaction not found");

  if (existing.server_id) {
    await table.update(id, { _sync_status: SyncStatus.PENDING });

    if (isOnline()) {
      await queueSyncOperation("transactions", SyncOperation.DELETE, {}, {
        localId: id,
        serverId: existing.server_id,
        serverVersion: existing._version,
      });
    }
  } else {
    await table.delete(id);
  }
}

export async function listTransactionsOffline(limit = 100) {
  const table = db.transactions;
  return await table.orderBy("date").reverse().limit(limit).toArray();
}

/**
 * INVOICES - Offline Support
 */

export async function createInvoiceOffline(invoice) {
  const table = db.invoices;
  const itemsTable = db.invoice_items;
  const localId = generateUUID();
  const idempotencyKey = `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

  // Generate provisional invoice number
  const provisionalNumber = `INV-PROV-${Date.now().toString().slice(-6)}`;

  const newInvoice = {
    id: localId,
    invoice_number: provisionalNumber,
    ...invoice,
    server_id: null,
    _sync_status: SyncStatus.PENDING,
    _synced_at: new Date().toISOString(),
    _version: 1,
    created_at: new Date().toISOString(),
  };

  await table.add(newInvoice);

  // Add invoice items
  if (invoice.items && Array.isArray(invoice.items)) {
    for (const item of invoice.items) {
      const itemId = generateUUID();
      await itemsTable.add({
        id: itemId,
        invoice_id: localId,
        ...item,
        server_id: null,
        _sync_status: SyncStatus.PENDING,
        _synced_at: new Date().toISOString(),
      });
    }
  }

  if (isOnline()) {
    await queueSyncOperation("invoices", SyncOperation.CREATE, invoice, {
      localId: localId,
      idempotencyKey: idempotencyKey,
    });
  }

  return { ...newInvoice, provisional_number: provisionalNumber };
}

export async function updateInvoiceOffline(invoice, id) {
  const table = db.invoices;
  const existing = await table.get(id);

  if (!existing) throw new Error("Invoice not found");

  const updated = {
    ...existing,
    ...invoice,
    _sync_status: SyncStatus.PENDING,
    _synced_at: new Date().toISOString(),
  };

  await table.update(id, updated);

  if (isOnline()) {
    await queueSyncOperation("invoices", SyncOperation.UPDATE, invoice, {
      localId: id,
      serverId: existing.server_id || id,
      serverVersion: existing._version,
    });
  }

  return updated;
}

export async function deleteInvoiceOffline(id) {
  const table = db.invoices;
  const existing = await table.get(id);

  if (!existing) throw new Error("Invoice not found");

  if (existing.server_id) {
    await table.update(id, { _sync_status: SyncStatus.PENDING });

    if (isOnline()) {
      await queueSyncOperation("invoices", SyncOperation.DELETE, {}, {
        localId: id,
        serverId: existing.server_id,
        serverVersion: existing._version,
      });
    }
  } else {
    await table.delete(id);
  }
}

export async function listInvoicesOffline(limit = 50) {
  const table = db.invoices;
  return await table.orderBy("invoice_date").reverse().limit(limit).toArray();
}

/**
 * CLIENT INTERACTIONS - Offline Support
 */

export async function addClientInteractionOffline(clientId, note) {
  const table = db.client_interactions;
  const localId = generateUUID();

  const interaction = {
    id: localId,
    client_id: clientId,
    note: note,
    server_id: null,
    _sync_status: SyncStatus.PENDING,
    _synced_at: new Date().toISOString(),
    created_at: new Date().toISOString(),
  };

  await table.add(interaction);

  if (isOnline()) {
    await queueSyncOperation("client_interactions", SyncOperation.CREATE, interaction, {
      localId: localId,
    });
  }

  return interaction;
}

export async function listClientInteractionsOffline(clientId, limit = 10) {
  const table = db.client_interactions;
  return await table.where("client_id").equals(clientId).reverse().limit(limit).toArray();
}

/**
 * UTILITY FUNCTIONS
 */

/**
 * Get sync status for a record
 */
export async function getRecordSyncStatus(tableName, id) {
  const table = db[tableName];
  const record = await table.get(id);
  return record?._sync_status || "unknown";
}

/**
 * Get all pending changes
 */
export async function getPendingChanges() {
  const clients = await db.clients.where("_sync_status").equals(SyncStatus.PENDING).toArray();
  const products = await db.products.where("_sync_status").equals(SyncStatus.PENDING).toArray();
  const transactions = await db.transactions.where("_sync_status").equals(SyncStatus.PENDING).toArray();
  const invoices = await db.invoices.where("_sync_status").equals(SyncStatus.PENDING).toArray();

  return {
    clients: clients.length,
    products: products.length,
    transactions: transactions.length,
    invoices: invoices.length,
    total: clients.length + products.length + transactions.length + invoices.length,
  };
}

/**
 * Mark record as synced (called after successful sync)
 */
export async function markRecordSynced(tableName, id, serverData) {
  const table = db[tableName];
  await table.update(id, {
    ...serverData,
    _sync_status: SyncStatus.SYNCED,
    _synced_at: new Date().toISOString(),
    _version: serverData.updated_at || serverData.created_at,
  });
}

