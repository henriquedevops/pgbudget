<?php
// Google OAuth 2.0 configuration
// Reads GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET from .env (loaded by database.php)

$google_cfg = [
    'client_id'     => $_ENV['GOOGLE_CLIENT_ID']     ?? '',
    'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
    'redirect_uri'  => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                       . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                       . '/pgbudget/public/auth/google-callback.php',
    'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_url'     => 'https://oauth2.googleapis.com/token',
    'userinfo_url'  => 'https://www.googleapis.com/oauth2/v3/userinfo',
    'scopes'        => 'openid email profile',
];
