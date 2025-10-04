/**
 * Inline Budget Assignment
 * Allows click-to-edit budget amounts directly on the budget dashboard
 */

(function() {
    'use strict';

    // Configuration
    const config = {
        editableSelector: '.budget-amount-editable',
        apiEndpoint: '../api/quick_assign.php',
        animationDuration: 300
    };

    // State
    let currentlyEditing = null;
    let ledgerUuid = null;

    /**
     * Initialize inline editing
     */
    function init() {
        // Get ledger UUID from page
        const urlParams = new URLSearchParams(window.location.search);
        ledgerUuid = urlParams.get('ledger');

        if (!ledgerUuid) {
            console.error('Ledger UUID not found in URL');
            return;
        }

        // Setup event listeners
        setupEditableFields();
        setupKeyboardShortcuts();

        console.log('Inline budget editing initialized');
    }

    /**
     * Setup click handlers for editable budget amounts
     */
    function setupEditableFields() {
        document.addEventListener('click', function(e) {
            const editableCell = e.target.closest(config.editableSelector);

            if (editableCell && currentlyEditing !== editableCell) {
                // Cancel any existing edit
                if (currentlyEditing) {
                    cancelEdit();
                }

                startEdit(editableCell);
            } else if (currentlyEditing && !e.target.closest('.inline-edit-container')) {
                // Click outside - save changes
                saveEdit();
            }
        });
    }

    /**
     * Setup keyboard shortcuts
     */
    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            if (!currentlyEditing) return;

            if (e.key === 'Enter') {
                e.preventDefault();
                saveEdit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancelEdit();
            }
        });
    }

    /**
     * Start editing a budget amount
     */
    function startEdit(cell) {
        currentlyEditing = cell;

        const categoryUuid = cell.dataset.categoryUuid;
        const currentAmount = parseFloat(cell.dataset.currentAmount) || 0;
        const categoryName = cell.dataset.categoryName || 'this category';

        // Store original content
        cell.dataset.originalContent = cell.innerHTML;

        // Create edit interface
        const editContainer = document.createElement('div');
        editContainer.className = 'inline-edit-container';
        editContainer.innerHTML = `
            <input type="text"
                   class="inline-edit-input"
                   value="${formatCurrencyForInput(currentAmount)}"
                   placeholder="0.00"
                   autocomplete="off"
                   autofocus>
            <div class="inline-edit-buttons">
                <button type="button" class="inline-edit-save" title="Save (Enter)">✓</button>
                <button type="button" class="inline-edit-cancel" title="Cancel (Esc)">✗</button>
            </div>
        `;

        // Replace cell content
        cell.innerHTML = '';
        cell.appendChild(editContainer);

        // Focus input and select all
        const input = editContainer.querySelector('.inline-edit-input');
        input.focus();
        input.select();

        // Setup button handlers
        editContainer.querySelector('.inline-edit-save').addEventListener('click', () => saveEdit());
        editContainer.querySelector('.inline-edit-cancel').addEventListener('click', () => cancelEdit());

        // Handle input validation
        input.addEventListener('input', function(e) {
            validateCurrencyInput(e.target);
        });
    }

    /**
     * Save the edited amount
     */
    async function saveEdit() {
        if (!currentlyEditing) return;

        const cell = currentlyEditing;
        const input = cell.querySelector('.inline-edit-input');
        const categoryUuid = cell.dataset.categoryUuid;
        const categoryName = cell.dataset.categoryName || 'category';

        // Parse the amount
        const amountStr = input.value.trim();
        const amount = parseCurrencyInput(amountStr);

        if (amount < 0) {
            showError('Amount must be positive');
            input.focus();
            return;
        }

        // Show loading state
        showLoadingState(cell);

        try {
            const response = await fetch(config.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ledger_uuid: ledgerUuid,
                    category_uuid: categoryUuid,
                    amount: amountStr,
                    date: new Date().toISOString().split('T')[0]
                })
            });

            const data = await response.json();

            if (data.success) {
                // Update the UI with new values
                updateBudgetUI(cell, data);
                showSuccess(data.message);
                currentlyEditing = null;
            } else {
                showError(data.error || 'Failed to assign budget');
                restoreOriginalContent(cell);
            }

        } catch (error) {
            console.error('Save error:', error);
            showError('Network error: ' + error.message);
            restoreOriginalContent(cell);
        }
    }

    /**
     * Cancel editing
     */
    function cancelEdit() {
        if (!currentlyEditing) return;

        restoreOriginalContent(currentlyEditing);
        currentlyEditing = null;
    }

    /**
     * Restore original cell content
     */
    function restoreOriginalContent(cell) {
        if (cell.dataset.originalContent) {
            cell.innerHTML = cell.dataset.originalContent;
            delete cell.dataset.originalContent;
        }
    }

    /**
     * Show loading state
     */
    function showLoadingState(cell) {
        cell.innerHTML = '<div class="inline-edit-loading">💰 Assigning...</div>';
    }

    /**
     * Update budget UI with new data
     */
    function updateBudgetUI(cell, data) {
        // Update the edited cell
        if (data.updated_category) {
            cell.innerHTML = data.updated_category.budgeted_formatted;
            cell.dataset.currentAmount = data.updated_category.budgeted;

            // Update activity and balance in the same row
            const row = cell.closest('tr');
            if (row) {
                const activityCell = row.querySelector('.category-activity');
                const balanceCell = row.querySelector('.category-balance');

                if (activityCell && data.updated_category.activity !== undefined) {
                    activityCell.textContent = data.updated_category.activity_formatted;
                    updateAmountClass(activityCell, data.updated_category.activity);
                }

                if (balanceCell && data.updated_category.balance !== undefined) {
                    balanceCell.textContent = data.updated_category.balance_formatted;
                    updateAmountClass(balanceCell, data.updated_category.balance);
                }
            }
        }

        // Update budget totals
        if (data.updated_totals) {
            const leftToBudgetEl = document.querySelector('.left-to-budget-amount');
            const budgetedEl = document.querySelector('.total-budgeted-amount');

            if (leftToBudgetEl) {
                leftToBudgetEl.textContent = data.updated_totals.left_to_budget_formatted;
                updateAmountClass(leftToBudgetEl, data.updated_totals.left_to_budget);
            }

            if (budgetedEl) {
                budgetedEl.textContent = data.updated_totals.budgeted_formatted;
            }

            // Update the banner if it exists
            updateReadyToAssignBanner(data.updated_totals.left_to_budget, data.updated_totals.left_to_budget_formatted);
        }

        // Add success animation
        cell.classList.add('budget-updated');
        setTimeout(() => {
            cell.classList.remove('budget-updated');
        }, config.animationDuration);
    }

    /**
     * Update "Ready to Assign" banner
     */
    function updateReadyToAssignBanner(amount, formattedAmount) {
        const banner = document.querySelector('.ready-to-assign-banner');
        if (banner) {
            const amountEl = banner.querySelector('.ready-to-assign-amount');
            if (amountEl) {
                amountEl.textContent = formattedAmount;
            }

            // Update banner color based on amount
            banner.classList.remove('has-funds', 'zero-funds', 'negative-funds');
            if (amount > 0) {
                banner.classList.add('has-funds');
            } else if (amount === 0) {
                banner.classList.add('zero-funds');
            } else {
                banner.classList.add('negative-funds');
            }
        }
    }

    /**
     * Update amount class (positive/negative/zero)
     */
    function updateAmountClass(element, amount) {
        element.classList.remove('positive', 'negative', 'zero');
        if (amount > 0) {
            element.classList.add('positive');
        } else if (amount < 0) {
            element.classList.add('negative');
        } else {
            element.classList.add('zero');
        }
    }

    /**
     * Validate currency input
     */
    function validateCurrencyInput(input) {
        let value = input.value;

        // Remove any characters that aren't digits, comma, or period
        value = value.replace(/[^0-9,.]/g, '');

        // Handle comma and period as decimal separators
        const commaIndex = value.lastIndexOf(',');
        const periodIndex = value.lastIndexOf('.');

        if (commaIndex !== -1 && periodIndex !== -1) {
            if (commaIndex > periodIndex) {
                value = value.replace(/\./g, '');
            } else {
                value = value.replace(/,/g, '');
            }
        }

        // Ensure only one decimal separator and max 2 decimal places
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

        input.value = value;
    }

    /**
     * Parse currency input to cents
     */
    function parseCurrencyInput(value) {
        if (!value) return 0;

        // Convert comma to period for standardization
        const normalized = value.replace(',', '.');
        const numValue = parseFloat(normalized);

        if (isNaN(numValue)) return 0;

        // Convert to cents
        return Math.round(numValue * 100);
    }

    /**
     * Format currency for input field
     */
    function formatCurrencyForInput(cents) {
        if (!cents) return '0.00';
        return (cents / 100).toFixed(2);
    }

    /**
     * Show success message
     */
    function showSuccess(message) {
        showNotification(message, 'success');
    }

    /**
     * Show error message
     */
    function showError(message) {
        showNotification(message, 'error');
    }

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        // Remove any existing notifications
        const existing = document.querySelector('.inline-edit-notification');
        if (existing) {
            existing.remove();
        }

        // Create notification
        const notification = document.createElement('div');
        notification.className = `inline-edit-notification notification-${type}`;
        notification.textContent = message;

        // Add to page
        document.body.appendChild(notification);

        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);

        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
