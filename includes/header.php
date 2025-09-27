<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PgBudget - Zero-Sum Budgeting</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <a href="/index.php" class="nav-logo">💰 PgBudget</a>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/index.php" class="nav-link">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a href="/ledgers/create.php" class="nav-link">New Budget</a>
                </li>
                <li class="nav-item">
                    <a href="/transactions/add.php" class="nav-link">Add Transaction</a>
                </li>
            </ul>
        </div>
    </nav>
    <main class="main-content"><?php
if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-error">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>