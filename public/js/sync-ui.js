import { getPendingSyncCount, getSyncStatus, attemptSync, downloadLatestData } from "./sync-engine.js";
import { getPendingChanges } from "./offline-data.js";

/**
 * Sync UI Component
 * Displays connection status, sync progress, and manual sync controls
 */

/**
 * Initialize sync UI
 */
export function initSyncUI() {
  // Create sync status indicator in header
  createSyncIndicator();

  // Update status periodically
  setInterval(updateSyncUI, 5000);

  // Initial update
  updateSyncUI();

  // Listen for sync events
  window.addEventListener("sync-complete", handleSyncComplete);
  window.addEventListener("online", updateSyncUI);
  window.addEventListener("offline", updateSyncUI);
}

/**
 * Create sync status indicator in header
 */
function createSyncIndicator() {
  const header = document.querySelector("header");
  if (!header) return;

  const indicator = document.createElement("div");
  indicator.id = "syncStatusIndicator";
  indicator.className = "sync-indicator sync-indicator--online";
  indicator.innerHTML = `
    <div class="sync-indicator-icon">
      <i class="bi bi-wifi"></i>
    </div>
    <div class="sync-indicator-content">
      <span id="syncStatusText" class="sync-indicator-text">Online</span>
      <span id="syncPendingCount" class="sync-indicator-count"></span>
    </div>
    <button id="syncManualButton" class="sync-indicator-btn btn btn-sm btn-outline-light" type="button" title="Sync Now">
      <i class="bi bi-arrow-clockwise"></i>
    </button>
  `;

  // Insert before the header name
  const headerContent = header.querySelector(".d-flex.align-items-center.gap-3");
  if (headerContent) {
    headerContent.insertBefore(indicator, headerContent.firstChild);
  }

  // Add click handler for manual sync
  const manualButton = document.getElementById("syncManualButton");
  if (manualButton) {
    manualButton.addEventListener("click", handleManualSync);
  }
}

/**
 * Update sync UI
 */
async function updateSyncUI() {
  const status = getSyncStatus();
  const indicator = document.getElementById("syncStatusIndicator");
  const text = document.getElementById("syncStatusText");
  const count = document.getElementById("syncPendingCount");
  const button = document.getElementById("syncManualButton");

  if (!indicator) return;

  // Update status class
  indicator.className = `sync-indicator sync-indicator--${status}`;

  // Update text
  const statusMap = {
    online: "Online",
    offline: "Offline Mode",
    syncing: "Synchronizing...",
    synced: "All Changes Synced",
    partial: "Some Changes Pending",
    error: "Sync Failed",
  };
  if (text) text.textContent = statusMap[status] || status;

  // Update pending count
  if (count) {
    const pending = await getPendingSyncCount();
    count.textContent = pending > 0 ? `(${pending})` : "";
  }

  // Update button state
  if (button) {
    button.disabled = status === "syncing" || status === "offline";
    if (status === "syncing") {
      button.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
    } else {
      button.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
    }
  }
}

/**
 * Handle manual sync button click
 */
async function handleManualSync() {
  const button = document.getElementById("syncManualButton");
  if (button) button.disabled = true;

  try {
    await attemptSync();
  } catch (error) {
    console.error("Manual sync failed:", error);
    showSyncError(error.message);
  } finally {
    if (button) button.disabled = false;
  }
}

/**
 * Handle sync complete event
 */
function handleSyncComplete(event) {
  const { processed, failed } = event.detail;

  if (failed > 0) {
    showSyncError(`${failed} operations failed to sync`);
  } else if (processed > 0) {
    showSyncSuccess(`${processed} changes synced successfully`);
  }
}

/**
 * Show sync success notification
 */
function showSyncSuccess(message) {
  const notice = document.getElementById("notice");
  if (notice) {
    notice.className = "alert alert-success";
    notice.textContent = message;
    notice.classList.remove("d-none");

    setTimeout(() => {
      notice.classList.add("d-none");
    }, 3000);
  }
}

/**
 * Show sync error notification
 */
function showSyncError(message) {
  const notice = document.getElementById("notice");
  if (notice) {
    notice.className = "alert alert-danger";
    notice.textContent = message;
    notice.classList.remove("d-none");

    setTimeout(() => {
      notice.classList.add("d-none");
    }, 5000);
  }
}

/**
 * Create sync status modal (for viewing pending changes)
 */
export function createSyncModal() {
  // Check if modal already exists
  if (document.getElementById("syncModal")) return;

  const modal = document.createElement("div");
  modal.id = "syncModal";
  modal.className = "modal fade";
  modal.setAttribute("tabindex", "-1");
  modal.setAttribute("aria-hidden", "true");
  modal.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Sync Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="syncModalContent">Loading...</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" id="syncModalSyncNow">Sync Now</button>
          <button type="button" class="btn btn-outline-light" id="syncModalDownload">Download Latest Data</button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  // Add event listeners
  const syncNowButton = document.getElementById("syncModalSyncNow");
  if (syncNowButton) {
    syncNowButton.addEventListener("click", async () => {
      await handleManualSync();
      await updateSyncModalContent();
    });
  }

  const downloadButton = document.getElementById("syncModalDownload");
  if (downloadButton) {
    downloadButton.addEventListener("click", async () => {
      downloadButton.disabled = true;
      downloadButton.textContent = "Downloading...";
      try {
        await downloadLatestData();
        showSyncSuccess("Latest data downloaded");
      } catch (error) {
        showSyncError("Failed to download data");
      } finally {
        downloadButton.disabled = false;
        downloadButton.textContent = "Download Latest Data";
      }
    });
  }
}

/**
 * Update sync modal content
 */
async function updateSyncModalContent() {
  const content = document.getElementById("syncModalContent");
  if (!content) return;

  const pending = await getPendingChanges();
  const status = getSyncStatus();

  let html = `
    <div class="mb-3">
      <strong>Connection Status:</strong>
      <span class="badge ${status === 'online' ? 'bg-success' : status === 'offline' ? 'bg-warning' : 'bg-info'}">
        ${status.charAt(0).toUpperCase() + status.slice(1)}
      </span>
    </div>
    <div class="mb-3">
      <strong>Pending Changes:</strong>
    </div>
    <ul class="list-group mb-3">
      <li class="list-group-item d-flex justify-content-between align-items-center">
        Clients
        <span class="badge bg-primary">${pending.clients}</span>
      </li>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        Products
        <span class="badge bg-primary">${pending.products}</span>
      </li>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        Transactions
        <span class="badge bg-primary">${pending.transactions}</span>
      </li>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        Invoices
        <span class="badge bg-primary">${pending.invoices}</span>
      </li>
    </ul>
    <div class="alert alert-info">
      <strong>Total:</strong> ${pending.total} pending changes
    </div>
  `;

  if (pending.total === 0) {
    html += `
      <div class="alert alert-success">
        <i class="bi bi-check-circle"></i> All changes are synced
      </div>
    `;
  }

  content.innerHTML = html;
}

/**
 * Show sync modal
 */
export function showSyncModal() {
  createSyncModal();
  const modal = document.getElementById("syncModal");
  if (modal) {
    const bsModal = new bootstrap.Modal(modal);
    updateSyncModalContent();
    bsModal.show();
  }
}
