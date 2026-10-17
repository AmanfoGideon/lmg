// Single service worker for both the static Supabase app (dist/) and the PHP pages (/public/).
// Asset URLs are scope-relative so the same file works whether it is served from the
// site root or from the /public/ directory.
const CACHE_NAME = "lmg-cm-v4";
const CORE_ASSETS = [
  "./",
  "./index.html",
  "./app.html",
  "./offline.html",
  "./pwa-manifest.webmanifest",
  "./js/config.js",
  "./js/vendor.css",
  "./css/theme.css",
  "./css/invoice-premium.css",
  "./images/logo.png",
  "./favicon/favicon.svg",
];

self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    // Cache assets individually so one missing file cannot abort the install.
    await Promise.allSettled(CORE_ASSETS.map((asset) => cache.add(asset)));
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Never cache authentication or API traffic.
  if (url.pathname.startsWith("/auth/") || url.pathname.startsWith("/rest/") || url.pathname.startsWith("/functions/")) return;

  // Navigations: network first, fall back to cache then the offline page.
  if (request.mode === "navigate") {
    event.respondWith((async () => {
      try {
        return await fetch(request);
      } catch {
        const cached = await caches.match(request, { ignoreSearch: true });
        return cached || caches.match("./offline.html");
      }
    })());
    return;
  }

  // Static assets: stale-while-revalidate for HTML, CSS, JS, fonts, images
  event.respondWith((async () => {
    const cached = await caches.match(request);
    const network = fetch(request).then((response) => {
      if (response && response.ok) {
        // Clone synchronously, before the response is handed to the page and its
        // body consumed. Cloning inside the async caches.open() callback fails with
        // "Response body is already used".
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
      }
      return response;
    }).catch(() => cached);
    return cached || network;
  })());
});
