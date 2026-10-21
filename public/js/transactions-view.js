import {
  createTransaction,
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
let transactions = [];
let editModal;
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

// Receipts open on about:blank, so relative asset paths must be resolved against
// the application URL before they are written into the popup.
const assetUrl = (path) => new URL(path, document.baseURI).href;
// The popup shares this document's origin, so any interpolated record data must be escaped.
const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => (
  { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]
));
const PAYMENT_METHOD_LABELS = { cash: "Cash", mobile: "Mobile Money", card: "Card" };

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
  const filtered = transactions.filter((transaction) => {
    const search = `${transaction.clients?.full_name || ""} ${transaction.type} ${transaction.invoices?.invoice_number || ""} ${transaction.description || ""}`;
    return search.toLowerCase().includes(query.toLowerCase());
  });
  if (!filtered.length) {
    setEmptyRow(body, 8, query ? "No transactions match this search." : "No transactions have been recorded yet.");
    return;
  }
  body.replaceChildren(...filtered.map((transaction) => {
    const row = document.createElement("tr");
    const values = [transaction.clients?.full_name, transaction.type, transaction.invoices?.invoice_number, `${currencySymbol}${Number(transaction.amount).toFixed(2)}`, transaction.date, transaction.payment_method, transaction.description];
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

function openEdit(transaction) {
  document.getElementById("editTransactionId").value = transaction.id;
  document.getElementById("editTransactionClient").value = transaction.clients?.full_name || "";
  document.getElementById("editTransactionAmount").value = transaction.amount;
  document.getElementById("editTransactionType").value = transaction.type;
  document.getElementById("editTransactionMethod").value = transaction.payment_method || "";
  document.getElementById("editTransactionDate").value = transaction.date;
  document.getElementById("editTransactionDescription").value = transaction.description || "";
  setSelectOptions(document.getElementById("editTransactionInvoice"), invoices.map((invoice) => ({ id: invoice.id, label: invoice.invoice_number })), "No linked invoice", transaction.invoice_id || "");
  editModal.show();
}

async function loadTransactions() {
  const body = document.getElementById("transactionRows");
  setEmptyRow(body, 8, "Loading transactions...");
  try {
    transactions = await listTransactions();
    renderTransactions(document.getElementById("transactionSearch").value.trim());
  } catch (error) {
    setEmptyRow(body, 8, "Unable to load transactions.");
    showNotice(error.message || "Unable to load transactions.", "danger");
  }
}

export async function initTransactions() {
  editModal = new Modal(document.getElementById("editTransactionModal"));
  clients = await listClientsForForms();
  invoices = await listInvoices();
  const clientOptions = [{ id: "__new__", label: "Add a new client..." }, ...clients.map((client) => ({ id: client.id, label: client.full_name }))];
  setSelectOptions(document.getElementById("transactionClient"), clientOptions, "Select a client");
  const clientSelect = document.getElementById("transactionClient");
  const newClientWrap = document.getElementById("transactionNewClientWrap");
  const newClientInput = document.getElementById("transactionNewClient");
  clientSelect.addEventListener("change", () => {
    const isNew = clientSelect.value === "__new__";
    newClientWrap.classList.toggle("d-none", !isNew);
    newClientInput.required = isNew;
  });
  document.getElementById("transactionForm").addEventListener("reset", () => {
    newClientWrap.classList.add("d-none");
    newClientInput.required = false;
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
        clientId: values.client_id === "__new__" ? null : Number(values.client_id),
        clientName: values.client_name?.trim(),
        amount: Number(values.amount),
        type: values.type,
        paymentMethod: values.payment_method,
        description: values.description.trim(),
        date: values.date,
        invoiceId: values.invoice_id,
        requestKey: transactionRequestKey,
      });
      transactionRequestKey = makeRequestKey();
      if (values.client_id === "__new__") window.dispatchEvent(new Event("lmg:clients-changed"));
      form.reset();
      document.getElementById("transactionDate").value = new Date().toISOString().slice(0, 10);
      showNotice("Transaction added.");
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
        amount: Number(document.getElementById("editTransactionAmount").value),
        type: document.getElementById("editTransactionType").value,
        paymentMethod: document.getElementById("editTransactionMethod").value,
        date: document.getElementById("editTransactionDate").value,
        description: document.getElementById("editTransactionDescription").value.trim(),
        invoiceId: document.getElementById("editTransactionInvoice").value,
      });
      editModal.hide();
      showNotice("Transaction updated.");
      await loadTransactions();
    } catch (error) {
      showNotice(error.message || "Unable to update transaction.", "danger");
    } finally {
      setFormBusy(formEl, false);
    }
  });
  document.getElementById("transactionSearch").addEventListener("input", debounce((event) => renderTransactions(event.target.value.trim()), 200));
  await loadTransactions();
}
