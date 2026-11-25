'use strict';

const CACHE = 'door-shell-v8';
const CACHE_PREFIX = 'door-shell-';

const SHELL = [
  '/',
  '/index.html',
  '/manifest.json',
  '/css/app.css',
  '/js/app.js',
  '/assets/door.svg'
];

const STATIC_ASSETS = new Set([
  '/manifest.json',
  '/css/app.css',
  '/js/app.js',
  '/assets/door.svg'
]);

// Установка новой версии. Старый кэш не удаляется,
// пока новая оболочка полностью не сохранена.
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE)
      .then(cache => cache.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

// Удаляем старые версии только после успешной установки новой.
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys
          .filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE)
          .map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  if (
    request.method !== 'GET' ||
    url.origin !== self.location.origin ||
    url.pathname.startsWith('/api/')
  ) {
    return;
  }

  // Стартовая страница: сначала локальный кэш, без ожидания сети.
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      const cached =
        await caches.match('/index.html') ||
        await caches.match('/');

      if (cached) return cached;

      try {
        return await fetch(request);
      } catch (_) {
        return new Response(
          'Оболочка приложения ещё не сохранена. Откройте приложение при подключённом интернете.',
          {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          }
        );
      }
    })());

    return;
  }

  // Статические ресурсы: локальный кэш, затем сеть.
  if (STATIC_ASSETS.has(url.pathname)) {
    event.respondWith((async () => {
      const cached = await caches.match(request);
      if (cached) return cached;

      try {
        const response = await fetch(request);

        if (response.ok && response.type === 'basic') {
          const cache = await caches.open(CACHE);
          await cache.put(request, response.clone()).catch(() => {});
        }

        return response;
      } catch (_) {
        return new Response(
          'Ресурс недоступен офлайн: ' + url.pathname,
          {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          }
        );
      }
    })());
  }
});
