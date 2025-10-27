<!-- Step 2: Budgeting Philosophy -->
<div class="onboarding-step step-philosophy">
    <div class="step-icon">💡</div>
    
    <h1>The PGBudget Method</h1>
    
    <p class="lead">
        These four principles will guide your financial journey:
    </p>
    
    <div class="principles-list">
        <div class="principle">
            <div class="principle-icon">✓</div>
            <div class="principle-content">
                <h3>Give every dollar a job</h3>
                <p>Assign all your money to specific categories. No dollar sits idle.</p>
            </div>
        </div>
        
        <div class="principle">
            <div class="principle-icon">✓</div>
            <div class="principle-content">
                <h3>Only budget money you actually have</h3>
                <p>Budget with real money, not future income. Stay grounded in reality.</p>
            </div>
        </div>
        
        <div class="principle">
            <div class="principle-icon">✓</div>
            <div class="principle-content">
                <h3>Adapt when life happens</h3>
                <p>Move money between categories as needed. Your budget is flexible.</p>
            </div>
        </div>
        
        <div class="principle">
            <div class="principle-icon">✓</div>
            <div class="principle-content">
                <h3>Break the paycheck-to-paycheck cycle</h3>
                <p>Build a buffer so you're spending last month's income, not this month's.</p>
            </div>
        </div>
    </div>
    
    <div class="step-actions">
        <button type="button" class="btn btn-secondary" onclick="previousStep()">
            Back
        </button>
        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()">
            Next
        </button>
    </div>
</div>

<script>
function nextStep() {
    completeStep(2, '/onboarding/wizard.php?step=3');
}

function previousStep() {
    window.location.href = '/onboarding/wizard.php?step=1';
}
</script>
