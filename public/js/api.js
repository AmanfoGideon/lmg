const API_BASE = "/business-pwa/api/";

async function apiGet(endpoint) {
  const token = localStorage.getItem("token");
  const res = await fetch(API_BASE + endpoint, {
    headers: { "Authorization": "Bearer " + token }
  });
  return res.json();
}

async function apiPost(endpoint, data) {
  const token = localStorage.getItem("token");
  const res = await fetch(API_BASE + endpoint, {
    method: "POST",
    headers: {
      "Authorization": "Bearer " + token,
      "Content-Type": "application/json"
    },
    body: JSON.stringify(data)
  });
  return res.json();
}
