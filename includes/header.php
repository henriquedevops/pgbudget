<?php require_once __DIR__ . '/session.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="PGBudget - Open-source zero-sum budgeting application with double-entry accounting">
    <meta name="theme-color" content="#3182ce">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PGBudget">
    <title>PgBudget - Zero-Sum Budgeting</title>

    <!-- PWA Manifest -->
    <link rel="manifest" href="/pgbudget/manifest.json">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="/pgbudget/css/style.css">
    <link rel="stylesheet" href="/pgbudget/css/mobile.css">
    <link rel="stylesheet" href="/pgbudget/css/keyboard-shortcuts.css">
    <link rel="stylesheet" href="/pgbudget/css/undo.css">
    <link rel="stylesheet" href="/pgbudget/css/delete-ledger.css">
    <link rel="stylesheet" href="/pgbudget/css/tooltips.css">
    <link rel="stylesheet" href="/pgbudget/css/help-sidebar.css">
    <link rel="stylesheet" href="/pgbudget/css/modals.css">

    <!-- Tooltip Library (Tippy.js) -->
    <script src="https://unpkg.com/@popperjs/core@2"></script>
    <script src="https://unpkg.com/tippy.js@6"></script>
    <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/themes/light.css" />

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
        });
    </script>
</head>
<body>
    <!-- Skip to content for accessibility -->
    <a href="#main-content" class="skip-to-content">Skip to content</a>

    <nav class="navbar">
        <div class="nav-container">
            <button class="mobile-menu-toggle" aria-label="Toggle menu" aria-expanded="false">☰</button>
            <a href="/pgbudget/" class="nav-logo">💰 PgBudget</a>

            <?php if (isset($_SESSION['user_id'])): ?>
            <!-- Undo/Redo Controls -->
            <div class="undo-redo-controls">
                <button id="undo-btn" class="undo-btn" onclick="window.undoManager?.undo()" disabled title="Nothing to undo">
                    <span class="icon">↩️</span>
                    <span class="label">Undo</span>
                    <span id="undo-count" class="undo-count" style="display: none;">0</span>
                </button>
                <button id="redo-btn" class="redo-btn" onclick="window.undoManager?.redo()" disabled title="Nothing to redo">
                    <span class="icon">↪️</span>
                    <span class="label">Redo</span>
                </button>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id']) && (isset($_GET['ledger']) || isset($ledger_uuid))): ?>
                <div class="nav-search">
                    <?php $current_ledger = $_GET['ledger'] ?? ($ledger_uuid ?? ''); ?>
                    <form action="/pgbudget/search/" method="GET" class="nav-search-form">
                        <input type="hidden" name="ledger" value="<?= htmlspecialchars($current_ledger) ?>">
                        <input type="text"
                               name="q"
                               class="nav-search-input"
                               placeholder="Search..."
                               title="Press / to focus (Ctrl+K)">
                        <button type="submit" class="nav-search-btn" title="Search">🔍</button>
                    </form>
                </div>
            <?php endif; ?>
            <ul class="nav-menu">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item">
                        <a href="/pgbudget/" class="nav-link">Dashboard</a>
                    </li>
                    <?php if (isset($_GET['ledger']) || isset($ledger_uuid)): ?>
                        <?php $current_ledger = $_GET['ledger'] ?? ($ledger_uuid ?? ''); ?>
                        <li class="nav-item">
                            <a href="/pgbudget/accounts/list.php?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">Accounts</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/categories/manage.php?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">📊 Categories</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/transactions/add.php?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">Add Transaction</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/credit-cards/?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">💳 Credit Cards</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/loans/?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">💰 Loans</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/installments/?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">📅 Installments</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/obligations/?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">📋 Bills</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/income-sources/?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">Income</a>
                        </li>
                        <li class="nav-item">
                            <a href="/pgbudget/projected-events/?ledger=<?= htmlspecialchars($current_ledger) ?>" class="nav-link">Events</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a href="/pgbudget/settings/" class="nav-link">⚙️ Settings</a>
                    </li>
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
    <main class="main-content" id="main-content"><?php
// Include enhanced error handler
require_once __DIR__ . '/error-handler.php';

// Display messages using enhanced system
echo displayMessages();
?>