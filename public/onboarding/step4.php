<!-- Step 4: Add Your First Account -->
<div class="onboarding-step step-add-account">
    <div class="step-icon">🏦</div>
    
    <h1>Add Your Main Account</h1>
    
    <p class="lead">
        Where do you keep most of your money?
    </p>
    
    <form id="createAccountForm" onsubmit="return handleCreateAccount(event)">
        <div class="form-group">
            <label>Account Type *</label>
            <div class="account-type-options">
                <label class="account-type-option">
                    <input type="radio" name="accountType" value="Checking Account" checked>
                    <span class="option-content">
                        <span class="option-icon">🏦</span>
                        <span class="option-text">Checking Account</span>
                    </span>
                </label>
                
                <label class="account-type-option">
                    <input type="radio" name="accountType" value="Savings Account">
                    <span class="option-content">
                        <span class="option-icon">💰</span>
                        <span class="option-text">Savings Account</span>
                    </span>
                </label>
                
                <label class="account-type-option">
                    <input type="radio" name="accountType" value="Cash">
                    <span class="option-content">
                        <span class="option-icon">💵</span>
                        <span class="option-text">Cash</span>
                    </span>
                </label>
                
                <label class="account-type-option">
                    <input type="radio" name="accountType" value="Other">
                    <span class="option-content">
                        <span class="option-icon">📝</span>
                        <span class="option-text">Other</span>
                    </span>
                </label>
            </div>
        </div>
        
        <div class="form-group">
            <label for="accountName">Account Name *</label>
            <input 
                type="text" 
                id="accountName" 
                name="accountName" 
                class="form-control form-control-lg" 
                placeholder="e.g., My Checking, Main Savings"
                required
                maxlength="255"
            >
        </div>
        
        <div class="form-group">
            <label for="currentBalance">Current Balance *</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input 
                    type="number" 
                    id="currentBalance" 
                    name="currentBalance" 
                    class="form-control form-control-lg" 
                    placeholder="0.00"
                    step="0.01"
                    min="0"
                    required
                >
            </div>
            <small class="form-text">Enter the current balance in this account</small>
        </div>
        
        <div class="step-actions">
            <button type="button" class="btn btn-secondary" onclick="previousStep()">
                Back
            </button>
            <button type="submit" class="btn btn-primary btn-lg" id="createAccountBtn">
                Add Account
            </button>
        </div>
    </form>
    
    <div id="errorMessage" class="alert alert-danger" style="display: none;"></div>
</div>

<script>
function handleCreateAccount(event) {
    event.preventDefault();
    
    const form = event.target;
    const submitBtn = document.getElementById('createAccountBtn');
    const errorDiv = document.getElementById('errorMessage');
    
    // Get ledger UUID from session storage
    const ledgerUuid = sessionStorage.getItem('onboarding_ledger_uuid');
    if (!ledgerUuid) {
        errorDiv.textContent = 'Budget not found. Please go back and create a budget first.';
        errorDiv.style.display = 'block';
        return false;
    }
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';
    errorDiv.style.display = 'none';
    
    const accountName = form.accountName.value.trim();
    const balance = Math.round(parseFloat(form.currentBalance.value) * 100); // Convert to cents
    
    const formData = {
        ledger_uuid: ledgerUuid,
        name: accountName,
        type: 'asset',
        initial_balance: balance
    };
    
    fetch('/api/accounts/create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.account_uuid) {
            // Store account UUID for reference
            sessionStorage.setItem('onboarding_account_uuid', data.account_uuid);
            
            // Complete step and move to next
            completeStep(4, '/onboarding/wizard.php?step=5');
        } else {
            throw new Error(data.error || 'Failed to create account');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errorDiv.textContent = error.message || 'Failed to create account. Please try again.';
        errorDiv.style.display = 'block';
        
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Account';
    });
    
    return false;
}

function previousStep() {
    window.location.href = '/onboarding/wizard.php?step=3';
}
</script>
