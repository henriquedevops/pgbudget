/**
 * Loan Payment Module - JavaScript for recording loan payments and managing payment schedules
 * Phase 4: JavaScript Modules - Step 4.2: Loan Payment Module
 * Part of LOAN_MANAGEMENT_IMPLEMENTATION.md
 */

class LoanPaymentHandler {
    constructor(loanUuid, ledgerUuid) {
        this.loanUuid = loanUuid;
        this.ledgerUuid = ledgerUuid;
        this.currentPayment = null;
        this.loanData = null;
        this.initializeEventListeners();
    }

    initializeEventListeners() {
        // Listen for payment amount changes to update preview
        const amountInput = document.getElementById('actual_amount');
        if (amountInput) {
            amountInput.addEventListener('input', () => this.updatePaymentPreview());
        }

        // Listen for payment date changes
        const dateInput = document.getElementById('paid_date');
        if (dateInput) {
            dateInput.addEventListener('change', () => this.validatePaymentDate());
        }

        // Listen for account selection changes
        const accountSelect = document.getElementById('from_account_uuid');
        if (accountSelect) {
            accountSelect.addEventListener('change', () => this.validateAccountSelection());
        }
    }

    /**
     * Update the payment preview when amount changes
     */
    updatePaymentPreview() {
        const amountInput = document.getElementById('actual_amount');
        const amount = parseFloat(amountInput?.value) || 0;

        if (amount <= 0) {
            return;
        }

        // Get loan data from data attributes or passed data
        const interestRate = parseFloat(document.querySelector('[data-interest-rate]')?.dataset.interestRate || 0);
        const currentBalance = parseFloat(document.querySelector('[data-current-balance]')?.dataset.currentBalance || 0);

        if (interestRate > 0 && currentBalance > 0) {
            // Calculate actual payment split
            const split = this.calculatePaymentSplit(currentBalance, amount, interestRate);

            // Update preview elements
            this.updatePreviewElement('previewPrincipal', split.principal);
            this.updatePreviewElement('previewInterest', split.interest);
            this.updatePreviewElement('previewBalance', split.remainingBalance);
        }
    }

    /**
     * Update a preview element with formatted currency
     */
    updatePreviewElement(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = this.formatCurrency(value);
        }
    }

    /**
     * Validate that payment date is not in the future
     */
    validatePaymentDate() {
        const dateInput = document.getElementById('paid_date');
        if (!dateInput) return true;

        const selectedDate = new Date(dateInput.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        if (selectedDate > today) {
            this.showNotification('Payment date cannot be in the future', 'warning');
            dateInput.value = this.formatDateForInput(today);
            return false;
        }

        return true;
    }

    /**
     * Validate account selection
     */
    validateAccountSelection() {
        const accountSelect = document.getElementById('from_account_uuid');
        if (!accountSelect) return true;

        if (!accountSelect.value) {
            this.showNotification('Please select an account to pay from', 'warning');
            return false;
        }

        return true;
    }

    /**
     * Record a loan payment
     * @param {Object} paymentData - Payment data object
     * @returns {Promise<Object>} API response
     */
    async recordPayment(paymentData) {
        try {
            // Validate payment data
            const validation = this.validatePaymentData(paymentData);
            if (!validation.isValid) {
                throw new Error(validation.errors.join(', '));
            }

            const response = await fetch('../api/loan-payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(paymentData)
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to record payment');
            }

            return data;
        } catch (error) {
            throw new Error('Error recording payment: ' + error.message);
        }
    }

    /**
     * Get payment schedule for the current loan
     * @returns {Promise<Array>} Array of payment objects
     */
    async getPaymentSchedule() {
        try {
            const response = await fetch(`../api/loan-payments.php?loan_uuid=${encodeURIComponent(this.loanUuid)}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to fetch payment schedule');
            }

            return data.payments || [];
        } catch (error) {
            throw new Error('Error fetching payment schedule: ' + error.message);
        }
    }

    /**
     * Calculate principal and interest split for a payment
     * @param {number} balance - Current loan balance
     * @param {number} payment - Payment amount
     * @param {number} annualRate - Annual interest rate (percentage)
     * @returns {Object} Payment split details
     */
    calculatePaymentSplit(balance, payment, annualRate) {
        const monthlyRate = annualRate / 100 / 12;
        const interestPayment = balance * monthlyRate;
        const principalPayment = payment - interestPayment;

        return {
            principal: Math.max(0, principalPayment),
            interest: interestPayment,
            total: payment,
            remainingBalance: Math.max(0, balance - principalPayment)
        };
    }

    /**
     * Calculate the effect of making a payment
     * @param {Object} loanData - Current loan data
     * @param {number} paymentAmount - Amount to pay
     * @returns {Object} Payment effect details
     */
    calculatePaymentEffect(loanData, paymentAmount) {
        const split = this.calculatePaymentSplit(
            loanData.current_balance,
            paymentAmount,
            loanData.interest_rate
        );

        return {
            paymentAmount: paymentAmount,
            principal: split.principal,
            interest: split.interest,
            newBalance: split.remainingBalance,
            oldBalance: loanData.current_balance,
            balanceReduction: loanData.current_balance - split.remainingBalance,
            percentPaid: (split.principal / loanData.current_balance) * 100
        };
    }

    /**
     * Validate payment data before submission
     * @param {Object} paymentData - Payment data to validate
     * @returns {Object} Validation result with isValid and errors array
     */
    validatePaymentData(paymentData) {
        const errors = [];

        if (!paymentData.payment_uuid && !paymentData.loan_uuid) {
            errors.push('Payment or loan UUID is required');
        }

        if (!paymentData.paid_date) {
            errors.push('Payment date is required');
        } else {
            const paymentDate = new Date(paymentData.paid_date);
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            if (paymentDate > today) {
                errors.push('Payment date cannot be in the future');
            }
        }

        if (!paymentData.actual_amount || paymentData.actual_amount <= 0) {
            errors.push('Payment amount must be greater than 0');
        }

        if (!paymentData.from_account_uuid) {
            errors.push('Payment account is required');
        }

        return {
            isValid: errors.length === 0,
            errors: errors
        };
    }

    /**
     * Show a confirmation modal before recording payment
     * @param {Object} paymentData - Payment data
     * @param {Object} effect - Payment effect details
     * @returns {Promise<boolean>} True if confirmed, false if cancelled
     */
    async confirmPayment(paymentData, effect) {
        return new Promise((resolve) => {
            const modal = this.createConfirmationModal(paymentData, effect);
            document.body.appendChild(modal);

            const confirmBtn = modal.querySelector('#confirm-payment-btn');
            const cancelBtn = modal.querySelector('#cancel-payment-btn');
            const closeBtn = modal.querySelector('.modal-close');

            const cleanup = () => {
                modal.remove();
            };

            confirmBtn.addEventListener('click', () => {
                cleanup();
                resolve(true);
            });

            cancelBtn.addEventListener('click', () => {
                cleanup();
                resolve(false);
            });

            closeBtn.addEventListener('click', () => {
                cleanup();
                resolve(false);
            });

            // Show modal
            requestAnimationFrame(() => {
                modal.style.display = 'flex';
                modal.classList.add('show');
            });
        });
    }

    /**
     * Create confirmation modal HTML
     */
    createConfirmationModal(paymentData, effect) {
        const modal = document.createElement('div');
        modal.className = 'modal-backdrop payment-confirm-modal';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Confirm Payment</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Please confirm the following payment details:</p>

                    <div class="confirm-details">
                        <div class="confirm-item">
                            <span class="confirm-label">Payment Amount:</span>
                            <span class="confirm-value">${this.formatCurrency(paymentData.actual_amount)}</span>
                        </div>
                        <div class="confirm-item">
                            <span class="confirm-label">Payment Date:</span>
                            <span class="confirm-value">${this.formatDate(paymentData.paid_date)}</span>
                        </div>
                        <div class="confirm-item">
                            <span class="confirm-label">Principal:</span>
                            <span class="confirm-value text-success">${this.formatCurrency(effect.principal)}</span>
                        </div>
                        <div class="confirm-item">
                            <span class="confirm-label">Interest:</span>
                            <span class="confirm-value text-warning">${this.formatCurrency(effect.interest)}</span>
                        </div>
                        <div class="confirm-item">
                            <span class="confirm-label">New Balance:</span>
                            <span class="confirm-value text-primary">${this.formatCurrency(effect.newBalance)}</span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" id="cancel-payment-btn">Cancel</button>
                        <button type="button" class="btn btn-primary" id="confirm-payment-btn">Confirm Payment</button>
                    </div>
                </div>
            </div>
        `;
        return modal;
    }

    /**
     * Format currency for display
     */
    formatCurrency(amount) {
        return '$' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Format date for display
     */
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    /**
     * Format date for input field (YYYY-MM-DD)
     */
    formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    /**
     * Show notification to user
     */
    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `payment-notification notification-${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);

        requestAnimationFrame(() => {
            notification.classList.add('show');
        });

        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    /**
     * Calculate next payment due date
     * @param {Date} lastPaymentDate - Last payment date
     * @param {string} frequency - Payment frequency (monthly, bi-weekly, etc.)
     * @returns {Date} Next payment due date
     */
    static calculateNextPaymentDate(lastPaymentDate, frequency) {
        const date = new Date(lastPaymentDate);

        switch (frequency) {
            case 'monthly':
                date.setMonth(date.getMonth() + 1);
                break;
            case 'bi-weekly':
                date.setDate(date.getDate() + 14);
                break;
            case 'weekly':
                date.setDate(date.getDate() + 7);
                break;
            case 'quarterly':
                date.setMonth(date.getMonth() + 3);
                break;
            case 'annually':
                date.setFullYear(date.getFullYear() + 1);
                break;
            default:
                date.setMonth(date.getMonth() + 1);
        }

        return date;
    }

    /**
     * Calculate total interest paid over a period
     * @param {Array} payments - Array of payment objects
     * @returns {number} Total interest paid
     */
    static calculateTotalInterestPaid(payments) {
        return payments
            .filter(p => p.status === 'paid')
            .reduce((sum, p) => sum + (parseFloat(p.actual_interest) || 0), 0);
    }

    /**
     * Calculate total principal paid over a period
     * @param {Array} payments - Array of payment objects
     * @returns {number} Total principal paid
     */
    static calculateTotalPrincipalPaid(payments) {
        return payments
            .filter(p => p.status === 'paid')
            .reduce((sum, p) => sum + (parseFloat(p.actual_principal) || 0), 0);
    }

    /**
     * Get payment statistics
     * @param {Array} payments - Array of payment objects
     * @returns {Object} Payment statistics
     */
    static getPaymentStatistics(payments) {
        const paidPayments = payments.filter(p => p.status === 'paid');
        const upcomingPayments = payments.filter(p => p.status === 'scheduled');
        const overduePayments = payments.filter(p => {
            if (p.status !== 'scheduled') return false;
            const dueDate = new Date(p.due_date);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            return dueDate < today;
        });

        return {
            total: payments.length,
            paid: paidPayments.length,
            upcoming: upcomingPayments.length,
            overdue: overduePayments.length,
            totalPaid: paidPayments.reduce((sum, p) => sum + (parseFloat(p.actual_amount_paid) || 0), 0),
            totalInterest: this.calculateTotalInterestPaid(payments),
            totalPrincipal: this.calculateTotalPrincipalPaid(payments),
            percentComplete: payments.length > 0 ? (paidPayments.length / payments.length) * 100 : 0
        };
    }

    /**
     * Find next unpaid payment
     * @param {Array} payments - Array of payment objects
     * @returns {Object|null} Next unpaid payment or null
     */
    static findNextUnpaidPayment(payments) {
        return payments.find(p => p.status !== 'paid') || null;
    }

    /**
     * Check if a payment is overdue
     * @param {Object} payment - Payment object
     * @returns {boolean} True if overdue
     */
    static isPaymentOverdue(payment) {
        if (payment.status === 'paid') return false;

        const dueDate = new Date(payment.due_date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        return dueDate < today;
    }
}

/**
 * Payment Form Handler - Manages the payment recording form
 */
class PaymentFormHandler {
    constructor(loanUuid, ledgerUuid) {
        this.loanUuid = loanUuid;
        this.ledgerUuid = ledgerUuid;
        this.paymentHandler = new LoanPaymentHandler(loanUuid, ledgerUuid);
        this.attachFormListeners();
    }

    attachFormListeners() {
        const form = document.getElementById('recordPaymentForm');
        if (form) {
            form.addEventListener('submit', (e) => this.handleFormSubmit(e));
        }
    }

    async handleFormSubmit(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('submitBtn');
        if (!submitBtn) return;

        // Get form values
        const formData = this.getFormData();

        // Validate
        const validation = this.paymentHandler.validatePaymentData(formData);
        if (!validation.isValid) {
            alert('Validation errors:\n' + validation.errors.join('\n'));
            return;
        }

        // Disable submit button
        submitBtn.disabled = true;
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Recording Payment...';

        try {
            const result = await this.paymentHandler.recordPayment(formData);

            if (result.success) {
                this.paymentHandler.showNotification('Payment recorded successfully!', 'success');

                // Redirect to loan view page
                setTimeout(() => {
                    window.location.href = `view.php?ledger=${encodeURIComponent(this.ledgerUuid)}&loan=${encodeURIComponent(this.loanUuid)}`;
                }, 1000);
            } else {
                throw new Error(result.error || 'Failed to record payment');
            }
        } catch (error) {
            this.paymentHandler.showNotification('Error: ' + error.message, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }

    getFormData() {
        return {
            payment_uuid: document.querySelector('input[name="payment_uuid"]')?.value || null,
            loan_uuid: this.loanUuid,
            paid_date: document.getElementById('paid_date')?.value,
            actual_amount: parseFloat(document.getElementById('actual_amount')?.value) || 0,
            from_account_uuid: document.getElementById('from_account_uuid')?.value,
            notes: document.getElementById('notes')?.value?.trim() || null
        };
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on a payment recording page
    const loanUuidElement = document.querySelector('[data-loan-uuid]');
    const ledgerUuidElement = document.querySelector('[data-ledger-uuid]');

    if (loanUuidElement && ledgerUuidElement) {
        const loanUuid = loanUuidElement.dataset.loanUuid;
        const ledgerUuid = ledgerUuidElement.dataset.ledgerUuid;

        // Initialize payment handler
        window.loanPaymentHandler = new LoanPaymentHandler(loanUuid, ledgerUuid);

        // Initialize form handler if form exists
        if (document.getElementById('recordPaymentForm')) {
            window.paymentFormHandler = new PaymentFormHandler(loanUuid, ledgerUuid);
        }
    }
});

// Export for use in other modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        LoanPaymentHandler,
        PaymentFormHandler
    };
}
