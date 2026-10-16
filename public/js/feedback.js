let noticeTimer;

export function showNotice(text, type = "success") {
  const notice = document.getElementById("notice");
  notice.textContent = text;
  notice.className = `alert alert-${type}`;
  clearTimeout(noticeTimer);
  noticeTimer = setTimeout(() => notice.classList.add("d-none"), 6000);
}

export function setFormBusy(form, busy, label = "Please wait...") {
  const button = form.querySelector("button[type='submit']");
  if (!button) return;
  if (!button.dataset.idleLabel) button.dataset.idleLabel = button.textContent;
  button.disabled = busy;
  button.textContent = busy ? label : button.dataset.idleLabel;
}

export function setEmptyRow(tbody, columns, text) {
  const row = document.createElement("tr");
  const cell = document.createElement("td");
  cell.colSpan = columns;
  cell.className = "text-muted";
  cell.textContent = text;
  row.append(cell);
  tbody.replaceChildren(row);
}