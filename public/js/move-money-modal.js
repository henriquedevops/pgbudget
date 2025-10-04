/**
 * Move Money Modal
 * Allows users to move budget allocation between categories (YNAB Rule 3)
 */

(function() {
    'use strict';

    // Configuration
    const config = {
        apiEndpoint: '../api/move_money.php',
        modalId: 'move-money-modal'
    };

    // State
    let ledgerUuid = null;
    let allCategories = [];

    /**
     * Initialize move money functionality
     */
    function init() {
        // Get ledger UUID from page
        const urlParams = new URLSearchParams(window.location.search);
        ledgerUuid = urlParams.get('ledger');

        if (!ledgerUuid) {
            console.error('Ledger UUID not found in URL');
            return;
        }

        // Load categories
        loadCategories();

        // Setup event listeners
        setupEventListeners();

        // Verify buttons exist
        const moveButtons = document.querySelectorAll('.move-money-btn');
        console.log('Move money modal initialized. Found ' + moveButtons.length + ' move buttons');
        console.log('Ledger UUID:', ledgerUuid);
    }

    /**
     * Load categories from budget status
     */
    function loadCategories() {
        const categoryRows = document.querySelectorAll('.category-row');
        allCategories = [];

        categoryRows.forEach(row => {
            const nameCell = row.querySelector('.category-name');
            const budgetCell = row.querySelector('.budget-amount-editable');
            const balanceCell = row.querySelector('.category-balance');

            if (budgetCell && nameCell && balanceCell) {
                // Parse balance from formatted currency (e.g., "$50.00" -> 5000 cents)
                const balanceText = balanceCell.textContent.trim();
                const balanceValue = parseFloat(balanceText.replace(/[^0-9.-]/g, '')) || 0;
                const balanceInCents = Math.round(balanceValue * 100);

                allCategories.push({
                    uuid: budgetCell.dataset.categoryUuid,
                    name: budgetCell.dataset.categoryName || nameCell.textContent.trim(),
                    balance: balanceInCents
                });
            }
        });

        console.log('Loaded categories:', allCategories);
    }

    /**
     * Setup event listeners
     */
    function setupEventListeners() {
        // Listen for move money button clicks
        document.addEventListener('click', function(e) {
            const moveBtn = e.target.closest('.move-money-btn');
            if (moveBtn) {
                e.preventDefault();
                console.log('Move button clicked:', moveBtn);
                const categoryUuid = moveBtn.dataset.categoryUuid;
                const categoryName = moveBtn.dataset.categoryName;
                console.log('Category UUID:', categoryUuid, 'Name:', categoryName);
                openMoveMoneyModal(categoryUuid, categoryName);
            }

            // Close modal on backdrop click
            if (e.target.classList.contains('modal-backdrop')) {
                closeModal();
            }

            // Close modal on close button
            if (e.target.closest('.modal-close')) {
                closeModal();
            }
        });

        // Setup keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            const modal = document.getElementById(config.modalId);
            if (modal && modal.style.display !== 'none') {
                if (e.key === 'Escape') {
                    closeModal();
                } else if (e.key === 'M' && e.shiftKey) {
                    // Shift+M to open move money (if no modal open)
                    if (!modal || modal.style.display === 'none') {
                        const firstMoveBtn = document.querySelector('.move-money-btn');
                        if (firstMoveBtn) {
                            firstMoveBtn.click();
                        }
                    }
                }
            }
        });
    }

    /**
     * Open move money modal
     */
    function openMoveMoneyModal(sourceUuid = null, sourceName = null) {
        // Remove existing modal if any
        const existingModal = document.getElementById(config.modalId);
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal
        const modal = createModal(sourceUuid, sourceName);
        document.body.appendChild(modal);

        // Show modal with animation
        setTimeout(() => {
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('show');
            }, 10);
        }, 10);

        // Focus first input
        const amountInput = modal.querySelector('#move-amount');
        if (amountInput) {
            amountInput.focus();
        }
    }

    /**
     * Create modal HTML
     */
    function createModal(sourceUuid, sourceName) {
        const modal = document.createElement('div');
        modal.id = config.modalId;
        modal.className = 'modal-backdrop';

        // Filter out categories with zero balance for source
        const availableSourceCategories = allCategories.filter(cat => cat.balance > 0);

        modal.innerHTML = `
            <div class="modal-content move-money-modal">
                <div class="modal-header">
                    <h2>💸 Move Money Between Categories</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="modal-description">
                        Move budget allocation from one category to another. This helps you adjust your budget when priorities change (YNAB Rule 3: Roll With The Punches).
                    </p>

                    <form id="move-money-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="move-from-category" class="form-label">From Category *</label>
                                <select id="move-from-category" name="from_category" class="form-select" required>
                                    <option value="">Choose source...</option>
                                    ${availableSourceCategories.map(cat => `
                                        <option value="${cat.uuid}"
                                                data-balance="${cat.balance}"
                                                ${sourceUuid === cat.uuid ? 'selected' : ''}>
                                            ${cat.name} (Available: ${formatCurrency(cat.balance)})
                                        </option>
                                    `).join('')}
                                </select>
                                <small class="form-help available-balance-help"></small>
                            </div>

                            <div class="form-group">
                                <label for="move-to-category" class="form-label">To Category *</label>
                                <select id="move-to-category" name="to_category" class="form-select" required>
                                    <option value="">Choose destination...</option>
                                    ${allCategories.map(cat => `
                                        <option value="${cat.uuid}" ${sourceUuid === cat.uuid ? 'disabled' : ''}>
                                            ${cat.name}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="move-amount" class="form-label">Amount to Move *</label>
                            <input type="text" id="move-amount" name="amount" class="form-input" required
                                   placeholder="0.00"
                                   autocomplete="off">
                            <small class="form-help">Enter the amount you want to move</small>
                        </div>

                        <div class="form-group">
                            <label for="move-description" class="form-label">Description (Optional)</label>
                            <input type="text" id="move-description" name="description" class="form-input"
                                   placeholder="e.g., Adjusting grocery budget for unexpected expense">
                            <small class="form-help">Leave blank for auto-generated description</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Move Money</button>
                            <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        </div>
                    </form>

                    <div class="move-money-help">
                        <h4>💡 When to Move Money</h4>
                        <ul>
                            <li><strong>Overspending:</strong> Cover overspending in one category from another</li>
                            <li><strong>Priorities change:</strong> Reallocate when plans shift</li>
                            <li><strong>Left over funds:</strong> Move unused budget to savings or other goals</li>
                        </ul>
                    </div>
                </div>
            </div>
        `;

        // Setup form handlers
        const form = modal.querySelector('#move-money-form');
        form.addEventListener('submit', handleMoveMoneySubmit);

        // Setup source category change handler
        const fromSelect = modal.querySelector('#move-from-category');
        if (fromSelect) {
            fromSelect.addEventListener('change', handleSourceCategoryChange);

            // Initial balance display
            if (sourceUuid && fromSelect.value) {
                // Trigger the change handler after a short delay to ensure DOM is ready
                setTimeout(() => {
                    handleSourceCategoryChange.call(fromSelect);
                }, 10);
            }
        }

        // Setup amount validation
        const amountInput = modal.querySelector('#move-amount');
        if (amountInput) {
            amountInput.addEventListener('input', function(e) {
                validateCurrencyInput(e.target);
            });
        }

        return modal;
    }

    /**
     * Handle source category change
     */
    function handleSourceCategoryChange() {
        // Ensure 'this' is a select element
        if (!this || !this.options) {
            console.error('handleSourceCategoryChange called without valid select element');
            return;
        }

        const selectedOption = this.options[this.selectedIndex];
        const balance = selectedOption ? selectedOption.dataset.balance : 0;
        const helpText = this.parentElement ? this.parentElement.querySelector('.available-balance-help') : null;

        if (helpText && balance) {
            helpText.textContent = `Available to move: ${formatCurrency(parseInt(balance))}`;
            helpText.style.color = '#38a169';
        }

        // Update to-category options (disable selected from-category)
        const toSelect = document.getElementById('move-to-category');
        if (!toSelect) {
            return;
        }

        const fromValue = this.value;

        Array.from(toSelect.options).forEach(option => {
            if (option.value === fromValue) {
                option.disabled = true;
            } else {
                option.disabled = false;
            }
        });
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
     * Handle move money form submit
     */
    async function handleMoveMoneySubmit(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        const fromCategoryUuid = formData.get('from_category');
        const toCategoryUuid = formData.get('to_category');
        const amountStr = formData.get('amount');
        const description = formData.get('description');

        // Validate
        if (!fromCategoryUuid || !toCategoryUuid || !amountStr) {
            showNotification('Please fill in all required fields', 'error');
            return;
        }

        // Parse amount
        const amount = parseCurrencyInput(amountStr);
        if (amount <= 0) {
            showNotification('Amount must be greater than zero', 'error');
            return;
        }

        // Check available balance
        const fromOption = document.querySelector(`#move-from-category option[value="${fromCategoryUuid}"]`);
        const availableBalance = fromOption ? parseInt(fromOption.dataset.balance) : 0;

        if (amount > availableBalance) {
            showNotification(`Insufficient funds. Available: ${formatCurrency(availableBalance)}`, 'error');
            return;
        }

        // Show loading state
        const submitBtn = e.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = '💸 Moving...';

        try {
            const response = await fetch(config.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ledger_uuid: ledgerUuid,
                    from_category_uuid: fromCategoryUuid,
                    to_category_uuid: toCategoryUuid,
                    amount: amountStr,
                    date: new Date().toISOString().split('T')[0],
                    description: description
                })
            });

            const data = await response.json();

            if (data.success) {
                // Update UI
                updateCategoriesUI(data);
                showNotification(data.message, 'success');
                closeModal();
                // Reload categories for next move
                setTimeout(() => loadCategories(), 500);
            } else {
                showNotification(data.error || 'Failed to move money', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }

        } catch (error) {
            console.error('Move money error:', error);
            showNotification('Network error: ' + error.message, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }

    /**
     * Update categories UI after move
     */
    function updateCategoriesUI(data) {
        // Update source category
        if (data.from_category) {
            updateCategoryRow(data.from_category);
        }

        // Update destination category
        if (data.to_category) {
            updateCategoryRow(data.to_category);
        }
    }

    /**
     * Update a category row with new data
     */
    function updateCategoryRow(categoryData) {
        const row = document.querySelector(`tr[data-category-uuid="${categoryData.uuid}"]`);
        if (!row) {
            // Try finding by cell data attribute
            const cell = document.querySelector(`.budget-amount-editable[data-category-uuid="${categoryData.uuid}"]`);
            if (cell) {
                const parentRow = cell.closest('tr');
                if (parentRow) {
                    updateRowCells(parentRow, categoryData);
                }
            }
        } else {
            updateRowCells(row, categoryData);
        }
    }

    /**
     * Update row cells with category data
     */
    function updateRowCells(row, categoryData) {
        // Update budgeted cell
        const budgetCell = row.querySelector('.budget-amount-editable');
        if (budgetCell) {
            budgetCell.textContent = categoryData.budgeted_formatted;
            budgetCell.dataset.currentAmount = categoryData.budgeted;
            budgetCell.classList.add('budget-updated');
            setTimeout(() => budgetCell.classList.remove('budget-updated'), 600);
        }

        // Update activity cell
        const activityCell = row.querySelector('.category-activity');
        if (activityCell) {
            activityCell.textContent = categoryData.activity_formatted;
            updateAmountClass(activityCell, categoryData.activity);
        }

        // Update balance cell
        const balanceCell = row.querySelector('.category-balance');
        if (balanceCell) {
            balanceCell.textContent = categoryData.balance_formatted;
            updateAmountClass(balanceCell, categoryData.balance);
        }

        // Update overspent class
        if (categoryData.balance < 0) {
            row.classList.add('overspent');
        } else {
            row.classList.remove('overspent');
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
     * Format currency for display
     */
    function formatCurrency(cents) {
        if (!cents && cents !== 0) return '$0.00';
        const dollars = cents / 100;
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(dollars);
    }

    /**
     * Close modal
     */
    function closeModal() {
        const modal = document.getElementById(config.modalId);
        if (modal) {
            modal.classList.remove('show');
            setTimeout(() => {
                modal.remove();
            }, 300);
        }
    }

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        // Remove any existing notifications
        const existing = document.querySelector('.move-money-notification');
        if (existing) {
            existing.remove();
        }

        // Create notification
        const notification = document.createElement('div');
        notification.className = `move-money-notification notification-${type}`;
        notification.textContent = message;

        // Add to page
        document.body.appendChild(notification);

        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);

        // Auto-remove after 4 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 4000);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose openMoveMoneyModal globally for external triggers
    window.openMoveMoneyModal = openMoveMoneyModal;

})();
