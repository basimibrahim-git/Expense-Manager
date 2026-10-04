// Bump CACHE_NAME whenever the precached asset list (or the caching strategy) changes.
const CACHE_NAME = 'expense-manager-v2';

// Paths are relative to this script (registered from BASE_URL, e.g. /expenses/sw.js),
// so they work whether the app lives at / or /expenses/.
const OFFLINE_URL = new URL('./offline.html', self.location).href;
const ASSETS_TO_CACHE = [
    OFFLINE_URL,
    new URL('./assets/css/style.css', self.location).href,
    new URL('./assets/js/app.js', self.location).href,
    new URL('./manifest.json', self.location).href,
    new URL('./assets/icons/icon-192.png', self.location).href,
    // Same CDN files the pages load (includes/header.php, includes/footer.php)
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'
];

// Install Event: precache static assets. One failing URL must not abort the install.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) =>
            Promise.all(ASSETS_TO_CACHE.map((url) =>
                cache.add(url).catch((err) => console.warn('SW precache failed:', url, err))
            ))
        ).then(() => self.skipWaiting())
    );
});

// Activate Event: drop old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => Promise.all(
            cacheNames.map((cacheName) => {
                if (cacheName !== CACHE_NAME) {
                    return caches.delete(cacheName);
                }
                return undefined;
            })
        )).then(() => self.clients.claim())
    );
});

/**
 * Static assets (CSS/JS/fonts/images from our /assets/ folder or the jsDelivr CDN) may be cached.
 * Pages and API responses are never cached: they contain authenticated, per-user data.
 */
function isStaticAsset(request) {
    const url = new URL(request.url);
    if (url.hostname === 'cdn.jsdelivr.net') {
        return true;
    }
    if (url.origin !== self.location.origin) {
        return false;
    }
    return ['style', 'script', 'font', 'image', 'manifest'].includes(request.destination)
        || url.pathname.indexOf('/assets/') !== -1
        || url.pathname.endsWith('/manifest.json');
}

// Fetch Event (network-first)
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only intercept GET requests to prevent issues with POST/PUT/DELETE actions
    if (request.method !== 'GET') {
        return;
    }

    // Page navigations: always from the network; offline page as the only fallback
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(OFFLINE_URL).then((offlineResponse) =>
                    offlineResponse || new Response('<h1>Offline</h1><p>Please check your internet connection.</p>', {
                        status: 503,
                        headers: { 'Content-Type': 'text/html' }
                    })
                )
            )
        );
        return;
    }

    if (!isStaticAsset(request)) {
        return; // let the browser handle it normally (no caching of dynamic data)
    }

    // Static assets: network first, refresh the cache, fall back to the cache offline.
    // ignoreSearch so "style.css?v=1.2.3" matches the precached "style.css".
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response && (response.ok || response.type === 'opaque')) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)).catch(() => {});
                }
                return response;
            })
            .catch(() =>
                caches.match(request, { ignoreSearch: true }).then((cached) =>
                    cached || new Response('Network error occurred', {
                        status: 408,
                        headers: { 'Content-Type': 'text/plain' }
                    })
                )
            )
    );
});
