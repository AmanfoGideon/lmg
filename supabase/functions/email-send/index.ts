// Shared code inlined for manual deployment
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

const projectUrl = Deno.env.get("SUPABASE_URL") ?? "";
const serviceRoleKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? "";
const allowedOrigins = (Deno.env.get("ALLOWED_ORIGINS") ?? "")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

const serviceClient = projectUrl && serviceRoleKey
  ? createClient(projectUrl, serviceRoleKey, { auth: { persistSession: false, autoRefreshToken: false } })
  : null;

function jsonResponse(body: unknown, status = 200, origin: string | null = null) {
  const headers = new Headers({
    "Content-Type": "application/json",
    "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Vary": "Origin",
  });
  if (origin && allowedOrigins.includes(origin)) headers.set("Access-Control-Allow-Origin", origin);
  return new Response(status === 204 ? null : JSON.stringify(body), { status, headers });
}

function originAllowed(origin: string | null) {
  return !origin || allowedOrigins.includes(origin);
}

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

Deno.serve(async (request) => {
  const origin = request.headers.get("origin");
  if (request.method === "OPTIONS") return jsonResponse({}, 204, origin);
  if (request.method !== "POST") return jsonResponse({ error: "Method not allowed." }, 405, origin);
  if (!originAllowed(origin)) return jsonResponse({ error: "Origin is not allowed." }, 403, origin);
  if (!serviceClient) return jsonResponse({ error: "Function configuration is incomplete." }, 500, origin);

  let caller;
  let payload: Record<string, unknown>;
  try {
    caller = await requireActiveUser(request);
    payload = await request.json();
  } catch (error) {
    const message = error instanceof Error ? error.message : "Invalid request.";
    return jsonResponse({ error: message }, message.includes("Authentication") ? 401 : 400, origin);
  }

  const invoiceId = Number(payload.invoice_id);
  const subject = typeof payload.subject === "string" ? payload.subject.trim() : "Invoice Reminder";
  const text = typeof payload.text === "string" ? payload.text.trim() : "";
  if (!Number.isInteger(invoiceId) || invoiceId <= 0 || !text || text.length > 10000) {
    return jsonResponse({ error: "A valid invoice and message are required." }, 400, origin);
  }

  const { data: invoice, error: invoiceError } = await caller.client
    .from("invoices")
    .select("invoice_number, clients(email)")
    .eq("id", invoiceId)
    .maybeSingle();
  const recipient = (invoice?.clients as { email?: string | null } | null)?.email;
  if (invoiceError || !invoice || !recipient) return jsonResponse({ error: "Invoice client has no email address." }, 400, origin);

  const apiKey = Deno.env.get("RESEND_API_KEY") ?? "";
  const from = Deno.env.get("EMAIL_FROM") ?? "";
  if (!apiKey || !from) return jsonResponse({ error: "Email provider configuration is incomplete." }, 503, origin);
  const sendResponse = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: { "Authorization": `Bearer ${apiKey}`, "Content-Type": "application/json" },
    body: JSON.stringify({ from, to: [recipient], subject: subject || `Invoice ${invoice.invoice_number} Reminder`, text }),
    signal: AbortSignal.timeout(15000),
  });
  if (!sendResponse.ok) return jsonResponse({ error: "Email provider rejected the message." }, 502, origin);

  await caller.client.from("user_logs").insert({
    user_id: caller.user.id,
    action: "email_send",
    details: `${invoice.invoice_number}|${recipient}`,
  });
  return jsonResponse({ success: true }, 200, origin);
});