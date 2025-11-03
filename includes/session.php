<?php
if (session_status() === PHP_SESSION_NONE) {
    // Set cookie parameters for better security and compatibility
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => '/pgbudget', // Set path to the application root
        'domain' => $cookieParams['domain'],
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}