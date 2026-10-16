import Papa from "papaparse";
import {
  addClientInteraction,
  deleteClient,
  importClients,
  listClientInteractions,
  listClients,
  saveClient,
} from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

const state = { query: "", limit: 25, page: 1, total: 0, rows: [], editId: null };

function addCell(row, value) {
  const cell = document.createElement("td");
  cell.textContent = value ?? "";
  row.append(cell);
  return cell;
}

function addButton(label, action, className = "btn btn-sm btn-outline-light") {
  const button = document.createElement("button");
  button.type = "button";
  button.className = className;
  button.dataset.action = action;
  button.textContent = label;
  return button;
}

function renderRows() {
  const body = document.getElementById("clientRows");
  if (!state.rows.length) {
    setEmptyRow(body, 5, state.query ? "No clients match this search." : "No clients have been added yet.");
    return;
  }

  const rows = state.rows.map((client) => {
    const row = document.createElement("tr");
    row.dataset.id = client.id;
    addCell(row, client.full_name);
    addCell(row, client.phone);
    addCell(row, client.email);
    addCell(row, client.address);
    const actions = document.createElement("td");
    actions.className = "d-flex flex-wrap gap-1";
    const edit = addButton("Edit", "edit");
    const notes = addButton("Notes", "notes");
    const remove = addButton("Delete", "delete", "btn btn-sm btn-outline-danger");
    actions.append(edit, notes, remove);
    row.append(actions);
    return row;
  });
  body.replaceChildren(...rows);
}

async function loadClients() {
  const body = document.getElementById("clientRows");
  setEmptyRow(body, 5, "Loading clients...");
  try {
    const result = await listClients({ query: state.query, limit: state.limit, page: state.page });
    state.rows = result.items;
    state.total = result.total;
    const pages = Math.max(1, Math.ceil(state.total / state.limit));
    if (state.page > pages) {
      state.page = pages;
      return loadClients();
    }
    renderRows();
    document.getElementById("clientCount").textContent = `${state.total} total`;
    document.getElementById("clientPageLabel").textContent = `Page ${state.page} of ${pages}`;
    document.getElementById("clientPrevious").disabled = state.page <= 1;
    document.getElementById("clientNext").disabled = state.page >= pages;
  } catch (error) {
    setEmptyRow(body, 5, "Unable to load clients.");
    showNotice(error.message || "Unable to load clients.", "danger");
  }
}

function resetClientForm() {
  state.editId = null;
  document.getElementById("clientForm").reset();
  document.getElementById("clientFormTitle").textContent = "Add Client";
  document.querySelector("#clientForm button[type='submit']").textContent = "Save Client";
  document.getElementById("cancelClientEdit").classList.add("d-none");
}

function downloadFile(filename, content, type) {
  const url = URL.createObjectURL(new Blob([content], { type }));
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

function exportCsv() {
  const fields = ["full_name", "phone", "email", "address"];
  const csv = Papa.unparse({ fields, data: state.rows.map((client) => fields.map((field) => client[field] ?? "")) }, { escapeFormulae: true });
  downloadFile("clients.csv", csv, "text/csv;charset=utf-8");
}

function exportExcel() {
  const fields = ["Full Name", "Phone", "Email", "Address"];
  const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
  const rows = state.rows.map((client) => `<tr>${[client.full_name, client.phone, client.email, client.address].map((value) => `<td>${escape(value)}</td>`).join("")}</tr>`).join("");
  const html = `<html><head><meta charset="utf-8"></head><body><table><thead><tr>${fields.map((field) => `<th>${field}</th>`).join("")}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
  downloadFile("clients.xls", html, "application/vnd.ms-excel;charset=utf-8");
}

function printClients() {
  const printWindow = window.open("", "_blank");
  if (!printWindow) {
    showNotice("Allow pop-ups to print this client list.", "warning");
    return;
  }
  printWindow.opener = null;
  const table = document.querySelector("#view-clients table").cloneNode(true);
  table.querySelectorAll("tr").forEach((row) => row.lastElementChild?.remove());
  printWindow.document.write("<!doctype html><html><head><meta charset='utf-8'><title>Clients</title><style>body{font:14px sans-serif;padding:24px;color:#16202a}table{width:100%;border-collapse:collapse}th,td{border:1px solid #9aa7b5;padding:8px;text-align:left}th{background:#f0c419}@media print{button{display:none}}</style></head><body></body></html>");
  printWindow.document.body.append(table);
  printWindow.document.close();
  printWindow.focus();
  printWindow.print();
}

async function handleCsv(file) {
  const parsed = Papa.parse(file, { header: true, skipEmptyLines: true, transformHeader: (header) => header.trim().toLowerCase() });
  if (parsed.errors.length) throw new Error(parsed.errors[0].message);
  if (parsed.data.length > 5000) throw new Error("Import is limited to 5,000 rows.");
  const first = (row, names) => {
    for (const name of names) if (row[name] !== undefined) return String(row[name] ?? "").trim();
    return "";
  };
  const rows = parsed.data.map((row) => ({
    full_name: first(row, ["name", "full name", "full_name"]) || [first(row, ["given name", "first name"]), first(row, ["family name", "last name"])].filter(Boolean).join(" "),
    phone: first(row, ["phone 1 - value", "phone", "primary phone"]),
    email: first(row, ["e-mail 1 - value", "email", "primary email"]),
    address: first(row, ["address 1 - formatted", "address", "primary address"]),
  })).filter((row) => row.full_name || row.phone || row.email);
  if (!rows.length) throw new Error("No usable client rows were found.");
  const result = await importClients(rows);
  const summary = result[0] || { inserted_count: 0, updated_count: 0 };
  window.dispatchEvent(new Event("lmg:clients-changed"));
  showNotice(`Imported ${summary.inserted_count} clients and updated ${summary.updated_count}.`);
  state.page = 1;
  await loadClients();
}

async function showInteractions(client, currentRow) {
  const existing = currentRow.nextElementSibling;
  if (existing?.dataset.interactionsFor === String(client.id)) {
    existing.remove();
    return;
  }
  existing?.remove();
  const detail = document.createElement("tr");
  detail.dataset.interactionsFor = client.id;
  const cell = document.createElement("td");
  cell.colSpan = 5;
  cell.textContent = "Loading notes...";
  detail.append(cell);
  currentRow.after(detail);
  try {
    const notes = await listClientInteractions(client.id);
    cell.replaceChildren();
    const list = document.createElement("div");
    list.className = "mb-2";
    if (!notes.length) list.textContent = "No interaction notes.";
    notes.forEach((note) => {
      const line = document.createElement("div");
      line.className = "small text-muted";
      line.textContent = `${note.created_at}: ${note.note}`;
      list.append(line);
    });
    const form = document.createElement("form");
    form.className = "d-flex gap-2";
    const input = document.createElement("input");
    input.className = "form-control";
    input.name = "note";
    input.maxLength = 5000;
    input.placeholder = "Add interaction note";
    input.required = true;
    const submit = document.createElement("button");
    submit.className = "btn btn-sm btn-primary";
    submit.textContent = "Add Note";
    form.append(input, submit);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      submit.disabled = true;
      try {
        await addClientInteraction(client.id, input.value);
        showNotice("Interaction note added.");
        await showInteractions(client, currentRow);
      } catch (error) {
        showNotice(error.message || "Unable to add note.", "danger");
      } finally {
        submit.disabled = false;
      }
    });
    cell.append(list, form);
  } catch (error) {
    cell.textContent = error.message || "Unable to load notes.";
  }
}

export async function initClients() {
  const form = document.getElementById("clientForm");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    setFormBusy(form, true);
    try {
      const values = Object.fromEntries(new FormData(form));
      await saveClient({
        full_name: values.full_name.trim(),
        phone: values.phone.trim() || null,
        email: values.email.trim() || null,
        address: values.address.trim() || null,
      }, state.editId);
      window.dispatchEvent(new Event("lmg:clients-changed"));
      showNotice(state.editId ? "Client updated." : "Client added.");
      resetClientForm();
      await loadClients();
    } catch (error) {
      showNotice(error.message || "Unable to save client.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });

  document.getElementById("cancelClientEdit").addEventListener("click", resetClientForm);
  document.getElementById("clientRows").addEventListener("click", async (event) => {
    const button = event.target.closest("button[data-action]");
    if (!button) return;
    const row = button.closest("tr");
    const client = state.rows.find((item) => String(item.id) === row.dataset.id);
    if (!client) return;
    if (button.dataset.action === "edit") {
      state.editId = client.id;
      for (const [name, value] of Object.entries(client)) {
        const field = form.elements.namedItem(name);
        if (field) field.value = value ?? "";
      }
      document.getElementById("clientFormTitle").textContent = "Edit Client";
      document.querySelector("#clientForm button[type='submit']").textContent = "Save Changes";
      document.getElementById("cancelClientEdit").classList.remove("d-none");
      form.scrollIntoView({ behavior: "smooth", block: "center" });
    } else if (button.dataset.action === "delete") {
      if (!window.confirm(`Delete ${client.full_name}? Linked transactions and notes follow their database delete rules.`)) return;
      button.disabled = true;
      try {
        await deleteClient(client.id);
        window.dispatchEvent(new Event("lmg:clients-changed"));
        showNotice("Client deleted.");
        await loadClients();
      } catch (error) {
        showNotice(error.message || "Unable to delete client.", "danger");
      } finally {
        button.disabled = false;
      }
    } else if (button.dataset.action === "notes") {
      await showInteractions(client, row);
    }
  });

  let searchTimer;
  document.getElementById("clientSearch").addEventListener("input", (event) => {
    clearTimeout(searchTimer);
    state.query = event.target.value;
    state.page = 1;
    searchTimer = setTimeout(loadClients, 250);
  });
  document.getElementById("clientLimit").addEventListener("change", (event) => {
    state.limit = Number(event.target.value);
    state.page = 1;
    loadClients();
  });
  document.getElementById("clientPrevious").addEventListener("click", () => { state.page--; loadClients(); });
  document.getElementById("clientNext").addEventListener("click", () => { state.page++; loadClients(); });
  document.getElementById("clientCsvExport").addEventListener("click", exportCsv);
  document.getElementById("clientExcelExport").addEventListener("click", exportExcel);
  document.getElementById("clientPrint").addEventListener("click", printClients);
  document.getElementById("clientCsvImport").addEventListener("change", async (event) => {
    const [file] = event.target.files;
    if (!file) return;
    try {
      await handleCsv(file);
    } catch (error) {
      showNotice(error.message || "Unable to import CSV.", "danger");
    } finally {
      event.target.value = "";
    }
  });
  await loadClients();
}