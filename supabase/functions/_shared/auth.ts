import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

const projectUrl = Deno.env.get("SUPABASE_URL") ?? "";
const serviceRoleKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? "";
const allowedOrigins = (Deno.env.get("ALLOWED_ORIGINS") ?? "")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

export const serviceClient = projectUrl && serviceRoleKey
  ? createClient(projectUrl, serviceRoleKey, { auth: { persistSession: false, autoRefreshToken: false } })
  : null;

export function jsonResponse(body: unknown, status = 200, origin: string | null = null) {
  const headers = new Headers({
    "Content-Type": "application/json",
    "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Vary": "Origin",
  });
  if (origin && allowedOrigins.includes(origin)) headers.set("Access-Control-Allow-Origin", origin);
  return new Response(status === 204 ? null : JSON.stringify(body), { status, headers });
}

export function originAllowed(origin: string | null) {
  return !origin || allowedOrigins.includes(origin);
}

export async function requireActiveUser(request: Request) {
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