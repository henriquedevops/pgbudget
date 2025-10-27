<!-- Step 5: Quick Start Categories -->
<div class="onboarding-step step-categories">
    <div class="step-icon">📁</div>
    
    <h1>Set Up Categories</h1>
    
    <p class="lead">
        Choose a template to get started quickly, or create your own categories.
    </p>
    
    <form id="categoriesForm" onsubmit="return handleApplyTemplate(event)">
        <div class="template-options">
            <label class="template-option">
                <input type="radio" name="template" value="single" checked>
                <div class="template-card">
                    <div class="template-icon">👤</div>
                    <h3>Single Person Starter</h3>
                    <p>Basic categories for individual living</p>
                    <ul class="template-preview">
                        <li>🍔 Food & Dining</li>
                        <li>🏠 Housing</li>
                        <li>🚗 Transportation</li>
                        <li>💳 Bills & Subscriptions</li>
                        <li>🎬 Entertainment</li>
                        <li>💰 Savings & Goals</li>
                    </ul>
                </div>
            </label>
            
            <label class="template-option">
                <input type="radio" name="template" value="family">
                <div class="template-card">
                    <div class="template-icon">👨‍👩‍👧</div>
                    <h3>Family Budget</h3>
                    <p>Categories for household management</p>
                    <ul class="template-preview">
                        <li>🍔 Food & Dining</li>
                        <li>🏠 Housing</li>
                        <li>🚗 Transportation</li>
                        <li>👨‍👩‍👧 Family</li>
                        <li>🏥 Healthcare</li>
                        <li>🎬 Entertainment</li>
                        <li>💰 Savings & Goals</li>
                    </ul>
                </div>
            </label>
            
            <label class="template-option">
                <input type="radio" name="template" value="student">
                <div class="template-card">
                    <div class="template-icon">📚</div>
                    <h3>Student Budget</h3>
                    <p>Education-focused with limited income</p>
                    <ul class="template-preview">
                        <li>🍔 Food & Dining</li>
                        <li>🏠 Housing</li>
                        <li>🚗 Transportation</li>
                        <li>📚 Education</li>
                        <li>👤 Personal</li>
                        <li>🎬 Entertainment</li>
                        <li>💰 Savings</li>
                    </ul>
                </div>
            </label>
            
            <label class="template-option">
                <input type="radio" name="template" value="custom">
                <div class="template-card">
                    <div class="template-icon">✏️</div>
                    <h3>Custom</h3>
                    <p>Start from scratch</p>
                    <p class="template-note">You can add categories later from the dashboard</p>
                </div>
            </label>
        </div>
        
        <div class="step-actions">
            <button type="button" class="btn btn-secondary" onclick="previousStep()">
                Back
            </button>
            <button type="submit" class="btn btn-primary btn-lg" id="finishBtn">
                Finish Setup & Start Budgeting!
            </button>
        </div>
    </form>
    
    <div id="errorMessage" class="alert alert-danger" style="display: none;"></div>
    <div id="successMessage" class="alert alert-success" style="display: none;"></div>
</div>

<script>
function handleApplyTemplate(event) {
    event.preventDefault();
    
    const form = event.target;
    const submitBtn = document.getElementById('finishBtn');
    const errorDiv = document.getElementById('errorMessage');
    const successDiv = document.getElementById('successMessage');
    
    // Get ledger UUID from session storage
    const ledgerUuid = sessionStorage.getItem('onboarding_ledger_uuid');
    if (!ledgerUuid) {
        errorDiv.textContent = 'Budget not found. Please go back and create a budget first.';
        errorDiv.style.display = 'block';
        return false;
    }
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Setting up...';
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    
    const selectedTemplate = form.template.value;
    
    // If custom, skip template application
    if (selectedTemplate === 'custom') {
        completeOnboarding();
        return false;
    }
    
    // Get template UUID based on selection
    fetch('/api/onboarding/templates.php')
    .then(response => response.json())
    .then(templates => {
        const template = templates.find(t => t.target_audience === selectedTemplate);
        if (!template) {
            throw new Error('Template not found');
        }
        
        // Apply template
        return fetch('/api/onboarding/apply-template.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ledger_uuid: ledgerUuid,
                template_uuid: template.uuid
            })
        });
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            successDiv.textContent = `Created ${data.categories_created} categories!`;
            successDiv.style.display = 'block';
            
            // Wait a moment to show success message, then complete
            setTimeout(() => {
                completeOnboarding();
            }, 1000);
        } else {
            throw new Error(data.error || 'Failed to apply template');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errorDiv.textContent = error.message || 'Failed to set up categories. Please try again.';
        errorDiv.style.display = 'block';
        
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = 'Finish Setup & Start Budgeting!';
    });
    
    return false;
}

function completeOnboarding() {
    completeStep(5, '/?onboarding_complete=1');
}

function previousStep() {
    window.location.href = '/onboarding/wizard.php?step=4';
}
</script>
