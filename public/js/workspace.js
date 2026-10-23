import { getActiveProfile, signOut, updatePassword } from "./auth.js";
import { updateOwnProfile } from "./data.js";
import { setFormBusy, showNotice } from "./feedback.js";
import { initClients } from "./clients.js";
import { initDashboard } from "./dashboard-view.js";
import { initInvoices } from "./invoices-view.js";
import { initProducts } from "./products.js";
import { initReports } from "./reports-view.js";
import { initSms } from "./sms-view.js";
import { initSuppliers } from "./suppliers-view.js";
import { initTransactions } from "./transactions-view.js";
import { initUsers } from "./users-view.js";
import { initBackup } from "./backup-view.js";
import { initSidebar } from "./sidebar.js";
import { getSupabase } from "./supabase.js";
import { initSyncEngine, downloadLatestData } from "./sync-engine.js";
import { initSyncUI } from "./sync-ui.js";

const titles = {
  dashboard: ["Dashboard", "Overview of business performance"],
  clients: ["Clients", "Manage client records"],
  products: ["Products", "Manage inventory and stock levels"],
  transactions: ["Transactions", "Record income, expenses, invoices and payments"],
  suppliers: ["Suppliers", "Manage supplier records and accounts payable"],
  invoices: ["Invoices", "Create invoices and track payments"],
  reports: ["Financial Reports", "Daily, weekly and monthly summaries"],
  sms: ["SMS", "Send and track messages"],
  users: ["Manage Users", "Administrator account provisioning"],
  backup: ["Data Export", "Administrator-only business data exports"],
  profile: ["My Profile", "Account details"],
};

// Each view is loaded only the first time it is opened, so the initial page load
// performs a single query instead of fetching every module at once.
const viewInits = {
  dashboard: initDashboard,
  clients: initClients,
  products: initProducts,
  transactions: initTransactions,
  suppliers: initSuppliers,
  invoices: initInvoices,
  reports: initReports,
  sms: initSms,
};
const initialized = new Set();
let activeProfile;

async function ensureView(view) {
  if (initialized.has(view)) return;
  const init = viewInits[view];
  initialized.add(view);
  if (!init) return;
  try {
    await init();
  } catch (error) {
    initialized.delete(view);
    showNotice(error.message || `Unable to load the ${view} view.`, "danger");
  }
}

async function navigate(view, pushHistory = true) {
  if (!titles[view]) return;
  document.querySelectorAll(".app-view").forEach((section) => {
    section.classList.toggle("d-none", section.id !== `view-${view}`);
  });
  document.querySelectorAll("[data-view-link]").forEach((link) => {
    if (link.closest(".quick-actions")) return;
    const active = link.dataset.viewLink === view;
    link.classList.toggle("active", active);
    if (active) link.setAttribute("aria-current", "page");
    else link.removeAttribute("aria-current");
  });
  document.getElementById("pageTitle").textContent = titles[view][0];
  document.getElementById("pageSubtitle").textContent = titles[view][1];
  if (window.location.hash !== `#${view}`) {
    if (pushHistory) history.pushState({ view }, "", `#${view}`);
    else history.replaceState({ view }, "", `#${view}`);
  }
  await ensureView(view);
}

function hideLoadingScreen() {
  document.body.classList.add("loaded");
  const loadingScreen = document.getElementById("loadingScreen");
  if (!loadingScreen) return;
  loadingScreen.style.opacity = "0";
  setTimeout(() => loadingScreen.remove(), 300);
}

document.querySelectorAll("[data-view-link]").forEach((link) => {
  link.addEventListener("click", async (event) => {
    event.preventDefault();
    document.querySelectorAll(".quick-actions[open]").forEach((menu) => { menu.open = false; });
    await navigate(link.dataset.viewLink);
    const invoiceForm = document.getElementById("invoiceForm");
    if (link.dataset.quickAction === "invoice" && invoiceForm?.classList.contains("d-none")) {
      document.getElementById("toggleInvoiceForm")?.click();
    }
  });
});

document.addEventListener("click", (event) => {
  if (event.target.closest(".quick-actions")) return;
  document.querySelectorAll(".quick-actions[open]").forEach((menu) => { menu.open = false; });
});
document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape") return;
  document.querySelectorAll(".quick-actions[open]").forEach((menu) => {
    menu.open = false;
    menu.querySelector("summary")?.focus();
  });
});

window.addEventListener("popstate", () => {
  const view = window.location.hash.slice(1);
  if (titles[view]) navigate(view, false);
});

document.getElementById("signOutButton").addEventListener("click", async () => {
  if (!window.confirm("Sign out of your account?")) return;
  try {
    await signOut();
    window.location.replace("index.html");
  } catch (error) {
    showNotice(error.message || "Unable to sign out.", "danger");
  }
});

function renderAccount() {
  const label = activeProfile.full_name || activeProfile.email;
  document.getElementById("profileName").textContent = label || "";
  document.getElementById("profileEmailSmall").textContent = activeProfile.email || "";
  document.getElementById("headerName").textContent = label || "";
}

try {
  getSupabase().auth.onAuthStateChange((event, session) => {
    if (event === "SIGNED_OUT" || !session) window.location.replace("index.html");
  });
  activeProfile = await getActiveProfile();
  renderAccount();
  document.getElementById("profileFullName").value = activeProfile.full_name;
  document.getElementById("profileEmail").value = activeProfile.email || "";
  document.getElementById("profilePhone").value = activeProfile.phone || "";
  document.getElementById("profileRole").value = activeProfile.role;
  if (activeProfile.role === "admin") {
    document.getElementById("adminUsersNav").classList.remove("d-none");
    document.getElementById("adminBackupNav").classList.remove("d-none");
    viewInits.users = initUsers;
    viewInits.backup = initBackup;
  }
  initSidebar();

  // Initialize offline sync engine and UI
  initSyncEngine();
  initSyncUI();

  // Download latest data for offline use
  downloadLatestData().catch((error) => {
    console.error("Failed to download latest data for offline use:", error);
  });

  document.getElementById("profileForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    setFormBusy(form, true);
    try {
      const values = Object.fromEntries(new FormData(form));
      const updated = await updateOwnProfile(activeProfile.id, values);
      if (values.email.trim().toLowerCase() !== (activeProfile.email || "").toLowerCase()) {
        const { error } = await getSupabase().auth.updateUser({ email: values.email.trim() });
        if (error) throw error;
        showNotice("Details saved. Confirm the email change using the message sent to your new address.", "info");
      } else {
        showNotice("Profile updated.");
      }
      activeProfile = { ...activeProfile, ...updated };
      renderAccount();
    } catch (error) {
      showNotice(error.message || "Unable to update profile.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });
  document.getElementById("passwordForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    setFormBusy(form, true);
    try {
      await updatePassword(new FormData(form).get("password"));
      form.reset();
      showNotice("Password updated.");
    } catch (error) {
      showNotice(error.message || "Unable to update password.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });

  const initial = window.location.hash.slice(1);
  await navigate(titles[initial] ? initial : "dashboard", false);
  hideLoadingScreen();
} catch (error) {
  hideLoadingScreen();
  const fatal = document.getElementById("fatalNotice");
  fatal.textContent = error.message || "Unable to load your account.";
  fatal.classList.remove("d-none");
  if (/sign in|session|inactive|provisioned/i.test(fatal.textContent)) {
    setTimeout(() => window.location.replace("index.html"), 1200);
  }
}
