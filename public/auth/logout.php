<?php
require_once __DIR__ . '/../../config/database.php';

// Destroy all session data
session_unset();
session_destroy();

// Start a new session for the redirect message
session_start();
$_SESSION['logout_message'] = 'You have been logged out successfully.';

// Redirect to login page
header('Location: login.php');
exit;
?>