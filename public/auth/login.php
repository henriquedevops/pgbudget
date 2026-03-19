<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== 'demo_user') {
    header('Location: /pgbudget/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation
    if (empty($username)) {
        $error = 'Username is required';
    } elseif (empty($password)) {
        $error = 'Password is required';
    } else {
        // Attempt to authenticate user
        try {
            $db = getDbConnection();
            $stmt = $db->prepare("SELECT * FROM api.authenticate_user(?, ?)");
            $stmt->execute([$username, $password]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['success']) {
                // Set session variables
                $_SESSION['user_id'] = $username; // Use username as user_id for now
                $_SESSION['user_uuid'] = $result['user_uuid'];
                $_SESSION['logged_in'] = true;

                // Set PostgreSQL user context
                $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
                $stmt->execute([$username]);

                // Ensure session is written before redirect
                session_write_close();

                // Redirect to dashboard
                header('Location: /pgbudget/');
                exit;
            } else {
                $error = $result['message'];
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PgBudget</title>
    <link rel="stylesheet" href="/pgbudget/css/auth.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1><span aria-hidden="true">💰</span> PgBudget</h1>
            <p>Welcome back</p>
        </div>

        <?php if ($error || isset($_GET['error']) || isset($_SESSION['logout_message'])): ?>
        <div class="auth-form" style="padding-bottom: 0;">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error"><?= htmlspecialchars($_GET['error']) ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['logout_message'])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($_SESSION['logout_message']) ?></div>
                <?php unset($_SESSION['logout_message']); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <a href="/pgbudget/auth/google.php" class="btn-google">
            <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                <path fill="none" d="M0 0h48v48H0z"/>
            </svg>
            Continue with Google
        </a>

        <div class="divider">or</div>

        <form method="POST" class="auth-form">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username ?? '') ?>" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn">Sign In</button>

            <div class="demo-login">
                <p>Want to explore first?</p>
                <a href="/pgbudget/?demo=1" class="btn-demo">Continue as Demo User</a>
            </div>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php">Sign up here</a>
        </div>
    </div>
</body>
</html>