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
            console.log('Creating delete modal...');
            this.createModal();
        }

        this.modal = document.getElementById('delete-ledger-modal');
        console.log('Modal element:', this.modal);

        if (!this.modal) {
            console.error('CRITICAL: Modal element not found after creation!');
        }

        this.attachEventListeners();
        this.attachDeleteButtonListeners();
    }

    createModal() {
        const modalHTML = `
            <div id="delete-ledger-modal" class="modal-overlay" style="display: none;">
                <div class="modal-container delete-ledger-modal">
                    <div class="modal-header">
                        <h2>⚠️ Delete Budget</h2>
                        <button type="button" class="modal-close delete-modal-close">×</button>
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
                            <button type="button" class="btn btn-secondary delete-modal-cancel">
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="btn btn-danger"
                                id="confirm-delete-btn"
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

    attachDeleteButtonListeners() {
        // Use event delegation for delete buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.delete-ledger-btn')) {
                e.preventDefault();
                const btn = e.target.closest('.delete-ledger-btn');
                const ledgerUuid = btn.dataset.ledgerUuid;
                const ledgerName = btn.dataset.ledgerName;

                console.log('Delete button clicked:', ledgerUuid, ledgerName);

                if (ledgerUuid && ledgerName) {
                    this.open(ledgerUuid, ledgerName);
                } else {
                    console.error('Missing ledger UUID or name');
                }
            }
        });
    }

    attachEventListeners() {
        // Listen for confirmation input
        document.addEventListener('input', (e) => {
            if (e.target.id === 'delete-confirmation-input') {
                const confirmBtn = document.getElementById('confirm-delete-btn');
                if (confirmBtn) {
                    if (e.target.value === 'DELETE') {
                        confirmBtn.disabled = false;
                    } else {
                        confirmBtn.disabled = true;
                    }
                }
            }
        });

        // Listen for confirm delete button
        document.addEventListener('click', (e) => {
            if (e.target.id === 'confirm-delete-btn' && !e.target.disabled) {
                this.confirmDelete();
            }
        });

        // Close modal buttons
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('delete-modal-close') ||
                e.target.classList.contains('delete-modal-cancel')) {
                this.close();
            }
        });

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
        console.log('Opening delete modal for:', ledgerUuid, ledgerName);
        this.currentLedgerUuid = ledgerUuid;
        this.currentLedgerName = ledgerName;

        // Update modal content
        const nameEl = document.getElementById('delete-ledger-name');
        const inputEl = document.getElementById('delete-confirmation-input');
        const btnEl = document.getElementById('confirm-delete-btn');
        const errorEl = document.getElementById('delete-ledger-error');

        if (!nameEl || !inputEl || !btnEl || !errorEl) {
            console.error('Modal elements not found!');
            return;
        }

        nameEl.textContent = ledgerName;
        inputEl.value = '';
        btnEl.disabled = true;
        errorEl.style.display = 'none';

        // Show modal
        if (this.modal) {
            // Force display with important properties including opacity!
            this.modal.style.cssText = 'display: flex !important; position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; z-index: 10000 !important; background: rgba(0, 0, 0, 0.5) !important; align-items: center !important; justify-content: center !important; opacity: 1 !important;';

            console.log('Modal displayed');
            console.log('Modal styles:', {
                display: this.modal.style.display,
                position: window.getComputedStyle(this.modal).position,
                zIndex: window.getComputedStyle(this.modal).zIndex,
                visibility: window.getComputedStyle(this.modal).visibility,
                opacity: window.getComputedStyle(this.modal).opacity
            });
            console.log('Modal in DOM:', document.body.contains(this.modal));
            console.log('Modal bounding rect:', this.modal.getBoundingClientRect());
            console.log('Modal innerHTML length:', this.modal.innerHTML.length);
            console.log('Modal children:', this.modal.children.length);

            // Try to make it REALLY visible
            this.modal.style.border = '10px solid red';
            this.modal.style.width = '500px';
            this.modal.style.height = '500px';
        } else {
            console.error('Modal element not found!');
        }

        // Focus on input
        setTimeout(() => {
            inputEl?.focus();
        }, 100);
    }

    close() {
        console.log('CLOSE called!', new Error().stack);
        this.modal.style.display = 'none';
        this.currentLedgerUuid = null;
        this.currentLedgerName = null;
    }

    async confirmDelete() {
        console.log('confirmDelete called, ledger UUID:', this.currentLedgerUuid);

        if (!this.currentLedgerUuid) {
            console.error('No ledger UUID set');
            return;
        }

        const confirmBtn = document.getElementById('confirm-delete-btn');
        const errorDiv = document.getElementById('delete-ledger-error');

        // Disable button and show loading
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner"></span> Deleting...';
        errorDiv.style.display = 'none';

        try {
            console.log('Sending delete request for ledger:', this.currentLedgerUuid);

            const response = await fetch('/pgbudget/api/delete-ledger.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ledger_uuid: this.currentLedgerUuid
                })
            });

            console.log('Response status:', response.status);

            const data = await response.json();
            console.log('Response data:', data);

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
