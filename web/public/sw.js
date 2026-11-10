/* Service worker: offline app shell + push notifications (payload-less: we fetch the latest note). */
const CACHE = 'gymos-v1';
self.addEventListener('install', (e) => { self.skipWaiting(); e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['/', '/icon-192.png', '/manifest.webmanifest']).catch(() => {}))); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', (e) => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin || u.pathname.startsWith('/api/') || u.pathname.startsWith('/iclock/')) return;
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).then((r) => { const copy = r.clone(); caches.open(CACHE).then((c) => c.put('/', copy)); return r; }).catch(() => caches.match('/')));
    return;
  }
  if (u.pathname.startsWith('/assets/')) {
    e.respondWith(caches.match(e.request).then((hit) => hit || fetch(e.request).then((r) => { const copy = r.clone(); caches.open(CACHE).then((c) => c.put(e.request, copy)); return r; })));
  }
});
self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let title = 'Gym update'; let body = 'Open the app to see what is new.'; let url = '/';
    const member = (await self.registration.scope) && (await clients.matchAll({ includeUncontrolled: true })).some((c) => new URL(c.url).pathname.startsWith('/m/'));
    try {
      const r = await fetch(member ? '/api/m/notifications' : '/api/notifications', { credentials: 'include', headers: { 'X-Requested-With': 'gymapp' } });
      if (r.ok) {
        const d = (await r.json()).data;
        const n = Array.isArray(d) ? d[0] : d.items?.[0];
        if (n) { title = n.title; body = n.body || ''; url = member ? '/m/' : '/owner'; }
      }
    } catch (_) { /* show generic */ }
    await self.registration.showNotification(title, { body, icon: '/icon-192.png', badge: '/icon-192.png', data: { url } });
  })());
});
self.addEventListener('notificationclick', (e) => { e.notification.close(); e.waitUntil(clients.openWindow(e.notification.data?.url || '/')); });
