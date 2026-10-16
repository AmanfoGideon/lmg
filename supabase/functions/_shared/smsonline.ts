function xmlEscape(value: string) {
  return value.replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&apos;",
  })[character]!);
}

export function normalizeGhanaPhone(value: string) {
  let digits = value.replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.startsWith("233")) return digits;
  if (digits.length === 10 && digits.startsWith("0")) return `233${digits.slice(1)}`;
  return digits;
}

export async function checkSmsOnline() {
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

export async function sendSmsOnline(phone: string, message: string) {
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