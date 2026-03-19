const CACHE_NAME = 'lmgcm-root-v1';
const CORE_ASSETS = [
  '/',
  '/index.php',
  '/public/offline.html',
  '/public/css/dashboard.css',
  '/public/css/style.css',
  '/public/js/app.js',
  '/public/js/ui.js',
  '/public/images/logo.png',
  '/favicon/favicon.ico',
  '/favicon/favicon.svg',
  '/favicon/favicon-96x96.png',
  '/favicon/apple-touch-icon.png',
  '/favicon/web-app-manifest-192x192.png',
  '/favicon/web-app-manifest-512x512.png',
  '/public/pwa-manifest.webmanifest',
  '/public/manifest.json',
  '/app.webmanifest'
];
self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(c => c.addAll(CORE_ASSETS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))))).then(() => self.clients.claim());
});
function isSameOrigin(u){try{const url=new URL(u, self.location.origin);return url.origin===self.location.origin;}catch(e){return false}}
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const acceptsHtml = req.headers.get('accept') && req.headers.get('accept').includes('text/html');
  if (acceptsHtml) {
    e.respondWith(fetch(req).catch(() => caches.match('/public/offline.html')));
    return;
  }
  const dest = req.destination;
  if (['style','script','image','font'].includes(dest) || isSameOrigin(req.url)) {
    e.respondWith(caches.match(req).then(cached => {
      const fetchPromise = fetch(req).then(resp => {
        const copy = resp.clone();
        caches.open(CACHE_NAME).then(c => c.put(req, copy));
        return resp;
      }).catch(() => cached);
      return cached || fetchPromise;
    }));
  }
});
