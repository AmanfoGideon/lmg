import JSZip from "https://esm.sh/jszip@3.10.1";
import { originAllowed, requireActiveUser, serviceClient } from "../_shared/auth.ts";

const tables: Record<string, string[]> = {
  profiles: ["id", "legacy_user_id", "username", "full_name", "email", "phone", "role", "status", "branch", "last_login", "created_at"],
  clients: ["id", "full_name", "phone", "email", "address", "created_at"],
  transactions: ["id", "client_id", "amount", "type", "payment_method", "description", "date", "invoice_id", "branch", "created_at"],
  invoices: ["id", "invoice_number", "client_id", "invoice_date", "due_date", "subtotal", "tax", "total", "status", "notes", "branch", "created_at"],
  invoice_items: ["id", "invoice_id", "item_name", "quantity", "price", "total"],
  products: ["id", "name", "sku", "price", "stock", "min_stock", "created_at"],
  client_interactions: ["id", "client_id", "note", "created_at"],
  user_logs: ["id", "user_id", "action", "details", "created_at"],
  sms_messages: ["id", "client_id", "phone", "message", "status", "provider", "provider_message_id", "error", "created_at", "scheduled_for"],
};

function escapeCsv(value: unknown) {
  let text = value == null ? "" : String(value);
  if (/^[\s]*[=+@\-]/.test(text)) text = `'${text}`;
  return `"${text.replaceAll('"', '""')}"`;
}

function csvFor(table: string, rows: Record<string, unknown>[]) {
  const columns = tables[table];
  return [columns, ...rows.map((row) => columns.map((column) => row[column]))]
    .map((row) => row.map(escapeCsv).join(","))
    .join("\r\n");
}

async function fetchAll(table: string) {
  const client = serviceClient!;
  const columns = tables[table];
  const rows: Record<string, unknown>[] = [];
  for (let offset = 0; ; offset += 1000) {
    const { data, error } = await client.from(table).select(columns.join(",")).order("id").range(offset, offset + 999);
    if (error) throw new Error(`Unable to export ${table}.`);
    rows.push(...data);
    if (data.length < 1000) return rows;
  }
}

function fileResponse(body: BodyInit, filename: string, contentType: string, origin: string | null) {
  const headers = new Headers({
    "Content-Type": contentType,
    "Content-Disposition": `attachment; filename="${filename}"`,
    "Cache-Control": "private, no-store",
    "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Vary": "Origin",
  });
  if (origin) headers.set("Access-Control-Allow-Origin", origin);
  return new Response(body, { headers });
}

function textResponse(body: string, status: number, origin: string | null) {
  const headers = new Headers({
    "Content-Type": "text/plain; charset=utf-8",
    "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Vary": "Origin",
  });
  if (origin && originAllowed(origin)) headers.set("Access-Control-Allow-Origin", origin);
  return new Response(status === 204 ? null : body, { status, headers });
}

Deno.serve(async (request) => {
  const origin = request.headers.get("origin");
  if (request.method === "OPTIONS") return textResponse("", 204, origin);
  if (request.method !== "POST") return textResponse("Method not allowed.", 405, origin);
  if (!originAllowed(origin)) return textResponse("Origin is not allowed.", 403, null);
  if (!serviceClient) return textResponse("Function configuration is incomplete.", 500, origin);

  try {
    const { profile } = await requireActiveUser(request);
    if (profile.role !== "admin") return textResponse("Administrator access is required.", 403, origin);
    const payload = await request.json();

    if (payload.action === "table" && typeof payload.table === "string" && Object.hasOwn(tables, payload.table)) {
      const csv = csvFor(payload.table, await fetchAll(payload.table));
      return fileResponse(csv, `${payload.table}-backup.csv`, "text/csv; charset=utf-8", origin);
    }
    if (payload.action === "all") {
      const archive = new JSZip();
      for (const table of Object.keys(tables)) archive.file(`${table}.csv`, csvFor(table, await fetchAll(table)));
      const bytes = await archive.generateAsync({ type: "uint8array", compression: "DEFLATE" });
      return fileResponse(bytes, "lmg-business-backup.zip", "application/zip", origin);
    }
    return textResponse("Unsupported export request.", 400, origin);
  } catch (error) {
    const message = error instanceof Error ? error.message : "Export failed.";
    const status = /Authentication/.test(message) ? 401 : 500;
    return textResponse(message, status, origin);
  }
});