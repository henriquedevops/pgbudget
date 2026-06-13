<?php require_once __DIR__ . '/session.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="PGBudget - Open-source zero-sum budgeting application with double-entry accounting">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PGBudget">
    <?php
    // Page title: explicit $page_title (set before this include) wins;
    // otherwise derive a section title from the URL path
    if (!isset($page_title)) {
        $pgb_section_titles = [
            'budget'             => 'Budget',
            'transactions'       => 'Transactions',
            'accounts'           => 'Accounts',
            'reports'            => 'Reports',
            'categories'         => 'Categories',
            'credit-cards'       => 'Credit Cards',
            'loans'              => 'Loans',
            'installments'       => 'Installments',
            'obligations'        => 'Bills',
            'income-sources'     => 'Income Sources',
            'payroll-deductions' => 'Payroll Deductions',
            'projected-events'   => 'Projected Events',
            'recurring'          => 'Recurring Transactions',
            'settings'           => 'Settings',
            'search'             => 'Search',
            'payees'             => 'Payees',
            'ledgers'            => 'Budgets',
            'onboarding'         => 'Welcome',
            'auth'               => 'Sign In',
        ];
        $pgb_path_parts = explode('/', trim(dirname($_SERVER['PHP_SELF'] ?? ''), '/'));
        $pgb_section    = end($pgb_path_parts);
        $page_title     = $pgb_section_titles[$pgb_section] ?? null;
    }
    ?>
    <title><?= !empty($page_title) ? htmlspecialchars($page_title) . ' — PgBudget' : 'PgBudget — Zero-Sum Budgeting' ?></title>

    <!-- PWA Manifest -->
    <link rel="manifest" href="/pgbudget/manifest.json">

    <!-- Stylesheets -->
    <?php $cv = '20260415a'; ?>
    <link rel="stylesheet" href="/pgbudget/css/core.css?v=<?= $cv ?>">
    <link rel="stylesheet" href="/pgbudget/css/components.css?v=<?= $cv ?>">

    <!-- Tooltip Library (Tippy.js) — vendored, pinned versions -->
    <script src="/pgbudget/js/vendor/popper-2.11.8.min.js"></script>
    <script src="/pgbudget/js/vendor/tippy-6.3.7.umd.min.js"></script>
    <link rel="stylesheet" href="/pgbudget/css/vendor/tippy-light-6.3.7.css" />

    <!-- Lucide Icons — vendored, pinned version -->
    <script src="/pgbudget/js/vendor/lucide-0.525.0.min.js"></script>

    <!-- Ledger currency (loaded in <head> so inline page scripts can format amounts) -->
    <script>
        window.PGB_CURRENCY = <?= json_encode(
            function_exists('pgb_currency_config')
                ? pgb_currency_config()
                : ['code' => 'USD', 'symbol' => '$', 'decimal' => '.', 'thousands' => ',', 'position' => 'before', 'space' => false]
        ) ?>;
    </script>
    <script src="/pgbudget/js/currency.js?v=<?= $cv ?>"></script>

    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" sizes="180x180" href="/pgbudget/images/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/pgbudget/images/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/pgbudget/images/icon-192x192.png">

    <script>
        // Auto-dismiss messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(function(message) {
                // Auto-dismiss success and info messages after 5 seconds
                if (message.classList.contains('message-success') || message.classList.contains('message-info')) {
                    setTimeout(function() {
                        if (message.parentElement) {
                            message.style.transition = 'opacity 0.3s, transform 0.3s';
                            message.style.opacity = '0';
                            message.style.transform = 'translateX(100%)';
                            setTimeout(function() {
                                if (message.parentElement) {
                                    message.remove();
                                }
                            }, 300);
                        }
                    }, 5000);
                }
            });
            lucide.createIcons();
        });
    </script>
</head>
<?php
// Current ledger: ?ledger= param, the page's $ledger_uuid, or the session
// fallback — so navigation survives URLs without ?ledger= (U3). Exposed as
// data-ledger-uuid so page scripts (quick add, shortcuts) can find it too.
$current_ledger = function_exists('pgb_current_ledger')
    ? pgb_current_ledger($ledger_uuid ?? null)
    : ($_GET['ledger'] ?? ($ledger_uuid ?? ''));
?>
<body<?= $current_ledger !== '' ? ' data-ledger-uuid="' . htmlspecialchars($current_ledger) . '"' : '' ?>>
    <!-- Skip to content for accessibility -->
    <a href="#main-content" class="skip-to-content">Skip to content</a>

    <nav class="navbar">
        <div class="nav-container">
            <button class="mobile-menu-toggle" aria-label="Toggle menu" aria-expanded="false"><i data-lucide="menu" aria-hidden="true"></i></button>
            <a href="/pgbudget/" class="nav-logo"><i data-lucide="piggy-bank" aria-hidden="true"></i> PgBudget</a>

            <?php if (isset($_SESSION['user_id'])): ?>
            <!-- Undo/Redo Controls -->
            <div class="undo-redo-controls">
                <button id="undo-btn" class="undo-btn" onclick="window.undoManager?.undo()" disabled title="Nothing to undo">
                    <i data-lucide="undo-2" aria-hidden="true"></i>
                    <span class="label">Undo</span>
                    <span id="undo-count" class="undo-count" style="display: none;">0</span>
                </button>
                <button id="redo-btn" class="redo-btn" onclick="window.undoManager?.redo()" disabled title="Nothing to redo">
                    <i data-lucide="redo-2" aria-hidden="true"></i>
                    <span class="label">Redo</span>
                </button>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id']) && $current_ledger !== ''): ?>
                <div class="nav-search">
                    <form action="/pgbudget/search/" method="GET" class="nav-search-form">
                        <input type="hidden" name="ledger" value="<?= htmlspecialchars($current_ledger) ?>">
                        <input type="text"
                               name="q"
                               class="nav-search-input"
                               placeholder="Search..."
                               title="Press / to focus (Ctrl+K)">
                        <button type="submit" class="nav-search-btn" title="Search"><i data-lucide="search" aria-hidden="true"></i></button>
                    </form>
                </div>
            <?php endif; ?>
            <!-- Navbar is intentionally minimal (U3): the sidebar is the single
                 navigation source; here only search, quick add and user actions -->
            <ul class="nav-menu">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if ($current_ledger !== ''): ?>
                        <li class="nav-item">
                            <a href="#" class="nav-link nav-quick-add" data-ledger="<?= htmlspecialchars($current_ledger) ?>" onclick="QuickAddModal.open();return false;"><i data-lucide="plus" aria-hidden="true"></i> Add</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <span class="nav-user">Hello, <?= htmlspecialchars($_SESSION['user_id']) ?>!</span>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/auth/logout.php" class="nav-link nav-logout">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a href="/pgbudget/auth/login.php" class="nav-link">Login</a>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/auth/register.php" class="nav-link">Register</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Sidebar overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    <?php endif; ?>

    <div class="app-body">

    <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Left sidebar -->
    <?php
    $current_path   = $_SERVER['PHP_SELF'] ?? '';
    function pgb_sidebar_active(string $segment): string {
        global $current_path;
        return str_contains($current_path, $segment) ? ' active' : '';
    }
    $user_initials = strtoupper(substr($_SESSION['user_id'], 0, 2));
    ?>
    <aside class="app-sidebar" id="app-sidebar">
        <a href="/pgbudget/" class="sidebar-brand">
            <div class="sidebar-brand-mark">P</div>
            PgBudget
        </a>

        <?php if (!empty($current_ledger)): ?>
        <a href="/pgbudget/ledgers/" class="sidebar-ledger-link">
            <div>
                <div class="sidebar-ledger-label">Ledger</div>
                <div class="sidebar-ledger-name"><?= htmlspecialchars(isset($ledger['name']) ? $ledger['name'] : $current_ledger) ?></div>
            </div>
            <i data-lucide="chevron-down" style="width:16px;height:16px;flex-shrink:0;"></i>
        </a>
        <?php endif; ?>

        <nav class="sidebar-nav">
            <a href="/pgbudget/" class="sidebar-nav-item<?= pgb_sidebar_active('/index') ?>">
                <i data-lucide="home"></i> Dashboard
            </a>
            <?php if (!empty($current_ledger)): ?>
            <a href="/pgbudget/budget/dashboard.php?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/budget/') ?>">
                <i data-lucide="pie-chart"></i> Budget
            </a>
            <a href="/pgbudget/transactions/list.php?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/transactions/') ?>">
                <i data-lucide="list"></i> Transactions
            </a>
            <a href="/pgbudget/reports/?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/reports/') ?>">
                <i data-lucide="bar-chart-2"></i> Reports
            </a>
            <a href="/pgbudget/accounts/list.php?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/accounts/') ?>">
                <i data-lucide="wallet"></i> Accounts
            </a>
            <a href="/pgbudget/categories/manage.php?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/categories/') ?>">
                <i data-lucide="tags"></i> Categories
            </a>
            <a href="/pgbudget/credit-cards/?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/credit-cards/') ?>">
                <i data-lucide="credit-card"></i> Credit Cards
            </a>
            <?php endif; ?>
        </nav>

        <?php if (!empty($current_ledger)): ?>
        <div class="sidebar-group-label">Plan</div>
        <nav class="sidebar-nav">
            <a href="/pgbudget/obligations/?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/obligations/') ?>">
                <i data-lucide="repeat"></i> Bills
            </a>
            <a href="/pgbudget/loans/?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/loans/') ?>">
                <i data-lucide="book"></i> Loans
            </a>
            <a href="/pgbudget/installments/?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/installments/') ?>">
                <i data-lucide="layers"></i> Installments
            </a>
            <a href="/pgbudget/income-sources/?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/income-sources/') ?>">
                <i data-lucide="trending-up"></i> Income Sources
            </a>
            <a href="/pgbudget/projected-events/?ledger=<?= urlencode($current_ledger) ?>" class="sidebar-nav-item<?= pgb_sidebar_active('/projected-events/') ?>">
                <i data-lucide="calendar"></i> Projected Events
            </a>
        </nav>
        <?php endif; ?>

        <div class="sidebar-spacer"></div>

        <nav class="sidebar-nav">
            <a href="/pgbudget/settings/" class="sidebar-nav-item<?= pgb_sidebar_active('/settings/') ?>">
                <i data-lucide="settings"></i> Settings
            </a>
            <a href="/pgbudget/auth/logout.php" class="sidebar-nav-item">
                <i data-lucide="log-out"></i> Logout
            </a>
        </nav>

        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= htmlspecialchars($user_initials) ?></div>
            <div style="min-width:0;flex:1;">
                <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['user_id']) ?></div>
            </div>
        </div>
    </aside>
    <?php endif; ?>

    <main class="main-content" id="main-content"><?php
// Include enhanced error handler
require_once __DIR__ . '/error-handler.php';

// Display messages using enhanced system
echo displayMessages();
?>