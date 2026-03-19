<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/database.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== 'demo_user') {
    header('Location: /');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name = sanitizeInput($_POST['last_name'] ?? '');

    // Validation
    if (empty($username)) {
        $error = 'Username is required';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username must be between 3 and 50 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = 'Username can only contain letters, numbers, and underscores';
    } elseif (empty($email)) {
        $error = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } elseif (empty($password)) {
        $error = 'Password is required';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match';
    } else {
        // Attempt to register user
        try {
            $db = getDbConnection();
            $stmt = $db->prepare("SELECT * FROM api.register_user(?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password, $first_name, $last_name]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['success']) {
                $success = 'Registration successful! You can now log in.';
                // Clear form data
                $username = $email = $first_name = $last_name = '';
            } else {
                $error = $result['message'];
            }
        } catch (PDOException $e) {
            $error = 'Registration failed. Please try again.';
            error_log("Registration error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - PgBudget</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
        }

        .auth-header {
            background: #2d3748;
            color: white;
            padding: 30px;
            text-align: center;
        }

        .auth-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .auth-header p {
            opacity: 0.8;
            font-size: 16px;
        }

        .auth-form {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn:hover {
            background: #5a67d8;
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-weight: 500;
        }

        .alert-error {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #feb2b2;
        }

        .alert-success {
            background: #c6f6d5;
            color: #2f855a;
            border: 1px solid #9ae6b4;
        }

        .auth-footer {
            text-align: center;
            padding: 20px 40px;
            background: #f7fafc;
            border-top: 1px solid #e2e8f0;
        }

        .auth-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        .password-requirements {
            font-size: 14px;
            color: #718096;
            margin-top: 5px;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
            color: #a0aec0;
            font-size: 14px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .btn-google {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 11px 24px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            font-size: 15px;
            font-weight: 600;
            color: #2d3748;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            margin-bottom: 4px;
        }

        .btn-google:hover {
            border-color: #a0aec0;
            background: #f7fafc;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1>💰 PgBudget</h1>
            <p>Create your account</p>
        </div>

        <a href="/pgbudget/public/auth/google.php" class="btn-google" style="display:flex;margin:0 40px 0 40px;width:auto;">
            <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                <path fill="none" d="M0 0h48v48H0z"/>
            </svg>
            Sign up with Google
        </a>

        <form method="POST" class="auth-form">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="divider">or create account manually</div>

            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username ?? '') ?>" required>
                <div class="password-requirements">3-50 characters, letters, numbers, and underscores only</div>
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" required>
                <div class="password-requirements">Minimum 8 characters</div>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirm Password *</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <button type="submit" class="btn">Create Account</button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>
    </div>
</body>
</html>