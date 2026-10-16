import { getSupabase } from "./supabase.js";

// EDGE FUNCTION DEPENDENCIES:
// This file includes calls to Supabase Edge Functions for operations that require:
// 1. Service-role privileges (admin-users: user invitation, deletion, status changes)
// 2. External provider API keys (sms-send: SMS provider; email-send: Resend email)
// These cannot be replaced with direct client calls due to security requirements.
// All other operations use direct Supabase client or database RPC functions.

function unwrap(result) {
  if (result.error) throw result.error;
  return result.data;
}

export async function listClients({ query = "", limit = 25, page = 1 } = {}) {
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
  const request = id
    ? getSupabase().from("clients").update(client).eq("id", id).select().single()
    : getSupabase().from("clients").insert(client).select().single();
  return unwrap(await request);
}

export async function deleteClient(id) {
  return unwrap(await getSupabase().from("clients").delete().eq("id", id));
}

export async function listClientInteractions(clientId, limit = 3) {
  return unwrap(await getSupabase()
    .from("client_interactions")
    .select("id, note, created_at")
    .eq("client_id", clientId)
    .order("created_at", { ascending: false })
    .limit(limit));
}

export async function addClientInteraction(clientId, note) {
  return unwrap(await getSupabase()
    .from("client_interactions")
    .insert({ client_id: clientId, note: note.trim() })
    .select()
    .single());
}

export async function listProducts() {
  return unwrap(await getSupabase()
    .from("products")
    .select("id, name, sku, price, stock, min_stock, created_at")
    .order("created_at", { ascending: false }));
}

export async function saveProduct(product, id = null) {
  const request = id
    ? getSupabase().from("products").update(product).eq("id", id).select().single()
    : getSupabase().from("products").insert(product).select().single();
  return unwrap(await request);
}

export async function deleteProduct(id) {
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
  return unwrap(await getSupabase().rpc("create_invoice", {
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
  }));
}

export async function importClients(rows) {
  return unwrap(await getSupabase().rpc("import_clients", { p_rows: rows }));
}

export async function getDashboard(period = "month") {
  return unwrap(await getSupabase().rpc("get_dashboard", { p_period: period }));
}

export async function listTransactions(limit = 100) {
  return unwrap(await getSupabase()
    .from("transactions")
    .select("id, client_id, type, amount, date, payment_method, description, invoice_id, clients(full_name), invoices(invoice_number)")
    .order("date", { ascending: false })
    .order("id", { ascending: false })
    .limit(limit));
}

export async function createTransaction(transaction) {
  return unwrap(await getSupabase().rpc("create_transaction", {
    p_client_id: transaction.clientId,
    p_client_name: transaction.clientName || null,
    p_amount: transaction.amount,
    p_type: transaction.type,
    p_payment_method: transaction.paymentMethod || null,
    p_description: transaction.description || null,
    p_date: transaction.date,
    p_invoice_id: transaction.invoiceId || null,
  }));
}

export async function updateTransaction(transaction) {
  return unwrap(await getSupabase().rpc("update_transaction", {
    p_id: transaction.id,
    p_amount: transaction.amount,
    p_type: transaction.type,
    p_payment_method: transaction.paymentMethod || null,
    p_description: transaction.description || null,
    p_date: transaction.date,
    p_invoice_id: transaction.invoiceId || null,
  }));
}

export async function removeTransaction(id) {
  return unwrap(await getSupabase().rpc("delete_transaction", { p_id: id }));
}

export async function listClientsForForms() {
  return unwrap(await getSupabase().from("clients").select("id, full_name, phone").order("full_name"));
}

export async function listInvoices() {
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

export async function setInvoiceStatus(id, status) {
  return unwrap(await getSupabase().from("invoices").update({ status }).eq("id", id).select().single());
}

export async function listReportBranches() {
  const { data, error } = await getSupabase().from("transactions").select("branch").not("branch", "is", null);
  if (error) throw error;
  return [...new Set(data.map(({ branch }) => branch).filter(Boolean))].sort();
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