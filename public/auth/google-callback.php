<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/google.php';

function google_post(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $body === false) return null;
    return json_decode($body, true);
}

function google_get(string $url, string $access_token): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $body === false) return null;
    return json_decode($body, true);
}

function redirect_error(string $msg): void {
    header('Location: /pgbudget/public/auth/login.php?error=' . urlencode($msg));
    exit;
}

// --- CSRF check ---
$state = $_GET['state'] ?? '';
if (empty($state) || $state !== ($_SESSION['oauth_state'] ?? '')) {
    redirect_error('Invalid OAuth state. Please try again.');
}
unset($_SESSION['oauth_state']);

// --- Error from Google ---
if (isset($_GET['error'])) {
    redirect_error('Google sign-in was cancelled or denied.');
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    redirect_error('Missing authorization code from Google.');
}

// --- Exchange code for tokens ---
$tokens = google_post($google_cfg['token_url'], [
    'code'          => $code,
    'client_id'     => $google_cfg['client_id'],
    'client_secret' => $google_cfg['client_secret'],
    'redirect_uri'  => $google_cfg['redirect_uri'],
    'grant_type'    => 'authorization_code',
]);

if (empty($tokens['access_token'])) {
    error_log('Google token exchange failed: ' . json_encode($tokens));
    redirect_error('Failed to authenticate with Google. Please try again.');
}

// --- Fetch user profile ---
$profile = google_get($google_cfg['userinfo_url'], $tokens['access_token']);

if (empty($profile['sub']) || empty($profile['email'])) {
    redirect_error('Could not retrieve your Google profile. Please try again.');
}

// --- Find or create user in DB ---
try {
    $db   = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM api.find_or_create_google_user(?, ?, ?, ?, ?)");
    $stmt->execute([
        $profile['sub'],
        $profile['email'],
        $profile['given_name']  ?? '',
        $profile['family_name'] ?? '',
        $profile['picture']     ?? null,
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Google auth DB error: ' . $e->getMessage());
    redirect_error('Account error. Please try again.');
}

if (empty($user['user_uuid'])) {
    redirect_error('Could not create or find your account. Please try again.');
}

// --- Set session (identical to normal login) ---
$_SESSION['user_id']   = $user['username'];
$_SESSION['user_uuid'] = $user['user_uuid'];
$_SESSION['logged_in'] = true;

$stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
$stmt->execute([$user['username']]);

session_write_close();

header('Location: /pgbudget/');
exit;
