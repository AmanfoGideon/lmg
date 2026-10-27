import {
  createTransaction,
  createSupplierRecord,
  listActiveSuppliers,
  listClientsForForms,
  listInvoices,
  listTransactions,
  removeTransaction,
  updateTransaction,
} from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";
import { debounce } from "./util.js";
import Modal from "bootstrap/js/dist/modal";

let clients = [];
let invoices = [];
let suppliers = [];
let transactions = [];
let filteredTransactions = [];
let editModal;
let createSupplierModal;
let supplierModalTarget = "create";
let supplierCreateBusy = false;
const currencySymbol = globalThis.APP_CONFIG?.currencySymbol || "₵";

function makeRequestKey() {
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), (value) => value.toString(16).padStart(2, "0")).join("");
}

function addOption(select, value, label) {
  const option = document.createElement("option");
  option.value = value;
  option.textContent = label;
  select.append(option);
}

function setSelectOptions(select, values, placeholder, selectedValue = "") {
  select.replaceChildren();
  addOption(select, "", placeholder);
  values.forEach((item) => addOption(select, String(item.id), item.label));
  select.value = selectedValue;
}

function setSupplierOptions(select, selectedValue = "", currentSupplier = null) {
  const options = suppliers.map((supplier) => ({ id: supplier.id, label: supplier.business_name }));
  if (currentSupplier && !options.some((supplier) => String(supplier.id) === String(currentSupplier.id))) {
    options.push({
      id: currentSupplier.id,
      label: `${currentSupplier.business_name || "Supplier"}${currentSupplier.status === "inactive" ? " (inactive)" : ""}`,
    });
  }
  setSelectOptions(select, options, "No supplier", selectedValue);
}

function syncSupplierFields(type, select, wrapper) {
  const isExpense = type.value === "expense";
  wrapper.classList.toggle("d-none", !isExpense);
  if (!isExpense) select.value = "";
}

function syncClientRequirement(type, supplier, client) {
  client.required = !(type.value === "expense" && supplier.value);
}

// Receipts open on about:blank, so relative asset paths must be resolved against
// the application URL before they are written into the popup.
const assetUrl = (path) => new URL(path, document.baseURI).href;
// The popup shares this document's origin, so any interpolated record data must be escaped.
const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => (
  { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]
));
const PAYMENT_METHOD_LABELS = { cash: "Cash", mobile: "MoMo", card: "Card", unspecified: "Not specified" };
const paymentMethodLabel = (method) => method ? (PAYMENT_METHOD_LABELS[method] || String(method)) : "Not specified";

// Existing receipt numbering is preserved: RCPT-##### derived from the transaction id.
const receiptNumber = (transaction) => `RCPT-${String(transaction.id).padStart(5, "0")}`;

function formatReceiptDate(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

// Opens a premium payment receipt preview with Print and Download PDF actions.
// A receipt is only ever offered for recorded income/payments (see renderTransactions),
// so it never implies that an unpaid transaction has been paid.
function openReceipt(transaction) {
  const windowRef = window.open("", "_blank");
  if (!windowRef) {
    showNotice("Allow pop-ups to view, print or download this receipt.", "warning");
    return;
  }
  windowRef.opener = null;

  const amount = Number(transaction.amount || 0);
  const money = (value) => `${currencySymbol}${Number(value || 0).toFixed(2)}`;
  const clientName = transaction.clients?.full_name || "Walk-in client";
  const description = transaction.description?.trim() || "Payment received";
  const method = transaction.payment_method
    ? (PAYMENT_METHOD_LABELS[transaction.payment_method] || String(transaction.payment_method))
    : "Not specified";
  const invoiceNumber = transaction.invoices?.invoice_number || "";
  const number = receiptNumber(transaction);
  const paymentDate = formatReceiptDate(transaction.date);
  const year = new Date().getFullYear();

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt ${escapeHtml(number)}</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="${assetUrl("css/receipt-premium.css")}">
  <style id="receiptPageStyle">@page { size: A4; margin: 15mm; }</style>
</head>
<body class="receipt-body">
  <div class="receipt-toolbar no-print" role="toolbar" aria-label="Receipt actions">
    <div class="receipt-toolbar__group" role="group" aria-label="Paper size">
      <button type="button" class="receipt-btn receipt-btn--ghost is-active" data-receipt-size="a4" aria-pressed="true">A4</button>
      <button type="button" class="receipt-btn receipt-btn--ghost" data-receipt-size="thermal" aria-pressed="false">80 mm</button>
    </div>
    <div class="receipt-toolbar__group">
      <button type="button" id="receiptPrint" class="receipt-btn receipt-btn--primary">Print Receipt</button>
      <button type="button" id="receiptPdf" class="receipt-btn receipt-btn--ghost" title="Choose &quot;Save as PDF&quot; in the print dialog">Download PDF</button>
      <button type="button" id="receiptClose" class="receipt-btn receipt-btn--ghost">Close</button>
    </div>
  </div>

  <div class="receipt-paper">
    <div class="receipt-brand">
      <div class="receipt-brand__id">
        <img class="receipt-brand__logo" src="${assetUrl("images/logo.png")}" alt="LMG Creative Media logo">
        <div>
          <div class="receipt-brand__name">LMG Creative Media</div>
          <div class="receipt-brand__tag">Professional Creative Solutions</div>
        </div>
      </div>
      <div class="receipt-heading">
        <div class="receipt-heading__label">Payment Receipt</div>
        <span class="receipt-heading__number">${escapeHtml(number)}</span>
        <div><span class="receipt-status">Paid</span></div>
      </div>
    </div>

    <div class="receipt-parties">
      <div class="receipt-block">
        <div class="receipt-block__label">Received From</div>
        <div class="receipt-block__value">${escapeHtml(clientName)}</div>
      </div>
      <div class="receipt-block">
        <div class="receipt-block__label">Payment Date</div>
        <div class="receipt-block__value">${escapeHtml(paymentDate)}</div>
      </div>
      <div class="receipt-block">
        <div class="receipt-block__label">Reference</div>
        <div class="receipt-block__value">TXN-${escapeHtml(String(transaction.id))}</div>
      </div>
    </div>

    <div class="receipt-amount">
      <span class="receipt-amount__label">Amount Paid</span>
      <span class="receipt-amount__value">${money(amount)}</span>
    </div>

    <table class="receipt-table">
      <thead>
        <tr>
          <th>Description</th>
          <th class="num">Qty</th>
          <th class="num">Unit Price</th>
          <th class="num">Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            ${escapeHtml(description)}
            ${invoiceNumber ? `<div class="receipt-line-note">Against invoice ${escapeHtml(invoiceNumber)}</div>` : ""}
          </td>
          <td class="num">1</td>
          <td class="num">${money(amount)}</td>
          <td class="num">${money(amount)}</td>
        </tr>
      </tbody>
    </table>

    <div class="receipt-summary">
      <div class="receipt-summary__row"><span>Subtotal</span><span>${money(amount)}</span></div>
      <div class="receipt-summary__row receipt-summary__row--total"><span>Total</span><span>${money(amount)}</span></div>
      <div class="receipt-summary__row receipt-summary__row--paid"><span>Amount Paid</span><span>${money(amount)}</span></div>
    </div>

    <div class="receipt-meta">
      <div class="receipt-meta__item"><dt>Payment Method</dt><dd>${escapeHtml(method)}</dd></div>
      <div class="receipt-meta__item"><dt>Payment Status</dt><dd>Paid</dd></div>
    </div>

    <div class="receipt-footer">
      <p><strong>Thank you for your business.</strong></p>
      <p>This receipt confirms the payment recorded against the details above.</p>
      <p>&copy; ${year} LMG Creative Media. All rights reserved.</p>
    </div>
  </div>
</body>
</html>`;

  windowRef.document.write(html);
  windowRef.document.close();

  const doc = windowRef.document;
  const paper = doc.querySelector(".receipt-paper");
  const pageStyle = doc.getElementById("receiptPageStyle");
  const sizeButtons = doc.querySelectorAll("[data-receipt-size]");
  const setSize = (size) => {
    const thermal = size === "thermal";
    paper.classList.toggle("receipt-paper--thermal", thermal);
    pageStyle.textContent = thermal
      ? "@page { size: 80mm auto; margin: 3mm; }"
      : "@page { size: A4; margin: 15mm; }";
    sizeButtons.forEach((button) => {
      const active = button.dataset.receiptSize === size;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", String(active));
    });
  };
  sizeButtons.forEach((button) => button.addEventListener("click", () => setSize(button.dataset.receiptSize)));
  doc.getElementById("receiptPrint").addEventListener("click", () => windowRef.print());
  doc.getElementById("receiptPdf").addEventListener("click", () => windowRef.print());
  doc.getElementById("receiptClose").addEventListener("click", () => windowRef.close());
  setSize("a4");
}

function renderTransactions(query = "") {
  const body = document.getElementById("transactionRows");
  const dateFrom = document.getElementById("dateFrom").value;
  const dateTo = document.getElementById("dateTo").value;
  const sortBy = document.getElementById("sortBy").value;
  
  let filtered = transactions.filter((transaction) => {
    // Text search
    const search = `${transaction.id} ${transaction.clients?.full_name || transaction.client_name || ""} ${transaction.type} ${transaction.invoices?.invoice_number || ""} ${transaction.suppliers?.business_name || transaction.supplier_name || ""} ${paymentMethodLabel(transaction.payment_method)} ${transaction.description || ""}`;
    const matchesSearch = search.toLowerCase().includes(query.toLowerCase());
    
    // Date filter
    let matchesDate = true;
    if (dateFrom && transaction.date) {
      matchesDate = matchesDate && transaction.date >= dateFrom;
    }
    if (dateTo && transaction.date) {
      matchesDate = matchesDate && transaction.date <= dateTo;
    }
    
    return matchesSearch && matchesDate;
  });
  
  // Apply sorting
  filtered.sort((a, b) => {
    switch (sortBy) {
      case "date_desc":
        return new Date(b.date || 0) - new Date(a.date || 0);
      case "date_asc":
        return new Date(a.date || 0) - new Date(b.date || 0);
      case "amount_desc":
        return Number(b.amount || 0) - Number(a.amount || 0);
      case "amount_asc":
        return Number(a.amount || 0) - Number(b.amount || 0);
      case "type":
        return (a.type || "").localeCompare(b.type || "");
      case "method_asc":
        return paymentMethodLabel(a.payment_method).localeCompare(paymentMethodLabel(b.payment_method));
      case "method_desc":
        return paymentMethodLabel(b.payment_method).localeCompare(paymentMethodLabel(a.payment_method));
      default:
        return 0;
    }
  });
  
  filteredTransactions = filtered;
  if (!filtered.length) {
    setEmptyRow(body, 10, query || dateFrom || dateTo ? "No transactions match this search." : "No transactions have been recorded yet.");
    return;
  }
  body.replaceChildren(...filtered.map((transaction) => {
    const row = document.createElement("tr");
    // Add checkbox for bulk selection
    const checkboxCell = document.createElement("td");
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.className = "form-check-input bulk-select";
    checkbox.value = transaction.id;
    checkboxCell.append(checkbox);
    row.append(checkboxCell);
    
    const values = [transaction.clients?.full_name || transaction.client_name, transaction.type, transaction.invoices?.invoice_number, transaction.suppliers?.business_name || transaction.supplier_name, `${currencySymbol}${Number(transaction.amount).toFixed(2)}`, transaction.date, paymentMethodLabel(transaction.payment_method), transaction.description];
    values.forEach((value) => {
      const cell = document.createElement("td");
      cell.textContent = value ?? "";
      row.append(cell);
    });
    const actions = document.createElement("td");
    actions.className = "d-flex gap-1";
    if (["income", "payment"].includes(transaction.type)
        && !(transaction.type === "payment" && transaction._sync_status === "pending")) {
      const receipt = document.createElement("button");
      receipt.className = "btn btn-sm btn-outline-light";
      receipt.type = "button";
      receipt.textContent = "View Receipt";
      receipt.title = "View, print or download this payment receipt";
      receipt.addEventListener("click", () => openReceipt(transaction));
      actions.append(receipt);
    }
    const edit = document.createElement("button");
    edit.className = "btn btn-sm btn-outline-primary";
    edit.type = "button";
    edit.textContent = "Edit";
    edit.addEventListener("click", () => openEdit(transaction));
    const remove = document.createElement("button");
    remove.className = "btn btn-sm btn-outline-danger";
    remove.type = "button";
    remove.textContent = "Delete";
    remove.addEventListener("click", async () => {
      if (!window.confirm("Delete this transaction? Invoice payment status will be recalculated.")) return;
      remove.disabled = true;
      try {
        await removeTransaction(transaction.id);
        showNotice("Transaction deleted.");
        window.dispatchEvent(new Event("lmg:transactions-changed"));
        await loadTransactions();
      } catch (error) {
        showNotice(error.message || "Unable to delete transaction.", "danger");
      } finally {
        remove.disabled = false;
      }
    });
    actions.append(edit, remove);
    row.append(actions);
    return row;
  }));
}

function printTransactions() {
  if (!filteredTransactions.length) {
    showNotice("There are no transactions to print for this search.", "warning");
    return;
  }
  const printWindow = window.open("", "_blank");
  if (!printWindow) {
    showNotice("Allow pop-ups to open the printable transaction table.", "warning");
    return;
  }
  const rows = filteredTransactions.map((transaction) => `<tr>
    <td>${escapeHtml(transaction.id)}</td>
    <td>${escapeHtml(transaction.date || "")}</td>
    <td>${escapeHtml(transaction.type || "")}</td>
    <td>${escapeHtml(transaction.description || "—")}</td>
    <td>${escapeHtml(transaction.clients?.full_name || transaction.client_name || "—")}</td>
    <td>${escapeHtml(transaction.suppliers?.business_name || transaction.supplier_name || "—")}</td>
    <td>${escapeHtml(transaction.invoices?.invoice_number || "—")}</td>
    <td>${escapeHtml(paymentMethodLabel(transaction.payment_method))}</td>
    <td class="amount">${escapeHtml(`${currencySymbol}${Number(transaction.amount || 0).toFixed(2)}`)}</td>
  </tr>`).join("");
  const searchTerm = document.getElementById("transactionSearch").value.trim();
  printWindow.document.open();
  printWindow.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transactions</title><style>
    body{font:12px/1.4 Arial,sans-serif;color:#111;margin:24px}h1{font-size:20px;margin:0 0 4px}.meta{color:#555;margin-bottom:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #bbb;padding:6px;text-align:left;vertical-align:top}th{background:#eee}.amount{text-align:right;white-space:nowrap}thead{display:table-header-group}tr{break-inside:avoid}@page{size:landscape;margin:12mm}@media print{body{margin:0}}
  </style></head><body><h1>Transactions</h1><div class="meta">${filteredTransactions.length} matching transactions${searchTerm ? ` · Search: ${escapeHtml(searchTerm)}` : ""}</div><table><thead><tr><th>ID</th><th>Date</th><th>Type</th><th>Description</th><th>Client</th><th>Supplier</th><th>Invoice</th><th>Payment Method</th><th>Amount</th></tr></thead><tbody>${rows}</tbody></table></body></html>`);
  printWindow.document.close();
  window.setTimeout(() => printWindow.print(), 300);
}

// Bulk actions functionality
const selectAllCheckbox = document.getElementById("selectAllTx");
const bulkActionsBtn = document.getElementById("bulkActionsBtn");

function updateBulkActionsButton() {
  const selectedCount = document.querySelectorAll(".bulk-select:checked").length;
  bulkActionsBtn.disabled = selectedCount === 0;
}

if (selectAllCheckbox) {
  selectAllCheckbox.addEventListener("change", function() {
    document.querySelectorAll(".bulk-select").forEach(cb => cb.checked = this.checked);
    updateBulkActionsButton();
  });
}

document.addEventListener("change", function(e) {
  if (e.target.classList.contains("bulk-select")) {
    updateBulkActionsButton();
  }
});

// Export CSV
document.getElementById("exportCsv")?.addEventListener("click", function() {
  const rows = filteredTransactions.map(t => 
    `${t.id},${t.date},${t.type},"${t.description || ''}","${t.clients?.full_name || ''}","${t.suppliers?.business_name || ''}","${t.invoices?.invoice_number || ''}","${paymentMethodLabel(t.payment_method)}",${t.amount}`
  ).join("\n");
  const csv = `ID,Date,Type,Description,Client,Supplier,Invoice,Payment Method,Amount\n${rows}`;
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `transactions_${new Date().toISOString().split('T')[0]}.csv`;
  link.click();
});

// Export Excel (tab-separated)
document.getElementById("exportExcel")?.addEventListener("click", function() {
  const rows = filteredTransactions.map(t => 
    `${t.id}\t${t.date}\t${t.type}\t${t.description || ''}\t${t.clients?.full_name || ''}\t${t.suppliers?.business_name || ''}\t${t.invoices?.invoice_number || ''}\t${paymentMethodLabel(t.payment_method)}\t${t.amount}`
  ).join("\n");
  const excel = `ID\tDate\tType\tDescription\tClient\tSupplier\tInvoice\tPayment Method\tAmount\n${rows}`;
  const blob = new Blob([excel], { type: "application/vnd.ms-excel;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `transactions_${new Date().toISOString().split('T')[0]}.xls`;
  link.click();
});

// Bulk export
document.getElementById("bulkExport")?.addEventListener("click", function(e) {
  e.preventDefault();
  const selectedIds = Array.from(document.querySelectorAll(".bulk-select:checked")).map(cb => cb.value);
  if (selectedIds.length === 0) {
    showNotice("Please select at least one transaction to export.", "warning");
    return;
  }
  const selected = filteredTransactions.filter(t => selectedIds.includes(String(t.id)));
  const rows = selected.map(t => 
    `${t.id},${t.date},${t.type},"${t.description || ''}","${t.clients?.full_name || ''}","${t.suppliers?.business_name || ''}","${t.invoices?.invoice_number || ''}","${paymentMethodLabel(t.payment_method)}",${t.amount}`
  ).join("\n");
  const csv = `ID,Date,Type,Description,Client,Supplier,Invoice,Payment Method,Amount\n${rows}`;
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `transactions_selected_${new Date().toISOString().split('T')[0]}.csv`;
  link.click();
});

// Bulk print
document.getElementById("bulkPrint")?.addEventListener("click", function(e) {
  e.preventDefault();
  const selectedIds = Array.from(document.querySelectorAll(".bulk-select:checked")).map(cb => cb.value);
  if (selectedIds.length === 0) {
    showNotice("Please select at least one transaction to print.", "warning");
    return;
  }
  const selected = filteredTransactions.filter(t => selectedIds.includes(String(t.id)));
  // Reuse printTransactions with filtered selection
  const originalFiltered = filteredTransactions;
  filteredTransactions = selected;
  printTransactions();
  filteredTransactions = originalFiltered;
});

// Bulk delete
document.getElementById("bulkDelete")?.addEventListener("click", async function(e) {
  e.preventDefault();
  const selectedIds = Array.from(document.querySelectorAll(".bulk-select:checked")).map(cb => cb.value);
  if (selectedIds.length === 0) {
    showNotice("Please select at least one transaction to delete.", "warning");
    return;
  }
  if (!confirm(`Delete ${selectedIds.length} selected transaction(s)? This action cannot be undone.`)) {
    return;
  }
  let deletedCount = 0;
  for (const id of selectedIds) {
    try {
      await removeTransaction(id);
      deletedCount++;
    } catch (error) {
      console.error(`Failed to delete transaction ${id}:`, error);
    }
  }
  showNotice(`Deleted ${deletedCount} transaction(s).`);
  await loadTransactions();
  // Reset checkboxes
  selectAllCheckbox.checked = false;
  updateBulkActionsButton();
});

// Date filter event listeners
document.getElementById("dateFrom")?.addEventListener("change", function() {
  renderTransactions(document.getElementById("transactionSearch").value.trim());
});

document.getElementById("dateTo")?.addEventListener("change", function() {
  renderTransactions(document.getElementById("transactionSearch").value.trim());
});

// Sort event listener
document.getElementById("sortBy")?.addEventListener("change", function() {
  renderTransactions(document.getElementById("transactionSearch").value.trim());
});

// Clear filters
document.getElementById("clearFilters")?.addEventListener("click", function() {
  document.getElementById("transactionSearch").value = "";
  document.getElementById("dateFrom").value = "";
  document.getElementById("dateTo").value = "";
  renderTransactions("");
});

function openEdit(transaction) {
  document.getElementById("editTransactionId").value = transaction.id;
  document.getElementById("editTransactionClient").value = transaction.client_id || "";
  document.getElementById("editTransactionAmount").value = transaction.amount;
  document.getElementById("editTransactionType").value = transaction.type;
  const editSupplier = document.getElementById("editTransactionSupplier");
  const currentSupplier = transaction.suppliers || (transaction.supplier_id ? {
    id: transaction.supplier_id,
    business_name: transaction.supplier_name || "Inactive supplier",
    status: "unknown",
  } : null);
  setSupplierOptions(editSupplier, transaction.supplier_id || "", currentSupplier);
  syncSupplierFields(document.getElementById("editTransactionType"), editSupplier, document.getElementById("editTransactionSupplierWrap"));
  syncClientRequirement(document.getElementById("editTransactionType"), editSupplier, document.getElementById("editTransactionClient"));
  document.getElementById("editTransactionMethod").value = transaction.payment_method || "";
  document.getElementById("editTransactionDate").value = transaction.date;
  document.getElementById("editTransactionDescription").value = transaction.description || "";
  setSelectOptions(document.getElementById("editTransactionInvoice"), invoices.map((invoice) => ({ id: invoice.id, label: invoice.invoice_number })), "No linked invoice", transaction.invoice_id || "");
  editModal.show();
}

async function loadTransactions() {
  const body = document.getElementById("transactionRows");
  setEmptyRow(body, 9, "Loading transactions...");
  try {
    transactions = await listTransactions();
    renderTransactions(document.getElementById("transactionSearch").value.trim());
  } catch (error) {
    setEmptyRow(body, 9, "Unable to load transactions.");
    showNotice(error.message || "Unable to load transactions.", "danger");
  }
}

export async function initTransactions() {
  editModal = new Modal(document.getElementById("editTransactionModal"));
  const createSupplierModalElement = document.getElementById("createSupplierModal");
  createSupplierModal = new Modal(createSupplierModalElement);
  createSupplierModalElement.addEventListener("hide.bs.modal", (event) => {
    if (supplierCreateBusy) event.preventDefault();
  });
  clients = await listClientsForForms();
  invoices = await listInvoices();
  try {
    suppliers = await listActiveSuppliers();
    document.getElementById("transactionSupplierHelp").textContent = suppliers.length
      ? "Links this expense to a supplier only; it does not create a bill or record a payment."
      : "No suppliers yet. Add one here; linking an expense does not create a bill or record a payment.";
  } catch (error) {
    suppliers = [];
    document.getElementById("transactionSupplierHelp").textContent = "Supplier choices could not be loaded. Check your connection and supplier database permissions.";
    console.error("Unable to load suppliers for transactions:", error);
  }
  setSupplierOptions(document.getElementById("transactionSupplier"));
  setSupplierOptions(document.getElementById("editTransactionSupplier"));
  const clientOptions = [{ id: "__new__", label: "Add a new client..." }, ...clients.map((client) => ({ id: client.id, label: client.full_name }))];
  setSelectOptions(document.getElementById("transactionClient"), clientOptions, "Select a client");
  const clientSelect = document.getElementById("transactionClient");
  const editClientSelect = document.getElementById("editTransactionClient");
  const editClientOptions = clients.map((client) => ({ id: client.id, label: client.full_name }));
  setSelectOptions(editClientSelect, editClientOptions, "No client");
  const newClientWrap = document.getElementById("transactionNewClientWrap");
  const newClientInput = document.getElementById("transactionNewClient");
  clientSelect.addEventListener("change", () => {
    const isNew = clientSelect.value === "__new__";
    newClientWrap.classList.toggle("d-none", !isNew);
    newClientInput.required = isNew;
    if (!isNew) newClientInput.value = "";
  });
  document.getElementById("transactionForm").addEventListener("reset", () => {
    newClientWrap.classList.add("d-none");
    newClientInput.required = false;
    document.getElementById("transactionSupplierWrap").classList.add("d-none");
    document.getElementById("transactionSupplier").value = "";
    setTimeout(() => syncClientRequirement(transactionType, transactionSupplier, clientSelect), 0);
  });
  const transactionType = document.getElementById("transactionType");
  const transactionSupplier = document.getElementById("transactionSupplier");
  const transactionSupplierWrap = document.getElementById("transactionSupplierWrap");
  syncClientRequirement(transactionType, transactionSupplier, clientSelect);
  transactionType.addEventListener("change", () => {
    syncSupplierFields(transactionType, transactionSupplier, transactionSupplierWrap);
    syncClientRequirement(transactionType, transactionSupplier, clientSelect);
  });
  transactionSupplier.addEventListener("change", () => syncClientRequirement(transactionType, transactionSupplier, clientSelect));
  const editTransactionType = document.getElementById("editTransactionType");
  const editTransactionSupplier = document.getElementById("editTransactionSupplier");
  const editSupplierWrap = document.getElementById("editTransactionSupplierWrap");
  editTransactionType.addEventListener("change", () => {
    syncSupplierFields(editTransactionType, editTransactionSupplier, editSupplierWrap);
    syncClientRequirement(editTransactionType, editTransactionSupplier, editClientSelect);
  });
  editTransactionSupplier.addEventListener("change", () => syncClientRequirement(editTransactionType, editTransactionSupplier, editClientSelect));
  window.addEventListener("lmg:suppliers-changed", async () => {
    try {
      const createSelection = transactionSupplier.value;
      const editSelection = editTransactionSupplier.value;
      suppliers = await listActiveSuppliers();
      setSupplierOptions(transactionSupplier, createSelection);
      setSupplierOptions(editTransactionSupplier, editSelection);
    } catch (error) {
      console.error("Unable to refresh supplier options:", error);
    }
  });

  document.getElementById("addSupplierFromTransaction").addEventListener("click", () => {
    supplierModalTarget = "create";
    createSupplierModal.show();
  });
  document.getElementById("addSupplierFromEdit").addEventListener("click", () => {
    supplierModalTarget = "edit";
    createSupplierModal.show();
  });
  document.getElementById("createSupplierForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    if (supplierCreateBusy) return;
    supplierCreateBusy = true;
    const form = event.currentTarget;
    setFormBusy(form, true, "Saving...");
    try {
      const supplier = await createSupplierRecord({
        businessName: document.getElementById("newSupplierBusinessName").value.trim(),
        contactPerson: document.getElementById("newSupplierContact").value.trim(),
        phone: document.getElementById("newSupplierPhone").value.trim(),
        email: document.getElementById("newSupplierEmail").value.trim(),
        address: document.getElementById("newSupplierAddress").value.trim(),
        notes: document.getElementById("newSupplierNotes").value.trim(),
      });
      suppliers.push(supplier);
      suppliers.sort((first, second) => first.business_name.localeCompare(second.business_name));
      const selectedValue = String(supplier.id);
      setSupplierOptions(transactionSupplier, supplierModalTarget === "create" ? selectedValue : transactionSupplier.value);
      setSupplierOptions(editTransactionSupplier, supplierModalTarget === "edit" ? selectedValue : editTransactionSupplier.value);
      if (supplierModalTarget === "edit") {
        editTransactionType.value = "expense";
        syncSupplierFields(editTransactionType, editTransactionSupplier, editSupplierWrap);
        syncClientRequirement(editTransactionType, editTransactionSupplier, editClientSelect);
      } else {
        transactionType.value = "expense";
        syncSupplierFields(transactionType, transactionSupplier, transactionSupplierWrap);
        syncClientRequirement(transactionType, transactionSupplier, clientSelect);
      }
      form.reset();
      supplierCreateBusy = false;
      createSupplierModal.hide();
      showNotice("Supplier added and selected for this expense.");
      window.dispatchEvent(new Event("lmg:suppliers-changed"));
    } catch (error) {
      showNotice(error.message || "Unable to create supplier.", "danger");
    } finally {
      supplierCreateBusy = false;
      setFormBusy(form, false);
    }
  });
  const invoiceOptions = invoices.map((invoice) => ({ id: invoice.id, label: invoice.invoice_number }));
  setSelectOptions(document.getElementById("transactionInvoice"), invoiceOptions, "No linked invoice");
  window.addEventListener("lmg:clients-changed", async () => {
    clients = await listClientsForForms();
    const selected = clientSelect.value;
    setSelectOptions(document.getElementById("transactionClient"), [
      { id: "__new__", label: "Add a new client..." },
      ...clients.map((client) => ({ id: client.id, label: client.full_name })),
    ], "Select a client", selected);
    const selectedEditClient = editClientSelect.value;
    setSelectOptions(editClientSelect, clients.map((client) => ({ id: client.id, label: client.full_name })), "No client", selectedEditClient);
  });
  window.addEventListener("lmg:invoices-changed", async () => {
    invoices = await listInvoices();
    const invoiceOptions = invoices.map((invoice) => ({ id: invoice.id, label: invoice.invoice_number }));
    setSelectOptions(document.getElementById("transactionInvoice"), invoiceOptions, "No linked invoice");
    setSelectOptions(document.getElementById("editTransactionInvoice"), invoiceOptions, "No linked invoice");
  });
  document.getElementById("transactionDate").value = new Date().toISOString().slice(0, 10);

  const form = document.getElementById("transactionForm");
  let transactionRequestKey = makeRequestKey();
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    setFormBusy(form, true);
    try {
      const values = Object.fromEntries(new FormData(form));
      await createTransaction({
        clientId: values.client_id && values.client_id !== "__new__" ? Number(values.client_id) : null,
        clientName: values.client_name?.trim(),
        amount: Number(values.amount),
        type: values.type,
        paymentMethod: values.payment_method,
        description: values.description.trim(),
        date: values.date,
        invoiceId: values.invoice_id,
        requestKey: transactionRequestKey,
        supplierId: values.type === "expense" ? transactionSupplier.value || null : null,
        supplierName: values.type === "expense" && transactionSupplier.value ? transactionSupplier.selectedOptions[0]?.textContent || null : null,
      });
      transactionRequestKey = makeRequestKey();
      if (values.client_id === "__new__") window.dispatchEvent(new Event("lmg:clients-changed"));
      form.reset();
      document.getElementById("transactionDate").value = new Date().toISOString().slice(0, 10);
      showNotice("Transaction added.");
      window.dispatchEvent(new Event("lmg:transactions-changed"));
      await loadTransactions();
    } catch (error) {
      showNotice(error.message || "Unable to add transaction.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });

  document.getElementById("editTransactionForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formEl = event.currentTarget;
    setFormBusy(formEl, true);
    try {
      await updateTransaction({
        id: Number(document.getElementById("editTransactionId").value),
        clientId: editClientSelect.value ? Number(editClientSelect.value) : null,
        amount: Number(document.getElementById("editTransactionAmount").value),
        type: document.getElementById("editTransactionType").value,
        paymentMethod: document.getElementById("editTransactionMethod").value,
        date: document.getElementById("editTransactionDate").value,
        description: document.getElementById("editTransactionDescription").value.trim(),
        invoiceId: document.getElementById("editTransactionInvoice").value,
        supplierId: editTransactionType.value === "expense" ? editTransactionSupplier.value || null : null,
        supplierName: editTransactionType.value === "expense" && editTransactionSupplier.value ? editTransactionSupplier.selectedOptions[0]?.textContent || null : null,
      });
      editModal.hide();
      showNotice("Transaction updated.");
      window.dispatchEvent(new Event("lmg:transactions-changed"));
      await loadTransactions();
    } catch (error) {
      showNotice(error.message || "Unable to update transaction.", "danger");
    } finally {
      setFormBusy(formEl, false);
    }
  });
  document.getElementById("transactionSearch").addEventListener("input", debounce((event) => renderTransactions(event.target.value.trim()), 200));
  document.getElementById("printTransactions").addEventListener("click", printTransactions);
  await loadTransactions();
}
