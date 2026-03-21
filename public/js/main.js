(function() {
// Main JavaScript functionality for PgBudget

document.addEventListener('DOMContentLoaded', function() {
    // Prevent double-submission: disable submit button and show "Saving…" on submit
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            const btn = form.querySelector('[type="submit"]:not([data-no-loading])');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = 'Saving\u2026';
            }
        });
    });

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

    // Confirm delete actions via styled modal
    document.querySelectorAll('[data-confirm]').forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.dataset.confirm || 'Are you sure you want to delete this item?';
            const title   = this.dataset.confirmTitle || 'Confirm Action';
            const self    = this;
            ConfirmModal.show({
                title:        title,
                message:      message,
                confirmText:  self.dataset.confirmText  || 'Delete',
                confirmClass: self.dataset.confirmClass || 'btn-danger',
                onConfirm: function() {
                    // If it's a link, follow it; if a button inside a form, submit; else click
                    if (self.tagName === 'A') {
                        window.location.href = self.href;
                    } else if (self.form) {
                        self.removeEventListener('click', arguments.callee);
                        self.form.submit();
                    } else {
                        self.dataset.confirmed = '1';
                        self.click();
                    }
                }
            });
        });
    });

    // Mobile hamburger toggle
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const navMenu = document.querySelector('.nav-menu');
    if (mobileToggle && navMenu) {
        mobileToggle.addEventListener('click', function() {
            const isOpen = navMenu.classList.toggle('active');
            mobileToggle.setAttribute('aria-expanded', isOpen);
        });
        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.navbar')) {
                navMenu.classList.remove('active');
                mobileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Nav dropdown toggles (mobile accordion)
    document.querySelectorAll('.nav-dropdown-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            // Only act as accordion on mobile (where position is static)
            if (window.innerWidth <= 768) {
                e.stopPropagation();
                const dropdown = this.closest('.nav-dropdown');
                const isOpen = dropdown.classList.toggle('open');
                this.setAttribute('aria-expanded', isOpen);
            }
        });
    });

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

    // Initialize Tippy.js tooltips
    tippy('[data-tippy-content]', {
        theme: 'light',
        animation: 'shift-away',
        delay: [100, 200],
    });
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

// Expose globals needed by inline scripts and other modules
window.formatCurrency = formatCurrency;

})();
