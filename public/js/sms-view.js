// EDGE FUNCTION DEPENDENCY: sms-send
// This module requires the sms-send Edge Function for sending SMS messages.
// The Edge Function is required because it uses the SMS provider API key (SMSONLINE_API_KEY)
// stored in function secrets. This credential cannot be exposed to the frontend.
import {
  checkSmsConnectivity,
  listClientsForForms,
  listSmsMessages,
  sendSms,
} from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";
import { debounce } from "./util.js";

const queueName = "lmg-sms-offline-queue";
let clients = [];
let messages = [];

function openQueueDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open("lmg-business-offline", 1);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(queueName)) request.result.createObjectStore(queueName, { keyPath: "id" });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function queueOffline(items) {
  const db = await openQueueDb();
  await new Promise((resolve, reject) => {
    const transaction = db.transaction(queueName, "readwrite");
    const store = transaction.objectStore(queueName);
    items.forEach((item) => store.put({ ...item, id: crypto.randomUUID(), queued_at: new Date().toISOString() }));
    transaction.oncomplete = resolve;
    transaction.onerror = () => reject(transaction.error);
  });
  db.close();
}

async function readQueue() {
  const db = await openQueueDb();
  const items = await new Promise((resolve, reject) => {
    const request = db.transaction(queueName, "readonly").objectStore(queueName).getAll();
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  db.close();
  return items;
}

async function deleteQueued(id) {
  const db = await openQueueDb();
  await new Promise((resolve, reject) => {
    const transaction = db.transaction(queueName, "readwrite");
    transaction.objectStore(queueName).delete(id);
    transaction.oncomplete = resolve;
    transaction.onerror = () => reject(transaction.error);
  });
  db.close();
}

function renderMessages(query = "") {
  const body = document.getElementById("smsRows");
  const filtered = messages.filter((message) => `${message.phone} ${message.status} ${message.provider} ${message.message}`.toLowerCase().includes(query.toLowerCase()));
  if (!filtered.length) {
    setEmptyRow(body, 5, query ? "No messages match this search." : "No SMS messages have been sent.");
    return;
  }
  body.replaceChildren(...filtered.map((message) => {
    const row = document.createElement("tr");
    [message.phone, message.status, message.provider, message.message, new Date(message.created_at).toLocaleString()].forEach((value) => {
      const cell = document.createElement("td");
      cell.textContent = value ?? "";
      row.append(cell);
    });
    return row;
  }));
}

async function loadMessages() {
  try {
    messages = await listSmsMessages();
    renderMessages(document.getElementById("smsSearch").value.trim());
  } catch (error) {
    setEmptyRow(document.getElementById("smsRows"), 5, "Unable to load SMS logs.");
    showNotice(error.message || "Unable to load SMS logs.", "danger");
  }
}

function recipientList() {
  const selected = [...document.getElementById("smsClients").selectedOptions].map((option) => {
    const client = clients.find((item) => String(item.id) === option.value);
    return client?.phone ? { phone: client.phone, client_id: client.id } : null;
  }).filter(Boolean);
  const custom = document.getElementById("smsPhones").value.split(/[\s,;]+/).map((phone) => phone.trim()).filter(Boolean).map((phone) => ({ phone, client_id: null }));
  const seen = new Set();
  return [...selected, ...custom].filter((recipient) => {
    const digits = recipient.phone.replace(/\D/g, "");
    if (!digits || seen.has(digits)) return false;
    seen.add(digits);
    return true;
  });
}

async function flushQueue() {
  if (!navigator.onLine) return;
  const queue = await readQueue();
  if (!queue.length) return;
  let delivered = 0;
  for (const item of queue) {
    try {
      await sendSms(item);
      await deleteQueued(item.id);
      delivered++;
    } catch {
      break;
    }
  }
  if (delivered) {
    showNotice(`${delivered} queued SMS message${delivered === 1 ? "" : "s"} sent.`);
    await loadMessages();
  }
}

export async function initSms() {
  clients = await listClientsForForms();
  const clientSelect = document.getElementById("smsClients");
  clients.forEach((client) => {
    const option = document.createElement("option");
    option.value = client.id;
    option.textContent = `${client.full_name}${client.phone ? ` (${client.phone})` : " (no phone)"}`;
    option.disabled = !client.phone;
    clientSelect.append(option);
  });

  const form = document.getElementById("smsForm");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const recipients = recipientList();
    const text = document.getElementById("smsMessage").value.trim();
    if (!recipients.length || !text) {
      showNotice("Select clients or enter phone numbers and a message.", "warning");
      return;
    }
    if (recipients.length > 200) {
      showNotice("Bulk send is limited to 200 recipients.", "warning");
      return;
    }
    const scheduleValue = document.getElementById("smsSchedule").value;
    const scheduledFor = scheduleValue ? new Date(scheduleValue).toISOString() : null;
    const payloads = recipients.map((recipient) => ({
      phone: recipient.phone,
      client_id: recipient.client_id,
      message: text,
      scheduled_for: scheduledFor,
    }));
    if (!navigator.onLine) {
      try {
        await queueOffline(payloads);
        showNotice(`${payloads.length} SMS message${payloads.length === 1 ? "" : "s"} queued on this device for when it is online.`, "info");
        form.reset();
      } catch (error) {
        showNotice(error.message || "Unable to queue messages on this device.", "danger");
      }
      return;
    }

    setFormBusy(form, true, "Sending...");
    let sent = 0;
    let queued = 0;
    let failed = 0;
    let nextIndex = 0;
    const worker = async () => {
      while (nextIndex < payloads.length) {
        const index = nextIndex++;
        try {
          const result = await sendSms(payloads[index]);
          if (result.status === "queued") queued++;
          else sent++;
        } catch {
          failed++;
        }
      }
    };
    try {
      await Promise.all(Array.from({ length: Math.min(5, payloads.length) }, worker));
      showNotice(`Sent ${sent}; queued ${queued}; failed ${failed}.`, failed ? "warning" : "success");
      if (sent || queued) form.reset();
      await loadMessages();
    } finally {
      setFormBusy(form, false);
    }
  });

  document.getElementById("smsConnectivity").addEventListener("click", async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      const result = await checkSmsConnectivity();
      showNotice(`SMS provider connectivity verified: ${result.host}`);
    } catch (error) {
      showNotice(error.message || "SMS provider connection failed.", "danger");
    } finally {
      button.disabled = false;
    }
  });
  document.getElementById("smsSearch").addEventListener("input", debounce((event) => renderMessages(event.target.value.trim()), 200));
  window.addEventListener("online", flushQueue);
  await loadMessages();
  await flushQueue();
}