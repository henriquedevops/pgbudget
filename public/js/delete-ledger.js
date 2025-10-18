/**
 * Delete Ledger Functionality
 * Handles confirmation and deletion of budget ledgers
 */

class DeleteLedgerManager {
    constructor() {
        this.modal = null;
        this.currentLedgerUuid = null;
        this.currentLedgerName = null;
        this.init();
    }

    init() {
        // Create modal if it doesn't exist
        if (!document.getElementById('delete-ledger-modal')) {
            this.createModal();
        }

        this.modal = document.getElementById('delete-ledger-modal');
        this.attachEventListeners();
    }

    createModal() {
        const modalHTML = `
            <div id="delete-ledger-modal" class="modal-overlay" style="display: none;">
                <div class="modal-container delete-ledger-modal">
                    <div class="modal-header">
                        <h2>⚠️ Delete Budget</h2>
                        <button type="button" class="modal-close" onclick="deleteLedgerManager.close()">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="warning-message">
                            <div class="warning-icon">⚠️</div>
                            <div class="warning-content">
                                <h3>This action cannot be undone!</h3>
                                <p>Deleting this budget will permanently remove:</p>
                                <ul>
                                    <li>All transactions</li>
                                    <li>All accounts</li>
                                    <li>All categories and assignments</li>
                                    <li>All recurring transactions</li>
                                    <li>All balance history</li>
                                    <li>All action history</li>
                                </ul>
                            </div>
                        </div>

                        <div class="confirmation-section">
                            <p><strong>Budget to delete:</strong> <span id="delete-ledger-name" class="ledger-name-display"></span></p>
                            <p>Type <strong>DELETE</strong> to confirm:</p>
                            <input
                                type="text"
                                id="delete-confirmation-input"
                                class="delete-confirmation-input"
                                placeholder="Type DELETE here"
                                autocomplete="off"
                            >
                        </div>

                        <div class="error-message" id="delete-ledger-error" style="display: none;"></div>

                        <div class="modal-actions">
                            <button type="button" class="btn btn-secondary" onclick="deleteLedgerManager.close()">
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="btn btn-danger"
                                id="confirm-delete-btn"
                                onclick="deleteLedgerManager.confirmDelete()"
                                disabled
                            >
                                Delete Budget Permanently
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }

    attachEventListeners() {
        // Listen for confirmation input
        const input = document.getElementById('delete-confirmation-input');
        if (input) {
            input.addEventListener('input', (e) => {
                const confirmBtn = document.getElementById('confirm-delete-btn');
                if (e.target.value === 'DELETE') {
                    confirmBtn.disabled = false;
                } else {
                    confirmBtn.disabled = true;
                }
            });
        }

        // Close modal on overlay click
        this.modal?.addEventListener('click', (e) => {
            if (e.target === this.modal) {
                this.close();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal?.style.display !== 'none') {
                this.close();
            }
        });
    }

    open(ledgerUuid, ledgerName) {
        this.currentLedgerUuid = ledgerUuid;
        this.currentLedgerName = ledgerName;

        // Update modal content
        document.getElementById('delete-ledger-name').textContent = ledgerName;
        document.getElementById('delete-confirmation-input').value = '';
        document.getElementById('confirm-delete-btn').disabled = true;
        document.getElementById('delete-ledger-error').style.display = 'none';

        // Show modal
        this.modal.style.display = 'flex';

        // Focus on input
        setTimeout(() => {
            document.getElementById('delete-confirmation-input')?.focus();
        }, 100);
    }

    close() {
        this.modal.style.display = 'none';
        this.currentLedgerUuid = null;
        this.currentLedgerName = null;
    }

    async confirmDelete() {
        if (!this.currentLedgerUuid) {
            return;
        }

        const confirmBtn = document.getElementById('confirm-delete-btn');
        const errorDiv = document.getElementById('delete-ledger-error');

        // Disable button and show loading
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner"></span> Deleting...';
        errorDiv.style.display = 'none';

        try {
            const response = await fetch('/pgbudget/api/delete-ledger.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ledger_uuid: this.currentLedgerUuid
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Failed to delete budget');
            }

            // Success! Show success message
            this.showSuccessNotification(data);

            // Close modal
            this.close();

            // Redirect to dashboard after a brief delay
            setTimeout(() => {
                window.location.href = '/pgbudget/';
            }, 1500);

        } catch (error) {
            console.error('Delete ledger error:', error);

            // Show error message
            errorDiv.textContent = error.message;
            errorDiv.style.display = 'block';

            // Re-enable button
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Delete Budget Permanently';
        }
    }

    showSuccessNotification(data) {
        const notification = document.createElement('div');
        notification.className = 'delete-success-notification';
        notification.innerHTML = `
            <div class="success-icon">✓</div>
            <div class="success-content">
                <strong>Budget Deleted</strong>
                <p>${data.ledger_name} has been permanently deleted</p>
                ${data.deleted_counts ? `
                    <small>
                        ${data.deleted_counts.transactions} transactions,
                        ${data.deleted_counts.accounts} accounts removed
                    </small>
                ` : ''}
            </div>
        `;
        document.body.appendChild(notification);

        setTimeout(() => notification.classList.add('show'), 10);
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    window.deleteLedgerManager = new DeleteLedgerManager();
    console.log('🗑️  Delete ledger manager initialized');
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DeleteLedgerManager;
}
