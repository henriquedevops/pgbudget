/**
 * Transfer Modal JavaScript
 * Phase 3.5 - Account Transfers Simplified
 *
 * Handles the account transfer modal functionality
 */

// Prevent redeclaration error if script is loaded multiple times
if (typeof TransferModal === 'undefined') {
    var TransferModal;
}

TransferModal = (function() {
    'use strict';

    // State
    let currentLedgerUuid = null;
    let accounts = [];
    let isSubmitting = false;

    /**
     * Initialize the transfer modal
     */
    function init() {
        const form = document.getElementById('transfer-form');
        if (form) {
            form.addEventListener('submit', handleSubmit);
        }

        // Setup account selection change handlers
        const fromAccount = document.getElementById('transfer-from-account');
        const toAccount = document.getElementById('transfer-to-account');

        if (fromAccount) {
            fromAccount.addEventListener('change', updateVisualDisplay);
        }

        if (toAccount) {
            toAccount.addEventListener('change', updateVisualDisplay);
        }

        // Setup amount input handler
        const amountInput = document.getElementById('transfer-amount');
        if (amountInput) {
            amountInput.addEventListener('input', handleAmountInput);
            amountInput.addEventListener('blur', formatAmountOnBlur);
        }

        // Keyboard shortcut: Escape to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('transfer-modal');
                if (modal && modal.style.display !== 'none') {
                    close();
                }
            }
        });

        console.log('TransferModal initialized');
    }

    /**
     * Open the transfer modal
     * @param {Object} options - Configuration options
     * @param {string} options.ledger_uuid - The ledger UUID
     * @param {string} [options.from_account_uuid] - Pre-selected source account
     * @param {string} [options.to_account_uuid] - Pre-selected destination account
     */
    function open(options) {
        options = options || {};

        currentLedgerUuid = options.ledger_uuid || getLedgerUuidFromPage();

        if (!currentLedgerUuid) {
            console.error('No ledger UUID provided to TransferModal.open()');
            alert('Unable to open transfer modal: No ledger specified');
            return;
        }

        // Load accounts and categories for this ledger
        loadAccountsAndCategories(function() {
            // Reset form
            resetForm();

            // Pre-select accounts if provided
            if (options.from_account_uuid) {
                const fromSelect = document.getElementById('transfer-from-account');
                if (fromSelect) {
                    fromSelect.value = options.from_account_uuid;
                }
            }

            if (options.to_account_uuid) {
                const toSelect = document.getElementById('transfer-to-account');
                if (toSelect) {
                    toSelect.value = options.to_account_uuid;
                }
            }

            // Set default date to today
            setDateToday();

            // Update visual display
            updateVisualDisplay();

            // Show modal with animation
            const modal = document.getElementById('transfer-modal');
            if (modal) {
                modal.style.display = 'flex';
                setTimeout(function() {
                    modal.classList.add('show');

                    // Focus first empty required field
                    const fromAccount = document.getElementById('transfer-from-account');
                    const toAccount = document.getElementById('transfer-to-account');
                    const amountInput = document.getElementById('transfer-amount');

                    if (!fromAccount.value) {
                        fromAccount.focus();
                    } else if (!toAccount.value) {
                        toAccount.focus();
                    } else if (!amountInput.value) {
                        amountInput.focus();
                    }
                }, 10);
            }
        });
    }

    /**
     * Close the transfer modal
     */
    function close() {
        const modal = document.getElementById('transfer-modal');
        if (modal) {
            modal.classList.remove('show');
            setTimeout(function() {
                modal.style.display = 'none';
            }, 200);
        }
    }

    /**
     * Reset the form to initial state
     */
    function resetForm() {
        const form = document.getElementById('transfer-form');
        if (form) {
            form.reset();
        }

        hideError();
        hideSuccess();

        // Hide visual displays
        const visual = document.getElementById('transfer-visual');
        if (visual) {
            visual.style.display = 'none';
        }

        const amountDisplay = document.getElementById('amount-display');
        if (amountDisplay) {
            amountDisplay.style.display = 'none';
        }

        isSubmitting = false;
        updateSubmitButton();
    }

    /**
     * Handle form submission
     */
    function handleSubmit(e) {
        e.preventDefault();

        if (isSubmitting) {
            return;
        }

        hideError();
        hideSuccess();

        // Get form data
        const fromAccountUuid = document.getElementById('transfer-from-account').value;
        const toAccountUuid = document.getElementById('transfer-to-account').value;
        const amount = document.getElementById('transfer-amount').value;
        const date = document.getElementById('transfer-date').value;
        const memo = document.getElementById('transfer-memo').value;

        // Validate
        if (!fromAccountUuid) {
            showError('Please select a source account');
            return;
        }

        if (!toAccountUuid) {
            showError('Please select a destination account');
            return;
        }

        if (fromAccountUuid === toAccountUuid) {
            showError('Cannot transfer to the same account');
            return;
        }

        if (!amount || parseFloat(parseAmount(amount)) <= 0) {
            showError('Please enter a valid amount');
            return;
        }

        if (!date) {
            showError('Please select a date');
            return;
        }

        // Prepare data
        const data = {
            ledger_uuid: currentLedgerUuid,
            from_account_uuid: fromAccountUuid,
            to_account_uuid: toAccountUuid,
            amount: parseAmount(amount),
            date: date,
            memo: memo || null
        };

        // Submit
        submitTransfer(data, function(success, response) {
            if (success) {
                showSuccess('Transfer completed successfully!');

                // Close modal after a short delay
                setTimeout(function() {
                    close();

                    // Reload the page to show updated balances
                    window.location.reload();
                }, 1500);
            } else {
                showError(response.error || 'Failed to create transfer');
            }
        });
    }

    /**
     * Submit transfer to API
     */
    function submitTransfer(data, callback) {
        isSubmitting = true;
        updateSubmitButton();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/pgbudget/api/account-transfer.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json');

        xhr.onload = function() {
            isSubmitting = false;
            updateSubmitButton();

            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    callback(response.success === true, response);
                } catch (e) {
                    console.error('Failed to parse response:', e);
                    callback(false, { error: 'Invalid response from server' });
                }
            } else {
                try {
                    const response = JSON.parse(xhr.responseText);
                    callback(false, response);
                } catch (e) {
                    callback(false, { error: 'Server error: ' + xhr.status });
                }
            }
        };

        xhr.onerror = function() {
            isSubmitting = false;
            updateSubmitButton();
            callback(false, { error: 'Network error' });
        };

        xhr.send(JSON.stringify(data));
    }

    /**
     * Load accounts for the ledger
     */
    function loadAccountsAndCategories(callback) {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '../api/ledger-data.php?ledger=' + encodeURIComponent(currentLedgerUuid), true);

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success && response.accounts) {
                        accounts = response.accounts;
                        populateAccountSelects();
                        callback();
                    } else {
                        console.error('Failed to load accounts:', response.error);
                        alert('Failed to load accounts: ' + (response.error || 'Unknown error'));
                    }
                } catch (e) {
                    console.error('Failed to parse accounts response:', e);
                    alert('Failed to load accounts');
                }
            } else {
                console.error('Failed to load accounts, status:', xhr.status);
                alert('Failed to load accounts');
            }
        };

        xhr.onerror = function() {
            console.error('Network error loading accounts');
            alert('Network error loading accounts');
        };

        xhr.send();
    }

    /**
     * Populate account select dropdowns
     */
    function populateAccountSelects() {
        const fromSelect = document.getElementById('transfer-from-account');
        const toSelect = document.getElementById('transfer-to-account');

        if (!fromSelect || !toSelect) {
            return;
        }

        // Clear existing options (except the first placeholder)
        fromSelect.innerHTML = '<option value="">Select source account...</option>';
        toSelect.innerHTML = '<option value="">Select destination account...</option>';

        // Add account options
        accounts.forEach(function(account) {
            const fromOption = document.createElement('option');
            fromOption.value = account.uuid;
            fromOption.textContent = account.name + ' (' + formatAccountType(account.type) + ')';
            fromSelect.appendChild(fromOption);

            const toOption = document.createElement('option');
            toOption.value = account.uuid;
            toOption.textContent = account.name + ' (' + formatAccountType(account.type) + ')';
            toSelect.appendChild(toOption);
        });
    }

    /**
     * Format account type for display
     */
    function formatAccountType(type) {
        const types = {
            'asset': 'Asset',
            'liability': 'Liability'
        };
        return types[type] || type;
    }

    /**
     * Update visual transfer display
     */
    function updateVisualDisplay() {
        const fromSelect = document.getElementById('transfer-from-account');
        const toSelect = document.getElementById('transfer-to-account');
        const visual = document.getElementById('transfer-visual');
        const visualFromAccount = document.getElementById('visual-from-account');
        const visualToAccount = document.getElementById('visual-to-account');

        if (!fromSelect || !toSelect || !visual || !visualFromAccount || !visualToAccount) {
            return;
        }

        // Show visual if both accounts are selected
        if (fromSelect.value && toSelect.value) {
            const fromText = fromSelect.options[fromSelect.selectedIndex].text;
            const toText = toSelect.options[toSelect.selectedIndex].text;

            visualFromAccount.textContent = fromText;
            visualToAccount.textContent = toText;

            visual.style.display = 'flex';
        } else {
            visual.style.display = 'none';
        }
    }

    /**
     * Handle amount input
     */
    function handleAmountInput(e) {
        const input = e.target;
        const value = input.value;

        // Allow only numbers, comma, period, and minus
        input.value = value.replace(/[^0-9,.-]/g, '');

        updateAmountDisplay();
    }

    /**
     * Format amount on blur
     */
    function formatAmountOnBlur(e) {
        const input = e.target;
        const value = input.value;

        if (!value) {
            return;
        }

        const amount = parseAmount(value);
        if (!isNaN(amount) && amount > 0) {
            input.value = formatAmount(amount);
        }

        updateAmountDisplay();
    }

    /**
     * Update amount display
     */
    function updateAmountDisplay() {
        const amountInput = document.getElementById('transfer-amount');
        const amountDisplay = document.getElementById('amount-display');
        const visualAmount = document.getElementById('visual-amount');

        if (!amountInput || !amountDisplay || !visualAmount) {
            return;
        }

        const value = amountInput.value;
        if (value) {
            const amount = parseAmount(value);
            if (!isNaN(amount) && amount > 0) {
                visualAmount.textContent = '$' + formatAmount(amount);
                amountDisplay.style.display = 'block';
            } else {
                amountDisplay.style.display = 'none';
            }
        } else {
            amountDisplay.style.display = 'none';
        }
    }

    /**
     * Parse amount from string (handles comma and period as decimal separator)
     */
    function parseAmount(value) {
        if (!value) {
            return 0;
        }

        // Remove any whitespace
        value = value.trim();

        // Handle both comma and period as decimal separators
        const commaPos = value.lastIndexOf(',');
        const periodPos = value.lastIndexOf('.');

        if (commaPos !== -1 && periodPos !== -1) {
            // Both exist, the last one is the decimal separator
            if (commaPos > periodPos) {
                // Comma is decimal separator
                value = value.replace(/\./g, '').replace(',', '.');
            } else {
                // Period is decimal separator
                value = value.replace(/,/g, '');
            }
        } else if (commaPos !== -1) {
            // Only comma exists, treat as decimal separator
            value = value.replace(',', '.');
        }
        // If only period exists, no change needed

        return parseFloat(value) || 0;
    }

    /**
     * Format amount for display
     */
    function formatAmount(amount) {
        return parseFloat(amount).toFixed(2);
    }

    /**
     * Set date to today
     */
    function setDateToday() {
        const dateInput = document.getElementById('transfer-date');
        if (dateInput) {
            const today = new Date();
            dateInput.value = formatDateForInput(today);
        }
    }

    /**
     * Set date to yesterday
     */
    function setDateYesterday() {
        const dateInput = document.getElementById('transfer-date');
        if (dateInput) {
            const yesterday = new Date();
            yesterday.setDate(yesterday.getDate() - 1);
            dateInput.value = formatDateForInput(yesterday);
        }
    }

    /**
     * Format date for input field (YYYY-MM-DD)
     */
    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    /**
     * Get ledger UUID from current page
     */
    function getLedgerUuidFromPage() {
        // Try to get from URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        const ledgerParam = urlParams.get('ledger');
        if (ledgerParam) {
            return ledgerParam;
        }

        // Try to get from data attribute on body or main element
        const body = document.body;
        if (body && body.dataset.ledgerUuid) {
            return body.dataset.ledgerUuid;
        }

        const main = document.querySelector('main');
        if (main && main.dataset.ledgerUuid) {
            return main.dataset.ledgerUuid;
        }

        return null;
    }

    /**
     * Show error message
     */
    function showError(message) {
        const errorDiv = document.getElementById('transfer-error');
        if (errorDiv) {
            errorDiv.textContent = message;
            errorDiv.classList.add('show');
        }
    }

    /**
     * Hide error message
     */
    function hideError() {
        const errorDiv = document.getElementById('transfer-error');
        if (errorDiv) {
            errorDiv.classList.remove('show');
        }
    }

    /**
     * Show success message
     */
    function showSuccess(message) {
        const successDiv = document.getElementById('transfer-success');
        if (successDiv) {
            successDiv.textContent = message;
            successDiv.classList.add('show');
        }
    }

    /**
     * Hide success message
     */
    function hideSuccess() {
        const successDiv = document.getElementById('transfer-success');
        if (successDiv) {
            successDiv.classList.remove('show');
        }
    }

    /**
     * Update submit button state
     */
    function updateSubmitButton() {
        const submitBtn = document.getElementById('transfer-submit-btn');
        if (!submitBtn) {
            return;
        }

        if (isSubmitting) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span>Transferring...';
        } else {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Transfer Money';
        }
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
        setDateToday: setDateToday,
        setDateYesterday: setDateYesterday
    };
})();
