/**
 * Account Reconciliation
 * Phase 4.5: Credit Card Reconciliation
 *
 * Handles the account reconciliation workflow:
 * 1. Enter statement balance and date
 * 2. Load uncleared transactions
 * 3. Mark transactions as cleared
 * 4. Complete reconciliation (creates record, marks as reconciled, creates adjustment if needed)
 */

const ReconcileAccount = {
    // State
    accountUuid: null,
    accountBalance: 0,
    statementBalance: 0,
    transactions: [],
    selectedTransactionUuids: new Set(),

    /**
     * Initialize reconciliation
     */
    init() {
        const accountUuidEl = document.getElementById('account-uuid');
        if (accountUuidEl) {
            this.accountUuid = accountUuidEl.value;
        }

        // Get account balance from the page
        const balanceAmountEl = document.querySelector('.balance-amount');
        if (balanceAmountEl) {
            const balanceText = balanceAmountEl.textContent.trim();
            this.accountBalance = this.parseCurrency(balanceText);
        }

        // Set up event listeners
        this.setupEventListeners();
    },

    /**
     * Set up event listeners
     */
    setupEventListeners() {
        // Load transactions button
        const loadBtn = document.getElementById('load-transactions-btn');
        if (loadBtn) {
            loadBtn.addEventListener('click', () => this.loadTransactions());
        }

        // Statement balance input - update summary on change
        const statementBalanceInput = document.getElementById('statement-balance');
        if (statementBalanceInput) {
            statementBalanceInput.addEventListener('input', () => this.updateSummary());
        }

        // Select/deselect all buttons
        const selectAllBtn = document.getElementById('select-all-btn');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => this.selectAll());
        }

        const deselectAllBtn = document.getElementById('deselect-all-btn');
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', () => this.deselectAll());
        }

        // Cancel button
        const cancelBtn = document.getElementById('cancel-reconcile-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.cancelReconcile());
        }

        // Complete reconciliation button
        const completeBtn = document.getElementById('complete-reconcile-btn');
        if (completeBtn) {
            completeBtn.addEventListener('click', () => this.completeReconciliation());
        }
    },

    /**
     * Update reconciliation summary
     */
    updateSummary() {
        const statementBalanceInput = document.getElementById('statement-balance');
        const summarySection = document.getElementById('reconcile-summary');

        if (!statementBalanceInput.value.trim()) {
            summarySection.style.display = 'none';
            return;
        }

        this.statementBalance = this.parseCurrency(statementBalanceInput.value);

        // Update summary display
        document.getElementById('summary-statement-balance').textContent = this.formatCurrency(this.statementBalance);
        document.getElementById('summary-pgbudget-balance').textContent = this.formatCurrency(this.accountBalance);

        const difference = this.statementBalance - this.accountBalance;
        document.getElementById('summary-difference').textContent = this.formatCurrency(difference);

        // Update difference styling
        const differenceEl = document.getElementById('summary-difference');
        differenceEl.classList.remove('positive', 'negative', 'zero');
        if (difference > 0) {
            differenceEl.classList.add('positive');
        } else if (difference < 0) {
            differenceEl.classList.add('negative');
        } else {
            differenceEl.classList.add('zero');
        }

        // Show explanation if there's a difference
        const explanationEl = document.getElementById('difference-explanation');
        const explanationText = document.getElementById('difference-text');

        if (difference !== 0) {
            if (difference > 0) {
                explanationText.textContent = `Your statement balance is ${this.formatCurrency(Math.abs(difference))} higher than PGBudget. An adjustment transaction will be created to increase your account balance.`;
            } else {
                explanationText.textContent = `Your statement balance is ${this.formatCurrency(Math.abs(difference))} lower than PGBudget. An adjustment transaction will be created to decrease your account balance.`;
            }
            explanationEl.style.display = 'block';
        } else {
            explanationText.textContent = 'Perfect! Your balances match exactly.';
            explanationEl.style.display = 'block';
            explanationEl.style.background = '#d1fae5';
            explanationEl.style.borderColor = '#10b981';
        }

        summarySection.style.display = 'block';
    },

    /**
     * Load uncleared transactions
     */
    async loadTransactions() {
        const loadBtn = document.getElementById('load-transactions-btn');
        const originalText = loadBtn.textContent;
        loadBtn.disabled = true;
        loadBtn.textContent = 'Loading...';

        try {
            const response = await fetch(`/api/reconcile-account.php?action=uncleared&account=${this.accountUuid}`);
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to load transactions');
            }

            this.transactions = data.transactions;
            this.renderTransactions();

            // Show transactions section
            document.getElementById('transactions-section').style.display = 'block';

            // Scroll to transactions
            document.getElementById('transactions-section').scrollIntoView({ behavior: 'smooth', block: 'start' });

        } catch (error) {
            console.error('Error loading transactions:', error);
            this.showNotification(error.message || 'Failed to load transactions', 'error');
            loadBtn.disabled = false;
            loadBtn.textContent = originalText;
        }
    },

    /**
     * Render transactions list
     */
    renderTransactions() {
        const listEl = document.getElementById('transactions-list');
        if (!listEl) return;

        if (this.transactions.length === 0) {
            listEl.innerHTML = '<div class="loading">No uncleared transactions found. All transactions are already reconciled!</div>';
            document.getElementById('load-transactions-btn').disabled = false;
            document.getElementById('load-transactions-btn').textContent = 'Reload Transactions';
            return;
        }

        listEl.innerHTML = '';

        this.transactions.forEach(txn => {
            const item = document.createElement('div');
            item.className = 'transaction-item';
            item.dataset.uuid = txn.transaction_uuid;

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'transaction-checkbox';
            checkbox.dataset.uuid = txn.transaction_uuid;
            checkbox.checked = this.selectedTransactionUuids.has(txn.transaction_uuid);

            checkbox.addEventListener('change', (e) => {
                if (e.target.checked) {
                    this.selectedTransactionUuids.add(txn.transaction_uuid);
                    item.classList.add('selected');
                } else {
                    this.selectedTransactionUuids.delete(txn.transaction_uuid);
                    item.classList.remove('selected');
                }
                this.updateSelectionCount();
            });

            const details = document.createElement('div');
            details.className = 'transaction-details';

            const description = document.createElement('div');
            description.className = 'transaction-description';
            description.textContent = txn.description;

            const meta = document.createElement('div');
            meta.className = 'transaction-meta';
            const date = new Date(txn.transaction_date);
            meta.textContent = `${date.toLocaleDateString()} • ${txn.other_account_name} • ${txn.cleared_status}`;

            details.appendChild(description);
            details.appendChild(meta);

            const amount = document.createElement('div');
            amount.className = `transaction-amount ${txn.is_debit ? 'debit' : 'credit'}`;
            amount.textContent = (txn.is_debit ? '-' : '+') + this.formatCurrency(txn.amount);

            item.appendChild(checkbox);
            item.appendChild(details);
            item.appendChild(amount);

            // Click on item to toggle checkbox
            item.addEventListener('click', (e) => {
                if (e.target !== checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });

            listEl.appendChild(item);
        });

        // Update counts
        document.getElementById('total-count').textContent = this.transactions.length;
        this.updateSelectionCount();

        // Re-enable load button
        document.getElementById('load-transactions-btn').disabled = false;
        document.getElementById('load-transactions-btn').textContent = 'Reload Transactions';
    },

    /**
     * Update selection count
     */
    updateSelectionCount() {
        document.getElementById('selected-count').textContent = this.selectedTransactionUuids.size;
    },

    /**
     * Select all transactions
     */
    selectAll() {
        this.transactions.forEach(txn => {
            this.selectedTransactionUuids.add(txn.transaction_uuid);
        });
        this.renderTransactions();
    },

    /**
     * Deselect all transactions
     */
    deselectAll() {
        this.selectedTransactionUuids.clear();
        this.renderTransactions();
    },

    /**
     * Cancel reconciliation
     */
    cancelReconcile() {
        if (confirm('Are you sure you want to cancel this reconciliation? Your selections will be lost.')) {
            window.location.reload();
        }
    },

    /**
     * Complete reconciliation
     */
    async completeReconciliation() {
        const statementDate = document.getElementById('statement-date').value;
        const statementBalanceInput = document.getElementById('statement-balance').value;
        const notes = document.getElementById('reconcile-notes').value;

        // Validate
        if (!statementDate) {
            this.showNotification('Please enter a statement date', 'error');
            return;
        }

        if (!statementBalanceInput) {
            this.showNotification('Please enter a statement balance', 'error');
            return;
        }

        const statementBalance = this.parseCurrency(statementBalanceInput);

        const completeBtn = document.getElementById('complete-reconcile-btn');
        const originalText = completeBtn.textContent;
        completeBtn.disabled = true;
        completeBtn.textContent = 'Processing...';

        try {
            const response = await fetch('/api/reconcile-account.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'reconcile',
                    account_uuid: this.accountUuid,
                    reconciliation_date: statementDate,
                    statement_balance: statementBalance,
                    transaction_uuids: Array.from(this.selectedTransactionUuids),
                    notes: notes || null
                })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to complete reconciliation');
            }

            // Success!
            this.showNotification('Reconciliation completed successfully!', 'success');

            // Reload page after a moment
            setTimeout(() => {
                window.location.reload();
            }, 1500);

        } catch (error) {
            console.error('Error completing reconciliation:', error);
            this.showNotification(error.message || 'Failed to complete reconciliation', 'error');
            completeBtn.disabled = false;
            completeBtn.textContent = originalText;
        }
    },

    /**
     * Show notification
     */
    showNotification(message, type = 'success') {
        // Remove existing notifications
        const existing = document.querySelector('.notification');
        if (existing) {
            existing.remove();
        }

        // Create notification
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);

        // Show notification
        setTimeout(() => notification.classList.add('show'), 10);

        // Hide after 5 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    },

    /**
     * Format currency
     */
    formatCurrency(cents) {
        const dollars = cents / 100;
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(dollars);
    },

    /**
     * Parse currency string to cents
     */
    parseCurrency(str) {
        // Remove currency symbols, commas, spaces
        const cleaned = str.replace(/[$,\s]/g, '');

        // Handle negative values
        const isNegative = cleaned.includes('-');
        const absolute = cleaned.replace('-', '');

        // Parse as float and convert to cents
        const dollars = parseFloat(absolute);
        if (isNaN(dollars)) return 0;

        const cents = Math.round(dollars * 100);
        return isNegative ? -cents : cents;
    }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => ReconcileAccount.init());
} else {
    ReconcileAccount.init();
}
