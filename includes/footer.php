    </main>

    <!-- Mobile Bottom Navigation -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <nav class="mobile-bottom-nav">
        <?php $current_ledger = $_GET['ledger'] ?? ($ledger_uuid ?? ''); ?>
        <a href="/pgbudget/" class="mobile-nav-item <?= !isset($_GET['ledger']) && basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
            <span class="icon">🏠</span>
            <span>Home</span>
        </a>
        <?php if (!empty($current_ledger)): ?>
        <a href="/pgbudget/budget/dashboard.php?ledger=<?= urlencode($current_ledger) ?>" class="mobile-nav-item <?= strpos($_SERVER['PHP_SELF'], '/budget/') !== false ? 'active' : '' ?>">
            <span class="icon">💰</span>
            <span>Budget</span>
        </a>
        <a href="/pgbudget/transactions/add.php?ledger=<?= urlencode($current_ledger) ?>" class="mobile-nav-item">
            <span class="icon">➕</span>
            <span>Add</span>
        </a>
        <a href="/pgbudget/transactions/list.php?ledger=<?= urlencode($current_ledger) ?>" class="mobile-nav-item <?= strpos($_SERVER['PHP_SELF'], '/transactions/') !== false ? 'active' : '' ?>">
            <span class="icon">📋</span>
            <span>Transactions</span>
        </a>
        <?php else: ?>
        <a href="/pgbudget/transactions/add.php" class="mobile-nav-item">
            <span class="icon">➕</span>
            <span>Add</span>
        </a>
        <a href="/pgbudget/ledgers/create.php" class="mobile-nav-item">
            <span class="icon">📊</span>
            <span>Budget</span>
        </a>
        <?php endif; ?>
        <a href="/pgbudget/settings/notifications.php" class="mobile-nav-item <?= strpos($_SERVER['PHP_SELF'], '/settings/') !== false ? 'active' : '' ?>">
            <span class="icon">⚙️</span>
            <span>Settings</span>
        </a>
    </nav>
    <?php endif; ?>

    <footer class="footer">
        <div class="footer-content">
            <p>&copy; 2025 PgBudget - Zero-sum budgeting with double-entry accounting</p>
        </div>
    </footer>
    <?php
    // Include Quick-Add Modal for authenticated users
    if (isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/quick-add-modal.php';
        require_once __DIR__ . '/transfer-modal.php';
    }
    ?>

    <!-- Core JavaScript -->
    <script src="/pgbudget/js/main.js"></script>
    <script src="/pgbudget/js/mobile-gestures.js"></script>
    <script src="/pgbudget/js/keyboard-shortcuts.js"></script>
    <script src="/pgbudget/js/undo-manager.js"></script>
    <script src="/pgbudget/js/delete-ledger.js"></script>

    <?php
    if (isset($_SESSION['user_id'])) {
        echo '<script src="/pgbudget/js/quick-add-modal.js"></script>';
        echo '<script src="/pgbudget/js/transfer-modal.js"></script>';
    }
    ?>

    <!-- PWA Service Worker Registration -->
    <script>
        // Register service worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/pgbudget/service-worker.js')
                    .then((registration) => {
                        console.log('ServiceWorker registered:', registration.scope);

                        // Check for updates
                        registration.addEventListener('updatefound', () => {
                            const newWorker = registration.installing;
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                    // New version available
                                    if (confirm('A new version is available! Reload to update?')) {
                                        newWorker.postMessage({ type: 'SKIP_WAITING' });
                                        window.location.reload();
                                    }
                                }
                            });
                        });
                    })
                    .catch((error) => {
                        console.log('ServiceWorker registration failed:', error);
                    });

                // Handle service worker messages
                navigator.serviceWorker.addEventListener('message', (event) => {
                    if (event.data.type === 'SYNC_COMPLETE') {
                        console.log('Background sync completed:', event.data.count, 'items');
                    }
                });
            });
        }

        // PWA install prompt
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;

            // Show install button if needed
            const installButton = document.getElementById('pwa-install-btn');
            if (installButton) {
                installButton.style.display = 'block';
                installButton.addEventListener('click', async () => {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    console.log('PWA install outcome:', outcome);
                    deferredPrompt = null;
                    installButton.style.display = 'none';
                });
            }
        });

        // Detect if running as installed PWA
        window.addEventListener('DOMContentLoaded', () => {
            if (window.matchMedia('(display-mode: standalone)').matches) {
                document.body.classList.add('pwa-installed');
                console.log('Running as installed PWA');
            }
        });

        // Online/offline status
        window.addEventListener('online', () => {
            console.log('Back online');
            // Trigger background sync if available
            if ('serviceWorker' in navigator && 'sync' in ServiceWorkerRegistration.prototype) {
                navigator.serviceWorker.ready.then((registration) => {
                    return registration.sync.register('sync-transactions');
                });
            }
        });

        window.addEventListener('offline', () => {
            console.log('Gone offline');
        });
    </script>
</body>
</html>