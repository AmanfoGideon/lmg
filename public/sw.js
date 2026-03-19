const CACHE_NAME = 'lmgcm-v1';
const CORE_ASSETS = [
  './offline.html',
  './css/dashboard.css',
  './css/style.css',
  './js/app.js',
  './js/ui.js',
  './images/logo.png',
  '/favicon/favicon.ico',
  '/favicon/favicon.svg',
  '/favicon/favicon-96x96.png',
  '/favicon/apple-touch-icon.png',
  '/favicon/web-app-manifest-192x192.png',
  '/favicon/web-app-manifest-512x512.png',
  './pwa-manifest.webmanifest',
  './manifest.json',
  '/favicon/site.webmanifest'
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
    e.respondWith(fetch(req).catch(() => caches.match('./offline.html')));
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

// IndexedDB helpers for SMS queue
const DB_NAME = 'lmgcm';
const STORE_SMS = 'smsQueue';
function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_SMS)) {
        db.createObjectStore(STORE_SMS, { keyPath: 'id' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
function getAllQueuedSMS(db) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_SMS, 'readonly');
    const store = tx.objectStore(STORE_SMS);
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(req.error);
  });
}
function deleteQueuedSMS(db, id) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_SMS, 'readwrite');
    const store = tx.objectStore(STORE_SMS);
    const req = store.delete(id);
    req.onsuccess = () => resolve(true);
    req.onerror = () => reject(req.error);
  });
}
function addQueuedSMS(db, item) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_SMS, 'readwrite');
    const store = tx.objectStore(STORE_SMS);
    const id = item && item.id ? item.id : (Date.now() + Math.floor(Math.random() * 1000));
    const payload = {
      id,
      path: item && item.path ? item.path : 'sms.php',
      data: item && item.data ? item.data : {}
    };
    const req = store.put(payload);
    req.onsuccess = () => resolve(true);
    req.onerror = () => reject(req.error);
  });
}
async function flushSMSQueue() {
  const db = await openDb();
  const items = await getAllQueuedSMS(db);
  for (const item of items) {
    try {
      const fd = new FormData();
      const data = item.data || {};
      Object.keys(data).forEach(k => {
        const val = data[k];
        if (Array.isArray(val)) {
          val.forEach(v => fd.append(k, v));
        } else if (val !== undefined && val !== null) {
          fd.append(k, val);
        }
      });
      const resp = await fetch(item.path || 'sms.php', {
        method: 'POST',
        body: fd,
        credentials: 'include'
      });
      if (resp && resp.ok) {
        await deleteQueuedSMS(db, item.id);
      }
    } catch (err) {
      // keep for next retry
    }
  }
}
self.addEventListener('sync', e => {
  if (e.tag === 'sms-sync') {
    e.waitUntil(flushSMSQueue());
  }
});
self.addEventListener('message', e => {
  const msg = e.data || {};
  if (msg && msg.type === 'QUEUE_SMS') {
    e.waitUntil((async () => {
      const db = await openDb();
      await addQueuedSMS(db, msg.item || {});
      if (self.registration && self.registration.sync) {
        try { await self.registration.sync.register('sms-sync'); } catch (_e) {}
      }
    })());
  } else if (msg && msg.type === 'FLUSH_SMS_QUEUE') {
    e.waitUntil(flushSMSQueue());
  }
});
