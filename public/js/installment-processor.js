/**
 * Installment Processing Module
 * Handles processing of individual and batch installment payments
 * Part of Step 4.2 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

/**
 * InstallmentProcessor - Main class for processing installments
 */
class InstallmentProcessor {
    constructor(ledgerUuid) {
        this.ledgerUuid = ledgerUuid;
        this.currentScheduleUuid = null;
        this.currentPlanUuid = null;
        this.processingModal = null;
        this.previewModal = null;
        this.batchProgressModal = null;
        this.initializeModals();
        this.attachEventListeners();
    }

    /**
     * Initialize modals for processing
     */
    initializeModals() {
        this.createProcessPreviewModal();
        this.createBatchProgressModal();
    }

    /**
     * Create process preview modal
     */
    createProcessPreviewModal() {
        const modal = document.createElement('div');
        modal.id = 'process-preview-modal';
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Process Installment Preview</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="preview-content">
                        <!-- Preview content will be inserted here -->
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                        <button type="button" class="btn btn-primary" id="confirm-process">Process Installment</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        this.previewModal = modal;

        modal.querySelector('.modal-close').addEventListener('click', () => this.closeModal('process-preview-modal'));
        modal.querySelector('.modal-cancel').addEventListener('click', () => this.closeModal('process-preview-modal'));
        modal.querySelector('#confirm-process').addEventListener('click', () => this.confirmProcessing());
    }

    /**
     * Create batch progress modal
     */
    createBatchProgressModal() {
        const modal = document.createElement('div');
        modal.id = 'batch-progress-modal';
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Processing Installments</h2>
                </div>
                <div class="modal-body">
                    <div class="progress-container">
                        <div class="progress-bar-wrapper">
                            <div id="batch-progress-bar" class="progress-bar"></div>
                        </div>
                        <div id="batch-progress-text" class="progress-text">Processing 0 of 0...</div>
                    </div>
                    <div id="batch-progress-details" class="progress-details">
                        <!-- Progress details will be inserted here -->
                    </div>
                    <div class="form-actions" style="display: none;">
                        <button type="button" class="btn btn-primary" id="batch-complete-btn">Done</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        this.batchProgressModal = modal;

        modal.querySelector('#batch-complete-btn')?.addEventListener('click', () => {
            this.closeModal('batch-progress-modal');
            window.location.reload();
        });
    }

    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Process single installment buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.process-installment-btn')) {
                const btn = e.target.closest('.process-installment-btn');
                const scheduleUuid = btn.dataset.scheduleUuid;
                const planUuid = btn.dataset.planUuid;
                this.showProcessPreview(scheduleUuid, planUuid);
            }

            // Batch process buttons
            if (e.target.closest('.batch-process-btn')) {
                const btn = e.target.closest('.batch-process-btn');
                const planUuid = btn.dataset.planUuid;
                this.handleBatchProcessing(planUuid);
            }
        });
    }

    /**
     * Show process preview modal
     * @param {string} scheduleUuid - Schedule UUID
     * @param {string} planUuid - Plan UUID
     */
    async showProcessPreview(scheduleUuid, planUuid) {
        try {
            this.currentScheduleUuid = scheduleUuid;
            this.currentPlanUuid = planUuid;

            // Show loading state
            this.showModal('process-preview-modal');
            const previewContent = document.getElementById('preview-content');
            previewContent.innerHTML = '<div class="loading">Loading preview...</div>';

            // Fetch plan and schedule details
            const planData = await InstallmentManager.getPlan(planUuid);
            const plan = planData.plan;

            // Find the specific schedule item
            const schedule = plan.schedule.find(s => s.uuid === scheduleUuid);

            if (!schedule) {
                throw new Error('Schedule item not found');
            }

            // Build preview content
            const previewHTML = this.buildPreviewContent(plan, schedule);
            previewContent.innerHTML = previewHTML;

        } catch (error) {
            console.error('Error showing preview:', error);
            this.showNotification('Error loading preview: ' + error.message, 'error');
            this.closeModal('process-preview-modal');
        }
    }

    /**
     * Build preview content HTML
     * @param {Object} plan - Installment plan
     * @param {Object} schedule - Schedule item
     * @returns {string} HTML content
     */
    buildPreviewContent(plan, schedule) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const dueDate = new Date(schedule.due_date);
        const isOverdue = dueDate < today;
        const isEarly = dueDate > today;

        let statusBadge = '';
        if (isOverdue) {
            const daysOverdue = Math.floor((today - dueDate) / (1000 * 60 * 60 * 24));
            statusBadge = `<span class="badge badge-danger">Overdue by ${daysOverdue} day${daysOverdue !== 1 ? 's' : ''}</span>`;
        } else if (isEarly) {
            const daysEarly = Math.floor((dueDate - today) / (1000 * 60 * 60 * 24));
            statusBadge = `<span class="badge badge-info">Early (${daysEarly} day${daysEarly !== 1 ? 's' : ''} early)</span>`;
        } else {
            statusBadge = `<span class="badge badge-success">On time</span>`;
        }

        return `
            <div class="preview-section">
                <h3>Plan Details</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Description:</span>
                        <span class="detail-value">${this.escapeHtml(plan.description)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Credit Card:</span>
                        <span class="detail-value">${this.escapeHtml(plan.credit_card_name)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Category:</span>
                        <span class="detail-value">${plan.category_name ? this.escapeHtml(plan.category_name) : 'None'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Total Purchase:</span>
                        <span class="detail-value">${InstallmentCalculator.formatCurrency(parseFloat(plan.purchase_amount))}</span>
                    </div>
                </div>
            </div>

            <div class="preview-section highlight">
                <h3>Installment to Process</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Installment:</span>
                        <span class="detail-value">#${schedule.installment_number} of ${plan.number_of_installments}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Due Date:</span>
                        <span class="detail-value">${InstallmentManager.formatDate(schedule.due_date, 'long')} ${statusBadge}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Amount:</span>
                        <span class="detail-value amount-large">${InstallmentCalculator.formatCurrency(parseFloat(schedule.scheduled_amount))}</span>
                    </div>
                </div>
            </div>

            <div class="preview-section">
                <h3>Budget Impact</h3>
                <p class="impact-description">Processing this installment will create the following budget transaction:</p>
                <div class="transaction-preview">
                    <div class="transaction-line debit">
                        <span class="transaction-label">Debit (Credit Card Payment Category):</span>
                        <span class="transaction-amount">${InstallmentCalculator.formatCurrency(parseFloat(schedule.scheduled_amount))}</span>
                    </div>
                    <div class="transaction-line credit">
                        <span class="transaction-label">Credit${plan.category_name ? ' (' + this.escapeHtml(plan.category_name) + ')' : ''}:</span>
                        <span class="transaction-amount">${InstallmentCalculator.formatCurrency(parseFloat(schedule.scheduled_amount))}</span>
                    </div>
                </div>
                <p class="impact-note">
                    <strong>Effect:</strong> This moves ${InstallmentCalculator.formatCurrency(parseFloat(schedule.scheduled_amount))}
                    from ${plan.category_name ? 'the ' + this.escapeHtml(plan.category_name) + ' category' : 'your budget'}
                    to the credit card payment category for ${this.escapeHtml(plan.credit_card_name)}.
                </p>
            </div>

            <div class="preview-section">
                <h3>After Processing</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Completed Installments:</span>
                        <span class="detail-value">${parseInt(plan.completed_installments) + 1} of ${plan.number_of_installments}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Remaining Installments:</span>
                        <span class="detail-value">${parseInt(plan.number_of_installments) - parseInt(plan.completed_installments) - 1}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Progress:</span>
                        <span class="detail-value">${Math.round(((parseInt(plan.completed_installments) + 1) / parseInt(plan.number_of_installments)) * 100)}%</span>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Confirm and process the installment
     */
    async confirmProcessing() {
        if (!this.currentScheduleUuid || !this.currentPlanUuid) {
            this.showNotification('Invalid installment data', 'error');
            return;
        }

        try {
            // Disable confirm button
            const confirmBtn = document.getElementById('confirm-process');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '⏳ Processing...';

            // Process the installment
            const result = await this.processInstallment(this.currentScheduleUuid);

            // Close modal
            this.closeModal('process-preview-modal');

            // Show success and handle post-processing
            this.handleProcessSuccess(result);

        } catch (error) {
            console.error('Error processing installment:', error);
            this.showNotification('Error: ' + error.message, 'error');

            // Re-enable button
            const confirmBtn = document.getElementById('confirm-process');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = 'Process Installment';
            }
        }
    }

    /**
     * Process a single installment
     * @param {string} scheduleUuid - Schedule UUID
     * @param {Object} processData - Optional processing data
     * @returns {Promise} API response
     */
    async processInstallment(scheduleUuid, processData = {}) {
        try {
            const result = await InstallmentManager.processInstallment(scheduleUuid, processData);

            if (!result.success) {
                throw new Error(result.error || 'Failed to process installment');
            }

            return result;
        } catch (error) {
            throw new Error('Failed to process installment: ' + error.message);
        }
    }

    /**
     * Handle successful processing
     * @param {Object} result - Processing result
     */
    handleProcessSuccess(result) {
        // Show success notification
        this.showNotification('✅ Installment processed successfully!', 'success');

        // Update UI elements if they exist
        this.updateUIAfterProcessing(result);

        // Reload page after a short delay to show the success message
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    }

    /**
     * Update UI after processing
     * @param {Object} result - Processing result
     */
    updateUIAfterProcessing(result) {
        // Update progress bars
        const progressBars = document.querySelectorAll('.installment-progress');
        progressBars.forEach(bar => {
            const planUuid = bar.dataset.planUuid;
            if (planUuid === this.currentPlanUuid && result.plan) {
                const progress = (parseInt(result.plan.completed_installments) / parseInt(result.plan.number_of_installments)) * 100;
                const progressBar = bar.querySelector('.progress-bar-fill');
                if (progressBar) {
                    progressBar.style.width = progress + '%';
                }
            }
        });

        // Update schedule table if visible
        const scheduleRow = document.querySelector(`tr[data-schedule-uuid="${this.currentScheduleUuid}"]`);
        if (scheduleRow) {
            const statusCell = scheduleRow.querySelector('.status-cell');
            if (statusCell) {
                statusCell.innerHTML = '<span class="badge badge-success">Processed</span>';
            }

            // Disable process button
            const processBtn = scheduleRow.querySelector('.process-installment-btn');
            if (processBtn) {
                processBtn.disabled = true;
                processBtn.textContent = 'Processed';
            }
        }

        // Update summary statistics
        this.updateSummaryStats();
    }

    /**
     * Update summary statistics on the page
     */
    updateSummaryStats() {
        // This will be called to update dashboard widgets or summary cards
        const statsElement = document.getElementById('installment-stats');
        if (statsElement) {
            // Trigger a refresh of stats
            const event = new CustomEvent('installment-processed', {
                detail: { planUuid: this.currentPlanUuid }
            });
            document.dispatchEvent(event);
        }
    }

    /**
     * Handle batch processing of multiple installments
     * @param {string} planUuid - Plan UUID
     */
    async handleBatchProcessing(planUuid) {
        try {
            // Fetch plan data
            const planData = await InstallmentManager.getPlan(planUuid);
            const plan = planData.plan;

            // Find overdue installments
            const overdueInstallments = InstallmentScheduleGenerator.getOverdueInstallments(plan.schedule);

            if (overdueInstallments.length === 0) {
                this.showNotification('No overdue installments to process', 'info');
                return;
            }

            // Confirm batch processing
            const confirmed = confirm(
                `You are about to process ${overdueInstallments.length} overdue installment${overdueInstallments.length !== 1 ? 's' : ''}.\n\n` +
                `Total amount: ${InstallmentCalculator.formatCurrency(
                    overdueInstallments.reduce((sum, item) => sum + parseFloat(item.scheduled_amount), 0)
                )}\n\n` +
                `Continue?`
            );

            if (!confirmed) return;

            // Show progress modal
            this.showModal('batch-progress-modal');
            this.updateBatchProgress(0, overdueInstallments.length);

            // Process in batch
            const results = await InstallmentManager.processBatch(
                overdueInstallments.map(item => item.uuid),
                (progress) => this.updateBatchProgress(progress.current, progress.total, progress)
            );

            // Update modal with results
            this.showBatchResults(results);

        } catch (error) {
            console.error('Error in batch processing:', error);
            this.showNotification('Error: ' + error.message, 'error');
            this.closeModal('batch-progress-modal');
        }
    }

    /**
     * Update batch progress
     * @param {number} current - Current item
     * @param {number} total - Total items
     * @param {Object} progress - Progress details
     */
    updateBatchProgress(current, total, progress = null) {
        const progressBar = document.getElementById('batch-progress-bar');
        const progressText = document.getElementById('batch-progress-text');
        const progressDetails = document.getElementById('batch-progress-details');

        if (progressBar) {
            const percentage = (current / total) * 100;
            progressBar.style.width = percentage + '%';
        }

        if (progressText) {
            progressText.textContent = `Processing ${current} of ${total}...`;
        }

        if (progress && progressDetails) {
            const itemHTML = `
                <div class="progress-item ${progress.success ? 'success' : 'error'}">
                    <span class="progress-icon">${progress.success ? '✓' : '✗'}</span>
                    <span class="progress-info">Installment ${progress.current} ${progress.success ? 'processed' : 'failed'}</span>
                </div>
            `;
            progressDetails.innerHTML += itemHTML;

            // Scroll to bottom
            progressDetails.scrollTop = progressDetails.scrollHeight;
        }
    }

    /**
     * Show batch processing results
     * @param {Object} results - Batch processing results
     */
    showBatchResults(results) {
        const progressText = document.getElementById('batch-progress-text');
        const actionsDiv = document.querySelector('#batch-progress-modal .form-actions');

        if (progressText) {
            progressText.innerHTML = `
                <div class="batch-results">
                    <h3>Processing Complete</h3>
                    <p><strong>Total:</strong> ${results.total}</p>
                    <p class="success"><strong>Successful:</strong> ${results.successful}</p>
                    ${results.failed > 0 ? `<p class="error"><strong>Failed:</strong> ${results.failed}</p>` : ''}
                </div>
            `;
        }

        if (actionsDiv) {
            actionsDiv.style.display = 'flex';
        }
    }

    /**
     * Show modal
     * @param {string} modalId - Modal ID
     */
    showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    /**
     * Close modal
     * @param {string} modalId - Modal ID
     */
    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        // Reset current references
        if (modalId === 'process-preview-modal') {
            this.currentScheduleUuid = null;
            this.currentPlanUuid = null;
        }
    }

    /**
     * Show notification
     * @param {string} message - Notification message
     * @param {string} type - Notification type (success, error, warning, info)
     */
    showNotification(message, type = 'info') {
        // Check if notification system exists
        if (typeof showNotification === 'function') {
            showNotification(message, type);
            return;
        }

        // Fallback to alert
        if (type === 'error') {
            alert('Error: ' + message);
        } else if (type === 'success') {
            alert(message);
        } else {
            alert(message);
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { InstallmentProcessor };
}
