/**
 * Budget Dashboard Enhancements (Phase 1.3)
 * - Sticky header for budget totals
 * - Enhanced color coding
 * - Quick-add transaction modal
 * - Overspending handling
 */

(function() {
    'use strict';

    // Configuration
    const config = {
        stickyHeaderThreshold: 150,
        quickAddModalId: 'quick-add-transaction-modal',
        coverOverspendingModalId: 'cover-overspending-modal'
    };

    // State
    let ledgerUuid = null;
    let lastScrollPosition = 0;

    /**
     * Initialize dashboard enhancements
     */
    function init() {
        // Get ledger UUID from URL
        const urlParams = new URLSearchParams(window.location.search);
        ledgerUuid = urlParams.get('ledger');

        if (!ledgerUuid) {
            console.error('Ledger UUID not found in URL');
            return;
        }

        // Initialize features
        initializeStickyHeader();
        initializeColorCoding();
        initializeQuickAddButton();
        initializeOverspendingHandling();

        console.log('Budget dashboard enhancements initialized');
    }

    /**
     * Initialize sticky header for budget totals
     */
    function initializeStickyHeader() {
        const banner = document.querySelector('.ready-to-assign-banner');
        if (!banner) return;

        // Create sticky clone
        const stickyBanner = banner.cloneNode(true);
        stickyBanner.classList.add('sticky-budget-header');
        stickyBanner.style.display = 'none';

        // Insert after original banner
        banner.parentNode.insertBefore(stickyBanner, banner.nextSibling);

        // Handle scroll
        window.addEventListener('scroll', function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const bannerBottom = banner.offsetTop + banner.offsetHeight;

            if (scrollTop > bannerBottom + config.stickyHeaderThreshold) {
                stickyBanner.style.display = 'flex';
                stickyBanner.classList.add('show-sticky');
            } else {
                stickyBanner.classList.remove('show-sticky');
                setTimeout(() => {
                    if (!stickyBanner.classList.contains('show-sticky')) {
                        stickyBanner.style.display = 'none';
                    }
                }, 300);
            }

            lastScrollPosition = scrollTop;
        });
    }

    /**
     * Initialize enhanced color coding for categories
     */
    function initializeColorCoding() {
        const categoryRows = document.querySelectorAll('.category-row');

        categoryRows.forEach(row => {
            const balanceCell = row.querySelector('.category-balance');
            if (!balanceCell) return;

            // Get balance value
            const balanceText = balanceCell.textContent.trim();
            const balanceValue = parseFloat(balanceText.replace(/[^0-9.-]/g, '')) || 0;

            // Apply color coding
            applyCategoryColorCoding(row, balanceValue);
        });
    }

    /**
     * Apply color coding to a category row based on balance
     */
    function applyCategoryColorCoding(row, balance) {
        // Remove existing color classes
        row.classList.remove('category-green', 'category-yellow', 'category-red');

        if (balance < 0) {
            // Red: Overspent
            row.classList.add('category-red', 'overspent');
        } else if (balance === 0) {
            // Yellow: Fully spent or not budgeted
            row.classList.add('category-yellow');
        } else {
            // Green: Has remaining balance
            row.classList.add('category-green');
        }
    }

    /**
     * Initialize quick-add transaction button
     */
    function initializeQuickAddButton() {
        // Add quick-add button to budget header if it doesn't exist
        const budgetActions = document.querySelector('.budget-actions');
        if (!budgetActions) return;

        // Check if button already exists
        if (document.querySelector('.quick-add-transaction-btn')) return;

        // Create button
        const quickAddBtn = document.createElement('button');
        quickAddBtn.type = 'button';
        quickAddBtn.className = 'btn btn-success quick-add-transaction-btn';
        quickAddBtn.innerHTML = '⚡ Quick Add Transaction';
        quickAddBtn.title = 'Keyboard shortcut: T';

        // Insert button
        budgetActions.insertBefore(quickAddBtn, budgetActions.firstChild);

        // Add event listener
        quickAddBtn.addEventListener('click', openQuickAddModal);

        // Add keyboard shortcut (T key)
        document.addEventListener('keydown', function(e) {
            // Only if not in an input field
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }

            if (e.key === 't' || e.key === 'T') {
                e.preventDefault();
                openQuickAddModal();
            }
        });
    }

    /**
     * Open quick-add transaction modal
     */
    async function openQuickAddModal() {
        // Remove existing modal if any
        const existingModal = document.getElementById(config.quickAddModalId);
        if (existingModal) {
            existingModal.remove();
        }

        // Get categories for dropdown
        const categories = getAllCategories();
        const accounts = await getAllAccounts();

        // Create modal
        const modal = document.createElement('div');
        modal.id = config.quickAddModalId;
        modal.className = 'modal-backdrop';

        modal.innerHTML = `
            <div class="modal-content quick-add-modal">
                <div class="modal-header">
                    <h2>⚡ Quick Add Transaction</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="quick-add-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="quick-transaction-type" class="form-label">Type *</label>
                                <select id="quick-transaction-type" class="form-select" required>
                                    <option value="outflow" selected>Expense (Outflow)</option>
                                    <option value="inflow">Income (Inflow)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="quick-amount" class="form-label">Amount *</label>
                                <input type="text" id="quick-amount" class="form-input" required
                                       placeholder="0.00" autocomplete="off">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="quick-description" class="form-label">Description *</label>
                            <input type="text" id="quick-description" class="form-input" required
                                   placeholder="e.g., Grocery shopping" autocomplete="off">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="quick-account" class="form-label">Account *</label>
                                <select id="quick-account" class="form-select" required>
                                    <option value="">Choose account...</option>
                                    ${accounts.length === 0 ? '<option value="" disabled>No accounts found - please create an account first</option>' : ''}
                                    ${accounts.map(acc => `
                                        <option value="${acc.uuid}">${acc.name}${acc.type ? ' (' + acc.type.charAt(0).toUpperCase() + acc.type.slice(1) + ')' : ''}</option>
                                    `).join('')}
                                </select>
                                ${accounts.length === 0 ? '<small class="form-help" style="color: #e53e3e;">You need to create at least one account before adding transactions.</small>' : ''}
                            </div>

                            <div class="form-group">
                                <label for="quick-category" class="form-label">Category *</label>
                                <select id="quick-category" class="form-select" required>
                                    <option value="">Choose category...</option>
                                    ${categories.map(cat => `
                                        <option value="${cat.uuid}">${cat.name}</option>
                                    `).join('')}
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="quick-date" class="form-label">Date *</label>
                            <input type="date" id="quick-date" class="form-input" required
                                   value="${new Date().toISOString().split('T')[0]}">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Add Transaction</button>
                            <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Show modal
        setTimeout(() => {
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);
        }, 10);

        // Setup handlers
        modal.querySelector('.modal-close').addEventListener('click', () => closeQuickAddModal());
        modal.querySelector('#quick-add-form').addEventListener('submit', handleQuickAddSubmit);
        modal.querySelector('#quick-amount').addEventListener('input', function(e) {
            validateCurrencyInput(e.target);
        });

        // Focus description field
        modal.querySelector('#quick-description').focus();

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                closeQuickAddModal();
            }
        });
    }

    /**
     * Handle quick-add form submission
     */
    async function handleQuickAddSubmit(e) {
        e.preventDefault();

        const form = e.target;

        const type = document.getElementById('quick-transaction-type').value;
        const amount = document.getElementById('quick-amount').value;
        const description = document.getElementById('quick-description').value;
        const account = document.getElementById('quick-account').value;
        const category = document.getElementById('quick-category').value;
        const date = document.getElementById('quick-date').value;

        // Validate
        if (!amount || !description || !account || !category || !date) {
            showNotification('Please fill in all required fields', 'error');
            return;
        }

        // Parse amount to validate
        const amountCents = parseCurrencyInput(amount);
        if (amountCents <= 0) {
            showNotification('Amount must be greater than zero', 'error');
            return;
        }

        // Show loading
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = '⚡ Adding...';

        try {
            // Call the API to create transaction
            // Use absolute path from document root
            const apiUrl = '/pgbudget/api/quick_add_transaction.php';
            console.log('Calling API:', apiUrl);

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ledger_uuid: ledgerUuid,
                    type: type,
                    amount: amount,
                    description: description,
                    account_uuid: account,
                    category_uuid: category,
                    date: date
                })
            });

            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);

            // Get response text first to see what we're actually receiving
            const responseText = await response.text();
            console.log('Response text:', responseText.substring(0, 500));

            // Try to parse as JSON
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (jsonError) {
                console.error('JSON parse error:', jsonError);
                console.error('Response was:', responseText);
                throw new Error('Invalid JSON response from server. Server returned HTML instead of JSON.');
            }

            if (data.success) {
                // Show success message
                showNotification(data.message, 'success');

                // Update budget totals if provided
                if (data.updated_totals) {
                    updateBudgetTotalsUI(data.updated_totals);
                }

                // Close modal
                closeQuickAddModal();

                // Reload page after short delay to show updated transactions
                setTimeout(() => {
                    window.location.reload();
                }, 1500);

            } else {
                showNotification(data.error || 'Failed to add transaction', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }

        } catch (error) {
            console.error('Quick add error:', error);
            showNotification('Network error: ' + error.message, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }

    /**
     * Update budget totals in the UI
     */
    function updateBudgetTotalsUI(totals) {
        // Update Ready to Assign banner
        const banner = document.querySelector('.ready-to-assign-banner');
        if (banner) {
            const amountEl = banner.querySelector('.ready-to-assign-amount');
            if (amountEl && totals.left_to_budget_formatted) {
                amountEl.textContent = totals.left_to_budget_formatted;
            }

            // Update banner color
            banner.classList.remove('has-funds', 'zero-funds', 'negative-funds');
            if (totals.left_to_budget > 0) {
                banner.classList.add('has-funds');
            } else if (totals.left_to_budget === 0) {
                banner.classList.add('zero-funds');
            } else {
                banner.classList.add('negative-funds');
            }
        }

        // Update budgeted total
        const budgetedEl = document.querySelector('.total-budgeted-amount');
        if (budgetedEl && totals.budgeted !== undefined) {
            budgetedEl.textContent = formatCurrency(totals.budgeted);
        }
    }

    /**
     * Close quick-add modal
     */
    function closeQuickAddModal() {
        const modal = document.getElementById(config.quickAddModalId);
        if (modal) {
            modal.classList.remove('show');
            setTimeout(() => modal.remove(), 300);
        }
    }

    /**
     * Initialize overspending handling
     */
    function initializeOverspendingHandling() {
        // Check for overspent categories
        const overspentRows = document.querySelectorAll('.category-row.overspent');

        if (overspentRows.length > 0) {
            // Add warning banner if overspending detected
            addOverspendingWarningBanner(overspentRows.length);

            // Add "Cover" buttons to overspent categories
            overspentRows.forEach(row => {
                addCoverOverspendingButton(row);
            });
        }
    }

    /**
     * Add warning banner for overspending
     */
    function addOverspendingWarningBanner(count) {
        const container = document.querySelector('.container');
        if (!container) return;

        // Check if banner already exists
        if (document.querySelector('.overspending-warning-banner')) return;

        const banner = document.createElement('div');
        banner.className = 'overspending-warning-banner';
        banner.innerHTML = `
            <div class="warning-content">
                <span class="warning-icon">⚠️</span>
                <div class="warning-text">
                    <strong>Overspending Detected
                        <span class="info-tooltip" title="When a category is overspent, it means you spent more than you budgeted. This reduces your overall available funds and should be addressed by either covering it now or handling it next month.">ℹ️</span>
                    </strong>
                    <span>You have ${count} ${count === 1 ? 'category' : 'categories'} with negative balance. Click the 🔧 Cover button to handle ${count === 1 ? 'it' : 'them'}.</span>
                </div>
            </div>
            <button type="button" class="btn btn-small btn-warning-action" onclick="document.querySelector('.categories-section').scrollIntoView({behavior: 'smooth'})">
                Review Categories
            </button>
        `;

        // Insert after period selector or at beginning
        const periodSelector = document.querySelector('.period-selector');
        if (periodSelector) {
            periodSelector.parentNode.insertBefore(banner, periodSelector.nextSibling);
        } else {
            container.insertBefore(banner, container.firstChild);
        }
    }

    /**
     * Add "Cover Overspending" button to a category row
     */
    function addCoverOverspendingButton(row) {
        const actionsCell = row.querySelector('.category-actions-cell');
        if (!actionsCell) return;

        // Check if button already exists
        if (actionsCell.querySelector('.cover-overspending-btn')) return;

        const categoryUuid = row.dataset.categoryUuid || row.querySelector('.budget-amount-editable')?.dataset.categoryUuid;
        const categoryName = row.querySelector('.category-name')?.textContent.trim();
        const balanceCell = row.querySelector('.category-balance');
        const balanceText = balanceCell?.textContent.trim();
        const balanceValue = parseFloat(balanceText?.replace(/[^0-9.-]/g, '')) || 0;
        const overspentAmount = Math.abs(balanceValue);

        const coverBtn = document.createElement('button');
        coverBtn.type = 'button';
        coverBtn.className = 'btn btn-small btn-danger cover-overspending-btn';
        coverBtn.innerHTML = '🔧 Cover';
        coverBtn.title = 'Cover overspending from another category';
        coverBtn.dataset.categoryUuid = categoryUuid;
        coverBtn.dataset.categoryName = categoryName;
        coverBtn.dataset.overspentAmount = overspentAmount;

        coverBtn.addEventListener('click', function() {
            openCoverOverspendingModal(categoryUuid, categoryName, overspentAmount);
        });

        // Insert at beginning of actions cell
        actionsCell.insertBefore(coverBtn, actionsCell.firstChild);
    }

    /**
     * Open cover overspending modal
     */
    function openCoverOverspendingModal(categoryUuid, categoryName, overspentAmount) {
        // Remove existing modal if any
        const existingModal = document.getElementById(config.coverOverspendingModalId);
        if (existingModal) {
            existingModal.remove();
        }

        // Get categories with positive balance
        const availableCategories = getAllCategories().filter(cat => cat.balance > 0);

        const modal = document.createElement('div');
        modal.id = config.coverOverspendingModalId;
        modal.className = 'modal-backdrop';

        modal.innerHTML = `
            <div class="modal-content cover-overspending-modal">
                <div class="modal-header">
                    <h2>🔧 Handle Overspending</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="overspending-summary">
                        <p><strong>${categoryName}</strong> is overspent by <span class="negative">$${overspentAmount.toFixed(2)}</span></p>
                        <p class="modal-description">Choose how you want to handle this overspending.</p>
                    </div>

                    <!-- What overspending means section -->
                    <div class="overspending-explanation">
                        <h4>⚠️ What Does This Mean?</h4>
                        <p>When a category is overspent, it means you've spent more money than you budgeted for this category. This creates a negative balance that needs to be addressed.</p>
                        <p><strong>Important:</strong> Overspending reduces your overall available funds. You need to account for this by either:</p>
                        <ul>
                            <li><strong>Cover it now</strong> - Move money from another category (recommended)</li>
                            <li><strong>Handle next month</strong> - Deduct from next month's budget</li>
                        </ul>
                    </div>

                    <form id="cover-overspending-form">
                        <!-- Handling method selection -->
                        <div class="form-group">
                            <label class="form-label">How do you want to handle this? *</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="handling-method" value="cover-now" checked>
                                    <span class="radio-label">
                                        <strong>Cover Now</strong>
                                        <small>Move money from another category to fix this immediately (YNAB Rule 3)</small>
                                    </span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="handling-method" value="next-month">
                                    <span class="radio-label">
                                        <strong>Deduct From Next Month</strong>
                                        <small>Let this carry over and reduce next month's available budget</small>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Cover now section -->
                        <div id="cover-now-section" class="conditional-section">
                            <div class="form-group">
                                <label for="cover-from-category" class="form-label">Cover From Category *</label>
                                <select id="cover-from-category" class="form-select" required>
                                    <option value="">Choose category...</option>
                                    ${availableCategories.map(cat => `
                                        <option value="${cat.uuid}" data-balance="${cat.balance}">
                                            ${cat.name} (Available: ${formatCurrency(cat.balance)})
                                        </option>
                                    `).join('')}
                                </select>
                                <small class="form-help available-balance-help"></small>
                            </div>

                            <div class="form-group">
                                <label for="cover-amount" class="form-label">Amount to Cover *</label>
                                <input type="text" id="cover-amount" class="form-input" required
                                       value="${overspentAmount.toFixed(2)}"
                                       placeholder="0.00" autocomplete="off">
                                <small class="form-help">Defaults to full overspent amount. You can cover partially.</small>
                            </div>
                        </div>

                        <!-- Next month section -->
                        <div id="next-month-section" class="conditional-section" style="display: none;">
                            <div class="info-box info-warning">
                                <p><strong>⏭️ Carrying Over to Next Month</strong></p>
                                <p>When you choose this option:</p>
                                <ul>
                                    <li>The negative balance of <strong>$${overspentAmount.toFixed(2)}</strong> will remain in this category</li>
                                    <li>Next month, you'll need to budget extra to cover both this overspending and your regular budget</li>
                                    <li>This category will start next month at <strong>-$${overspentAmount.toFixed(2)}</strong></li>
                                    <li>Best for rare overspending situations or when you genuinely don't have funds to cover it now</li>
                                </ul>
                                <p class="warning-text">⚠️ Note: It's generally better to cover overspending immediately to maintain accurate budget awareness.</p>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submit-overspending-btn">Cover Overspending</button>
                            <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        </div>
                    </form>

                    <div class="move-money-help">
                        <h4>💡 Best Practice (YNAB Rule 3: Roll With The Punches)</h4>
                        <p>Life happens! Budget categories aren't predictions—they're plans that can change.</p>
                        <p><strong>When you overspend:</strong></p>
                        <ul>
                            <li>✅ <strong>Cover immediately</strong> by moving money from another category</li>
                            <li>✅ This keeps your budget accurate and shows your true financial picture</li>
                            <li>✅ Common practice: Move from flexible categories (Dining Out, Entertainment) to cover essentials</li>
                            <li>⚠️ Carrying over to next month can make it harder to budget accurately</li>
                        </ul>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Show modal
        setTimeout(() => {
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);
        }, 10);

        // Setup handlers
        modal.querySelector('.modal-close').addEventListener('click', () => closeCoverOverspendingModal());
        modal.querySelector('#cover-overspending-form').addEventListener('submit', function(e) {
            handleCoverOverspendingSubmit(e, categoryUuid, categoryName, overspentAmount);
        });
        modal.querySelector('#cover-amount').addEventListener('input', function(e) {
            validateCurrencyInput(e.target);
        });

        // Handle source category change
        const fromSelect = modal.querySelector('#cover-from-category');
        fromSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const balance = selectedOption?.dataset.balance || 0;
            const helpText = this.parentElement.querySelector('.available-balance-help');
            if (helpText && balance) {
                helpText.textContent = `Available to move: ${formatCurrency(parseInt(balance))}`;
                helpText.style.color = '#38a169';
            }
        });

        // Handle handling method radio buttons
        const radioButtons = modal.querySelectorAll('input[name="handling-method"]');
        const coverNowSection = modal.querySelector('#cover-now-section');
        const nextMonthSection = modal.querySelector('#next-month-section');
        const submitBtn = modal.querySelector('#submit-overspending-btn');
        const coverFromCategory = modal.querySelector('#cover-from-category');
        const coverAmount = modal.querySelector('#cover-amount');

        radioButtons.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'cover-now') {
                    coverNowSection.style.display = 'block';
                    nextMonthSection.style.display = 'none';
                    submitBtn.textContent = 'Cover Overspending';
                    coverFromCategory.required = true;
                    coverAmount.required = true;
                } else if (this.value === 'next-month') {
                    coverNowSection.style.display = 'none';
                    nextMonthSection.style.display = 'block';
                    submitBtn.textContent = 'Handle Next Month';
                    coverFromCategory.required = false;
                    coverAmount.required = false;
                }
            });
        });

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                closeCoverOverspendingModal();
            }
        });
    }

    /**
     * Handle cover overspending form submission
     */
    async function handleCoverOverspendingSubmit(e, toCategory, toCategoryName, overspentAmount) {
        e.preventDefault();

        const form = e.target;
        const handlingMethod = form.querySelector('input[name="handling-method"]:checked')?.value;

        // Show loading
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;

        // Handle "next month" option
        if (handlingMethod === 'next-month') {
            submitBtn.textContent = 'Acknowledged...';

            // Just acknowledge and close - the negative balance will naturally carry forward
            showNotification(
                `Overspending of $${overspentAmount.toFixed(2)} in ${toCategoryName} will be handled next month. ` +
                `Remember to budget extra next month to cover this.`,
                'info'
            );

            closeCoverOverspendingModal();
            return;
        }

        // Handle "cover now" option
        const fromCategory = document.getElementById('cover-from-category').value;
        const amountStr = document.getElementById('cover-amount').value;

        if (!fromCategory || !amountStr) {
            showNotification('Please fill in all required fields', 'error');
            submitBtn.disabled = false;
            return;
        }

        const amount = parseCurrencyInput(amountStr);
        if (amount <= 0) {
            showNotification('Amount must be greater than zero', 'error');
            submitBtn.disabled = false;
            return;
        }

        // Check available balance
        const fromOption = document.querySelector(`#cover-from-category option[value="${fromCategory}"]`);
        const availableBalance = fromOption ? parseInt(fromOption.dataset.balance) : 0;

        if (amount > availableBalance) {
            showNotification(`Insufficient funds. Available: ${formatCurrency(availableBalance)}`, 'error');
            submitBtn.disabled = false;
            return;
        }

        submitBtn.textContent = '🔧 Covering...';

        try {
            // Use the move money API
            const response = await fetch('../api/move_money.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ledger_uuid: ledgerUuid,
                    from_category_uuid: fromCategory,
                    to_category_uuid: toCategory,
                    amount: amountStr,
                    date: new Date().toISOString().split('T')[0],
                    description: `Cover overspending in ${toCategoryName}`
                })
            });

            const data = await response.json();

            if (data.success) {
                showNotification(data.message, 'success');
                closeCoverOverspendingModal();
                // Reload page to update all values
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showNotification(data.error || 'Failed to cover overspending', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }

        } catch (error) {
            console.error('Cover overspending error:', error);
            showNotification('Network error: ' + error.message, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }

    /**
     * Close cover overspending modal
     */
    function closeCoverOverspendingModal() {
        const modal = document.getElementById(config.coverOverspendingModalId);
        if (modal) {
            modal.classList.remove('show');
            setTimeout(() => modal.remove(), 300);
        }
    }

    /**
     * Get all categories from the page
     */
    function getAllCategories() {
        const categories = [];
        const rows = document.querySelectorAll('.category-row');

        rows.forEach(row => {
            const nameCell = row.querySelector('.category-name');
            const budgetCell = row.querySelector('.budget-amount-editable');
            const balanceCell = row.querySelector('.category-balance');

            if (budgetCell && nameCell && balanceCell) {
                const balanceText = balanceCell.textContent.trim();
                const balanceValue = parseFloat(balanceText.replace(/[^0-9.-]/g, '')) || 0;
                const balanceInCents = Math.round(balanceValue * 100);

                categories.push({
                    uuid: budgetCell.dataset.categoryUuid,
                    name: budgetCell.dataset.categoryName || nameCell.textContent.trim(),
                    balance: balanceInCents
                });
            }
        });

        return categories;
    }

    /**
     * Get all accounts from the ledger
     */
    async function getAllAccounts() {
        try {
            // We'll need to fetch accounts from the database
            // For now, we'll embed them in the page or fetch via a simple query

            // Option 1: Try to get from a data attribute on the page
            const accountsData = document.getElementById('ledger-accounts-data');
            if (accountsData && accountsData.dataset.accounts) {
                return JSON.parse(accountsData.dataset.accounts);
            }

            // Option 2: Extract from any existing account links/selectors on the page
            const accountLinks = document.querySelectorAll('a[href*="account="]');
            const accountsMap = new Map();

            accountLinks.forEach(link => {
                const href = link.getAttribute('href');
                const match = href.match(/account=([^&]+)/);
                if (match) {
                    const uuid = match[1];
                    const name = link.textContent.trim();
                    if (uuid && name && !accountsMap.has(uuid)) {
                        accountsMap.set(uuid, { uuid, name });
                    }
                }
            });

            const accounts = Array.from(accountsMap.values());

            // If we still have no accounts, return empty array
            // The modal will show a message
            return accounts;

        } catch (error) {
            console.error('Error getting accounts:', error);
            return [];
        }
    }

    /**
     * Validate currency input
     */
    function validateCurrencyInput(input) {
        let value = input.value;
        value = value.replace(/[^0-9,.]/g, '');

        const commaIndex = value.lastIndexOf(',');
        const periodIndex = value.lastIndexOf('.');

        if (commaIndex !== -1 && periodIndex !== -1) {
            if (commaIndex > periodIndex) {
                value = value.replace(/\./g, '');
            } else {
                value = value.replace(/,/g, '');
            }
        }

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
        const normalized = value.replace(',', '.');
        const numValue = parseFloat(normalized);
        if (isNaN(numValue)) return 0;
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
     * Show notification
     */
    function showNotification(message, type = 'info') {
        const existing = document.querySelector('.dashboard-notification');
        if (existing) {
            existing.remove();
        }

        const notification = document.createElement('div');
        notification.className = `dashboard-notification notification-${type}`;
        notification.textContent = message;

        document.body.appendChild(notification);

        setTimeout(() => notification.classList.add('show'), 10);

        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 4000);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Handle escape key for all modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeQuickAddModal();
            closeCoverOverspendingModal();
        }
    });

})();
