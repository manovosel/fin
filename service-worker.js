const CACHE_NAME = 'fin-shell-task-27-iter-1';
const APP_SHELL = [
  './',
  './index.html',
  './manifest.webmanifest',
  './favicon.svg',
  './icons/fin-192.png',
  './icons/fin-512.png',
  './style.css?v=20261023-1',
  './finance.js?v=20260831-13',
  './app.js?v=20261023-1',
];
const APP_SHELL_URLS = new Set(APP_SHELL.map((path) => new URL(path, self.location.href).href));

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith('fin-shell-') && key !== CACHE_NAME).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.endsWith('/api.php')) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(async () => (
        (await caches.match('./index.html')) ||
        (await caches.match('./')) ||
        Response.error()
      )),
    );
    return;
  }

  if (!APP_SHELL_URLS.has(url.href)) return;
  event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
});
