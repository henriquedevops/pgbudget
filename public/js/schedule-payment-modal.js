/**
 * Payment Scheduling Modal (Phase 5)
 * Handles scheduling credit card payments with various payment types
 */

const SchedulePaymentModal = {
    modal: null,
    currentCardUuid: null,
    currentCardName: null,
    ledgerUuid: null,
    bankAccounts: [],
    currentStatement: null,

    init() {
        this.createModal();
        this.attachEventListeners();
    },

    createModal() {
        const modalHTML = `
            <div id="schedule-payment-modal" class="modal" style="display: none;">
                <div class="modal-content schedule-payment-modal-content">
                    <div class="modal-header">
                        <h2>Schedule Credit Card Payment</h2>
                        <button type="button" class="modal-close" onclick="SchedulePaymentModal.close()">&times;</button>
                    </div>

                    <div class="modal-body">
                        <div class="card-info-display">
                            <div class="info-label">Credit Card:</div>
                            <div class="info-value" id="payment-card-name"></div>
                        </div>

                        <div class="form-group">
                            <label for="payment-bank-account">Pay From Account *</label>
                            <select id="payment-bank-account" class="form-control" required>
                                <option value="">Select bank account...</option>
                            </select>
                            <small class="form-hint">The bank account to withdraw payment from</small>
                        </div>

                        <div class="form-group">
                            <label for="payment-type">Payment Type *</label>
                            <select id="payment-type" class="form-control" required onchange="SchedulePaymentModal.handlePaymentTypeChange()">
                                <option value="">Select payment type...</option>
                                <option value="minimum">Minimum Payment (from current statement)</option>
                                <option value="full_balance">Full Balance (pay entire balance)</option>
                                <option value="fixed_amount">Fixed Amount (enter specific amount)</option>
                                <option value="custom">Custom Amount (one-time custom amount)</option>
                            </select>
                        </div>

                        <div id="statement-info-container" style="display: none;">
                            <div class="statement-info-box">
                                <div class="statement-info-row">
                                    <span>Statement Balance:</span>
                                    <span id="statement-balance"></span>
                                </div>
                                <div class="statement-info-row">
                                    <span>Minimum Payment Due:</span>
                                    <span id="statement-minimum"></span>
                                </div>
                                <div class="statement-info-row">
                                    <span>Due Date:</span>
                                    <span id="statement-due-date"></span>
                                </div>
                            </div>
                        </div>

                        <div id="payment-amount-container" style="display: none;">
                            <div class="form-group">
                                <label for="payment-amount">Payment Amount *</label>
                                <input type="text" id="payment-amount" class="form-control" placeholder="0.00">
                                <small class="form-hint">Enter the payment amount</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="payment-scheduled-date">Scheduled Date *</label>
                            <input type="date" id="payment-scheduled-date" class="form-control" required>
                            <small class="form-hint">The date to process the payment</small>
                        </div>

                        <div class="form-group">
                            <label for="payment-notes">Notes (Optional)</label>
                            <textarea id="payment-notes" class="form-control" rows="2" placeholder="Add notes about this payment..."></textarea>
                        </div>

                        <div id="payment-preview-container" style="display: none;">
                            <div class="payment-preview-box">
                                <h4>Payment Preview</h4>
                                <div class="preview-row">
                                    <span>From:</span>
                                    <span id="preview-bank-account"></span>
                                </div>
                                <div class="preview-row">
                                    <span>To:</span>
                                    <span id="preview-credit-card"></span>
                                </div>
                                <div class="preview-row">
                                    <span>Type:</span>
                                    <span id="preview-payment-type"></span>
                                </div>
                                <div class="preview-row">
                                    <span>Amount:</span>
                                    <span id="preview-amount" class="preview-amount"></span>
                                </div>
                                <div class="preview-row">
                                    <span>Scheduled:</span>
                                    <span id="preview-date"></span>
                                </div>
                            </div>
                        </div>

                        <div id="payment-error-message" class="error-message" style="display: none;"></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="SchedulePaymentModal.close()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="SchedulePaymentModal.schedulePayment()">Schedule Payment</button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.modal = document.getElementById('schedule-payment-modal');
    },

    attachEventListeners() {
        // Close modal when clicking outside
        this.modal.addEventListener('click', (e) => {
            if (e.target === this.modal) {
                this.close();
            }
        });

        // Update preview when fields change
        const fields = ['payment-bank-account', 'payment-type', 'payment-amount', 'payment-scheduled-date'];
        fields.forEach(fieldId => {
            const element = document.getElementById(fieldId);
            if (element) {
                element.addEventListener('change', () => this.updatePreview());
                element.addEventListener('input', () => this.updatePreview());
            }
        });

        // ESC key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal.style.display === 'block') {
                this.close();
            }
        });
    },

    async open(cardUuid, cardName, ledgerUuid) {
        this.currentCardUuid = cardUuid;
        this.currentCardName = cardName;
        this.ledgerUuid = ledgerUuid || document.getElementById('ledger-accounts-data')?.getAttribute('data-ledger-uuid');

        // Reset form
        this.resetForm();

        // Update card name
        document.getElementById('payment-card-name').textContent = cardName;
        document.getElementById('preview-credit-card').textContent = cardName;

        // Load bank accounts
        await this.loadBankAccounts();

        // Load current statement
        await this.loadCurrentStatement();

        // Set default date to today
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('payment-scheduled-date').value = today;

        // Show modal
        this.modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    },

    close() {
        this.modal.style.display = 'none';
        document.body.style.overflow = '';
        this.resetForm();
    },

    resetForm() {
        document.getElementById('payment-bank-account').value = '';
        document.getElementById('payment-type').value = '';
        document.getElementById('payment-amount').value = '';
        document.getElementById('payment-scheduled-date').value = '';
        document.getElementById('payment-notes').value = '';
        document.getElementById('statement-info-container').style.display = 'none';
        document.getElementById('payment-amount-container').style.display = 'none';
        document.getElementById('payment-preview-container').style.display = 'none';
        document.getElementById('payment-error-message').style.display = 'none';
        this.currentStatement = null;
    },

    async loadBankAccounts() {
        try {
            const response = await fetch(`/pgbudget/api/get-accounts.php?ledger=${this.ledgerUuid}&type=asset`);
            const data = await response.json();

            if (data.success && data.accounts) {
                this.bankAccounts = data.accounts;
                const select = document.getElementById('payment-bank-account');
                select.innerHTML = '<option value="">Select bank account...</option>';

                data.accounts.forEach(account => {
                    const option = document.createElement('option');
                    option.value = account.uuid;
                    option.textContent = `${account.name} (${this.formatCurrency(account.balance || 0)})`;
                    option.dataset.balance = account.balance || 0;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error loading bank accounts:', error);
            this.showError('Failed to load bank accounts');
        }
    },

    async loadCurrentStatement() {
        try {
            const response = await fetch(`/pgbudget/api/credit-card-statements.php?credit_card_uuid=${this.currentCardUuid}&is_current=true`);
            const data = await response.json();

            if (data.success && data.statements && data.statements.length > 0) {
                this.currentStatement = data.statements[0];
            }
        } catch (error) {
            console.error('Error loading statement:', error);
        }
    },

    handlePaymentTypeChange() {
        const paymentType = document.getElementById('payment-type').value;
        const amountContainer = document.getElementById('payment-amount-container');
        const statementContainer = document.getElementById('statement-info-container');

        // Reset containers
        amountContainer.style.display = 'none';
        statementContainer.style.display = 'none';

        if (paymentType === 'minimum' || paymentType === 'full_balance') {
            if (this.currentStatement) {
                statementContainer.style.display = 'block';
                document.getElementById('statement-balance').textContent = this.formatCurrency(this.currentStatement.ending_balance);
                document.getElementById('statement-minimum').textContent = this.formatCurrency(this.currentStatement.minimum_payment_due);
                document.getElementById('statement-due-date').textContent = this.formatDate(this.currentStatement.due_date);

                // Auto-set date to due date
                document.getElementById('payment-scheduled-date').value = this.currentStatement.due_date;
            } else {
                this.showError('No current statement found. Please select a different payment type.');
            }
        } else if (paymentType === 'fixed_amount' || paymentType === 'custom') {
            amountContainer.style.display = 'block';
        }

        this.updatePreview();
    },

    updatePreview() {
        const bankAccountSelect = document.getElementById('payment-bank-account');
        const paymentType = document.getElementById('payment-type').value;
        const paymentAmount = document.getElementById('payment-amount').value;
        const scheduledDate = document.getElementById('payment-scheduled-date').value;
        const previewContainer = document.getElementById('payment-preview-container');

        // Only show preview if we have the required fields
        if (!bankAccountSelect.value || !paymentType || !scheduledDate) {
            previewContainer.style.display = 'none';
            return;
        }

        // Update preview
        const bankAccountName = bankAccountSelect.options[bankAccountSelect.selectedIndex].text;
        document.getElementById('preview-bank-account').textContent = bankAccountName.split(' (')[0];
        document.getElementById('preview-payment-type').textContent = this.getPaymentTypeLabel(paymentType);
        document.getElementById('preview-date').textContent = this.formatDate(scheduledDate);

        // Calculate amount
        let amount = 0;
        if (paymentType === 'minimum' && this.currentStatement) {
            amount = parseFloat(this.currentStatement.minimum_payment_due);
        } else if (paymentType === 'full_balance' && this.currentStatement) {
            amount = parseFloat(this.currentStatement.ending_balance);
        } else if ((paymentType === 'fixed_amount' || paymentType === 'custom') && paymentAmount) {
            amount = parseFloat(paymentAmount.replace(/[^0-9.]/g, ''));
        }

        document.getElementById('preview-amount').textContent = this.formatCurrency(amount);

        previewContainer.style.display = 'block';
    },

    async schedulePayment() {
        const bankAccount = document.getElementById('payment-bank-account').value;
        const paymentType = document.getElementById('payment-type').value;
        const paymentAmount = document.getElementById('payment-amount').value;
        const scheduledDate = document.getElementById('payment-scheduled-date').value;
        const notes = document.getElementById('payment-notes').value;

        // Validation
        if (!bankAccount || !paymentType || !scheduledDate) {
            this.showError('Please fill in all required fields');
            return;
        }

        if ((paymentType === 'fixed_amount' || paymentType === 'custom') && !paymentAmount) {
            this.showError('Please enter a payment amount');
            return;
        }

        if ((paymentType === 'minimum' || paymentType === 'full_balance') && !this.currentStatement) {
            this.showError('No current statement found for this payment type');
            return;
        }

        // Prepare request data
        const requestData = {
            credit_card_uuid: this.currentCardUuid,
            bank_account_uuid: bankAccount,
            payment_type: paymentType,
            scheduled_date: scheduledDate,
            notes: notes || null
        };

        // Add statement UUID if needed
        if (paymentType === 'minimum' || paymentType === 'full_balance') {
            requestData.statement_uuid = this.currentStatement.uuid;
        }

        // Add amount if needed
        if (paymentType === 'fixed_amount' || paymentType === 'custom') {
            requestData.payment_amount = parseFloat(paymentAmount.replace(/[^0-9.]/g, ''));
        }

        try {
            const response = await fetch('/pgbudget/api/scheduled-payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestData)
            });

            const data = await response.json();

            if (data.success) {
                this.showSuccess('Payment scheduled successfully');
                this.close();
                // Reload page to show updated payment
                setTimeout(() => window.location.reload(), 1500);
            } else {
                this.showError(data.error || 'Failed to schedule payment');
            }
        } catch (error) {
            console.error('Error scheduling payment:', error);
            this.showError('An error occurred while scheduling the payment');
        }
    },

    showError(message) {
        const errorDiv = document.getElementById('payment-error-message');
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    },

    showSuccess(message) {
        // Show success message
        const successDiv = document.createElement('div');
        successDiv.className = 'message message-success';
        successDiv.textContent = message;
        document.body.insertAdjacentElement('afterbegin', successDiv);

        // Auto-remove after 3 seconds
        setTimeout(() => successDiv.remove(), 3000);
    },

    getPaymentTypeLabel(type) {
        const labels = {
            'minimum': 'Minimum Payment',
            'full_balance': 'Full Balance',
            'fixed_amount': 'Fixed Amount',
            'custom': 'Custom Amount'
        };
        return labels[type] || type;
    },

    formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(amount);
    },

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    }
};

// Global function to open modal (called from dashboard)
function openSchedulePaymentModal(cardUuid, cardName) {
    SchedulePaymentModal.open(cardUuid, cardName);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => SchedulePaymentModal.init());
} else {
    SchedulePaymentModal.init();
}
