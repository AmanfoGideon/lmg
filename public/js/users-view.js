// EDGE FUNCTION DEPENDENCY: admin-users
// This module requires the admin-users Edge Function for user management operations.
// The Edge Function is required because it uses Auth Admin API with service-role privileges
// to invite users, delete accounts, and update user status - operations that cannot be
// performed with the publishable/anon key from the frontend.
import { callAdminUsers } from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";
import { debounce } from "./util.js";

let users = [];

function searchQuery() {
  return (document.getElementById("userSearch")?.value || "").trim().toLowerCase();
}

// Filtering happens in memory so typing never re-invokes the admin edge function.
function renderUsers() {
  const body = document.getElementById("userRows");
  const query = searchQuery();
  const filtered = query
    ? users.filter((user) => [user.full_name, user.email, user.username, user.branch]
        .some((value) => (value || "").toLowerCase().includes(query)))
    : users;

  if (!filtered.length) {
    setEmptyRow(body, 7, query ? "No users match your search." : "No user accounts found.");
    return;
  }
  body.replaceChildren(...filtered.map((user) => {
    const row = document.createElement("tr");
    [user.full_name, user.email, user.username, user.role, user.status, user.branch].forEach((value) => {
      const cell = document.createElement("td");
      cell.textContent = value ?? "";
      row.append(cell);
    });
    const actions = document.createElement("td");
    actions.className = "d-flex gap-1";

    const edit = document.createElement("button");
    edit.className = "btn btn-sm btn-outline-light";
    edit.type = "button";
    edit.innerHTML = '<i class="bi bi-pencil"></i>';
    edit.title = "Edit User";
    edit.addEventListener("click", () => startEdit(user));
    actions.append(edit);

    if (user.status === "active") {
      const disable = document.createElement("button");
      disable.className = "btn btn-sm btn-outline-warning";
      disable.type = "button";
      disable.innerHTML = '<i class="bi bi-person-x"></i>';
      disable.title = "Disable User";
      disable.addEventListener("click", () => updateStatus(user, "inactive", disable));
      actions.append(disable);
    } else {
      const activate = document.createElement("button");
      activate.className = "btn btn-sm btn-outline-success";
      activate.type = "button";
      activate.innerHTML = '<i class="bi bi-person-check"></i>';
      activate.title = "Activate User";
      activate.addEventListener("click", () => updateStatus(user, "active", activate));
      actions.append(activate);
    }
    const remove = document.createElement("button");
    remove.className = "btn btn-sm btn-outline-danger";
    remove.type = "button";
    remove.innerHTML = '<i class="bi bi-trash"></i>';
    remove.title = "Delete User";
    remove.addEventListener("click", async () => {
      if (!window.confirm(`Delete the account for ${user.full_name}?`)) return;
      remove.disabled = true;
      try {
        await callAdminUsers({ action: "delete", user_id: user.id });
        showNotice("User account deleted.");
        await loadUsers();
      } catch (error) {
        showNotice(error.message || "Unable to delete account.", "danger");
      } finally {
        remove.disabled = false;
      }
    });
    actions.append(remove);
    row.append(actions);
    return row;
  }));
}

async function loadUsers() {
  const body = document.getElementById("userRows");
  setEmptyRow(body, 7, "Loading users...");
  try {
    const result = await callAdminUsers({ action: "list" });
    users = result.users || [];
    renderUsers();
  } catch (error) {
    setEmptyRow(body, 7, "Unable to load user accounts.");
    showNotice(error.message || "Unable to load user accounts.", "danger");
  }
}

function startEdit(user) {
  const form = document.getElementById("userInviteForm");
  const editId = document.getElementById("editUserId");
  const cancelBtn = document.getElementById("cancelUserEdit");
  const submitBtn = document.getElementById("userSubmitBtn");
  const passwordField = document.getElementById("passwordField");
  const passwordInput = document.getElementById("invitePassword");
  const passwordHelp = document.getElementById("passwordHelp");
  const methodGroup = document.querySelector('.btn-group[role="group"]');

  editId.value = user.id;
  form.full_name.value = user.full_name || "";
  form.username.value = user.username || "";
  form.email.value = user.email || "";
  form.role.value = user.role || "staff";
  form.status.value = user.status || "active";
  form.branch.value = user.branch || "";

  // UI changes for edit mode
  methodGroup.style.display = "none";
  passwordField.style.display = "block";
  passwordInput.required = false;
  passwordHelp.textContent = "Leave blank to keep current password";
  submitBtn.textContent = "Update User";
  cancelBtn.classList.remove("d-none");

  document.getElementById("userFormTitle").textContent = "Edit User";
  form.scrollIntoView({ behavior: 'smooth' });
}

function stopEdit() {
  const form = document.getElementById("userInviteForm");
  const editId = document.getElementById("editUserId");
  const cancelBtn = document.getElementById("cancelUserEdit");
  const submitBtn = document.getElementById("userSubmitBtn");
  const passwordField = document.getElementById("passwordField");
  const passwordInput = document.getElementById("invitePassword");
  const passwordHelp = document.getElementById("passwordHelp");
  const methodGroup = document.querySelector('.btn-group[role="group"]');
  const methodInvite = document.getElementById("methodInvite");

  form.reset();
  editId.value = "";
  methodGroup.style.display = "flex";
  methodInvite.checked = true;
  passwordField.style.display = "none";
  passwordInput.required = false;
  passwordHelp.textContent = "Minimum 6 characters";
  submitBtn.textContent = "Send Invitation";
  cancelBtn.classList.add("d-none");
  document.getElementById("userFormTitle").textContent = "Add User";
}

async function updateStatus(user, status, button) {
  button.disabled = true;
  try {
    await callAdminUsers({ action: "status", user_id: user.id, status });
    showNotice("User status updated.");
    await loadUsers();
  } catch (error) {
    showNotice(error.message || "Unable to update user status.", "danger");
  } finally {
    button.disabled = false;
  }
}

export async function initUsers() {
  const form = document.getElementById("userInviteForm");
  const passwordField = document.getElementById("passwordField");
  const passwordInput = document.getElementById("invitePassword");
  const submitBtn = document.getElementById("userSubmitBtn");
  const cancelBtn = document.getElementById("cancelUserEdit");
  const searchInput = document.getElementById("userSearch");

  // Search filters the already-loaded list in memory.
  searchInput?.addEventListener("input", debounce(renderUsers, 200));

  // Cancel edit
  cancelBtn?.addEventListener("click", stopEdit);

  // Toggle between invite and create modes
  document.querySelectorAll('input[name="userMethod"]').forEach((radio) => {
    radio.addEventListener("change", (event) => {
      if (event.target.value === "create") {
        passwordField.style.display = "block";
        passwordInput.required = true;
        submitBtn.textContent = "Create User";
      } else {
        passwordField.style.display = "none";
        passwordInput.required = false;
        passwordInput.value = "";
        submitBtn.textContent = "Send Invitation";
      }
    });
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const editId = document.getElementById("editUserId").value;
    const method = document.querySelector('input[name="userMethod"]:checked')?.value || (editId ? 'update' : 'invite');
    
    let action = method;
    if (editId) action = "update";
    else if (method === "create") action = "create";
    else action = "invite";

    const actionText = action === "update" ? "Updating..." : (action === "create" ? "Creating..." : "Sending...");
    setFormBusy(form, true, actionText);
    
    try {
      const values = Object.fromEntries(new FormData(form));
      const result = await callAdminUsers({ action, ...values });
      
      if (editId) {
        stopEdit();
      } else {
        form.reset();
        // Reset to default mode
        document.getElementById("methodInvite").checked = true;
        passwordField.style.display = "none";
        passwordInput.required = false;
        submitBtn.textContent = "Send Invitation";
      }
      
      showNotice(result.message || "Operation successful.");
      await loadUsers();
    } catch (error) {
      showNotice(error.message || "Operation failed.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });
  await loadUsers();
}