<!-- Step 3: Create Your Budget -->
<div class="onboarding-step step-create-budget">
    <div class="step-icon">📊</div>
    
    <h1>Name Your Budget</h1>
    
    <p class="lead">
        What should we call your budget?
    </p>
    
    <form id="createBudgetForm" onsubmit="return handleCreateBudget(event)">
        <div class="form-group">
            <label for="budgetName">Budget Name *</label>
            <input 
                type="text" 
                id="budgetName" 
                name="budgetName" 
                class="form-control form-control-lg" 
                placeholder="e.g., Personal Budget, Family Finances"
                required
                maxlength="255"
                autofocus
            >
            <small class="form-text">Choose a name that makes sense to you</small>
        </div>
        
        <div class="form-group">
            <label for="budgetDescription">Description (Optional)</label>
            <textarea 
                id="budgetDescription" 
                name="budgetDescription" 
                class="form-control" 
                rows="3"
                placeholder="Add any notes about this budget..."
                maxlength="500"
            ></textarea>
        </div>
        
        <div class="step-actions">
            <button type="button" class="btn btn-secondary" onclick="previousStep()">
                Back
            </button>
            <button type="submit" class="btn btn-primary btn-lg" id="createBudgetBtn">
                Create Budget
            </button>
        </div>
    </form>
    
    <div id="errorMessage" class="alert alert-danger" style="display: none;"></div>
</div>

<script>
function handleCreateBudget(event) {
    event.preventDefault();
    
    const form = event.target;
    const submitBtn = document.getElementById('createBudgetBtn');
    const errorDiv = document.getElementById('errorMessage');
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';
    errorDiv.style.display = 'none';
    
    const formData = {
        name: form.budgetName.value.trim(),
        description: form.budgetDescription.value.trim() || null
    };
    
    fetch('/pgbudget/api/ledgers/create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.ledger_uuid) {
            // Store ledger UUID in session storage for next steps
            sessionStorage.setItem('onboarding_ledger_uuid', data.ledger_uuid);
            
            // Complete step and move to next
            completeStep(3, '/onboarding/wizard.php?step=4');
        } else {
            throw new Error(data.error || 'Failed to create budget');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errorDiv.textContent = error.message || 'Failed to create budget. Please try again.';
        errorDiv.style.display = 'block';
        
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Budget';
    });
    
    return false;
}

function previousStep() {
    window.location.href = '/onboarding/wizard.php?step=2';
}
</script>
