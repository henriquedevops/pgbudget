/**
 * Service Worker for PGBudget PWA
 * Phase 6.5: Offline support and caching
 */

const CACHE_VERSION = 'pgbudget-v1.0.0';
const CACHE_NAME = `${CACHE_VERSION}-static`;
const DATA_CACHE_NAME = `${CACHE_VERSION}-data`;

// Files to cache immediately on install
const STATIC_CACHE_FILES = [
    '/pgbudget/',
    '/pgbudget/index.php',
    '/pgbudget/css/style.css',
    '/pgbudget/css/mobile.css',
    '/pgbudget/css/bulk-operations.css',
    '/pgbudget/js/mobile-gestures.js',
    '/pgbudget/js/bulk-operations.js',
    '/pgbudget/manifest.json',
    // Add commonly used pages
    '/pgbudget/auth/login.php',
    '/pgbudget/budget/dashboard.php',
    '/pgbudget/transactions/list.php',
    '/pgbudget/transactions/add.php'
];

// API endpoints to cache dynamically
const API_CACHE_URLS = [
    '/pgbudget/api/',
];

// Install event - cache static resources
self.addEventListener('install', (event) => {
    console.log('[ServiceWorker] Installing...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[ServiceWorker] Caching static files');
                return cache.addAll(STATIC_CACHE_FILES.map(url => new Request(url, {
                    credentials: 'same-origin'
                })));
            })
            .then(() => {
                console.log('[ServiceWorker] Skip waiting');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[ServiceWorker] Cache failed:', error);
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('[ServiceWorker] Activating...');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName.startsWith('pgbudget-') && cacheName !== CACHE_NAME && cacheName !== DATA_CACHE_NAME) {
                            console.log('[ServiceWorker] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => {
                console.log('[ServiceWorker] Claiming clients');
                return self.clients.claim();
            })
    );
});

// Fetch event - serve from cache, fall back to network
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip cross-origin requests
    if (url.origin !== location.origin) {
        return;
    }

    // Handle API requests differently
    if (isApiRequest(request)) {
        event.respondWith(handleApiRequest(request));
        return;
    }

    // Handle static assets
    event.respondWith(handleStaticRequest(request));
});

// Check if request is for API
function isApiRequest(request) {
    const url = new URL(request.url);
    return url.pathname.includes('/api/');
}

// Handle API requests with network-first strategy
async function handleApiRequest(request) {
    try {
        // Try network first
        const networkResponse = await fetch(request);

        // Cache successful GET requests
        if (request.method === 'GET' && networkResponse.ok) {
            const cache = await caches.open(DATA_CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        console.log('[ServiceWorker] Network request failed, trying cache:', request.url);

        // Fall back to cache for GET requests
        if (request.method === 'GET') {
            const cachedResponse = await caches.match(request);
            if (cachedResponse) {
                return cachedResponse;
            }
        }

        // Return offline page or error response
        return new Response(
            JSON.stringify({
                error: 'Offline',
                message: 'You are currently offline. This action requires an internet connection.'
            }),
            {
                status: 503,
                statusText: 'Service Unavailable',
                headers: new Headers({
                    'Content-Type': 'application/json'
                })
            }
        );
    }
}

// Handle static requests with cache-first strategy
async function handleStaticRequest(request) {
    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
        return cachedResponse;
    }

    try {
        const networkResponse = await fetch(request);

        // Cache successful responses
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        console.log('[ServiceWorker] Failed to fetch:', request.url);

        // Return offline page for navigation requests
        if (request.mode === 'navigate') {
            const offlineResponse = await caches.match('/pgbudget/offline.html');
            if (offlineResponse) {
                return offlineResponse;
            }
        }

        return new Response('Offline', {
            status: 503,
            statusText: 'Service Unavailable'
        });
    }
}

// Background sync for offline transactions
self.addEventListener('sync', (event) => {
    console.log('[ServiceWorker] Background sync:', event.tag);

    if (event.tag === 'sync-transactions') {
        event.waitUntil(syncTransactions());
    }
});

async function syncTransactions() {
    try {
        // Get pending transactions from IndexedDB
        const db = await openDatabase();
        const transactions = await getPendingTransactions(db);

        console.log('[ServiceWorker] Syncing', transactions.length, 'transactions');

        for (const transaction of transactions) {
            try {
                const response = await fetch('/pgbudget/api/transactions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(transaction.data)
                });

                if (response.ok) {
                    await markTransactionSynced(db, transaction.id);
                    console.log('[ServiceWorker] Transaction synced:', transaction.id);
                }
            } catch (error) {
                console.error('[ServiceWorker] Failed to sync transaction:', error);
            }
        }

        // Notify clients of sync completion
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
            client.postMessage({
                type: 'SYNC_COMPLETE',
                count: transactions.length
            });
        });

    } catch (error) {
        console.error('[ServiceWorker] Sync failed:', error);
        throw error;
    }
}

// Push notifications for budget alerts
self.addEventListener('push', (event) => {
    console.log('[ServiceWorker] Push notification received');

    let data = {
        title: 'PGBudget',
        body: 'You have a budget notification',
        icon: '/pgbudget/images/icon-192x192.png',
        badge: '/pgbudget/images/badge-72x72.png'
    };

    if (event.data) {
        data = event.data.json();
    }

    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        vibrate: [200, 100, 200],
        data: data.url || '/pgbudget/',
        actions: [
            {
                action: 'view',
                title: 'View'
            },
            {
                action: 'dismiss',
                title: 'Dismiss'
            }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Handle notification clicks
self.addEventListener('notificationclick', (event) => {
    console.log('[ServiceWorker] Notification clicked:', event.action);

    event.notification.close();

    if (event.action === 'view') {
        const urlToOpen = event.notification.data || '/pgbudget/';

        event.waitUntil(
            clients.matchAll({ type: 'window', includeUncontrolled: true })
                .then((windowClients) => {
                    // Check if there's already a window open
                    for (let client of windowClients) {
                        if (client.url === urlToOpen && 'focus' in client) {
                            return client.focus();
                        }
                    }
                    // Open new window
                    if (clients.openWindow) {
                        return clients.openWindow(urlToOpen);
                    }
                })
        );
    }
});

// IndexedDB helper functions for offline transaction queue
function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('pgbudget-offline', 1);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;

            if (!db.objectStoreNames.contains('transactions')) {
                const store = db.createObjectStore('transactions', { keyPath: 'id', autoIncrement: true });
                store.createIndex('synced', 'synced', { unique: false });
                store.createIndex('timestamp', 'timestamp', { unique: false });
            }
        };
    });
}

function getPendingTransactions(db) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(['transactions'], 'readonly');
        const store = transaction.objectStore('transactions');
        const index = store.index('synced');
        const request = index.getAll(false);

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function markTransactionSynced(db, id) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(['transactions'], 'readwrite');
        const store = transaction.objectStore('transactions');
        const request = store.get(id);

        request.onsuccess = () => {
            const data = request.result;
            data.synced = true;
            const updateRequest = store.put(data);

            updateRequest.onsuccess = () => resolve();
            updateRequest.onerror = () => reject(updateRequest.error);
        };

        request.onerror = () => reject(request.error);
    });
}

// Message handler for communication with main app
self.addEventListener('message', (event) => {
    console.log('[ServiceWorker] Message received:', event.data);

    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys().then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName.startsWith('pgbudget-')) {
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
        );
    }
});
