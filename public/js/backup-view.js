// This module uses direct Supabase client for CSV exports (no Edge Function required).
// The previous ZIP export feature (admin-backup Edge Function) has been removed because
// individual CSV exports can be performed directly via the client with proper RLS policies.
// ZIP bundling would require a backend service, which is not necessary for this use case.
import { exportTableAsCsv } from "./data.js";
import { requireAdmin } from "./auth.js";
import { showNotice } from "./feedback.js";

const tables = [
  { name: "profiles", columns: ["id", "legacy_user_id", "username", "full_name", "email", "phone", "role", "status", "branch", "last_login", "created_at"] },
  { name: "clients", columns: ["id", "full_name", "phone", "email", "address", "created_at"] },
  { name: "transactions", columns: ["id", "client_id", "amount", "type", "payment_method", "description", "date", "invoice_id", "branch", "created_at"] },
  { name: "invoices", columns: ["id", "invoice_number", "client_id", "invoice_date", "due_date", "subtotal", "tax", "total", "status", "notes", "branch", "created_at"] },
  { name: "invoice_items", columns: ["id", "invoice_id", "item_name", "quantity", "price", "total"] },
  { name: "products", columns: ["id", "name", "sku", "price", "stock", "min_stock", "created_at"] },
  { name: "client_interactions", columns: ["id", "client_id", "note", "created_at"] },
  { name: "user_logs", columns: ["id", "user_id", "action", "details", "created_at"] },
  { name: "sms_messages", columns: ["id", "client_id", "phone", "message", "status", "provider", "provider_message_id", "error", "created_at", "scheduled_for"] },
];

function download(filename, content) {
  const url = URL.createObjectURL(content);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export async function initBackup() {
  await requireAdmin();
  const actions = document.getElementById("backupActions");
  tables.forEach((table) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "btn btn-outline-light";
    button.textContent = `${table.name} CSV`;
    button.addEventListener("click", async () => {
      button.disabled = true;
      try {
        const blob = await exportTableAsCsv(table.name, table.columns);
        if (blob) {
          download(`${table.name}-backup.csv`, blob);
        } else {
          showNotice(`No data found in ${table.name}.`, "warning");
        }
      } catch (error) {
        showNotice(error.message || `Unable to export ${table.name}.`, "danger");
      } finally {
        button.disabled = false;
      }
    });
    actions.append(button);
  });
}