import { getSupabase } from "./supabase.js";
import { getActiveProfile, requestPasswordReset, signIn, updatePassword } from "./auth.js";

const signInForm = document.getElementById("signInForm");
const recoveryForm = document.getElementById("recoveryForm");
const updatePasswordForm = document.getElementById("updatePasswordForm");
const recoveryToggle = document.getElementById("recoveryToggle");
const message = document.getElementById("authMessage");
const recoveryMode = new URLSearchParams(window.location.search).has("recovery");

function showMessage(text, type = "danger") {
  message.className = `alert alert-${type}`;
  message.textContent = text;
}

function setBusy(form, busy) {
  const button = form.querySelector("button[type='submit']");
  if (!button) return;
  button.disabled = busy;
  button.textContent = busy ? "Please wait..." : button.dataset.label;
}

for (const form of [signInForm, recoveryForm, updatePasswordForm]) {
  const button = form.querySelector("button[type='submit']");
  button.dataset.label = button.textContent;
}

if (recoveryMode) {
  signInForm.classList.add("d-none");
  recoveryToggle.classList.add("d-none");
  updatePasswordForm.classList.remove("d-none");
  recoveryForm.classList.add("d-none");
} else {
  recoveryForm.classList.add("d-none");
}

recoveryToggle.addEventListener("click", () => {
  const showRecovery = recoveryForm.classList.contains("d-none");
  signInForm.classList.toggle("d-none", showRecovery);
  recoveryForm.classList.toggle("d-none", !showRecovery);
  recoveryToggle.textContent = showRecovery ? "Back to sign in" : "Forgot password?";
  message.classList.add("d-none");
});

signInForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setBusy(signInForm, true);
  try {
    const form = new FormData(signInForm);
    await signIn(form.get("email").trim(), form.get("password"));
    window.location.replace("app.html");
  } catch (error) {
    showMessage(error.message || "Unable to sign in.");
  } finally {
    setBusy(signInForm, false);
  }
});

recoveryForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setBusy(recoveryForm, true);
  try {
    const form = new FormData(recoveryForm);
    await requestPasswordReset(form.get("email").trim());
    showMessage("If the account exists, a password reset email has been sent.", "success");
  } catch (error) {
    showMessage(error.message || "Unable to request a password reset.");
  } finally {
    setBusy(recoveryForm, false);
  }
});

updatePasswordForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setBusy(updatePasswordForm, true);
  try {
    const form = new FormData(updatePasswordForm);
    await updatePassword(form.get("password"));
    showMessage("Password updated. Sign in with your new password.", "success");
    await getSupabase().auth.signOut();
    updatePasswordForm.classList.add("d-none");
    signInForm.classList.remove("d-none");
  } catch (error) {
    showMessage(error.message || "Unable to update your password.");
  } finally {
    setBusy(updatePasswordForm, false);
  }
});

if (!recoveryMode) {
  getActiveProfile()
    .then(() => window.location.replace("app.html"))
    .catch(() => {});
}