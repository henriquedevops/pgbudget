/**
 * Onboarding Wizard JavaScript
 * Handles step progression and API calls
 */



/**
 * Format currency for display
 * @param {number} cents - Amount in cents
 * @returns {string} Formatted currency string
 */
function formatCurrency(cents) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(cents / 100);
}

/**
 * Parse currency input to cents
 * @param {string} value - Currency string
 * @returns {number} Amount in cents
 */
function parseCurrency(value) {
    const cleaned = value.replace(/[^0-9.]/g, '');
    return Math.round(parseFloat(cleaned) * 100);
}

/**
 * Show loading state on a button
 * @param {HTMLElement} button - Button element
 * @param {string} loadingText - Text to show while loading
 */
function setButtonLoading(button, loadingText = 'Loading...') {
    button.disabled = true;
    button.dataset.originalText = button.textContent;
    button.textContent = loadingText;
}

/**
 * Reset button from loading state
 * @param {HTMLElement} button - Button element
 */
function resetButton(button) {
    button.disabled = false;
    if (button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
    }
}

/**
 * Show error message
 * @param {string} message - Error message to display
 * @param {HTMLElement} container - Container element for error
 */
function showError(message, container) {
    if (!container) {
        container = document.getElementById('errorMessage');
    }
    if (container) {
        container.textContent = message;
        container.style.display = 'block';
        
        // Scroll to error
        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

/**
 * Hide error message
 * @param {HTMLElement} container - Container element for error
 */
function hideError(container) {
    if (!container) {
        container = document.getElementById('errorMessage');
    }
    if (container) {
        container.style.display = 'none';
    }
}

/**
 * Show success message
 * @param {string} message - Success message to display
 * @param {HTMLElement} container - Container element for success
 */
function showSuccess(message, container) {
    if (!container) {
        container = document.getElementById('successMessage');
    }
    if (container) {
        container.textContent = message;
        container.style.display = 'block';
        
        // Scroll to success
        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

/**
 * Validate form before submission
 * @param {HTMLFormElement} form - Form to validate
 * @returns {boolean} True if valid
 */
function validateForm(form) {
    // Use HTML5 validation
    if (!form.checkValidity()) {
        form.reportValidity();
        return false;
    }
    return true;
}

// Auto-focus first input on page load
document.addEventListener('DOMContentLoaded', function() {
    const firstInput = document.querySelector('input[autofocus], input:not([type="radio"]):not([type="checkbox"])');
    if (firstInput) {
        setTimeout(() => {
            firstInput.focus();
        }, 100);
    }
    
    // Add enter key handler for radio buttons
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
        radio.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.checked = true;
                // Trigger change event
                this.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });
});

// Prevent accidental navigation away
let formModified = false;

document.addEventListener('DOMContentLoaded', function() {
    // Track form modifications
    document.querySelectorAll('input, textarea, select').forEach(element => {
        element.addEventListener('change', function() {
            formModified = true;
        });
    });
    
    // Warn before leaving if form is modified
    window.addEventListener('beforeunload', function(e) {
        if (formModified) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });
    
    // Don't warn when submitting forms
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            formModified = false;
        });
    });
});
