import { getSupabase } from "./supabase.js";
import { getSyncStatus } from "./sync-engine.js";
import {
  saveClientOffline,
  deleteClientOffline,
  listClientsOffline,
  saveProductOffline,
  deleteProductOffline,
  listProductsOffline,
  createTransactionOffline,
  updateTransactionOffline,
  deleteTransactionOffline,
  listTransactionsOffline,
  createInvoiceOffline,
  updateInvoiceOffline,
  deleteInvoiceOffline,
  listInvoicesOffline,
  addClientInteractionOffline,
  listClientInteractionsOffline,
} from "./offline-data.js";

// EDGE FUNCTION DEPENDENCIES:
// This file includes calls to Supabase Edge Functions for operations that require:
// 1. Service-role privileges (admin-users: user invitation, deletion, status changes)
// 2. External provider API keys (sms-send: SMS provider; email-send: Resend email)
// These cannot be replaced with direct client calls due to security requirements.
// All other operations use direct Supabase client or database RPC functions.
//
// OFFLINE SUPPORT:
// Functions now support offline operation by falling back to IndexedDB when offline.
// Changes are queued for synchronization when connectivity is restored.

function unwrap(result) {
  if (result.error) throw result.error;
  return result.data;
}

function isOnline() {
  return getSyncStatus() !== "offline";
}

function normalizeTransactionClientId(value) {
  if (value === null || value === undefined || value === "") return null;
  const clientId = Number(value);
  if (!Number.isSafeInteger(clientId) || clientId <= 0) {
    throw new Error("Select a valid client or leave it blank for a supplier-linked expense.");
  }
  return clientId;
}

function transactionOfflineRecord(transaction) {
  const record = {
    amount: transaction.amount,
    type: transaction.type,
    payment_method: transaction.paymentMethod ?? transaction.payment_method ?? null,
    description: transaction.description || null,
    date: transaction.date,
    invoice_id: transaction.invoiceId || transaction.invoice_id || null,
    supplier_id: transaction.supplierId || transaction.supplier_id || null,
    supplier_name: transaction.supplierName ?? transaction.supplier_name ?? null,
  };
  if (transaction.id !== undefined) record.id = transaction.id;
  if (transaction.clientId !== undefined || transaction.client_id !== undefined) {
    record.client_id = transaction.clientId ?? transaction.client_id;
  }
  if (transaction.clientName !== undefined || transaction.client_name !== undefined) {
    record.client_name = transaction.clientName ?? transaction.client_name;
  }
  if (transaction.clients !== undefined) record.clients = transaction.clients;
  return record;
}

export async function listClients({ query = "", limit = 25, page = 1 } = {}) {
  if (!isOnline()) {
    return await listClientsOffline({ query, limit, page });
  }

  const offset = Math.max(0, page - 1) * limit;
  const { data, error } = await getSupabase().rpc("search_clients", {
    p_query: query.trim(),
    p_limit: limit,
    p_offset: offset,
  });
  if (error) throw error;
  return {
    items: data.map(({ total_count, ...client }) => client),
    total: Number(data[0]?.total_count ?? 0),
  };
}

export async function saveClient(client, id = null) {
  if (!isOnline()) {
    return await saveClientOffline(client, id);
  }

  const request = id
    ? getSupabase().from("clients").update(client).eq("id", id).select().single()
    : getSupabase().from("clients").insert(client).select().single();
  return unwrap(await request);
}

export async function deleteClient(id) {
  if (!isOnline()) {
    return await deleteClientOffline(id);
  }

  return unwrap(await getSupabase().from("clients").delete().eq("id", id));
}

export async function listClientInteractions(clientId, limit = 3) {
  if (!isOnline()) {
    return await listClientInteractionsOffline(clientId, limit);
  }

  return unwrap(await getSupabase()
    .from("client_interactions")
    .select("id, note, created_at")
    .eq("client_id", clientId)
    .order("created_at", { ascending: false })
    .limit(limit));
}

export async function addClientInteraction(clientId, note) {
  if (!isOnline()) {
    return await addClientInteractionOffline(clientId, note);
  }

  return unwrap(await getSupabase()
    .from("client_interactions")
    .insert({ client_id: clientId, note: note.trim() })
    .select()
    .single());
}

export async function listProducts() {
  if (!isOnline()) {
    return await listProductsOffline();
  }

  return unwrap(await getSupabase()
    .from("products")
    .select("id, name, sku, price, stock, min_stock, created_at")
    .order("created_at", { ascending: false }));
}

export async function saveProduct(product, id = null) {
  if (!isOnline()) {
    return await saveProductOffline(product, id);
  }

  const request = id
    ? getSupabase().from("products").update(product).eq("id", id).select().single()
    : getSupabase().from("products").insert(product).select().single();
  return unwrap(await request);
}

export async function deleteProduct(id) {
  if (!isOnline()) {
    return await deleteProductOffline(id);
  }

  return unwrap(await getSupabase().from("products").delete().eq("id", id));
}

export async function updateOwnProfile(id, profile) {
  return unwrap(await getSupabase().from("profiles")
    .update({ full_name: profile.full_name, phone: profile.phone || null })
    .eq("id", id)
    .select("id, full_name, email, phone, role, status")
    .single());
}

export async function createInvoice(invoice) {
  if (!isOnline()) {
    return await createInvoiceOffline(invoice);
  }

  return unwrap(await getSupabase().rpc("create_invoice_idempotent", {
    p_idempotency_key: invoice.requestKey,
    p_client_id: invoice.clientId ?? null,
    p_client_name: invoice.clientName ?? null,
    p_invoice_date: invoice.invoiceDate,
    p_due_date: invoice.dueDate || null,
    p_tax: invoice.tax,
    p_notes: invoice.notes || null,
    p_items: invoice.items,
  }));
}

export async function recordInvoicePayment(payment) {
  return unwrap(await getSupabase().rpc("record_invoice_payment", {
    p_invoice_id: payment.invoiceId,
    p_amount: payment.amount,
    p_payment_method: payment.paymentMethod || null,
    p_description: payment.description || null,
    p_date: payment.date || null,
    p_request_key: payment.requestKey,
  }));
}

export async function deleteInvoice(invoiceId) {
  if (!isOnline()) {
    throw new Error("Invoice deletion requires an internet connection.");
  }
  return unwrap(await getSupabase().rpc("delete_invoice", { p_invoice_id: invoiceId }));
}

export async function updateInvoice(invoice) {
  if (!isOnline()) {
    throw new Error("Invoice editing requires an internet connection.");
  }
  return unwrap(await getSupabase().rpc("update_unpaid_invoice", {
    p_invoice_id: invoice.id,
    p_client_id: invoice.clientId,
    p_invoice_date: invoice.invoiceDate,
    p_due_date: invoice.dueDate || null,
    p_tax: invoice.tax,
    p_notes: invoice.notes || null,
    p_items: invoice.items,
  }));
}

export async function importClients(rows) {
  return unwrap(await getSupabase().rpc("import_clients", { p_rows: rows }));
}

export async function getDashboard(period = "month") {
  return unwrap(await getSupabase().rpc("get_dashboard", { p_period: period }));
}

export async function listTransactions(limit = 100) {
  if (!isOnline()) {
    return await listTransactionsOffline(limit);
  }

  return unwrap(await getSupabase()
    .from("transactions")
    .select("id, client_id, type, amount, date, payment_method, description, invoice_id, supplier_id, clients(full_name), invoices(invoice_number), suppliers(business_name, status)")
    .order("date", { ascending: false })
    .order("id", { ascending: false })
    .limit(limit));
}

export async function listActiveSuppliers() {
  return unwrap(await getSupabase().from("suppliers")
    .select("id, business_name, status")
    .eq("status", "active")
    .order("business_name", { ascending: true }));
}

export async function createSupplierRecord(supplier) {
  const result = unwrap(await getSupabase().rpc("create_supplier_record", {
    p_business_name: supplier.businessName,
    p_contact_person: supplier.contactPerson || null,
    p_phone: supplier.phone || null,
    p_email: supplier.email || null,
    p_address: supplier.address || null,
    p_category: supplier.category || null,
    p_notes: supplier.notes || null,
  }));
  return { id: result, business_name: supplier.businessName, status: "active" };
}

export async function createTransaction(transaction) {
  if (transaction.type === "payment" && !isOnline()) {
    throw new Error("Payments must be recorded while online so they are not counted as received before confirmation.");
  }
  if (transaction.type === "payment" && transaction.invoiceId) {
    return await recordInvoicePayment({
      invoiceId: transaction.invoiceId,
      amount: transaction.amount,
      paymentMethod: transaction.paymentMethod,
      description: transaction.description,
      date: transaction.date,
      requestKey: transaction.requestKey,
    });
  }
  if (!isOnline()) {
    return await createTransactionOffline(transactionOfflineRecord(transaction));
  }

  return unwrap(await getSupabase().rpc("create_transaction_with_supplier", {
    p_client_id: normalizeTransactionClientId(transaction.clientId),
    p_client_name: transaction.clientName || null,
    p_amount: transaction.amount,
    p_type: transaction.type,
    p_payment_method: transaction.paymentMethod || null,
    p_description: transaction.description || null,
    p_date: transaction.date,
    p_invoice_id: transaction.invoiceId || null,
    p_supplier_id: transaction.supplierId || null,
  }));
}

export async function updateTransaction(transaction) {
  if (!isOnline()) {
    return await updateTransactionOffline(transactionOfflineRecord(transaction), transaction.id);
  }

  return unwrap(await getSupabase().rpc("update_transaction_with_supplier", {
    p_id: transaction.id,
    p_client_id: normalizeTransactionClientId(transaction.clientId),
    p_amount: transaction.amount,
    p_type: transaction.type,
    p_payment_method: transaction.paymentMethod || null,
    p_description: transaction.description || null,
    p_date: transaction.date,
    p_invoice_id: transaction.invoiceId || null,
    p_supplier_id: transaction.supplierId || null,
  }));
}

export async function removeTransaction(id) {
  if (!isOnline()) {
    return await deleteTransactionOffline(id);
  }

  return unwrap(await getSupabase().rpc("delete_transaction", { p_id: id }));
}

export async function listClientsForForms() {
  return unwrap(await getSupabase().from("clients").select("id, full_name, phone").order("full_name"));
}

export async function listInvoices() {
  if (!isOnline()) {
    return await listInvoicesOffline(100);
  }

  return unwrap(await getSupabase()
    .from("invoices")
    .select("id, invoice_number, client_id, invoice_date, due_date, total, status, clients(full_name)")
    .order("invoice_date", { ascending: false })
    .order("id", { ascending: false })
    .limit(100));
}

export async function getFinancialReport(group, start, end, branch = null) {
  return unwrap(await getSupabase().rpc("get_financial_report", {
    p_group: group,
    p_start: start,
    p_end: end,
    p_branch: branch || null,
  }));
}

export async function getInvoiceDetails(id) {
  return unwrap(await getSupabase()
    .from("invoices")
    .select("id, invoice_number, client_id, invoice_date, due_date, subtotal, tax, total, notes, status, clients(full_name, phone, email, address), invoice_items(id, item_name, quantity, price, total)")
    .eq("id", id)
    .single());
}

export async function listReportBranches() {
  return unwrap(await getSupabase().rpc("get_report_branches"));
}

// EDGE FUNCTION: sms-send
// Requires SMS provider API key (SMSONLINE_API_KEY) stored in function secrets
// Cannot expose provider credentials to frontend
export async function sendSms(payload) {
  const { data, error } = await getSupabase().functions.invoke("sms-send", { body: payload });
  if (error) throw error;
  return data;
}

// EDGE FUNCTION: sms-send (check_connectivity action)
// Requires SMS provider API key for connectivity verification
export async function checkSmsConnectivity() {
  const { data, error } = await getSupabase().functions.invoke("sms-send", { body: { action: "check_connectivity" } });
  if (error) throw error;
  return data;
}

export async function listSmsMessages() {
  return unwrap(await getSupabase()
    .from("sms_messages")
    .select("id, phone, message, status, provider, provider_message_id, error, created_at")
    .order("created_at", { ascending: false })
    .limit(200));
}

// EDGE FUNCTION: admin-users
// Requires Auth Admin API (admin.inviteUserByEmail, admin.deleteUser) with service-role key
// Cannot expose service-role key to frontend - used for user invitation, status, and deletion
export async function callAdminUsers(payload) {
  const { data, error } = await getSupabase().functions.invoke("admin-users", { body: payload });
  if (error) throw error;
  return data;
}

// EDGE FUNCTION: email-send
// Requires Resend API key stored in function secrets
// Cannot expose email provider credentials to frontend
export async function sendInvoiceEmail(payload) {
  const { data, error } = await getSupabase().functions.invoke("email-send", { body: payload });
  if (error) throw error;
  return data;
}

export async function exportTableRows(table, columns) {
  if (!/^(clients|invoices|invoice_items|transactions|products|client_interactions|user_logs|sms_messages|profiles)$/.test(table)) {
    throw new Error("Unsupported export table.");
  }
  const rows = [];
  for (let offset = 0; ; offset += 1000) {
    const { data, error } = await getSupabase()
      .from(table)
      .select(columns.join(","))
      .order("id", { ascending: true })
      .range(offset, offset + 999);
    if (error) throw error;
    rows.push(...data);
    if (data.length < 1000) return rows;
  }
}

export async function exportTableAsCsv(table, columns) {
  const rows = await exportTableRows(table, columns);
  if (!rows.length) return null;
  const headers = columns;
  const csvContent = [
    headers.join(","),
    ...rows.map((row) =>
      headers
        .map((header) => {
          const value = row[header];
          if (value === null || value === undefined) return "";
          const str = String(value);
          if (str.includes(",") || str.includes('"') || str.includes("\n")) {
            return `"${str.replace(/"/g, '""')}"`;
          }
          return str;
        })
        .join(",")
    ),
  ].join("\n");
  return new Blob([csvContent], { type: "text/csv;charset=utf-8" });
}
