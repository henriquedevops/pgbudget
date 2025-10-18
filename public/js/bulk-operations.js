/**
 * Bulk Operations JavaScript
 * Handles multi-select and bulk actions for transactions
 * Phase 6.3: Bulk Operations
 */

class BulkOperations {
    constructor() {
        this.selectedTransactions = new Set();
        this.init();
    }

    init() {
        this.setupEventListeners();
    }

    setupEventListeners() {
        // Select all checkbox
        const selectAllCheckbox = document.getElementById('select-all-transactions');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.handleSelectAll(e.target.checked);
            });
        }

        // Individual transaction checkboxes
        this.setupTransactionCheckboxes();

        // Bulk action buttons
        this.setupBulkActionButtons();

        // Handle shift-click for range selection
        this.setupShiftClickSelection();
    }

    setupTransactionCheckboxes() {
        const checkboxes = document.querySelectorAll('.transaction-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                this.handleCheckboxChange(e.target);
            });
        });
    }

    setupBulkActionButtons() {
        const bulkCategorizeBtn = document.getElementById('bulk-categorize-btn');
        if (bulkCategorizeBtn) {
            bulkCategorizeBtn.addEventListener('click', () => this.showCategorizeModal());
        }

        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', () => this.showDeleteConfirmation());
        }

        const bulkEditDateBtn = document.getElementById('bulk-edit-date-btn');
        if (bulkEditDateBtn) {
            bulkEditDateBtn.addEventListener('click', () => this.showEditDateModal());
        }

        const bulkEditAccountBtn = document.getElementById('bulk-edit-account-btn');
        if (bulkEditAccountBtn) {
            bulkEditAccountBtn.addEventListener('click', () => this.showEditAccountModal());
        }
    }

    setupShiftClickSelection() {
        let lastChecked = null;
        const checkboxes = document.querySelectorAll('.transaction-checkbox');

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('click', (e) => {
                if (e.shiftKey && lastChecked) {
                    const checkboxArray = Array.from(checkboxes);
                    const start = checkboxArray.indexOf(lastChecked);
                    const end = checkboxArray.indexOf(checkbox);
                    const range = checkboxArray.slice(
                        Math.min(start, end),
                        Math.max(start, end) + 1
                    );

                    range.forEach(cb => {
                        cb.checked = lastChecked.checked;
                        this.handleCheckboxChange(cb);
                    });
                }
                lastChecked = checkbox;
            });
        });
    }

    handleSelectAll(checked) {
        const checkboxes = document.querySelectorAll('.transaction-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = checked;
            this.handleCheckboxChange(checkbox);
        });
    }

    handleCheckboxChange(checkbox) {
        const transactionUuid = checkbox.dataset.transactionUuid;

        if (checkbox.checked) {
            this.selectedTransactions.add(transactionUuid);
        } else {
            this.selectedTransactions.delete(transactionUuid);
        }

        this.updateBulkActionBar();
        this.updateSelectAllCheckbox();
    }

    updateBulkActionBar() {
        const bulkActionBar = document.getElementById('bulk-action-bar');
        const selectedCount = document.getElementById('selected-count');

        if (this.selectedTransactions.size > 0) {
            bulkActionBar.classList.remove('hidden');
            selectedCount.textContent = this.selectedTransactions.size;
        } else {
            bulkActionBar.classList.add('hidden');
        }
    }

    updateSelectAllCheckbox() {
        const selectAllCheckbox = document.getElementById('select-all-transactions');
        const checkboxes = document.querySelectorAll('.transaction-checkbox');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        const someChecked = Array.from(checkboxes).some(cb => cb.checked);

        if (selectAllCheckbox) {
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        }
    }

    showCategorizeModal() {
        const modal = document.getElementById('bulk-categorize-modal');
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    showDeleteConfirmation() {
        const count = this.selectedTransactions.size;
        const message = `Are you sure you want to delete ${count} transaction${count > 1 ? 's' : ''}? This action cannot be undone.`;

        if (confirm(message)) {
            this.performBulkDelete();
        }
    }

    showEditDateModal() {
        const modal = document.getElementById('bulk-edit-date-modal');
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    showEditAccountModal() {
        const modal = document.getElementById('bulk-edit-account-modal');
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    async performBulkCategorize(categoryUuid) {
        const transactionUuids = Array.from(this.selectedTransactions);

        try {
            const response = await fetch('/api/bulk-operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'categorize',
                    transaction_uuids: transactionUuids,
                    category_uuid: categoryUuid
                })
            });

            const data = await response.json();

            if (response.ok) {
                this.showSuccessMessage(`${data.count} transaction(s) categorized successfully`);
                this.clearSelection();
                this.reloadPage();
            } else {
                this.showErrorMessage(data.error || 'Failed to categorize transactions');
            }
        } catch (error) {
            console.error('Bulk categorize error:', error);
            this.showErrorMessage('An error occurred while categorizing transactions');
        }
    }

    async performBulkDelete() {
        const transactionUuids = Array.from(this.selectedTransactions);

        try {
            const response = await fetch('/api/bulk-operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'delete',
                    transaction_uuids: transactionUuids
                })
            });

            const data = await response.json();

            if (response.ok) {
                this.showSuccessMessage(`${data.count} transaction(s) deleted successfully`);
                this.clearSelection();
                this.reloadPage();
            } else {
                this.showErrorMessage(data.error || 'Failed to delete transactions');
            }
        } catch (error) {
            console.error('Bulk delete error:', error);
            this.showErrorMessage('An error occurred while deleting transactions');
        }
    }

    async performBulkEditDate(newDate) {
        const transactionUuids = Array.from(this.selectedTransactions);

        try {
            const response = await fetch('/api/bulk-operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'edit_date',
                    transaction_uuids: transactionUuids,
                    new_date: newDate
                })
            });

            const data = await response.json();

            if (response.ok) {
                this.showSuccessMessage(`${data.count} transaction(s) date updated successfully`);
                this.clearSelection();
                this.reloadPage();
            } else {
                this.showErrorMessage(data.error || 'Failed to update transaction dates');
            }
        } catch (error) {
            console.error('Bulk edit date error:', error);
            this.showErrorMessage('An error occurred while updating transaction dates');
        }
    }

    async performBulkEditAccount(accountUuid) {
        const transactionUuids = Array.from(this.selectedTransactions);

        try {
            const response = await fetch('/api/bulk-operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'edit_account',
                    transaction_uuids: transactionUuids,
                    new_account_uuid: accountUuid
                })
            });

            const data = await response.json();

            if (response.ok) {
                this.showSuccessMessage(`${data.count} transaction(s) account updated successfully`);
                this.clearSelection();
                this.reloadPage();
            } else {
                this.showErrorMessage(data.error || 'Failed to update transaction accounts');
            }
        } catch (error) {
            console.error('Bulk edit account error:', error);
            this.showErrorMessage('An error occurred while updating transaction accounts');
        }
    }

    clearSelection() {
        this.selectedTransactions.clear();
        const checkboxes = document.querySelectorAll('.transaction-checkbox');
        checkboxes.forEach(cb => cb.checked = false);
        this.updateBulkActionBar();
        this.updateSelectAllCheckbox();
    }

    showSuccessMessage(message) {
        // You can implement a toast notification system here
        alert(message);
    }

    showErrorMessage(message) {
        // You can implement a toast notification system here
        alert('Error: ' + message);
    }

    reloadPage() {
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }
}

// Initialize bulk operations when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.bulkOps = new BulkOperations();
});

// Modal helper functions
function closeBulkModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
    }
}

function submitBulkCategorize() {
    const categorySelect = document.getElementById('bulk-category-select');
    const categoryUuid = categorySelect.value;

    if (!categoryUuid) {
        alert('Please select a category');
        return;
    }

    window.bulkOps.performBulkCategorize(categoryUuid);
    closeBulkModal('bulk-categorize-modal');
}

function submitBulkEditDate() {
    const dateInput = document.getElementById('bulk-date-input');
    const newDate = dateInput.value;

    if (!newDate) {
        alert('Please select a date');
        return;
    }

    window.bulkOps.performBulkEditDate(newDate);
    closeBulkModal('bulk-edit-date-modal');
}

function submitBulkEditAccount() {
    const accountSelect = document.getElementById('bulk-account-select');
    const accountUuid = accountSelect.value;

    if (!accountUuid) {
        alert('Please select an account');
        return;
    }

    window.bulkOps.performBulkEditAccount(accountUuid);
    closeBulkModal('bulk-edit-account-modal');
}
