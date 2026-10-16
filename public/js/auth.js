import { getSupabase } from "./supabase.js";

export async function signIn(email, password) {
  const client = getSupabase();
  const { error } = await client.auth.signInWithPassword({ email, password });
  if (error) throw error;

  try {
    return await getActiveProfile();
  } catch (error) {
    await client.auth.signOut();
    throw error;
  }
}

export async function signOut() {
  const { error } = await getSupabase().auth.signOut();
  if (error) throw error;
}

export async function requestPasswordReset(email) {
  const redirectTo = new URL("./index.html?recovery=1", window.location.href).toString();
  const { error } = await getSupabase().auth.resetPasswordForEmail(email, { redirectTo });
  if (error) throw error;
}

export async function updatePassword(password) {
  const { error } = await getSupabase().auth.updateUser({ password });
  if (error) throw error;
}

export async function getActiveProfile() {
  const client = getSupabase();
  const { data: { user }, error: userError } = await client.auth.getUser();
  if (userError) throw userError;
  if (!user) throw new Error("Sign in to continue.");

  const { data: profile, error } = await client
    .from("profiles")
    .select("id, username, full_name, email, phone, role, status, branch")
    .eq("id", user.id)
    .maybeSingle();
  if (error) throw error;
  if (!profile || profile.status !== "active") {
    await client.auth.signOut();
    throw new Error("This account is inactive or has not been provisioned.");
  }
  return profile;
}

export async function requireAdmin() {
  const profile = await getActiveProfile();
  if (profile.role !== "admin") throw new Error("Administrator access is required.");
  return profile;
}