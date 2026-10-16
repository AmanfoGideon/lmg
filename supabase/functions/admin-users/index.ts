import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

// Shared configuration (inlined for manual deployment)
const projectUrl = Deno.env.get("SUPABASE_URL") ?? "";
const serviceRoleKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? "";
const allowedOrigins = (Deno.env.get("ALLOWED_ORIGINS") ?? "")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);
const adminClient = projectUrl && serviceRoleKey
  ? createClient(projectUrl, serviceRoleKey, { auth: { persistSession: false, autoRefreshToken: false } })
  : null;

function response(body: unknown, status: number, origin: string | null) {
  const corsOrigin = origin && allowedOrigins.includes(origin) ? origin : "null";
  return new Response(status === 204 ? null : JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json",
      "Access-Control-Allow-Origin": corsOrigin,
      "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
      "Access-Control-Allow-Methods": "POST, OPTIONS",
      "Vary": "Origin",
    },
  });
}

Deno.serve(async (request) => {
  const origin = request.headers.get("origin");
  if (request.method === "OPTIONS") return response({}, 204, origin);
  if (request.method !== "POST") return response({ error: "Method not allowed." }, 405, origin);
  if (!adminClient) return response({ error: "Function configuration is incomplete." }, 500, origin);
  if (origin && !allowedOrigins.includes(origin)) return response({ error: "Origin is not allowed." }, 403, origin);

  const authorization = request.headers.get("authorization") ?? "";
  const token = authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
  if (!token) return response({ error: "Authentication is required." }, 401, origin);

  const { data: authData, error: authError } = await adminClient.auth.getUser(token);
  if (authError || !authData.user) return response({ error: "Authentication is invalid or expired." }, 401, origin);
  const actorId = authData.user.id;
  const { data: actor, error: actorError } = await adminClient
    .from("profiles")
    .select("id, role, status")
    .eq("id", actorId)
    .maybeSingle();
  if (actorError || actor?.role !== "admin" || actor.status !== "active") {
    return response({ error: "Administrator access is required." }, 403, origin);
  }

  let payload: Record<string, unknown>;
  try {
    payload = await request.json();
  } catch {
    return response({ error: "Request body must be valid JSON." }, 400, origin);
  }

  if (payload.action === "list") {
    const { data, error } = await adminClient
      .from("profiles")
      .select("id, legacy_user_id, username, full_name, email, phone, role, status, branch, created_at")
      .order("created_at", { ascending: false });
    if (error) return response({ error: "Unable to load user accounts." }, 500, origin);
    return response({ users: data }, 200, origin);
  }

  if (payload.action === "invite") {
    const email = typeof payload.email === "string" ? payload.email.trim().toLowerCase() : "";
    const fullName = typeof payload.full_name === "string" ? payload.full_name.trim() : "";
    const username = typeof payload.username === "string" ? payload.username.trim() : "";
    const role = payload.role === "admin" ? "admin" : "staff";
    const status = payload.status === "inactive" ? "inactive" : "active";
    const branch = typeof payload.branch === "string" ? payload.branch.trim() : "";
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !fullName || !username) {
      return response({ error: "A valid email, full name, and username are required." }, 400, origin);
    }

    const redirectTo = Deno.env.get("APP_REDIRECT_URL");
    if (!redirectTo) return response({ error: "Invitation redirect is not configured." }, 500, origin);
    const { data: inviteData, error: inviteError } = await adminClient.auth.admin.inviteUserByEmail(email, {
      data: { full_name: fullName, username },
      redirectTo,
    });
    if (inviteError || !inviteData.user) {
      return response({ error: inviteError?.message ?? "Unable to invite this account." }, 400, origin);
    }

    const { error: profileError } = await adminClient.from("profiles").update({
      full_name: fullName,
      username,
      role,
      status,
      branch: branch || null,
    }).eq("id", inviteData.user.id);
    if (profileError) {
      await adminClient.auth.admin.deleteUser(inviteData.user.id);
      return response({ error: "Account invitation could not be provisioned." }, 500, origin);
    }
    return response({ user_id: inviteData.user.id }, 201, origin);
  }

  if (payload.action === "create") {
    const email = typeof payload.email === "string" ? payload.email.trim().toLowerCase() : "";
    const fullName = typeof payload.full_name === "string" ? payload.full_name.trim() : "";
    const username = typeof payload.username === "string" ? payload.username.trim() : "";
    const password = typeof payload.password === "string" ? payload.password : "";
    const role = payload.role === "admin" ? "admin" : "staff";
    const status = payload.status === "inactive" ? "inactive" : "active";
    const branch = typeof payload.branch === "string" ? payload.branch.trim() : "";
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !fullName || !username || !password) {
      return response({ error: "A valid email, full name, username, and password are required." }, 400, origin);
    }
    if (password.length < 6) {
      return response({ error: "Password must be at least 6 characters." }, 400, origin);
    }

    const { data: userData, error: userError } = await adminClient.auth.admin.createUser({
      email,
      password,
      email_confirm: true,
      user_metadata: { full_name: fullName, username },
    });
    if (userError || !userData.user) {
      return response({ error: userError?.message ?? "Unable to create this account." }, 400, origin);
    }

    const { error: profileError } = await adminClient.from("profiles").update({
      full_name: fullName,
      username,
      role,
      status,
      branch: branch || null,
    }).eq("id", userData.user.id);
    if (profileError) {
      await adminClient.auth.admin.deleteUser(userData.user.id);
      return response({ error: "Account could not be provisioned." }, 500, origin);
    }
    return response({ user_id: userData.user.id, message: "User created successfully. They can log in with their email and password." }, 201, origin);
  }

  if (payload.action === "update") {
    const targetId = typeof payload.user_id === "string" ? payload.user_id : "";
    const email = typeof payload.email === "string" ? payload.email.trim().toLowerCase() : "";
    const fullName = typeof payload.full_name === "string" ? payload.full_name.trim() : "";
    const username = typeof payload.username === "string" ? payload.username.trim() : "";
    const role = payload.role === "admin" ? "admin" : "staff";
    const status = payload.status === "inactive" ? "inactive" : "active";
    const branch = typeof payload.branch === "string" ? payload.branch.trim() : "";
    const password = typeof payload.password === "string" ? payload.password : "";

    if (!targetId) return response({ error: "A target user is required." }, 400, origin);
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !fullName || !username) {
      return response({ error: "A valid email, full name, and username are required." }, 400, origin);
    }

    // Update Auth user (email and optionally password)
    const updateData: any = { email };
    if (password) {
      if (password.length < 6) return response({ error: "Password must be at least 6 characters." }, 400, origin);
      updateData.password = password;
    }

    const { error: authError } = await adminClient.auth.admin.updateUserById(targetId, updateData);
    if (authError) return response({ error: authError.message }, 400, origin);

    // Update Profile
    const { error: profileError } = await adminClient.from("profiles").update({
      full_name: fullName,
      username,
      email,
      role,
      status,
      branch: branch || null,
      updated_at: new Date().toISOString()
    }).eq("id", targetId);

    if (profileError) return response({ error: "Profile could not be updated." }, 500, origin);
    return response({ message: "User updated successfully." }, 200, origin);
  }

  const targetId = typeof payload.user_id === "string" ? payload.user_id : "";
  if (!targetId) return response({ error: "A target user is required." }, 400, origin);
  if (targetId === actorId) return response({ error: "You cannot disable or delete your own account." }, 400, origin);

  if (payload.action === "status") {
    const status = payload.status === "inactive" ? "inactive" : "active";
    const { error } = await adminClient.from("profiles").update({ status }).eq("id", targetId);
    if (error) return response({ error: "Unable to update account status." }, 400, origin);
    return response({ success: true }, 200, origin);
  }

  if (payload.action === "delete") {
    const { error } = await adminClient.auth.admin.deleteUser(targetId);
    if (error) return response({ error: "Unable to delete this account." }, 400, origin);
    return response({ success: true }, 200, origin);
  }

  return response({ error: "Unsupported admin action." }, 400, origin);
});