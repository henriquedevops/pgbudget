// Main JavaScript functionality for PgBudget

document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });

    // Format currency inputs
    const currencyInputs = document.querySelectorAll('input[type="text"][placeholder*="$"]');
    currencyInputs.forEach(input => {
        input.addEventListener('input', formatCurrencyInput);
        input.addEventListener('blur', formatCurrencyInput);
    });

    // Confirm delete actions
    const deleteButtons = document.querySelectorAll('.btn-danger, [data-confirm]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const message = this.dataset.confirm || 'Are you sure you want to delete this item?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // Mobile menu toggle (if needed)
    const navToggle = document.querySelector('.nav-toggle');
    const navMenu = document.querySelector('.nav-menu');

    if (navToggle && navMenu) {
        navToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
        });
    }
});

function formatCurrencyInput(e) {
    let value = e.target.value.replace(/[^0-9.-]/g, '');

    if (value) {
        // Handle negative values
        const isNegative = value.startsWith('-');
        value = value.replace('-', '');

        const numValue = parseFloat(value);
        if (!isNaN(numValue)) {
            const formatted = (isNegative ? '-$' : '$') + numValue.toFixed(2);
            e.target.value = formatted;
        }
    }
}

function formatCurrency(cents) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(cents / 100);
}

function parseCurrency(value) {
    // Remove currency symbols and convert to cents
    const cleaned = value.replace(/[^0-9.-]/g, '');
    return Math.round(parseFloat(cleaned) * 100);
}

// Utility function to show loading state
function showLoading(element) {
    element.disabled = true;
    element.innerHTML = 'Loading...';
}

function hideLoading(element, originalText) {
    element.disabled = false;
    element.innerHTML = originalText;
}

// Form validation helpers
function validateRequired(form) {
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;

    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('error');
            isValid = false;
        } else {
            field.classList.remove('error');
        }
    });

    return isValid;
}

// Add error styling for form validation
const style = document.createElement('style');
style.textContent = `
    .form-input.error,
    .form-select.error,
    .form-textarea.error {
        border-color: #e53e3e;
        box-shadow: 0 0 0 3px rgba(229, 62, 62, 0.1);
    }
`;
document.head.appendChild(style);