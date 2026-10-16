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

function xmlEscape(value: string) {
  return value.replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&apos;",
  })[character]!);
}

function normalizeGhanaPhone(value: string) {
  let digits = value.replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.startsWith("233")) return digits;
  if (digits.length === 10 && digits.startsWith("0")) return `233${digits.slice(1)}`;
  return digits;
}

async function checkSmsOnline() {
  const apiKey = Deno.env.get("SMSONLINE_API_KEY") ?? "";
  const host = Deno.env.get("SMSONLINE_HOST") ?? "api.smsonlinegh.com";
  if (!apiKey) throw new Error("SMS provider API key is not configured.");
  if (!/^[a-z0-9.-]+$/i.test(host)) throw new Error("SMS provider host is invalid.");
  const response = await fetch(`https://${host}/v5/auth`, {
    method: "POST",
    headers: {
      "Authorization": `key ${apiKey}   ls`,
      "Accept": "application/xml",
      "Content-Type": "application/xml",
    },
    body: "<request></request>",
    signal: AbortSignal.timeout(15000),
  });
  const responseBody = await response.text();
  const handshake = responseBody.match(/<handshake\b[^>]*>[\s\S]*?<id>\s*(\d+)\s*<\/id>[\s\S]*?<\/handshake>/i);
  if (!response.ok || !/^\s*<response[\s>]/i.test(responseBody) || !handshake || Number(handshake[1]) !== 0) {
    throw new Error(`SMS provider authentication failed (HTTP ${response.status}).`);
  }
  return { host };
}

async function sendSmsOnline(phone: string, message: string) {
  const apiKey = Deno.env.get("SMSONLINE_API_KEY") ?? "";
  const host = Deno.env.get("SMSONLINE_HOST") ?? "api.smsonlinegh.com";
  const sender = Deno.env.get("SMSONLINE_SENDER") ?? "";
  if (!apiKey || !sender) throw new Error("SMS provider configuration is incomplete.");
  if (!/^[a-z0-9.-]+$/i.test(host)) throw new Error("SMS provider host is invalid.");

  const number = normalizeGhanaPhone(phone);
  if (!/^\d{8,15}$/.test(number)) throw new Error("Phone number is invalid.");
  const body = `<request><text>${xmlEscape(message)}</text><type>0</type><sender>${xmlEscape(sender)}</sender><destinations><to>${number}</to></destinations></request>`;
  const response = await fetch(`https://${host}/v5/message/sms/send`, {
    method: "POST",
    headers: {
      "Authorization": `key ${apiKey}`,
      "Accept": "application/xml",
      "Content-Type": "application/xml",
    },
    body,
    signal: AbortSignal.timeout(15000),
  });
  const responseBody = await response.text();
  if (!response.ok) throw new Error(`SMS provider returned HTTP ${response.status}.`);
  if (!/^\s*<response[\s>]/i.test(responseBody)) throw new Error("SMS provider returned an invalid response.");

  const handshake = responseBody.match(/<handshake\b[^>]*>[\s\S]*?<id>\s*(\d+)\s*<\/id>[\s\S]*?<\/handshake>/i);
  if (!handshake || Number(handshake[1]) !== 0) throw new Error("SMS provider rejected the request.");
  const destination = responseBody.match(/<destinations\b[^>]*>[\s\S]*?<item\b[^>]*>([\s\S]*?)<\/item>/i)?.[1] ?? "";
  const messageId = destination.match(/<id>\s*([^<]+)\s*<\/id>/i)?.[1]?.trim() ?? null;
  const statusIds = [...destination.matchAll(/<status\b[^>]*>[\s\S]*?<id>\s*(\d+)\s*<\/id>[\s\S]*?<\/status>/gi)].map((match) => Number(match[1]));
  const accepted = [2105, 2106, 2109, 2110];
  const queued = [2104, 2107, 2108, 2139];
  const rejected = [2111, 2112, 2113, 2114, 2115, 2116, 2117, 2118, 2119, 2120, 2121, 2122, 2123, 2124, 2125, 2126, 2127, 2128, 2129, 2130, 2131, 2132, 2133, 2134, 2135, 2136, 2137, 2138, 2140];
  const status = statusIds.some((id) => accepted.includes(id))
    ? "sent"
    : statusIds.some((id) => queued.includes(id))
      ? "queued"
      : statusIds.some((id) => rejected.includes(id))
        ? "failed"
        : "sent";
  if (status === "failed") throw new Error("SMS provider rejected or could not deliver the destination.");
  return { phone: number, messageId, status };
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

  if (payload.action === "check_connectivity") {
    try {
      const result = await checkSmsOnline();
      return jsonResponse({ ok: true, host: result.host }, 200, origin);
    } catch (error) {
      const message = error instanceof Error ? error.message : "SMS provider check failed.";
      return jsonResponse({ ok: false, error: message }, 502, origin);
    }
  }

  const phone = typeof payload.phone === "string" ? payload.phone.trim() : "";
  const message = typeof payload.message === "string" ? payload.message.trim() : "";
  const clientId = Number.isInteger(payload.client_id) ? Number(payload.client_id) : null;
  const scheduledFor = typeof payload.scheduled_for === "string" ? new Date(payload.scheduled_for) : null;
  if (!phone || !message || message.length > 1600 || (scheduledFor && Number.isNaN(scheduledFor.getTime()))) {
    return jsonResponse({ error: "Phone and a message up to 1,600 characters are required." }, 400, origin);
  }

  if (scheduledFor && scheduledFor.getTime() > Date.now()) {
    const { error } = await caller.client.from("sms_messages").insert({
      client_id: clientId,
      phone,
      message,
      status: "queued",
      scheduled_for: scheduledFor.toISOString(),
    });
    if (error) return jsonResponse({ error: "Unable to schedule this message." }, 400, origin);
    return jsonResponse({ status: "queued" }, 202, origin);
  }

  let providerResult: { phone: string; messageId: string | null; status: "sent" | "queued" } | null = null;
  let failure = "";
  try {
    providerResult = await sendSmsOnline(phone, message);
  } catch (error) {
    failure = error instanceof Error ? error.message : "SMS provider request failed.";
  }
  const status = providerResult?.status ?? "failed";
  const { error: logError } = await caller.client.from("sms_messages").insert({
    client_id: clientId,
    phone: providerResult?.phone ?? phone,
    message,
    status,
    provider: "smsonline",
    provider_message_id: providerResult?.messageId,
    error: failure || null,
  });
  if (logError) return jsonResponse({ error: "Message attempted but its delivery log could not be stored." }, 500, origin);
  if (!providerResult) return jsonResponse({ error: "SMS delivery failed. Check provider settings and message log." }, 502, origin);

  await caller.client.from("user_logs").insert({ user_id: caller.user.id, action: "sms_send", details: providerResult.phone });
  return jsonResponse({ status, provider_message_id: providerResult.messageId }, 200, origin);
});