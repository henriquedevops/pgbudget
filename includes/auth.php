<?php
// Authentication helper functions

function requireAuth($allowDemo = false) {
    if (!isset($_SESSION['user_id'])) {
        // Check if user wants demo mode (only for main dashboard)
        if ($allowDemo && isset($_GET['demo'])) {
            $_SESSION['user_id'] = 'demo_user';
            return;
        }

        // For API requests, return JSON error
        if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }

        // Redirect to login for web pages
        header('Location: /pgbudget/auth/login.php');
        exit;
    }

    if (!$allowDemo && $_SESSION['user_id'] === 'demo_user') {
        header('Location: /pgbudget/auth/login.php?message=This feature requires a registered account');
        exit;
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] !== 'demo_user';
}

function isDemoUser() {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] === 'demo_user';
}

function getCurrentUser() {
    return $_SESSION['user_id'] ?? null;
}

function setUserContext($db) {
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
        $stmt->execute([$_SESSION['user_id']]);
    }
}
?>