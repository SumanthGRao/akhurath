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
