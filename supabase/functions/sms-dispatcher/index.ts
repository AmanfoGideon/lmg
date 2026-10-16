import { jsonResponse, serviceClient } from "../_shared/auth.ts";
import { sendSmsOnline } from "../_shared/smsonline.ts";

Deno.serve(async (request) => {
  if (request.method !== "POST") return jsonResponse({ error: "Method not allowed." }, 405);
  const expectedSecret = Deno.env.get("SMS_DISPATCH_SECRET") ?? "";
  const suppliedSecret = request.headers.get("x-cron-secret") ?? "";
  if (!expectedSecret || suppliedSecret.length !== expectedSecret.length) {
    return jsonResponse({ error: "Unauthorized." }, 401);
  }
  let secretMatches = true;
  for (let index = 0; index < expectedSecret.length; index++) {
    secretMatches = secretMatches && expectedSecret.charCodeAt(index) === suppliedSecret.charCodeAt(index);
  }
  if (!secretMatches) return jsonResponse({ error: "Unauthorized." }, 401);
  if (!serviceClient) return jsonResponse({ error: "Function configuration is incomplete." }, 500);

  const batchSize = Math.min(100, Math.max(1, Number(Deno.env.get("SMS_DISPATCH_BATCH") || 100)));
  const maxRetry = Math.min(10, Math.max(0, Number(Deno.env.get("SMS_MAX_RETRY") || 3)));
  const { data: messages, error } = await serviceClient.rpc("claim_sms_messages", {
    p_batch_size: batchSize,
    p_max_retry: maxRetry,
  });
  if (error) return jsonResponse({ error: "Unable to claim scheduled messages." }, 500);

  let sent = 0;
  let failed = 0;
  for (const message of messages ?? []) {
    try {
      const result = await sendSmsOnline(message.phone, message.message);
      const { error: updateError } = await serviceClient.from("sms_messages").update({
        status: "sent",
        phone: result.phone,
        provider_message_id: result.messageId,
        error: null,
        next_retry_at: null,
      }).eq("id", message.id).eq("status", "sending");
      if (updateError) throw new Error("Delivery result could not be recorded.");
      sent++;
    } catch (error) {
      const attempts = Number(message.retry_count || 0);
      const retryLimitReached = attempts >= maxRetry;
      const backoffMinutes = Math.min(120, 15 * 2 ** Math.max(0, attempts - 1));
      const nextRetry = retryLimitReached ? null : new Date(Date.now() + backoffMinutes * 60_000).toISOString();
      await serviceClient.from("sms_messages").update({
        status: "failed",
        error: error instanceof Error ? error.message.slice(0, 500) : "SMS provider request failed.",
        next_retry_at: nextRetry,
      }).eq("id", message.id).eq("status", "sending");
      failed++;
    }
  }
  return jsonResponse({ claimed: messages?.length ?? 0, sent, failed });
});