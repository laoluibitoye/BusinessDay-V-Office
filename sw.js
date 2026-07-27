// HRI Mail Service Worker — network-first, no offline caching
// Mail data must always be live; this SW exists solely to enable PWA install.

const CACHE = 'hri-mail-v1';

// Static shell assets to cache on install (speeds up app launch)
const SHELL = [
  '/',
  '/lib/layout_shell.css',
  '/favicon.svg',
  '/hri-logo.png',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // Never intercept: API calls, POST requests, cross-origin requests
  if (
    e.request.method !== 'GET' ||
    url.origin !== self.location.origin ||
    url.pathname.startsWith('/api/') ||
    url.pathname.startsWith('/uploads/')
  ) {
    return;
  }

  // Network-first for all PHP pages (mail must be live)
  e.respondWith(
    fetch(e.request).catch(() => caches.match(e.request))
  );
});
