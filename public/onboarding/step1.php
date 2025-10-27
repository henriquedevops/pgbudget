<!-- Step 1: Welcome -->
<div class="onboarding-step step-welcome">
    <div class="step-icon">🎉</div>
    
    <h1>Welcome to PGBudget!</h1>
    
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
        <button type="button" class="btn btn-link" onclick="skipOnboarding()">
            Skip - I'm a pro
        </button>
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
    if (confirm('Are you sure you want to skip the setup wizard? You can always access help later.')) {
        fetch('/api/onboarding/skip.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = '/';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to skip onboarding. Please try again.');
        });
    }
}
</script>
