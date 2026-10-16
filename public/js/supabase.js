import { createClient } from "@supabase/supabase-js";

const config = globalThis.APP_CONFIG || {};

export const supabase = config.supabaseUrl && config.supabaseAnonKey
  ? createClient(config.supabaseUrl, config.supabaseAnonKey, {
      auth: {
        autoRefreshToken: true,
        detectSessionInUrl: true,
        persistSession: true,
      },
    })
  : null;

export function getSupabase() {
  if (!supabase) {
    throw new Error("Set Supabase URL and publishable key in js/config.js.");
  }
  return supabase;
}