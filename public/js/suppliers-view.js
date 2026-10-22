import { getSupabase } from "./supabase.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

let suppliers = [];
let supplierBalances = new Map();
let activeUserId = null;

const money = (amount) => `${globalThis.APP_CONFIG?.currencySymbol || "GH₵"}${Number(amount || 0).toFixed(2)}`;

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
  const filtered = suppliers.filter((supplier) => [
    supplier.business_name,
    supplier.contact_person,
    supplier.phone,
    supplier.email,
    supplier.category,
  ].some((value) => String(value || "").toLowerCase().includes(term)));

  document.getElementById("supplierCount").textContent = `${filtered.length} of ${suppliers.length} supplier${suppliers.length === 1 ? "" : "s"}`;
  if (!filtered.length) {
    setEmptyRow(body, 7, term ? "No suppliers match this search." : "No suppliers yet. Add your first supplier using the form.");
    return;
  }

  body.replaceChildren(...filtered.map((supplier) => {
    const row = document.createElement("tr");
    const balance = supplierBalances.get(String(supplier.id)) || { outstanding: 0, overdue: 0 };
    const values = [
      supplier.business_name,
      [supplier.contact_person, supplier.phone].filter(Boolean).join(" · "),
      supplier.category || "—",
      money(balance.outstanding),
      money(balance.overdue),
      supplier.status === "active" ? "Active" : "Inactive",
    ];
    values.forEach((value, index) => {
      const cell = document.createElement("td");
      cell.textContent = value;
      if (index === 3 || index === 4) cell.classList.add("text-nowrap");
      if (index === 5) {
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
  setEmptyRow(body, 7, "Loading suppliers...");
  try {
    const client = getSupabase();
    const [{ data: supplierRows, error: supplierError }, { data: bills, error: billError }] = await Promise.all([
      client.from("suppliers")
        .select("id, business_name, contact_person, phone, email, address, category, notes, status, created_at")
        .order("business_name", { ascending: true }),
      client.from("supplier_bills")
        .select("supplier_id, total, status, due_date, supplier_payment_allocations(amount, reversed_at)"),
    ]);
    if (supplierError) throw supplierError;
    if (billError) throw billError;

    suppliers = supplierRows || [];
    supplierBalances = new Map();
    const today = new Date().toISOString().slice(0, 10);
    (bills || []).forEach((bill) => {
      if (bill.status === "cancelled") return;
      const paid = (bill.supplier_payment_allocations || [])
        .filter((allocation) => !allocation.reversed_at)
        .reduce((total, allocation) => total + Number(allocation.amount || 0), 0);
      const outstanding = Math.max(0, Number(bill.total || 0) - paid);
      const balance = supplierBalances.get(String(bill.supplier_id)) || { outstanding: 0, overdue: 0 };
      balance.outstanding += outstanding;
      if (outstanding > 0 && bill.due_date && bill.due_date < today) balance.overdue += outstanding;
      supplierBalances.set(String(bill.supplier_id), balance);
    });
    renderSuppliers(document.getElementById("supplierSearch").value);
  } catch (error) {
    setEmptyRow(body, 7, "Unable to load supplier records. Check the supplier database migration and access policies.");
    document.getElementById("supplierCount").textContent = "Supplier data unavailable";
    showNotice(error.message || "Unable to load suppliers.", "danger");
  }
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
      const request = id
        ? client.from("suppliers").update(values).eq("id", id)
        : client.from("suppliers").insert({ ...values, status: "active", created_by: activeUserId });
      const { error: saveError } = await request;
      if (saveError) throw saveError;
      resetSupplierForm();
      showNotice(id ? "Supplier updated." : "Supplier added.");
      await loadSuppliers();
    } catch (saveError) {
      showNotice(saveError.message || "Unable to save supplier.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });

  document.getElementById("cancelSupplierEdit").addEventListener("click", resetSupplierForm);
  document.getElementById("supplierSearch").addEventListener("input", (event) => renderSuppliers(event.currentTarget.value));
  await loadSuppliers();
}
