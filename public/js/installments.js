/**
 * Installments JavaScript Module
 * Handles installment plan creation, calculation, and management
 * Part of Step 4.1 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

/**
 * InstallmentCalculator - Calculate installment amounts with proper rounding
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
     * Calculate installment amount for a specific number of installments
     * @param {number} totalAmount - Total purchase amount
     * @param {number} numInstallments - Number of installments
     * @returns {number} Single installment amount
     */
    static calculateInstallmentAmount(totalAmount, numInstallments) {
        if (totalAmount <= 0 || numInstallments <= 0) {
            return 0;
        }
        return Math.floor((totalAmount / numInstallments) * 100) / 100;
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

    /**
     * Validate plan data (alias for validatePlanData for backward compatibility)
     * @param {Object} planData - Plan data to validate
     * @returns {Object} Validation result
     */
    static validatePlan(planData) {
        return this.validatePlanData(planData);
    }
}

/**
 * InstallmentScheduleGenerator - Generate payment schedules with dates
 */
class InstallmentScheduleGenerator {
    /**
     * Generate payment schedule with dates
     * @param {string} startDate - First payment date (YYYY-MM-DD)
     * @param {number} numInstallments - Number of installments
     * @param {string} frequency - Payment frequency (monthly, bi-weekly, weekly)
     * @returns {Array} Schedule array with dates
     */
    static generateSchedule(startDate, numInstallments, frequency) {
        const schedule = [];
        let currentDate = new Date(startDate);

        for (let i = 1; i <= numInstallments; i++) {
            schedule.push({
                installmentNumber: i,
                dueDate: currentDate.toISOString().split('T')[0],
                status: 'scheduled'
            });

            // Calculate next date based on frequency
            currentDate = this.getNextDate(currentDate, frequency);
        }

        return schedule;
    }

    /**
     * Generate full schedule with amounts
     * @param {string} startDate - First payment date (YYYY-MM-DD)
     * @param {number} numInstallments - Number of installments
     * @param {string} frequency - Payment frequency (monthly, bi-weekly, weekly)
     * @param {number} regularAmount - Regular installment amount
     * @param {number} lastAmount - Last installment amount
     * @returns {Array} Schedule array with dates and amounts
     */
    static generateScheduleWithAmounts(startDate, numInstallments, frequency, regularAmount, lastAmount) {
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
     * Calculate number of days between two dates
     * @param {string} date1 - First date (YYYY-MM-DD)
     * @param {string} date2 - Second date (YYYY-MM-DD)
     * @returns {number} Number of days
     */
    static daysBetween(date1, date2) {
        const d1 = new Date(date1);
        const d2 = new Date(date2);
        const diffTime = Math.abs(d2 - d1);
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    }

    /**
     * Get the end date for a payment schedule
     * @param {string} startDate - First payment date (YYYY-MM-DD)
     * @param {number} numInstallments - Number of installments
     * @param {string} frequency - Payment frequency
     * @returns {string} End date (YYYY-MM-DD)
     */
    static getEndDate(startDate, numInstallments, frequency) {
        if (numInstallments <= 0) return startDate;

        let currentDate = new Date(startDate);

        // Move to the date of the last installment
        for (let i = 1; i < numInstallments; i++) {
            currentDate = this.getNextDate(currentDate, frequency);
        }

        return currentDate.toISOString().split('T')[0];
    }

    /**
     * Check if an installment is overdue
     * @param {string} dueDate - Due date (YYYY-MM-DD)
     * @returns {boolean} True if overdue
     */
    static isOverdue(dueDate) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const due = new Date(dueDate);
        return due < today;
    }

    /**
     * Get overdue installments from schedule
     * @param {Array} schedule - Schedule array
     * @returns {Array} Overdue installments
     */
    static getOverdueInstallments(schedule) {
        return schedule.filter(item =>
            item.status === 'scheduled' && this.isOverdue(item.due_date)
        );
    }

    /**
     * Get upcoming installments (due within N days)
     * @param {Array} schedule - Schedule array
     * @param {number} days - Number of days to look ahead
     * @returns {Array} Upcoming installments
     */
    static getUpcomingInstallments(schedule, days = 30) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const futureDate = new Date(today);
        futureDate.setDate(futureDate.getDate() + days);

        return schedule.filter(item => {
            if (item.status !== 'scheduled') return false;
            const due = new Date(item.due_date);
            return due >= today && due <= futureDate;
        });
    }
}

/**
 * InstallmentManager - Main class for CRUD operations and batch processing
 */
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
     * Process multiple installments in batch
     * @param {Array} installmentIds - Array of schedule UUIDs to process
     * @param {Function} progressCallback - Optional callback for progress updates
     * @returns {Promise<Object>} Batch processing results
     */
    static async processBatch(installmentIds, progressCallback = null) {
        const results = {
            total: installmentIds.length,
            successful: 0,
            failed: 0,
            errors: [],
            processed: []
        };

        for (let i = 0; i < installmentIds.length; i++) {
            const scheduleUuid = installmentIds[i];

            try {
                const result = await this.processInstallment(scheduleUuid);
                results.successful++;
                results.processed.push({
                    scheduleUuid: scheduleUuid,
                    success: true,
                    result: result
                });

                if (progressCallback) {
                    progressCallback({
                        current: i + 1,
                        total: installmentIds.length,
                        scheduleUuid: scheduleUuid,
                        success: true
                    });
                }
            } catch (error) {
                results.failed++;
                results.errors.push({
                    scheduleUuid: scheduleUuid,
                    error: error.message
                });
                results.processed.push({
                    scheduleUuid: scheduleUuid,
                    success: false,
                    error: error.message
                });

                if (progressCallback) {
                    progressCallback({
                        current: i + 1,
                        total: installmentIds.length,
                        scheduleUuid: scheduleUuid,
                        success: false,
                        error: error.message
                    });
                }
            }
        }

        return results;
    }

    /**
     * Process all overdue installments for a plan
     * @param {string} planUuid - Plan UUID
     * @param {Function} progressCallback - Optional callback for progress updates
     * @returns {Promise<Object>} Batch processing results
     */
    static async processOverdueInstallments(planUuid, progressCallback = null) {
        // First, get the plan with schedule
        const planData = await this.getPlan(planUuid);
        const plan = planData.plan;

        if (!plan || !plan.schedule) {
            throw new Error('Plan not found or has no schedule');
        }

        // Find overdue installments
        const overdueInstallments = InstallmentScheduleGenerator.getOverdueInstallments(plan.schedule);

        if (overdueInstallments.length === 0) {
            return {
                total: 0,
                successful: 0,
                failed: 0,
                errors: [],
                processed: []
            };
        }

        // Extract UUIDs
        const installmentIds = overdueInstallments.map(item => item.uuid);

        // Process in batch
        return await this.processBatch(installmentIds, progressCallback);
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

    /**
     * Get summary statistics for all plans
     * @param {Array} plans - Array of installment plans
     * @returns {Object} Summary statistics
     */
    static getSummaryStats(plans) {
        const stats = {
            totalPlans: plans.length,
            activePlans: 0,
            completedPlans: 0,
            cancelledPlans: 0,
            totalDebt: 0,
            totalPaid: 0,
            totalRemaining: 0,
            monthlyObligation: 0
        };

        plans.forEach(plan => {
            const status = plan.status;
            const purchaseAmount = parseFloat(plan.purchase_amount || 0);
            const installmentAmount = parseFloat(plan.installment_amount || 0);
            const completed = parseInt(plan.completed_installments || 0);
            const total = parseInt(plan.number_of_installments || 0);
            const remaining = total - completed;

            if (status === 'active') {
                stats.activePlans++;
                stats.totalDebt += purchaseAmount;
                stats.totalRemaining += (remaining * installmentAmount);

                // Calculate monthly obligation (convert to monthly if needed)
                if (plan.frequency === 'monthly') {
                    stats.monthlyObligation += installmentAmount;
                } else if (plan.frequency === 'bi-weekly') {
                    stats.monthlyObligation += (installmentAmount * 26 / 12); // ~2.17 payments/month
                } else if (plan.frequency === 'weekly') {
                    stats.monthlyObligation += (installmentAmount * 52 / 12); // ~4.33 payments/month
                }
            } else if (status === 'completed') {
                stats.completedPlans++;
            } else if (status === 'cancelled') {
                stats.cancelledPlans++;
            }

            stats.totalPaid += (completed * installmentAmount);
        });

        return stats;
    }

    /**
     * Filter plans by status
     * @param {Array} plans - Array of installment plans
     * @param {string} status - Status to filter by
     * @returns {Array} Filtered plans
     */
    static filterByStatus(plans, status) {
        return plans.filter(plan => plan.status === status);
    }

    /**
     * Filter plans by credit card
     * @param {Array} plans - Array of installment plans
     * @param {string} creditCardUuid - Credit card UUID to filter by
     * @returns {Array} Filtered plans
     */
    static filterByCreditCard(plans, creditCardUuid) {
        return plans.filter(plan => plan.credit_card_uuid === creditCardUuid);
    }

    /**
     * Sort plans by various criteria
     * @param {Array} plans - Array of installment plans
     * @param {string} sortBy - Field to sort by (date, amount, name, status)
     * @param {string} direction - Sort direction (asc, desc)
     * @returns {Array} Sorted plans
     */
    static sortPlans(plans, sortBy = 'date', direction = 'desc') {
        const sorted = [...plans];

        sorted.sort((a, b) => {
            let comparison = 0;

            switch (sortBy) {
                case 'date':
                    comparison = new Date(a.purchase_date) - new Date(b.purchase_date);
                    break;
                case 'amount':
                    comparison = parseFloat(a.purchase_amount) - parseFloat(b.purchase_amount);
                    break;
                case 'name':
                    comparison = a.description.localeCompare(b.description);
                    break;
                case 'status':
                    comparison = a.status.localeCompare(b.status);
                    break;
                case 'remaining':
                    const remainingA = a.number_of_installments - a.completed_installments;
                    const remainingB = b.number_of_installments - b.completed_installments;
                    comparison = remainingA - remainingB;
                    break;
                default:
                    comparison = 0;
            }

            return direction === 'asc' ? comparison : -comparison;
        });

        return sorted;
    }

    /**
     * Check if plan can be edited
     * @param {Object} plan - Installment plan
     * @returns {boolean} True if editable
     */
    static isEditable(plan) {
        return plan.status === 'active' &&
               (plan.number_of_installments - plan.completed_installments) > 0;
    }

    /**
     * Check if plan can be deleted
     * @param {Object} plan - Installment plan
     * @returns {boolean} True if deletable
     */
    static isDeletable(plan) {
        return plan.completed_installments === 0;
    }

    /**
     * Format date for display
     * @param {string} dateString - Date string (YYYY-MM-DD)
     * @param {string} format - Format type (short, long, relative)
     * @returns {string} Formatted date
     */
    static formatDate(dateString, format = 'short') {
        const date = new Date(dateString);

        switch (format) {
            case 'short':
                return date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            case 'long':
                return date.toLocaleDateString('en-US', {
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric'
                });
            case 'relative':
                return this.getRelativeDate(dateString);
            default:
                return dateString;
        }
    }

    /**
     * Get relative date string (e.g., "3 days ago", "in 5 days")
     * @param {string} dateString - Date string (YYYY-MM-DD)
     * @returns {string} Relative date string
     */
    static getRelativeDate(dateString) {
        const date = new Date(dateString);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const diffTime = date - today;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Tomorrow';
        if (diffDays === -1) return 'Yesterday';
        if (diffDays > 0) return `in ${diffDays} days`;
        return `${Math.abs(diffDays)} days ago`;
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        InstallmentCalculator,
        InstallmentScheduleGenerator,
        InstallmentManager
    };
}
