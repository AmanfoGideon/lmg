// EDGE FUNCTION DEPENDENCIES: email-send, sms-send
// This module requires Edge Functions for sending invoice emails and SMS reminders.
// email-send requires Resend API key; sms-send requires SMS provider API key.
// These credentials cannot be exposed to the frontend.
import {
  createInvoice,
  deleteInvoice,
  getInvoiceDetails,
  listClientsForForms,
  listInvoices,
  recordInvoicePayment,
  sendInvoiceEmail,
  sendSms,
} from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

let invoices = [];
let clients = [];
const currencySymbol = globalThis.APP_CONFIG?.currencySymbol || "₵";
const currency = (amount) => `${currencySymbol}${Number(amount || 0).toFixed(2)}`;
// The print popup opens on about:blank, so relative asset paths must be resolved
// against the application URL before they are written into it.
const assetUrl = (path) => new URL(path, document.baseURI).href;
// The popup shares this document's origin, so any interpolated record data must be escaped.
const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => (
  { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]
));

function makeOption(value, label) {
  const option = document.createElement("option");
  option.value = value;
  option.textContent = label;
  return option;
}

function makeRequestKey() {
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), (value) => value.toString(16).padStart(2, "0")).join("");
}

function invoiceRow(invoice) {
  const row = document.createElement("tr");
  [invoice.invoice_number, invoice.clients?.full_name || "", invoice.invoice_date, invoice.due_date || "", currency(invoice.total)].forEach((value) => {
    const cell = document.createElement("td");
    cell.textContent = value;
    row.append(cell);
  });
  const statusCell = document.createElement("td");
  const status = document.createElement("span");
  status.className = `badge ${invoice.status === "paid" ? "bg-success" : invoice.status === "partial" ? "bg-warning text-dark" : "bg-secondary"}`;
  status.textContent = invoice.status;
  statusCell.append(status);
  row.append(statusCell);
  const actions = document.createElement("td");
  actions.className = "d-flex gap-2 align-items-center";
  const view = document.createElement("button");
  view.className = "btn btn-sm btn-outline-light";
  view.type = "button";
  view.textContent = "View";
  view.addEventListener("click", () => showInvoice(invoice.id));
  actions.append(view);
  const remove = document.createElement("button");
  remove.className = "btn btn-sm btn-outline-danger";
  remove.type = "button";
  remove.textContent = "Delete";
  remove.disabled = invoice.status !== "unpaid";
  remove.title = remove.disabled ? "Invoices with recorded payments cannot be deleted." : "Delete invoice";
  remove.addEventListener("click", async () => {
    if (invoice.status !== "unpaid" || !window.confirm("Delete this invoice? This cannot be undone.")) return;
    remove.disabled = true;
    try {
      await deleteInvoice(invoice.id);
      document.getElementById("invoiceDetails").classList.add("d-none");
      showNotice("Invoice deleted.");
      await loadInvoices();
    } catch (error) {
      showNotice(error.message || "Unable to delete invoice.", "danger");
      remove.disabled = false;
    }
  });
  actions.append(remove);
  row.append(actions);
  return row;
}

async function loadInvoices() {
  const body = document.getElementById("invoiceRows");
  setEmptyRow(body, 7, "Loading invoices...");
  try {
    invoices = await listInvoices();
    if (!invoices.length) setEmptyRow(body, 7, "No invoices have been created yet.");
    else body.replaceChildren(...invoices.map(invoiceRow));
  } catch (error) {
    setEmptyRow(body, 7, "Unable to load invoices.");
    showNotice(error.message || "Unable to load invoices.", "danger");
  }
}

function addInvoiceItem(initial = {}) {
  const row = document.createElement("tr");
  const descriptionCell = document.createElement("td");
  const description = document.createElement("input");
  description.className = "form-control form-control-sm";
  description.name = "item_name";
  description.maxLength = 255;
  description.value = initial.name || "";
  description.required = true;
  descriptionCell.append(description);

  const quantityCell = document.createElement("td");
  const quantity = document.createElement("input");
  quantity.className = "form-control form-control-sm";
  quantity.name = "quantity";
  quantity.type = "number";
  quantity.min = "1";
  quantity.step = "1";
  quantity.value = initial.qty || 1;
  quantity.required = true;
  quantityCell.append(quantity);

  const priceCell = document.createElement("td");
  const price = document.createElement("input");
  price.className = "form-control form-control-sm";
  price.name = "price";
  price.type = "number";
  price.min = "0";
  price.step = "0.01";
  price.value = initial.price ?? "0.00";
  price.required = true;
  priceCell.append(price);

  const removeCell = document.createElement("td");
  const remove = document.createElement("button");
  remove.className = "btn btn-sm btn-outline-danger";
  remove.type = "button";
  remove.setAttribute("aria-label", "Remove invoice item");
  remove.innerHTML = '<i class="bi bi-x-lg"></i>';
  remove.addEventListener("click", () => { row.remove(); recalculateTotal(); });
  removeCell.append(remove);
  row.append(descriptionCell, quantityCell, priceCell, removeCell);
  row.querySelectorAll("input").forEach((input) => input.addEventListener("input", recalculateTotal));
  document.getElementById("invoiceItemRows").append(row);
  recalculateTotal();
}

function recalculateTotal() {
  const subtotal = [...document.querySelectorAll("#invoiceItemRows tr")].reduce((sum, row) => {
    const quantity = Number(row.querySelector("[name='quantity']").value || 0);
    const price = Number(row.querySelector("[name='price']").value || 0);
    return sum + quantity * price;
  }, 0);
  const tax = Number(document.getElementById("invoiceTax").value || 0);
  document.getElementById("invoiceTotal").value = currency(subtotal + tax);
}

function buildPrintView(invoice) {
  const windowRef = window.open("", "_blank");
  if (!windowRef) {
    showNotice("Allow pop-ups to print this invoice.", "warning");
    return;
  }
  
  const itemsHtml = invoice.invoice_items.map(item => `
    <tr>
      <td>${escapeHtml(item.item_name)}</td>
      <td class="text-right">${escapeHtml(item.quantity)}</td>
      <td class="text-right">${currency(item.price)}</td>
      <td class="text-right">${currency(item.total)}</td>
    </tr>
  `).join('');

  const html = `
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>Invoice ${escapeHtml(invoice.invoice_number)}</title>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800;900&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="${assetUrl("css/invoice-premium.css")}">
    </head>
    <body onload="window.print()">
      <div class="invoice-paper">
        <div class="invoice-header">
          <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
              <img src="${assetUrl("images/logo.png")}" alt="LMG" style="width: 80px; margin-bottom: 15px;">
              <div class="brand-name">LMG Creative Media</div>
              <div class="invoice-meta">Professional Creative Solutions</div>
            </div>
            <div class="text-right">
              <div class="invoice-title">INVOICE</div>
              <div class="invoice-meta" style="font-weight: 700; color: #000;"># ${escapeHtml(invoice.invoice_number)}</div>
              <div class="invoice-meta">Date: ${escapeHtml(invoice.invoice_date)}</div>
              <div class="invoice-meta">Due: ${escapeHtml(invoice.due_date || 'N/A')}</div>
            </div>
          </div>
        </div>

        <div class="bill-to-section">
          <div class="bill-to-label">Bill To</div>
          <div class="bill-to-name">${escapeHtml(invoice.clients?.full_name || 'Client')}</div>
          <div class="invoice-meta">${escapeHtml(invoice.clients?.email || '')}</div>
          <div class="invoice-meta">${escapeHtml(invoice.clients?.phone || '')}</div>
          <div class="invoice-meta">${escapeHtml(invoice.clients?.address || '')}</div>
        </div>

        <table class="invoice-table">
          <thead>
            <tr>
              <th>Description</th>
              <th class="text-right">Qty</th>
              <th class="text-right">Price</th>
              <th class="text-right">Total</th>
            </tr>
          </thead>
          <tbody>
            ${itemsHtml}
          </tbody>
        </table>

        <div class="invoice-totals">
          <div class="total-row">
            <span>Subtotal</span>
            <span>${currency(invoice.subtotal)}</span>
          </div>
          <div class="total-row">
            <span>Tax</span>
            <span>${currency(invoice.tax)}</span>
          </div>
          <div class="total-row grand-total">
            <span>Grand Total</span>
            <span>${currency(invoice.total)}</span>
          </div>
          <div class="total-row" style="margin-top: 10px; color: ${invoice.status === 'paid' ? '#22c55e' : '#666'}">
            <span>Status</span>
            <span style="text-transform: uppercase; font-weight: 700;">${escapeHtml(invoice.status)}</span>
          </div>
        </div>

        <div class="invoice-status-watermark">${escapeHtml(invoice.status)}</div>

        <div class="invoice-footer">
          <p>Thank you for your business!</p>
          <p>Payment should be made within 14 days. Please include invoice number in payment reference.</p>
          <p>&copy; ${new Date().getFullYear()} LMG Creative Media. All rights reserved.</p>
        </div>
      </div>
    </body>
    </html>
  `;

  windowRef.document.write(html);
  windowRef.document.close();
}

async function showInvoice(id) {
  const panel = document.getElementById("invoiceDetails");
  panel.classList.remove("d-none");
  panel.textContent = "Loading invoice...";
  try {
    const invoice = await getInvoiceDetails(id);
    const heading = document.createElement("div");
    heading.className = "d-flex justify-content-between align-items-start mb-3";
    const title = document.createElement("h2");
    title.className = "h5";
    title.textContent = `${invoice.invoice_number} · ${invoice.clients?.full_name || "Client removed"}`;
    const printButton = document.createElement("button");
    printButton.type = "button";
    printButton.className = "btn btn-sm btn-outline-light";
    printButton.innerHTML = '<i class="bi bi-printer"></i> Print';
    printButton.addEventListener("click", () => buildPrintView(invoice));
    heading.append(title, printButton);

    const itemTable = document.createElement("table");
    itemTable.className = "table table-sm table-brand";
    const header = document.createElement("tr");
    ["Description", "Qty", "Price", "Total"].forEach((label) => {
      const cell = document.createElement("th");
      cell.textContent = label;
      header.append(cell);
    });
    itemTable.append(header);
    invoice.invoice_items.forEach((item) => {
      const row = document.createElement("tr");
      [item.item_name, item.quantity, currency(item.price), currency(item.total)].forEach((value) => {
        const cell = document.createElement("td");
        cell.textContent = value;
        row.append(cell);
      });
      itemTable.append(row);
    });

    const summary = document.createElement("p");
    summary.textContent = `Subtotal ${currency(invoice.subtotal)} · Tax ${currency(invoice.tax)} · Total ${currency(invoice.total)} · ${invoice.status}`;
    const form = document.createElement("form");
    form.className = "row g-2 align-items-end";
    form.innerHTML = `<input type="hidden" name="invoice_id" value="${invoice.id}"><div class="col-md-3"><label class="form-label" for="paymentAmount">Payment amount</label><input class="form-control" id="paymentAmount" name="amount" type="number" min="0.01" step="0.01" required></div><div class="col-md-3"><label class="form-label" for="paymentMethod">Method</label><select class="form-select" id="paymentMethod" name="payment_method"><option value="">Not specified</option><option value="cash">Cash</option><option value="mobile">Mobile</option><option value="card">Card</option></select></div><div class="col-md-3"><label class="form-label" for="paymentDate">Date</label><input class="form-control" id="paymentDate" name="date" type="date" required></div><div class="col-md-3"><button class="btn btn-primary" type="submit">Record Payment</button></div>`;
    form.elements.date.value = new Date().toISOString().slice(0, 10);
    let paymentRequestKey = makeRequestKey();
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setFormBusy(form, true);
      try {
        const values = Object.fromEntries(new FormData(form));
        await recordInvoicePayment({
          invoiceId: invoice.id,
          amount: Number(values.amount),
          paymentMethod: values.payment_method,
          description: "Invoice payment",
          date: values.date,
          requestKey: paymentRequestKey,
        });
        paymentRequestKey = makeRequestKey();
        showNotice("Payment recorded. Invoice status updated.");
        await loadInvoices();
        await showInvoice(invoice.id);
      } catch (error) {
        showNotice(error.message || "Unable to record payment.", "danger");
      } finally {
        setFormBusy(form, false);
      }
    });

    const reminders = document.createElement("div");
    reminders.className = "row g-3 mt-2";
    const smsColumn = document.createElement("div");
    smsColumn.className = "col-lg-6";
    const smsForm = document.createElement("form");
    smsForm.className = "card p-3";
    smsForm.innerHTML = '<h3 class="h6">SMS Reminder</h3><label class="form-label" for="invoiceSmsPhone">Phone</label><input class="form-control mb-2" id="invoiceSmsPhone" name="phone"><label class="form-label" for="invoiceSmsText">Message</label><textarea class="form-control mb-2" id="invoiceSmsText" name="message" rows="3" required></textarea><button class="btn btn-outline-primary" type="submit">Send SMS</button>';
    smsForm.elements.phone.value = invoice.clients?.phone || "";
    smsForm.elements.message.value = `Hi ${invoice.clients?.full_name || ""}, this is a reminder for invoice ${invoice.invoice_number} of ${currency(invoice.total)} (status: ${invoice.status}). Please pay by ${invoice.due_date || ""}. Thank you.`;
    smsForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      setFormBusy(smsForm, true);
      try {
        await sendSms({ phone: smsForm.elements.phone.value.trim(), client_id: invoice.client_id, message: smsForm.elements.message.value.trim() });
        showNotice("SMS reminder sent.");
      } catch (error) {
        showNotice(error.message || "Unable to send SMS reminder.", "danger");
      } finally {
        setFormBusy(smsForm, false);
      }
    });
    smsColumn.append(smsForm);

    const emailColumn = document.createElement("div");
    emailColumn.className = "col-lg-6";
    const emailForm = document.createElement("form");
    emailForm.className = "card p-3";
    emailForm.innerHTML = '<h3 class="h6">Email Reminder</h3><label class="form-label" for="invoiceEmailSubject">Subject</label><input class="form-control mb-2" id="invoiceEmailSubject" name="subject" required><label class="form-label" for="invoiceEmailText">Message</label><textarea class="form-control mb-2" id="invoiceEmailText" name="text" rows="3" required></textarea><button class="btn btn-outline-primary" type="submit">Send Email</button>';
    emailForm.elements.subject.value = `Invoice ${invoice.invoice_number} Reminder`;
    emailForm.elements.text.value = `Hi ${invoice.clients?.full_name || ""}, this is a reminder for invoice ${invoice.invoice_number} of ${currency(invoice.total)} (status: ${invoice.status}). Please pay by ${invoice.due_date || ""}. Thank you.`;
    emailForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      setFormBusy(emailForm, true);
      try {
        await sendInvoiceEmail({ invoice_id: invoice.id, subject: emailForm.elements.subject.value.trim(), text: emailForm.elements.text.value.trim() });
        showNotice("Email reminder sent.");
      } catch (error) {
        showNotice(error.message || "Unable to send email reminder.", "danger");
      } finally {
        setFormBusy(emailForm, false);
      }
    });
    emailColumn.append(emailForm);
    reminders.append(smsColumn, emailColumn);
    panel.replaceChildren(heading, itemTable, summary, form, reminders);
  } catch (error) {
    panel.textContent = error.message || "Unable to load invoice.";
  }
}

export async function initInvoices() {
  clients = await listClientsForForms();
  const clientSelect = document.getElementById("invoiceClient");
  clientSelect.replaceChildren(makeOption("", "Select a client"), makeOption("__new__", "Add a new client..."));
  clients.forEach((client) => clientSelect.append(makeOption(String(client.id), client.full_name)));
  const newClientWrap = document.getElementById("invoiceNewClientWrap");
  const newClientInput = document.getElementById("invoiceNewClient");
  clientSelect.addEventListener("change", () => {
    const isNew = clientSelect.value === "__new__";
    newClientWrap.classList.toggle("d-none", !isNew);
    newClientInput.required = isNew;
  });
  window.addEventListener("lmg:clients-changed", async () => {
    const selected = clientSelect.value;
    clients = await listClientsForForms();
    clientSelect.replaceChildren(makeOption("", "Select a client"), makeOption("__new__", "Add a new client..."));
    clients.forEach((client) => clientSelect.append(makeOption(String(client.id), client.full_name)));
    if ([...clientSelect.options].some((option) => option.value === selected)) clientSelect.value = selected;
  });

  document.getElementById("toggleInvoiceForm").addEventListener("click", () => {
    const form = document.getElementById("invoiceForm");
    form.classList.toggle("d-none");
    if (!form.classList.contains("d-none") && !document.getElementById("invoiceItemRows").children.length) addInvoiceItem();
  });
  document.getElementById("addInvoiceItem").addEventListener("click", () => addInvoiceItem());
  document.getElementById("invoiceTax").addEventListener("input", recalculateTotal);
  document.getElementById("invoiceDate").value = new Date().toISOString().slice(0, 10);

  const form = document.getElementById("invoiceForm");
  let invoiceRequestKey = makeRequestKey();
  form.addEventListener("reset", () => {
    newClientWrap.classList.add("d-none");
    newClientInput.required = false;
  });
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    setFormBusy(form, true, "Creating...");
    try {
      const values = Object.fromEntries(new FormData(form));
      const items = [...document.querySelectorAll("#invoiceItemRows tr")].map((row) => ({
        name: row.querySelector("[name='item_name']").value.trim(),
        qty: Number(row.querySelector("[name='quantity']").value),
        price: Number(row.querySelector("[name='price']").value),
      }));
      await createInvoice({
        requestKey: invoiceRequestKey,
        clientId: values.client_id === "__new__" ? null : Number(values.client_id),
        clientName: values.client_id === "__new__" ? values.client_name.trim() : null,
        invoiceDate: values.invoice_date,
        dueDate: values.due_date,
        tax: Number(values.tax),
        notes: values.notes,
        items,
      });
      invoiceRequestKey = makeRequestKey();
      if (values.client_id === "__new__") window.dispatchEvent(new Event("lmg:clients-changed"));
      window.dispatchEvent(new Event("lmg:invoices-changed"));
      form.reset();
      document.getElementById("invoiceItemRows").replaceChildren();
      document.getElementById("invoiceDate").value = new Date().toISOString().slice(0, 10);
      document.getElementById("invoiceTotal").value = "";
      form.classList.add("d-none");
      showNotice("Invoice created and inventory updated.");
      await loadInvoices();
    } catch (error) {
      showNotice(error.message || "Unable to create invoice.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });
  await loadInvoices();
}
