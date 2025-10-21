/**
 * Loan Management Module - JavaScript for loan creation, editing, payment tracking, and management
 * Phase 4: JavaScript Modules - Step 4.1: Loan Management Module
 * Part of LOAN_MANAGEMENT_IMPLEMENTATION.md
 */

class LoanManager {
    constructor(ledgerUuid) {
        this.ledgerUuid = ledgerUuid;
        this.currentLoan = null;
        this.loans = [];
        this.initializeModals();
        this.attachEventListeners();
    }

    initializeModals() {
        // Create delete confirmation modal
        this.createDeleteModal();
        // Create payment preview modal if needed
        this.createPaymentPreviewModal();
    }

    createDeleteModal() {
        const modal = document.createElement('div');
        modal.id = 'delete-loan-modal';
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal-content modal-small">
                <div class="modal-header">
                    <h2>Delete Loan?</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this loan?</p>
                    <p class="text-warning"><strong>This action cannot be undone and will delete all payment records.</strong></p>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirm-delete-loan">Delete Loan</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.modal-close').addEventListener('click', () => this.closeModal('delete-loan-modal'));
        modal.querySelector('.modal-cancel').addEventListener('click', () => this.closeModal('delete-loan-modal'));
        modal.querySelector('#confirm-delete-loan').addEventListener('click', () => this.confirmDeleteLoan());
    }

    createPaymentPreviewModal() {
        const modal = document.createElement('div');
        modal.id = 'payment-preview-modal';
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Payment Preview</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="payment-preview-content">
                        <!-- Preview content will be inserted here -->
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                        <button type="button" class="btn btn-primary" id="confirm-payment">Confirm Payment</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.modal-close').addEventListener('click', () => this.closeModal('payment-preview-modal'));
        modal.querySelector('.modal-cancel').addEventListener('click', () => this.closeModal('payment-preview-modal'));
    }

    attachEventListeners() {
        // Delete loan buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.delete-loan-btn')) {
                const btn = e.target.closest('.delete-loan-btn');
                const loanUuid = btn.dataset.loanUuid;
                const loanName = btn.dataset.loanName || 'this loan';
                this.openDeleteLoan(loanUuid, loanName);
            }
        });
    }

    openDeleteLoan(loanUuid, loanName) {
        this.currentLoan = { loan_uuid: loanUuid, name: loanName };
        this.showModal('delete-loan-modal');
    }

    async confirmDeleteLoan() {
        if (!this.currentLoan) return;

        try {
            const response = await fetch(`../api/loans.php?loan_uuid=${encodeURIComponent(this.currentLoan.loan_uuid)}`, {
                method: 'DELETE'
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to delete loan');
            }

            this.showNotification('Loan deleted successfully', 'success');
            this.closeModal('delete-loan-modal');

            // Redirect to loans list
            setTimeout(() => {
                window.location.href = 'index.php?ledger=' + encodeURIComponent(this.ledgerUuid);
            }, 1000);

        } catch (error) {
            this.showNotification('Failed to delete loan: ' + error.message, 'error');
        }
    }

    /**
     * Create a new loan
     * @param {Object} loanData - Loan data object
     * @returns {Promise<Object>} API response
     */
    async createLoan(loanData) {
        try {
            const response = await fetch('../api/loans.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(loanData)
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to create loan');
            }

            return data;
        } catch (error) {
            throw new Error('Error creating loan: ' + error.message);
        }
    }

    /**
     * Update an existing loan
     * @param {string} loanUuid - UUID of the loan to update
     * @param {Object} loanData - Updated loan data
     * @returns {Promise<Object>} API response
     */
    async updateLoan(loanUuid, loanData) {
        try {
            const response = await fetch(`../api/loans.php?loan_uuid=${encodeURIComponent(loanUuid)}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(loanData)
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to update loan');
            }

            return data;
        } catch (error) {
            throw new Error('Error updating loan: ' + error.message);
        }
    }

    /**
     * Delete a loan
     * @param {string} loanUuid - UUID of the loan to delete
     * @returns {Promise<Object>} API response
     */
    async deleteLoan(loanUuid) {
        try {
            const response = await fetch(`../api/loans.php?loan_uuid=${encodeURIComponent(loanUuid)}`, {
                method: 'DELETE'
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to delete loan');
            }

            return data;
        } catch (error) {
            throw new Error('Error deleting loan: ' + error.message);
        }
    }

    /**
     * Get all loans for a ledger
     * @param {string} ledgerUuid - UUID of the ledger
     * @returns {Promise<Array>} Array of loan objects
     */
    async getLoans(ledgerUuid) {
        try {
            const response = await fetch(`../api/loans.php?ledger_uuid=${encodeURIComponent(ledgerUuid)}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to fetch loans');
            }

            this.loans = data.loans || [];
            return this.loans;
        } catch (error) {
            throw new Error('Error fetching loans: ' + error.message);
        }
    }

    /**
     * Get a single loan by UUID
     * @param {string} loanUuid - UUID of the loan
     * @returns {Promise<Object>} Loan object
     */
    async getLoan(loanUuid) {
        try {
            const response = await fetch(`../api/loans.php?loan_uuid=${encodeURIComponent(loanUuid)}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to fetch loan');
            }

            return data.loan;
        } catch (error) {
            throw new Error('Error fetching loan: ' + error.message);
        }
    }

    /**
     * Calculate loan payment amount using standard amortization formula
     * @param {number} principal - Principal amount
     * @param {number} annualRate - Annual interest rate (percentage)
     * @param {number} termMonths - Loan term in months
     * @returns {Object} Payment calculation results
     */
    static calculateLoanPayment(principal, annualRate, termMonths) {
        const monthlyRate = annualRate / 100 / 12;
        let monthlyPayment;

        if (monthlyRate > 0) {
            // Standard amortization formula
            monthlyPayment = principal * (monthlyRate * Math.pow(1 + monthlyRate, termMonths)) /
                           (Math.pow(1 + monthlyRate, termMonths) - 1);
        } else {
            // Zero interest case
            monthlyPayment = principal / termMonths;
        }

        const totalPaid = monthlyPayment * termMonths;
        const totalInterest = totalPaid - principal;

        return {
            monthlyPayment: monthlyPayment,
            totalPaid: totalPaid,
            totalInterest: totalInterest,
            principal: principal,
            termMonths: termMonths,
            annualRate: annualRate
        };
    }

    /**
     * Calculate principal and interest split for a specific payment
     * @param {number} balance - Current loan balance
     * @param {number} payment - Payment amount
     * @param {number} annualRate - Annual interest rate (percentage)
     * @returns {Object} Payment split
     */
    static calculatePaymentSplit(balance, payment, annualRate) {
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
     * Generate full amortization schedule
     * @param {number} principal - Principal amount
     * @param {number} annualRate - Annual interest rate (percentage)
     * @param {number} termMonths - Loan term in months
     * @param {Date} startDate - Loan start date
     * @returns {Array} Array of payment objects
     */
    static generateAmortizationSchedule(principal, annualRate, termMonths, startDate) {
        const calculation = LoanManager.calculateLoanPayment(principal, annualRate, termMonths);
        const monthlyPayment = calculation.monthlyPayment;
        const schedule = [];
        let balance = principal;

        for (let i = 1; i <= termMonths; i++) {
            const split = LoanManager.calculatePaymentSplit(balance, monthlyPayment, annualRate);

            // Calculate due date
            const dueDate = new Date(startDate);
            dueDate.setMonth(dueDate.getMonth() + i);

            schedule.push({
                paymentNumber: i,
                dueDate: dueDate,
                scheduledAmount: monthlyPayment,
                principal: split.principal,
                interest: split.interest,
                balance: split.remainingBalance
            });

            balance = split.remainingBalance;
        }

        return schedule;
    }

    /**
     * Validate loan form data
     * @param {Object} formData - Form data to validate
     * @returns {Object} Validation result with isValid and errors
     */
    static validateLoanData(formData) {
        const errors = [];

        if (!formData.lender_name || formData.lender_name.trim().length === 0) {
            errors.push('Lender name is required');
        }

        if (!formData.principal_amount || formData.principal_amount <= 0) {
            errors.push('Principal amount must be greater than 0');
        }

        if (formData.interest_rate === undefined || formData.interest_rate < 0) {
            errors.push('Interest rate must be 0 or greater');
        }

        if (!formData.loan_term_months || formData.loan_term_months <= 0) {
            errors.push('Loan term must be greater than 0');
        }

        if (!formData.start_date) {
            errors.push('Start date is required');
        }

        if (!formData.payment_frequency) {
            errors.push('Payment frequency is required');
        }

        return {
            isValid: errors.length === 0,
            errors: errors
        };
    }

    showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'flex';
            requestAnimationFrame(() => {
                modal.classList.add('show');
            });
        }
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `loan-notification notification-${type}`;
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

    // Format currency for display
    static formatCurrency(amount) {
        return '$' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Format date for display
    static formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Calculate progress percentage
    static calculateProgress(current, total) {
        if (total === 0) return 0;
        return Math.min(100, Math.round((current / total) * 100));
    }

    // Calculate remaining balance
    static calculateRemainingBalance(principal, totalPaid) {
        return Math.max(0, principal - totalPaid);
    }
}

/**
 * Loan Payment Manager - Handles payment recording and tracking
 */
class LoanPaymentManager {
    constructor(loanUuid, ledgerUuid) {
        this.loanUuid = loanUuid;
        this.ledgerUuid = ledgerUuid;
        this.payments = [];
    }

    /**
     * Record a loan payment
     * @param {Object} paymentData - Payment data object
     * @returns {Promise<Object>} API response
     */
    async recordPayment(paymentData) {
        try {
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
     * Get payment schedule for a loan
     * @param {string} loanUuid - UUID of the loan
     * @returns {Promise<Array>} Array of payment objects
     */
    async getPayments(loanUuid) {
        try {
            const response = await fetch(`../api/loan-payments.php?loan_uuid=${encodeURIComponent(loanUuid)}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to fetch payments');
            }

            this.payments = data.payments || [];
            return this.payments;
        } catch (error) {
            throw new Error('Error fetching payments: ' + error.message);
        }
    }

    /**
     * Calculate payment effect (preview what happens when payment is made)
     * @param {Object} loanData - Current loan data
     * @param {number} paymentAmount - Amount to pay
     * @returns {Object} Payment effect details
     */
    calculatePaymentEffect(loanData, paymentAmount) {
        const split = LoanManager.calculatePaymentSplit(
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
            balanceReduction: loanData.current_balance - split.remainingBalance
        };
    }

    /**
     * Validate payment data
     * @param {Object} paymentData - Payment data to validate
     * @returns {Object} Validation result
     */
    static validatePaymentData(paymentData) {
        const errors = [];

        if (!paymentData.loan_uuid) {
            errors.push('Loan UUID is required');
        }

        if (!paymentData.payment_date) {
            errors.push('Payment date is required');
        }

        if (!paymentData.amount || paymentData.amount <= 0) {
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
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on a loans page
    const ledgerUuidElement = document.querySelector('[data-ledger-uuid]');
    if (ledgerUuidElement) {
        const ledgerUuid = ledgerUuidElement.dataset.ledgerUuid;
        window.loanManager = new LoanManager(ledgerUuid);
    }

    // Check if we're on a specific loan page
    const loanUuidElement = document.querySelector('[data-loan-uuid]');
    if (loanUuidElement && ledgerUuidElement) {
        const loanUuid = loanUuidElement.dataset.loanUuid;
        const ledgerUuid = ledgerUuidElement.dataset.ledgerUuid;
        window.loanPaymentManager = new LoanPaymentManager(loanUuid, ledgerUuid);
    }
});

// Export for use in other modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        LoanManager,
        LoanPaymentManager
    };
}
