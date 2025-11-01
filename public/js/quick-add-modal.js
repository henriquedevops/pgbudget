/**
 * Quick-Add Transaction Modal
 * Phase 3.4 - Quick-Add Transaction Modal
 *
 * Features:
 * - Keyboard shortcut 'T' to open modal anywhere
 * - Modal overlay on any page
 * - Pre-fill account if on account page
 * - Smart date picker (Today/Yesterday/Custom)
 * - Save & Add Another option
 * - Payee autocomplete
 */

const QuickAddModal = (function() {
    'use strict';

    // Private variables
    let modal = null;
    let form = null;
    let currentLedgerUuid = null;
    let currentAccountUuid = null;
    let accounts = [];
    let categories = [];
    let payeeSearchTimeout = null;
    let payeeActiveIndex = -1;
    let payeeSuggestions = [];

    /**
     * Initialize the Quick-Add Modal
     */
    function init() {
        modal = document.getElementById('quick-add-modal');
        form = document.getElementById('quick-add-form');

        if (!modal || !form) {
            console.error('Quick-Add Modal: Modal or form not found');
            return;
        }

        setupEventListeners();
        setupKeyboardShortcuts();
    }

    /**
     * Setup all event listeners
     */
    function setupEventListeners() {
        // Type toggle buttons
        document.querySelectorAll('.type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const type = this.dataset.type;
                setTransactionType(type);
            });
        });

        // Date quick select buttons
        document.querySelectorAll('.date-quick-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const days = this.dataset.days;
                setDate(days);
            });
        });

        // Amount input formatting
        const amountInput = document.getElementById('qa-amount');
        amountInput.addEventListener('input', formatAmountInput);

        // Form submission
        form.addEventListener('submit', handleSubmit);

        // Payee autocomplete
        const payeeInput = document.getElementById('qa-payee');
        payeeInput.addEventListener('input', handlePayeeInput);
        payeeInput.addEventListener('keydown', handlePayeeKeydown);

        // Click outside to close
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                close();
            }
        });

        // ESC key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display !== 'none') {
                close();
            }
        });

        // Category select change for type
        const typeInput = document.getElementById('qa-type');
        if (typeInput) {
            const observer = new MutationObserver(updateCategoryLabel);
            observer.observe(typeInput, { attributes: true, attributeFilter: ['value'] });
        }
    }

    /**
     * Setup keyboard shortcuts
     */
    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // 'T' key to open modal (not in input fields)
            if (e.key === 'T' && !isInputActive() && modal.style.display === 'none') {
                e.preventDefault();
                open();
            }
        });
    }

    /**
     * Check if an input field is currently focused
     */
    function isInputActive() {
        const activeElement = document.activeElement;
        return activeElement && (
            activeElement.tagName === 'INPUT' ||
            activeElement.tagName === 'TEXTAREA' ||
            activeElement.tagName === 'SELECT' ||
            activeElement.isContentEditable
        );
    }

    /**
     * Open the modal
     */
    function open(options = {}) {
        // Set ledger UUID (from options or from page)
        currentLedgerUuid = options.ledger_uuid || getLedgerUuidFromPage();
        if (!currentLedgerUuid) {
            alert('No budget selected. Please select a budget first.');
            return;
        }

        // Set account UUID if provided (e.g., from account page)
        currentAccountUuid = options.account_uuid || null;

        // Load accounts and categories
        loadAccountsAndCategories(function() {
            // Clear form first
            resetForm();

            // Set form values
            document.getElementById('qa-ledger-uuid').value = currentLedgerUuid;

            // Pre-select account if provided
            if (currentAccountUuid) {
                document.getElementById('qa-account').value = currentAccountUuid;
            }

            // Set default type to outflow
            setTransactionType('outflow');

            // Set default date to today
            setDate('0');

            // Show modal with animation
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('active');
            }, 10);

            // Focus on amount field
            setTimeout(() => {
                document.getElementById('qa-amount').focus();
            }, 200);
        });
    }

    /**
     * Close the modal
     */
    function close() {
        modal.classList.remove('active');
        setTimeout(() => {
            modal.style.display = 'none';
            resetForm();
        }, 200);
    }

    /**
     * Reset the form
     */
    function resetForm() {
        form.reset();
        hideError();
        hideSuccess();
        document.getElementById('qa-amount').value = '';
        document.getElementById('qa-description').value = '';
        document.getElementById('qa-payee').value = '';
        document.getElementById('qa-save-and-add').checked = false;

        // Reset type to outflow
        setTransactionType('outflow');

        // Hide custom date input
        document.getElementById('qa-date').style.display = 'none';
    }

    /**
     * Set transaction type
     */
    function setTransactionType(type) {
        document.getElementById('qa-type').value = type;

        // Update button states
        document.querySelectorAll('.type-btn').forEach(btn => {
            if (btn.dataset.type === type) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Update category label and help text
        updateCategoryLabel();
    }

    /**
     * Update category label based on type
     */
    function updateCategoryLabel() {
        const type = document.getElementById('qa-type').value;
        const categoryLabel = document.querySelector('label[for="qa-category"]');
        const categoryHelp = document.getElementById('qa-category-help');
        const categorySelect = document.getElementById('qa-category');

        if (type === 'inflow') {
            categoryLabel.textContent = 'Category';
            categoryHelp.textContent = 'Leave blank for Income account';
            categorySelect.required = false;
        } else {
            categoryLabel.textContent = 'Category *';
            categoryHelp.textContent = 'Choose the budget category for this expense';
            categorySelect.required = true;
        }
    }

    /**
     * Set date
     */
    function setDate(days) {
        const dateInput = document.getElementById('qa-date');

        // Update button states
        document.querySelectorAll('.date-quick-btn').forEach(btn => {
            if (btn.dataset.days === days) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        if (days === 'custom') {
            // Show custom date picker
            dateInput.style.display = 'block';
            dateInput.required = true;
            dateInput.value = getTodayDate();
        } else {
            // Hide custom date picker
            dateInput.style.display = 'none';
            dateInput.required = false;

            // Calculate date
            const date = new Date();
            date.setDate(date.getDate() + parseInt(days));
            dateInput.value = formatDate(date);
        }
    }

    /**
     * Format amount input
     */
    function formatAmountInput(e) {
        let value = e.target.value;

        // Remove any characters that aren't digits, comma, or period
        value = value.replace(/[^0-9,.]/g, '');

        // If there's both comma and period, keep only the last one as decimal separator
        const commaIndex = value.lastIndexOf(',');
        const periodIndex = value.lastIndexOf('.');

        if (commaIndex !== -1 && periodIndex !== -1) {
            if (commaIndex > periodIndex) {
                // Comma is the decimal separator, remove periods
                value = value.replace(/\./g, '');
            } else {
                // Period is the decimal separator, remove commas
                value = value.replace(/,/g, '');
            }
        }

        // Ensure only one decimal separator and at most 2 decimal places
        if (value.includes(',')) {
            const parts = value.split(',');
            if (parts.length > 2) {
                value = parts[0] + ',' + parts.slice(1).join('');
            }
            if (parts[1] && parts[1].length > 2) {
                value = parts[0] + ',' + parts[1].substring(0, 2);
            }
        } else if (value.includes('.')) {
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            if (parts[1] && parts[1].length > 2) {
                value = parts[0] + '.' + parts[1].substring(0, 2);
            }
        }

        e.target.value = value;
    }

    /**
     * Handle form submission
     */
    function handleSubmit(e) {
        e.preventDefault();

        // Validate form
        if (!validateForm()) {
            return;
        }

        // Get form data
        const formData = new FormData(form);
        const data = {
            ledger_uuid: formData.get('ledger_uuid'),
            type: formData.get('type'),
            amount: formData.get('amount'),
            date: formData.get('date'),
            description: formData.get('description'),
            payee: formData.get('payee') || null,
            account: formData.get('account'),
            category: formData.get('category') || null
        };

        const saveAndAdd = document.getElementById('qa-save-and-add').checked;

        // Check credit limit if this is a credit card outflow (Phase 5)
        if (data.type === 'outflow') {
            checkCreditLimit(data.account, data.amount, function(limitWarning) {
                if (limitWarning) {
                    showLimitWarning(limitWarning, function(proceed) {
                        if (proceed) {
                            proceedWithSubmit(data, saveAndAdd);
                        }
                    });
                } else {
                    proceedWithSubmit(data, saveAndAdd);
                }
            });
        } else {
            proceedWithSubmit(data, saveAndAdd);
        }
    }

    /**
     * Proceed with submitting transaction (Phase 5 - extracted for limit check)
     */
    function proceedWithSubmit(data, saveAndAdd) {
        // Show loading state
        const submitBtn = document.getElementById('qa-submit-btn');
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;

        // Submit transaction
        submitTransaction(data, function(success, message) {
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;

            if (success) {
                if (saveAndAdd) {
                    // Show success message and reset form
                    showSuccess(message);
                    setTimeout(() => {
                        hideSuccess();
                        resetForm();
                        setDate('0');
                        document.getElementById('qa-amount').focus();
                    }, 1000);
                } else {
                    // Close modal and reload page
                    showSuccess(message);
                    setTimeout(() => {
                        close();
                        window.location.reload();
                    }, 1000);
                }
            } else {
                showError(message);
            }
        });
    }

    /**
     * Check credit card limit (Phase 5)
     */
    function checkCreditLimit(accountUuid, amountStr, callback) {
        // Find the account in our accounts list
        const account = accounts.find(acc => acc.uuid === accountUuid);

        // Only check if it's a liability account (credit card)
        if (!account || account.type !== 'liability') {
            callback(null);
            return;
        }

        // Parse amount
        const normalizedAmount = amountStr.replace(',', '.');
        const amount = parseFloat(normalizedAmount);

        // Check credit limit via API
        fetch(`/pgbudget/api/credit-card-limits.php?account_uuid=${accountUuid}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.limit) {
                    const currentBalance = parseFloat(data.current_balance || 0);
                    const creditLimit = parseFloat(data.limit.credit_limit);
                    const newBalance = currentBalance + amount;
                    const utilizationPercent = (newBalance / creditLimit) * 100;
                    const warningThreshold = parseFloat(data.limit.warning_threshold_percent || 80);

                    if (newBalance > creditLimit) {
                        callback({
                            type: 'over_limit',
                            currentBalance: currentBalance,
                            creditLimit: creditLimit,
                            newBalance: newBalance,
                            utilizationPercent: utilizationPercent,
                            amount: amount
                        });
                    } else if (utilizationPercent >= 95) {
                        callback({
                            type: 'critical',
                            currentBalance: currentBalance,
                            creditLimit: creditLimit,
                            newBalance: newBalance,
                            utilizationPercent: utilizationPercent,
                            amount: amount
                        });
                    } else if (utilizationPercent >= warningThreshold) {
                        callback({
                            type: 'warning',
                            currentBalance: currentBalance,
                            creditLimit: creditLimit,
                            newBalance: newBalance,
                            utilizationPercent: utilizationPercent,
                            amount: amount
                        });
                    } else {
                        callback(null);
                    }
                } else {
                    // No limit set, proceed normally
                    callback(null);
                }
            })
            .catch(error => {
                console.error('Error checking credit limit:', error);
                // On error, proceed anyway (don't block transaction)
                callback(null);
            });
    }

    /**
     * Show credit limit warning modal (Phase 5)
     */
    function showLimitWarning(warning, callback) {
        const warningHTML = `
            <div class="limit-warning-overlay">
                <div class="limit-warning-content">
                    <div class="warning-icon-large">⚠️</div>
                    <div class="warning-title">
                        ${warning.type === 'over_limit' ? 'Credit Limit Exceeded!' :
                          warning.type === 'critical' ? 'Critical: Near Credit Limit' :
                          'Warning: Approaching Credit Limit'}
                    </div>
                    <div class="warning-message">
                        ${warning.type === 'over_limit' ?
                            `This transaction would exceed your credit limit by <strong>${formatCurrency(warning.newBalance - warning.creditLimit)}</strong>.` :
                            `This transaction will bring your credit utilization to <strong>${warning.utilizationPercent.toFixed(1)}%</strong>.`}
                        <br><br>
                        <strong>Current Balance:</strong> ${formatCurrency(warning.currentBalance)}<br>
                        <strong>Transaction Amount:</strong> ${formatCurrency(warning.amount)}<br>
                        <strong>New Balance:</strong> ${formatCurrency(warning.newBalance)}<br>
                        <strong>Credit Limit:</strong> ${formatCurrency(warning.creditLimit)}
                    </div>
                    <div class="warning-actions">
                        ${warning.type === 'over_limit' ?
                            '<button type="button" class="btn btn-secondary" onclick="this.closest(\'.limit-warning-overlay\').remove()">Cancel</button>' :
                            '<button type="button" class="btn btn-secondary" onclick="this.closest(\'.limit-warning-overlay\').remove()">Cancel</button>'}
                        <button type="button" class="btn btn-${warning.type === 'over_limit' ? 'danger' : 'warning'}"
                                onclick="QuickAddModal.confirmLimitWarning()">
                            ${warning.type === 'over_limit' ? 'Proceed Anyway' : 'Continue'}
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', warningHTML);

        // Store callback for confirmation
        window._limitWarningCallback = callback;
    }

    /**
     * Confirm limit warning and proceed (Phase 5)
     */
    function confirmLimitWarning() {
        // Remove warning overlay
        const overlay = document.querySelector('.limit-warning-overlay');
        if (overlay) {
            overlay.remove();
        }

        // Call callback to proceed
        if (window._limitWarningCallback) {
            window._limitWarningCallback(true);
            delete window._limitWarningCallback;
        }
    }

    /**
     * Validate form
     */
    function validateForm() {
        const amount = document.getElementById('qa-amount').value;
        const description = document.getElementById('qa-description').value;
        const account = document.getElementById('qa-account').value;
        const date = document.getElementById('qa-date').value;
        const type = document.getElementById('qa-type').value;
        const category = document.getElementById('qa-category').value;

        if (!amount || !description || !account || !date) {
            showError('Please fill in all required fields.');
            return false;
        }

        // Validate amount
        const normalizedAmount = amount.replace(',', '.');
        const numAmount = parseFloat(normalizedAmount);
        if (isNaN(numAmount) || numAmount <= 0) {
            showError('Please enter a valid amount greater than 0.');
            return false;
        }

        // Validate category for outflows
        if (type === 'outflow' && !category) {
            showError('Please select a category for this expense.');
            return false;
        }

        return true;
    }

    /**
     * Submit transaction to API
     */
    function submitTransaction(data, callback) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/pgbudget/api/quick-add-transaction.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json');

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        callback(true, response.message || 'Transaction added successfully!');
                    } else {
                        callback(false, response.error || 'Failed to add transaction.');
                    }
                } catch (e) {
                    callback(false, 'Error parsing response.');
                }
            } else {
                callback(false, 'Network error. Please try again.');
            }
        };

        xhr.onerror = function() {
            callback(false, 'Network error. Please try again.');
        };

        xhr.send(JSON.stringify(data));
    }

    /**
     * Load accounts and categories for the ledger
     */
    function loadAccountsAndCategories(callback) {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '/pgbudget/api/ledger-data.php?ledger=' + currentLedgerUuid, true);

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    accounts = response.accounts || [];
                    categories = response.categories || [];

                    populateAccountSelect();
                    populateCategorySelect();

                    if (callback) callback();
                } catch (e) {
                    console.error('Error parsing ledger data:', e);
                    alert('Failed to load accounts and categories.');
                }
            }
        };

        xhr.send();
    }

    /**
     * Populate account select
     */
    function populateAccountSelect() {
        const select = document.getElementById('qa-account');
        select.innerHTML = '<option value="">Choose account...</option>';

        accounts.forEach(account => {
            const option = document.createElement('option');
            option.value = account.uuid;
            option.textContent = `${account.name} (${account.type})`;
            select.appendChild(option);
        });
    }

    /**
     * Populate category select
     */
    function populateCategorySelect() {
        const select = document.getElementById('qa-category');
        select.innerHTML = '<option value="">Choose category...</option>';

        categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.uuid;
            option.textContent = category.name;
            select.appendChild(option);
        });
    }

    /**
     * Handle payee input for autocomplete
     */
    function handlePayeeInput(e) {
        clearTimeout(payeeSearchTimeout);
        const query = e.target.value.trim();

        if (query.length < 2) {
            hidePayeeSuggestions();
            return;
        }

        payeeSearchTimeout = setTimeout(() => {
            searchPayees(query);
        }, 300);
    }

    /**
     * Handle payee keyboard navigation
     */
    function handlePayeeKeydown(e) {
        const suggestionsContainer = document.getElementById('qa-payee-suggestions');
        if (suggestionsContainer.style.display === 'none') {
            return;
        }

        const suggestions = suggestionsContainer.querySelectorAll('.autocomplete-suggestion');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            payeeActiveIndex = Math.min(payeeActiveIndex + 1, suggestions.length - 1);
            updateActivePayeeSuggestion(suggestions);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            payeeActiveIndex = Math.max(payeeActiveIndex - 1, -1);
            updateActivePayeeSuggestion(suggestions);
        } else if (e.key === 'Enter' && payeeActiveIndex >= 0) {
            e.preventDefault();
            suggestions[payeeActiveIndex].click();
        } else if (e.key === 'Escape') {
            hidePayeeSuggestions();
        }
    }

    /**
     * Search payees
     */
    function searchPayees(query) {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '/pgbudget/api/payees-search.php?q=' + encodeURIComponent(query), true);

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    payeeSuggestions = data;
                    displayPayeeSuggestions(data);
                } catch (e) {
                    console.error('Error parsing payee suggestions:', e);
                    hidePayeeSuggestions();
                }
            }
        };

        xhr.send();
    }

    /**
     * Display payee suggestions
     */
    function displayPayeeSuggestions(payees) {
        const container = document.getElementById('qa-payee-suggestions');
        payeeActiveIndex = -1;

        if (payees.length === 0) {
            container.innerHTML = '<div class="autocomplete-empty">No payees found. Type to create new.</div>';
            container.style.display = 'block';
            return;
        }

        let html = '';
        payees.forEach((payee, index) => {
            let meta = [];
            if (payee.transaction_count > 0) {
                meta.push(`${payee.transaction_count} transactions`);
            }
            if (payee.default_category_name) {
                meta.push(`Default: ${payee.default_category_name}`);
            }

            html += `
                <div class="autocomplete-suggestion" data-index="${index}">
                    <span class="autocomplete-payee-name">${escapeHtml(payee.name)}</span>
                    ${meta.length > 0 ? `<div class="autocomplete-payee-meta">${meta.join(' • ')}</div>` : ''}
                </div>
            `;
        });

        container.innerHTML = html;
        container.style.display = 'block';

        // Add click handlers
        container.querySelectorAll('.autocomplete-suggestion').forEach((el, index) => {
            el.addEventListener('click', function() {
                selectPayee(payeeSuggestions[index]);
            });
        });
    }

    /**
     * Update active payee suggestion
     */
    function updateActivePayeeSuggestion(suggestions) {
        suggestions.forEach((el, index) => {
            if (index === payeeActiveIndex) {
                el.classList.add('active');
                el.scrollIntoView({ block: 'nearest' });
            } else {
                el.classList.remove('active');
            }
        });
    }

    /**
     * Select payee
     */
    function selectPayee(payee) {
        document.getElementById('qa-payee').value = payee.name;
        hidePayeeSuggestions();

        // Auto-fill category if available
        if (payee.auto_categorize && payee.default_category_uuid) {
            const categorySelect = document.getElementById('qa-category');
            if (categorySelect && !categorySelect.value) {
                categorySelect.value = payee.default_category_uuid;
            }
        }
    }

    /**
     * Hide payee suggestions
     */
    function hidePayeeSuggestions() {
        const container = document.getElementById('qa-payee-suggestions');
        container.style.display = 'none';
        container.innerHTML = '';
        payeeActiveIndex = -1;
    }

    /**
     * Show error message
     */
    function showError(message) {
        const errorDiv = document.getElementById('qa-error');
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }

    /**
     * Hide error message
     */
    function hideError() {
        const errorDiv = document.getElementById('qa-error');
        errorDiv.style.display = 'none';
    }

    /**
     * Show success message
     */
    function showSuccess(message) {
        const successDiv = document.getElementById('qa-success');
        successDiv.textContent = message;
        successDiv.style.display = 'block';
    }

    /**
     * Hide success message
     */
    function hideSuccess() {
        const successDiv = document.getElementById('qa-success');
        successDiv.style.display = 'none';
    }

    /**
     * Get ledger UUID from current page
     */
    function getLedgerUuidFromPage() {
        // Try to get from URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        const ledgerFromUrl = urlParams.get('ledger');
        if (ledgerFromUrl) {
            return ledgerFromUrl;
        }

        // Try to get from page element
        const ledgerElement = document.querySelector('[data-ledger-uuid]');
        if (ledgerElement) {
            return ledgerElement.dataset.ledgerUuid;
        }

        return null;
    }

    /**
     * Get today's date in YYYY-MM-DD format
     */
    function getTodayDate() {
        return formatDate(new Date());
    }

    /**
     * Format date to YYYY-MM-DD
     */
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Public API
    return {
        open: open,
        close: close,
        confirmLimitWarning: confirmLimitWarning  // Phase 5: Expose for limit warning modal
    };
})();
