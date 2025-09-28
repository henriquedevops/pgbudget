<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PgBudget - Zero-Sum Budgeting</title>
    <link rel="stylesheet" href="/pgbudget/css/style.css">
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
    <nav class="navbar">
        <div class="nav-container">
            <a href="/pgbudget/" class="nav-logo">💰 PgBudget</a>
            <ul class="nav-menu">
                <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== 'demo_user'): ?>
                    <li class="nav-item">
                        <a href="/pgbudget/" class="nav-link">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/ledgers/create.php" class="nav-link">New Budget</a>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/transactions/add.php" class="nav-link">Add Transaction</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-user">Hello, <?= htmlspecialchars($_SESSION['user_id']) ?>!</span>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/auth/logout.php" class="nav-link nav-logout">Logout</a>
                    </li>
                <?php elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] === 'demo_user'): ?>
                    <li class="nav-item">
                        <a href="/pgbudget/" class="nav-link">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/ledgers/create.php" class="nav-link">New Budget</a>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/transactions/add.php" class="nav-link">Add Transaction</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-user">Demo Mode</span>
                    </li>
                    <li class="nav-item">
                        <a href="/pgbudget/auth/login.php" class="nav-link">Login</a>
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
    <main class="main-content"><?php
// Include enhanced error handler
require_once __DIR__ . '/error-handler.php';

// Display messages using enhanced system
echo displayMessages();
?>