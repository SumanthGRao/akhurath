/* Network-only service worker. Billing and invoice pages must never be cached. */
self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET' || request.mode !== 'navigate') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith('/billing-dashboard/')) return;

  // Pass dashboard navigations directly to the server; do not store sensitive billing data.
  event.respondWith(fetch(request));
});

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) { data = { body: event.data?.text() || '' }; }
  const title = typeof data.title === 'string' ? data.title : 'New delivery task';
  const options = {
    body: typeof data.body === 'string' ? data.body : 'A new task is waiting in the billing dashboard.',
    icon: new URL('../assets/images/brand/akhurath-favicon-192.png', self.registration.scope).href,
    badge: new URL('../assets/images/brand/akhurath-favicon-48.png', self.registration.scope).href,
    tag: typeof data.tag === 'string' ? data.tag : 'billing-new-task',
    data: { url: typeof data.url === 'string' ? data.url : new URL('./', self.registration.scope).href }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = event.notification.data?.url || new URL('./', self.registration.scope).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
    const openClient = clients.find((client) => client.url.startsWith(self.registration.scope));
    if (openClient) return openClient.focus();
    return self.clients.openWindow(target);
  }));
});
