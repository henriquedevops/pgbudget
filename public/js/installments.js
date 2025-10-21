/**
 * Installments JavaScript Module
 * Handles installment plan creation, calculation, and management
 * Part of Phase 4 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

class InstallmentCalculator {
    /**
     * Calculate installment amounts with proper rounding
     * @param {number} totalAmount - Total purchase amount
     * @param {number} numInstallments - Number of installments
     * @returns {Object} Calculation results
     */
    static calculateInstallments(totalAmount, numInstallments) {
        if (totalAmount <= 0 || numInstallments <= 0) {
            return {
                regularAmount: 0,
                lastAmount: 0,
                total: 0,
                valid: false
            };
        }

        // Calculate regular installment amount (rounded to 2 decimals)
        const regularAmount = Math.floor((totalAmount / numInstallments) * 100) / 100;

        // Calculate total of regular installments
        const totalScheduled = regularAmount * (numInstallments - 1);

        // Last installment picks up the rounding difference
        const lastAmount = Math.round((totalAmount - totalScheduled) * 100) / 100;

        // Verify total
        const calculatedTotal = (regularAmount * (numInstallments - 1)) + lastAmount;
        const valid = Math.abs(calculatedTotal - totalAmount) < 0.01;

        return {
            regularAmount: regularAmount,
            lastAmount: lastAmount,
            total: calculatedTotal,
            valid: valid,
            roundingDifference: Math.abs(calculatedTotal - totalAmount)
        };
    }

    /**
     * Generate payment schedule with dates
     * @param {string} startDate - First payment date (YYYY-MM-DD)
     * @param {number} numInstallments - Number of installments
     * @param {string} frequency - Payment frequency (monthly, bi-weekly, weekly)
     * @param {number} regularAmount - Regular installment amount
     * @param {number} lastAmount - Last installment amount
     * @returns {Array} Schedule array
     */
    static generateSchedule(startDate, numInstallments, frequency, regularAmount, lastAmount) {
        const schedule = [];
        let currentDate = new Date(startDate);

        for (let i = 1; i <= numInstallments; i++) {
            const amount = (i === numInstallments) ? lastAmount : regularAmount;

            schedule.push({
                installmentNumber: i,
                dueDate: currentDate.toISOString().split('T')[0],
                amount: amount,
                status: 'scheduled'
            });

            // Calculate next date based on frequency
            currentDate = this.getNextDate(currentDate, frequency);
        }

        return schedule;
    }

    /**
     * Calculate next payment date based on frequency
     * @param {Date} currentDate - Current date
     * @param {string} frequency - Payment frequency
     * @returns {Date} Next payment date
     */
    static getNextDate(currentDate, frequency) {
        const nextDate = new Date(currentDate);

        switch (frequency) {
            case 'weekly':
                nextDate.setDate(nextDate.getDate() + 7);
                break;
            case 'bi-weekly':
                nextDate.setDate(nextDate.getDate() + 14);
                break;
            case 'monthly':
            default:
                nextDate.setMonth(nextDate.getMonth() + 1);
                break;
        }

        return nextDate;
    }

    /**
     * Format currency for display
     * @param {number} amount - Amount to format
     * @returns {string} Formatted currency string
     */
    static formatCurrency(amount) {
        return '$' + amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }

    /**
     * Validate installment plan data
     * @param {Object} planData - Plan data to validate
     * @returns {Object} Validation result
     */
    static validatePlanData(planData) {
        const errors = [];

        if (!planData.purchase_amount || planData.purchase_amount <= 0) {
            errors.push('Purchase amount must be greater than zero');
        }

        if (!planData.number_of_installments || planData.number_of_installments < 2) {
            errors.push('Number of installments must be at least 2');
        }

        if (planData.number_of_installments > 36) {
            errors.push('Number of installments cannot exceed 36');
        }

        if (!planData.credit_card_account_uuid) {
            errors.push('Credit card account is required');
        }

        if (!planData.category_account_uuid) {
            errors.push('Budget category is required');
        }

        if (!planData.description || planData.description.trim() === '') {
            errors.push('Description is required');
        }

        if (!planData.start_date) {
            errors.push('Start date is required');
        }

        if (!planData.frequency || !['monthly', 'bi-weekly', 'weekly'].includes(planData.frequency)) {
            errors.push('Invalid payment frequency');
        }

        return {
            isValid: errors.length === 0,
            errors: errors
        };
    }
}

class InstallmentManager {
    /**
     * Create a new installment plan
     * @param {Object} planData - Plan data
     * @returns {Promise} API response
     */
    static async createPlan(planData) {
        const response = await fetch('/pgbudget/api/installment-plans.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(planData)
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Failed to create installment plan');
        }

        return result;
    }

    /**
     * Get installment plan by UUID
     * @param {string} planUuid - Plan UUID
     * @returns {Promise} API response
     */
    static async getPlan(planUuid) {
        const response = await fetch(`/pgbudget/api/installment-plans.php?plan_uuid=${planUuid}`);

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Failed to fetch installment plan');
        }

        return result;
    }

    /**
     * Get all installment plans for a ledger
     * @param {string} ledgerUuid - Ledger UUID
     * @returns {Promise} API response
     */
    static async getPlansForLedger(ledgerUuid) {
        const response = await fetch(`/pgbudget/api/installment-plans.php?ledger_uuid=${ledgerUuid}`);

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Failed to fetch installment plans');
        }

        return result;
    }

    /**
     * Update installment plan
     * @param {string} planUuid - Plan UUID
     * @param {Object} updateData - Data to update
     * @returns {Promise} API response
     */
    static async updatePlan(planUuid, updateData) {
        const response = await fetch(`/pgbudget/api/installment-plans.php?plan_uuid=${planUuid}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(updateData)
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Failed to update installment plan');
        }

        return result;
    }

    /**
     * Delete installment plan
     * @param {string} planUuid - Plan UUID
     * @returns {Promise} API response
     */
    static async deletePlan(planUuid) {
        const response = await fetch(`/pgbudget/api/installment-plans.php?plan_uuid=${planUuid}`, {
            method: 'DELETE'
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Failed to delete installment plan');
        }

        return result;
    }

    /**
     * Process an installment
     * @param {string} scheduleUuid - Schedule item UUID
     * @param {Object} processData - Processing data (optional)
     * @returns {Promise} API response
     */
    static async processInstallment(scheduleUuid, processData = {}) {
        const data = {
            schedule_uuid: scheduleUuid,
            ...processData
        };

        const response = await fetch('/pgbudget/api/process-installment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Failed to process installment');
        }

        return result;
    }

    /**
     * Calculate progress statistics
     * @param {Object} plan - Installment plan object
     * @returns {Object} Progress statistics
     */
    static calculateProgress(plan) {
        const total = parseInt(plan.number_of_installments);
        const completed = parseInt(plan.completed_installments);
        const remaining = total - completed;
        const percentComplete = total > 0 ? (completed / total) * 100 : 0;

        return {
            total: total,
            completed: completed,
            remaining: remaining,
            percentComplete: percentComplete,
            isComplete: completed >= total
        };
    }

    /**
     * Get next scheduled installment
     * @param {Array} schedule - Schedule array
     * @returns {Object|null} Next scheduled installment
     */
    static getNextScheduledInstallment(schedule) {
        return schedule.find(item => item.status === 'scheduled') || null;
    }

    /**
     * Calculate total remaining amount
     * @param {Array} schedule - Schedule array
     * @returns {number} Total remaining amount
     */
    static calculateRemainingAmount(schedule) {
        return schedule
            .filter(item => item.status === 'scheduled')
            .reduce((sum, item) => sum + parseFloat(item.scheduled_amount || 0), 0);
    }

    /**
     * Format plan status for display
     * @param {string} status - Plan status
     * @returns {Object} Formatted status with label and CSS class
     */
    static formatStatus(status) {
        const statusMap = {
            'active': { label: 'Active', class: 'status-active' },
            'completed': { label: 'Completed', class: 'status-completed' },
            'cancelled': { label: 'Cancelled', class: 'status-cancelled' }
        };

        return statusMap[status] || { label: status, class: 'status-unknown' };
    }

    /**
     * Format frequency for display
     * @param {string} frequency - Payment frequency
     * @returns {string} Formatted frequency
     */
    static formatFrequency(frequency) {
        const frequencyMap = {
            'monthly': 'Monthly',
            'bi-weekly': 'Bi-weekly',
            'weekly': 'Weekly'
        };

        return frequencyMap[frequency] || frequency;
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { InstallmentCalculator, InstallmentManager };
}
