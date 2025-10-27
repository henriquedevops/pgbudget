<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth(true);

// Set PostgreSQL user context
$db = getDbConnection();
setUserContext($db);

// Get current user's onboarding status
$stmt = $db->prepare("
    SELECT onboarding_completed, onboarding_step 
    FROM data.users 
    WHERE user_data = current_setting('app.current_user_id', true)
");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If onboarding is already completed, redirect to dashboard
if ($user && $user['onboarding_completed']) {
    header('Location: /');
    exit;
}

// Get current step from query parameter or user's saved step
$currentStep = isset($_GET['step']) ? (int)$_GET['step'] : ($user['onboarding_step'] ?? 0);

// Ensure step is within valid range
$currentStep = max(1, min(5, $currentStep));

// Include the appropriate step file
$stepFile = __DIR__ . "/step{$currentStep}.php";

if (!file_exists($stepFile)) {
    // Fallback to step 1 if file doesn't exist
    $currentStep = 1;
    $stepFile = __DIR__ . "/step1.php";
}

// Set page title
$pageTitle = "Welcome to PGBudget - Step {$currentStep} of 5";

require_once '../../includes/header.php';
?>

<link rel="stylesheet" href="/css/onboarding.css">

<div class="onboarding-container">
    <!-- Progress indicator -->
    <div class="onboarding-progress">
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= ($currentStep / 5) * 100 ?>%"></div>
        </div>
        <div class="progress-text">Step <?= $currentStep ?> of 5</div>
    </div>

    <!-- Step content -->
    <div class="onboarding-content">
        <?php include $stepFile; ?>
    </div>
</div>

<script src="/js/onboarding.js"></script>

<?php require_once '../../includes/footer.php'; ?>
