const CACHE_NAME = 'tv-guide-v1';
const STATIC_ASSETS = [
    '/',
    '/favicon.ico',
    '/favicon.svg',
    '/favicon-96x96.png',
    '/apple-touch-icon.png',
    '/web-app-manifest-192x192.png',
    '/web-app-manifest-512x512.png'
];

// Установка Service Worker
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

// Активация и очистка старых кэшей
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Стратегия: сначала сеть, потом кэш (для актуальности данных EPG)
self.addEventListener('fetch', (event) => {
    // Пропускаем запросы к API
    if (event.request.url.includes('action=')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Кэшируем успешные ответы
                if (response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                // При ошибке сети — отдаём из кэша
                return caches.match(event.request);
            })
    );
});
