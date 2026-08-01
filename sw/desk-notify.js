/* Akhurath desk — show system notifications from the service worker (background tab). */
self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('message', function (event) {
  var data = event.data || {};
  if (data.type !== 'notify') {
    return;
  }
  var title = String(data.title || 'Update');
  var options = {
    body: String(data.body || ''),
    tag: String(data.tag || 'akh-desk-alert'),
    renotify: true,
    silent: false,
    data: {
      url: String(data.url || '/'),
      taskId: String(data.taskId || ''),
    },
  };
  if (data.icon) {
    options.icon = String(data.icon);
    options.badge = String(data.icon);
  }
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var payload = event.notification.data || {};
  var url = String(payload.url || '/');
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if ('focus' in client) {
          client.focus();
          if ('navigate' in client && url) {
            return client.navigate(url);
          }
          return client;
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});
