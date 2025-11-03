<!-- Step 1: Welcome -->
<div class="onboarding-step step-welcome">
    <div class="step-icon">🎉</div>
    
    <h1>Welcome to PGBudget! <?= rand() ?></h1>
    
    <p class="lead">
        You're about to take control of your money. Let's set up your budget together.
    </p>
    
    <p class="subtitle">
        This will take about 3 minutes.
    </p>
    
    <div class="step-actions">
        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()">
            Get Started
        </button>
        <?php if (isLoggedIn()): ?>
        <button type="button" class="btn btn-link" onclick="skipOnboarding()">
            Skip - I'm a pro
        </button>
        <?php endif; ?>
    </div>
    
    <div class="step-features">
        <div class="feature">
            <span class="feature-icon">💰</span>
            <span class="feature-text">Give every dollar a job</span>
        </div>
        <div class="feature">
            <span class="feature-icon">📊</span>
            <span class="feature-text">Track spending in real-time</span>
        </div>
        <div class="feature">
            <span class="feature-icon">🎯</span>
            <span class="feature-text">Reach your financial goals</span>
        </div>
    </div>
</div>

<script>
function nextStep() {
    completeStep(1, '/onboarding/wizard.php?step=2');
}

function skipOnboarding() {
    fetch('/pgbudget/api/onboarding/skip.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include' // Include cookies
    })
    .then(response => {
        if (response.status === 401) {
            // Not logged in, redirect to login
            alert('You need to be logged in to skip the setup wizard. Redirecting to login page.');
            window.location.href = '/pgbudget/auth/login.php';
            return null;
        }
        return response.json();
    })
    .then(data => {
        if (data) {
            if (data.success) {
                window.location.href = '/pgbudget/';
            } else {
                alert(data.error || 'An error occurred.');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('A network error occurred.');
    });
}
</script>
