import { getSupabase } from "./supabase.js";
import { createSupplierRecord } from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

let suppliers = [];
let filteredSuppliers = [];
let supplierBalances = new Map();
let supplierBalancesAvailable = true;
let supplierSpending = new Map();
let supplierSpendingAvailable = true;
let supplierPurchases = new Map();
let supplierPaid = new Map();
let activeUserId = null;

const money = (amount) => `${globalThis.APP_CONFIG?.currencySymbol || "GH₵"}${Number(amount || 0).toFixed(2)}`;
const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => (
  { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]
));
const assetUrl = (path) => new URL(path, document.baseURI).href;

async function loadAllRows(query) {
  const rows = [];
  for (let offset = 0; ; offset += 1000) {
    const { data, error } = await query().range(offset, offset + 999);
    if (error) throw error;
    rows.push(...data);
    if (data.length < 1000) return rows;
  }
}

function resetSupplierForm() {
  const form = document.getElementById("supplierForm");
  form.reset();
  document.getElementById("supplierId").value = "";
  document.getElementById("supplierFormTitle").textContent = "Add Supplier";
  document.getElementById("cancelSupplierEdit").classList.add("d-none");
}

function openSupplierEdit(supplier) {
  document.getElementById("supplierId").value = supplier.id;
  document.getElementById("supplierBusinessName").value = supplier.business_name || "";
  document.getElementById("supplierContactPerson").value = supplier.contact_person || "";
  document.getElementById("supplierPhone").value = supplier.phone || "";
  document.getElementById("supplierEmail").value = supplier.email || "";
  document.getElementById("supplierCategory").value = supplier.category || "";
  document.getElementById("supplierAddress").value = supplier.address || "";
  document.getElementById("supplierNotes").value = supplier.notes || "";
  document.getElementById("supplierFormTitle").textContent = "Edit Supplier";
  document.getElementById("cancelSupplierEdit").classList.remove("d-none");
  document.getElementById("supplierBusinessName").focus();
}

function renderSuppliers(query = "") {
  const body = document.getElementById("supplierRows");
  const term = query.trim().toLowerCase();
  const status = document.getElementById("supplierStatusFilter").value;
  const filtered = suppliers.filter((supplier) => (status === "all" || supplier.status === status) && [
    supplier.business_name,
    supplier.contact_person,
    supplier.phone,
    supplier.email,
    supplier.category,
    supplier.address,
  ].some((value) => String(value || "").toLowerCase().includes(term)));
  filteredSuppliers = filtered;

  document.getElementById("supplierCount").textContent = `${filtered.length} of ${suppliers.length} supplier${suppliers.length === 1 ? "" : "s"}`;
  if (!filtered.length) {
    setEmptyRow(body, 8, term ? "No suppliers match this search." : "No suppliers yet. Add your first supplier using the form.");
    return;
  }

  body.replaceChildren(...filtered.map((supplier) => {
    const row = document.createElement("tr");
    const balance = supplierBalances.get(String(supplier.id)) || { outstanding: 0, overdue: 0 };
    const totalSpent = supplierSpending.get(String(supplier.id)) || 0;
    const values = [
      supplier.business_name,
      [supplier.contact_person, supplier.phone].filter(Boolean).join(" · "),
      supplier.category || "—",
      supplierSpendingAvailable ? money(totalSpent) : "—",
      supplierBalancesAvailable ? money(balance.outstanding) : "—",
      supplierBalancesAvailable ? money(balance.overdue) : "—",
      supplier.status === "active" ? "Active" : "Inactive",
    ];
    values.forEach((value, index) => {
      const cell = document.createElement("td");
      cell.textContent = value;
      if (index >= 3 && index <= 5) cell.classList.add("text-nowrap");
      if (index === 6) {
        const badge = document.createElement("span");
        badge.className = `badge ${supplier.status === "active" ? "text-bg-success" : "text-bg-secondary"}`;
        badge.textContent = value;
        cell.replaceChildren(badge);
      }
      row.append(cell);
    });

    const actions = document.createElement("td");
    actions.className = "text-nowrap";
    const edit = document.createElement("button");
    edit.type = "button";
    edit.className = "btn btn-sm btn-outline-primary me-1";
    edit.textContent = "Edit";
    edit.addEventListener("click", () => openSupplierEdit(supplier));
    const status = document.createElement("button");
    status.type = "button";
    status.className = "btn btn-sm btn-outline-secondary";
    status.textContent = supplier.status === "active" ? "Deactivate" : "Activate";
    status.addEventListener("click", () => updateSupplierStatus(supplier));
    actions.append(edit, status);
    row.append(actions);
    return row;
  }));
}

async function loadSuppliers() {
  const body = document.getElementById("supplierRows");
  filteredSuppliers = [];
  setEmptyRow(body, 8, "Loading suppliers...");
  try {
    const client = getSupabase();
    suppliers = await loadAllRows(() => client.from("suppliers")
      .select("id, business_name, contact_person, phone, email, address, category, notes, status, created_at")
      .order("business_name", { ascending: true })
      .order("id", { ascending: true }));
    supplierBalances = new Map();
    supplierSpending = new Map();
    supplierPurchases = new Map();
    supplierPaid = new Map();
    const [{ data: spendingRows, error: spendingError }, { data: bills, error: billError }] = await Promise.all([
      client.rpc("get_supplier_spending_totals"),
      loadAllRows(() => client.from("supplier_bills")
        .select("supplier_id, total, status, due_date, supplier_payment_allocations(amount, reversed_at, supplier_payments(status))"),
      ).then((data) => ({ data, error: null })).catch((error) => ({ data: null, error })),
    ]);
    supplierSpendingAvailable = !spendingError;
    if (spendingError) {
      console.error("Supplier records loaded, but expense totals could not be loaded:", spendingError);
    } else {
      (spendingRows || []).forEach((row) => supplierSpending.set(String(row.supplier_id), Number(row.total_spent || 0)));
    }
    if (billError) {
      supplierBalancesAvailable = false;
      console.error("Supplier records loaded, but payable balances could not be loaded:", billError);
    } else {
      supplierBalancesAvailable = true;
      const today = new Date();
      const todayValue = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
      bills.forEach((bill) => {
        if (bill.status === "cancelled") return;
        const paid = (bill.supplier_payment_allocations || [])
          .filter((allocation) => !allocation.reversed_at && allocation.supplier_payments?.status !== "cancelled")
          .reduce((total, allocation) => total + Number(allocation.amount || 0), 0);
        const total = Number(bill.total || 0);
        const outstanding = Math.max(0, total - paid);
        const supplierId = String(bill.supplier_id);
        supplierPurchases.set(supplierId, (supplierPurchases.get(supplierId) || 0) + total);
        supplierPaid.set(supplierId, (supplierPaid.get(supplierId) || 0) + paid);
        const balance = supplierBalances.get(supplierId) || { outstanding: 0, overdue: 0 };
        balance.outstanding += outstanding;
        if (outstanding > 0 && bill.due_date && bill.due_date < todayValue) balance.overdue += outstanding;
        supplierBalances.set(supplierId, balance);
      });
    }
    renderSuppliers(document.getElementById("supplierSearch").value);
    if (!supplierSpendingAvailable || !supplierBalancesAvailable) {
      showNotice("Supplier records loaded, but some totals are unavailable. Check the latest database migrations and access grants.", "warning");
    }
  } catch (error) {
    filteredSuppliers = [];
    supplierBalancesAvailable = false;
    supplierSpendingAvailable = false;
    console.error("Supplier records could not be loaded:", error);
    setEmptyRow(body, 8, `Unable to load suppliers: ${error.message || "Check Supabase table permissions and migrations."}`);
    document.getElementById("supplierCount").textContent = "Supplier data unavailable";
    showNotice(error.message || "Unable to load suppliers.", "danger");
  }
}

function printSuppliers() {
  if (!filteredSuppliers.length) {
    showNotice("There are no suppliers matching the current search and status filters.", "warning");
    return;
  }
  const printWindow = window.open("", "_blank");
  if (!printWindow) {
    showNotice("Allow pop-ups to open the supplier register for printing.", "warning");
    return;
  }
  const total = (map) => filteredSuppliers.reduce((sum, supplier) => sum + (map.get(String(supplier.id)) || 0), 0);
  const rows = filteredSuppliers.map((supplier) => {
    const balance = supplierBalances.get(String(supplier.id)) || { outstanding: 0, overdue: 0 };
    const amountSpent = supplierSpending.get(String(supplier.id)) || 0;
    const details = [
      supplier.contact_person,
      supplier.phone,
      supplier.email,
      supplier.address,
    ].filter(Boolean).map(escapeHtml).join("<br>");
    return `<tr>
      <td>${escapeHtml(supplier.business_name)}</td>
      <td>${details || "—"}</td>
      <td>${escapeHtml(supplier.category || "—")}</td>
      <td class="amount">${supplierSpendingAvailable ? escapeHtml(money(amountSpent)) : "Unavailable"}</td>
      <td class="amount">${supplierBalancesAvailable ? escapeHtml(money(supplierPurchases.get(String(supplier.id)) || 0)) : "Unavailable"}</td>
      <td class="amount">${supplierBalancesAvailable ? escapeHtml(money(supplierPaid.get(String(supplier.id)) || 0)) : "Unavailable"}</td>
      <td class="amount">${supplierBalancesAvailable ? escapeHtml(money(balance.outstanding)) : "Unavailable"}</td>
      <td class="amount">${supplierBalancesAvailable ? escapeHtml(money(balance.overdue)) : "Unavailable"}</td>
      <td>${escapeHtml(supplier.status === "active" ? "Active" : "Inactive")}</td>
    </tr>`;
  }).join("");
  const status = document.getElementById("supplierStatusFilter").selectedOptions[0].textContent;
  const search = document.getElementById("supplierSearch").value.trim();
  const filters = [`Status: ${status}`, search ? `Search: ${search}` : ""].filter(Boolean).join(" · ");
  const logo = assetUrl("images/logo.png");
  const stylesheet = assetUrl("css/report-print.css");
  const generated = new Date().toLocaleString();
  const outstandingTotal = filteredSuppliers.reduce((sum, supplier) => (
    sum + (supplierBalances.get(String(supplier.id))?.outstanding || 0)
  ), 0);
  const summary = `
    <div class="print-summary"><span><strong>Suppliers included</strong>${filteredSuppliers.length}</span>
      <span><strong>Total amount spent</strong>${supplierSpendingAvailable ? escapeHtml(money(total(supplierSpending))) : "Unavailable"}</span>
      <span><strong>Bill purchases</strong>${supplierBalancesAvailable ? escapeHtml(money(total(supplierPurchases))) : "Unavailable"}</span>
      <span><strong>Paid against bills</strong>${supplierBalancesAvailable ? escapeHtml(money(total(supplierPaid))) : "Unavailable"}</span>
      <span><strong>Outstanding</strong>${supplierBalancesAvailable ? escapeHtml(money(outstandingTotal)) : "Unavailable"}</span>
    </div>`;
  printWindow.document.open();
  printWindow.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Supplier Register</title><link rel="stylesheet" href="${stylesheet}"></head><body><main class="print-document print-document--landscape">
    <header class="print-brand"><img src="${logo}" alt="LMG Creative Media"><div><strong>LMG Creative Media</strong><span>Bringing Creative Ideas to Life</span></div></header>
    <div class="print-heading"><h1>Supplier Register</h1><p>Generated ${escapeHtml(generated)} · ${filteredSuppliers.length} suppliers</p><p>Filters: ${escapeHtml(filters)}</p></div>
    ${summary}
    <table><thead><tr><th>Supplier</th><th>Contact details</th><th>Category</th><th>Amount spent</th><th>Bill purchases</th><th>Paid</th><th>Outstanding</th><th>Overdue</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table>
    <footer class="print-footer">LMG Creative Media · Supplier Register · Generated ${escapeHtml(generated)}</footer>
    </main></body></html>`);
  printWindow.document.close();
  printWindow.setTimeout(() => printWindow.print(), 500);
}

async function updateSupplierStatus(supplier) {
  const nextStatus = supplier.status === "active" ? "inactive" : "active";
  const verb = nextStatus === "inactive" ? "deactivate" : "activate";
  if (!window.confirm(`${verb[0].toUpperCase()}${verb.slice(1)} ${supplier.business_name}? Historical records will be preserved.`)) return;
  try {
    const { error } = await getSupabase().from("suppliers")
      .update({ status: nextStatus, updated_by: activeUserId })
      .eq("id", supplier.id);
    if (error) throw error;
    showNotice(`Supplier ${nextStatus === "active" ? "activated" : "deactivated"}.`);
    await loadSuppliers();
  } catch (error) {
    showNotice(error.message || "Unable to change supplier status.", "danger");
  }
}

export async function initSuppliers() {
  const client = getSupabase();
  const { data: { user }, error } = await client.auth.getUser();
  if (error) throw error;
  if (!user) throw new Error("Sign in to manage suppliers.");
  activeUserId = user.id;

  document.getElementById("supplierForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const id = document.getElementById("supplierId").value;
    const values = {
      business_name: document.getElementById("supplierBusinessName").value.trim(),
      contact_person: document.getElementById("supplierContactPerson").value.trim() || null,
      phone: document.getElementById("supplierPhone").value.trim() || null,
      email: document.getElementById("supplierEmail").value.trim() || null,
      category: document.getElementById("supplierCategory").value.trim() || null,
      address: document.getElementById("supplierAddress").value.trim() || null,
      notes: document.getElementById("supplierNotes").value.trim() || null,
      updated_by: activeUserId,
    };
    setFormBusy(form, true, "Saving...");
    try {
      if (id) {
        const { error: saveError } = await client.from("suppliers").update(values).eq("id", id);
        if (saveError) throw saveError;
      } else {
        await createSupplierRecord({
          businessName: values.business_name,
          contactPerson: values.contact_person,
          phone: values.phone,
          email: values.email,
          category: values.category,
          address: values.address,
          notes: values.notes,
        });
      }
      resetSupplierForm();
      showNotice(id ? "Supplier updated." : "Supplier added.");
      await loadSuppliers();
      if (!id) window.dispatchEvent(new Event("lmg:suppliers-changed"));
    } catch (saveError) {
      showNotice(saveError.message || "Unable to save supplier.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });

  document.getElementById("cancelSupplierEdit").addEventListener("click", resetSupplierForm);
  document.getElementById("supplierSearch").addEventListener("input", (event) => renderSuppliers(event.currentTarget.value));
  document.getElementById("supplierStatusFilter").addEventListener("change", () => {
    renderSuppliers(document.getElementById("supplierSearch").value);
  });
  document.getElementById("printSuppliers").addEventListener("click", printSuppliers);
  window.addEventListener("lmg:suppliers-changed", loadSuppliers);
  window.addEventListener("lmg:transactions-changed", loadSuppliers);
  window.addEventListener("sync-complete", loadSuppliers);
  await loadSuppliers();
}
