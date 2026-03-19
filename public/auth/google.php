<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/google.php';

if (empty($google_cfg['client_id'])) {
    die('Google Sign-In is not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env');
}

// Generate and store CSRF state token
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
session_write_close();

$params = http_build_query([
    'client_id'     => $google_cfg['client_id'],
    'redirect_uri'  => $google_cfg['redirect_uri'],
    'response_type' => 'code',
    'scope'         => $google_cfg['scopes'],
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: ' . $google_cfg['auth_url'] . '?' . $params);
exit;
