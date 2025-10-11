/**
 * Goals Manager - JavaScript for goal creation, editing, and management
 * Phase 2.4: Goal UI Components
 */

class GoalsManager {
    constructor(ledgerUuid) {
        this.ledgerUuid = ledgerUuid;
        this.currentGoal = null;
        this.goals = [];
        this.initializeModals();
        this.attachEventListeners();
    }

    initializeModals() {
        // Create goal modal
        this.createGoalModal();
        // Edit goal modal (reuses create modal)
        // Delete confirmation modal
        this.createDeleteModal();
    }

    createGoalModal() {
        const modal = document.createElement('div');
        modal.id = 'goal-modal';
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="goal-modal-title">Set Goal</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="goal-form">
                        <input type="hidden" id="goal-uuid" name="goal_uuid">
                        <input type="hidden" id="goal-category-uuid" name="category_uuid">

                        <div class="form-group">
                            <label class="form-label">Category: <span id="goal-category-name" class="text-primary"></span></label>
                        </div>

                        <div class="form-group">
                            <label for="goal-type" class="form-label">Goal Type</label>
                            <div class="goal-type-selector">
                                <div class="goal-type-option" data-type="monthly_funding">
                                    <input type="radio" id="type-monthly" name="goal_type" value="monthly_funding" required>
                                    <label for="type-monthly">
                                        <strong>💰 Monthly Funding</strong>
                                        <p>Budget a fixed amount every month</p>
                                        <small>Example: $500/month for groceries</small>
                                    </label>
                                </div>
                                <div class="goal-type-option" data-type="target_balance">
                                    <input type="radio" id="type-balance" name="goal_type" value="target_balance" required>
                                    <label for="type-balance">
                                        <strong>🎯 Target Balance</strong>
                                        <p>Save up to a specific total amount</p>
                                        <small>Example: Build $5,000 emergency fund</small>
                                    </label>
                                </div>
                                <div class="goal-type-option" data-type="target_by_date">
                                    <input type="radio" id="type-date" name="goal_type" value="target_by_date" required>
                                    <label for="type-date">
                                        <strong>📅 Target by Date</strong>
                                        <p>Reach a target amount by a specific date</p>
                                        <small>Example: Save $1,200 for vacation by June</small>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="target-amount" class="form-label">Target Amount ($)</label>
                            <input type="number" id="target-amount" name="target_amount" class="form-input"
                                   step="0.01" min="0.01" required placeholder="0.00">
                            <span class="form-help">Enter the target amount in dollars</span>
                        </div>

                        <div class="form-group" id="target-date-group" style="display: none;">
                            <label for="target-date" class="form-label">Target Date</label>
                            <input type="date" id="target-date" name="target_date" class="form-input">
                            <span class="form-help">When do you want to reach this goal?</span>
                        </div>

                        <div class="form-group" id="repeat-frequency-group" style="display: none;">
                            <label for="repeat-frequency" class="form-label">Repeat Frequency (Optional)</label>
                            <select id="repeat-frequency" name="repeat_frequency" class="form-select">
                                <option value="">None</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                            <span class="form-help">Does this goal repeat?</span>
                        </div>

                        <div id="goal-preview" class="goal-preview" style="display: none;">
                            <h4>Goal Preview:</h4>
                            <p id="goal-preview-text"></p>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="goal-submit-btn">Create Goal</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Attach event listeners
        modal.querySelector('.modal-close').addEventListener('click', () => this.closeModal('goal-modal'));
        modal.querySelector('.modal-cancel').addEventListener('click', () => this.closeModal('goal-modal'));
        modal.querySelector('#goal-form').addEventListener('submit', (e) => this.handleGoalSubmit(e));

        // Goal type change handler
        modal.querySelectorAll('input[name="goal_type"]').forEach(radio => {
            radio.addEventListener('change', () => this.handleGoalTypeChange());
        });

        // Live preview
        modal.querySelectorAll('#target-amount, #target-date, input[name="goal_type"]').forEach(input => {
            input.addEventListener('input', () => this.updateGoalPreview());
        });
    }

    createDeleteModal() {
        const modal = document.createElement('div');
        modal.id = 'delete-goal-modal';
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal-content modal-small">
                <div class="modal-header">
                    <h2>Delete Goal?</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this goal?</p>
                    <p class="text-warning"><strong>This action cannot be undone.</strong></p>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirm-delete-goal">Delete Goal</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.modal-close').addEventListener('click', () => this.closeModal('delete-goal-modal'));
        modal.querySelector('.modal-cancel').addEventListener('click', () => this.closeModal('delete-goal-modal'));
        modal.querySelector('#confirm-delete-goal').addEventListener('click', () => this.confirmDeleteGoal());
    }

    attachEventListeners() {
        // Set Goal buttons in category rows
        document.addEventListener('click', (e) => {
            if (e.target.closest('.set-goal-btn')) {
                const btn = e.target.closest('.set-goal-btn');
                const categoryUuid = btn.dataset.categoryUuid;
                const categoryName = btn.dataset.categoryName;
                this.openCreateGoal(categoryUuid, categoryName);
            }

            if (e.target.closest('.edit-goal-btn')) {
                const btn = e.target.closest('.edit-goal-btn');
                const goalUuid = btn.dataset.goalUuid;
                this.openEditGoal(goalUuid);
            }

            if (e.target.closest('.delete-goal-btn')) {
                const btn = e.target.closest('.delete-goal-btn');
                const goalUuid = btn.dataset.goalUuid;
                this.openDeleteGoal(goalUuid);
            }
        });
    }

    openCreateGoal(categoryUuid, categoryName) {
        this.currentGoal = null;
        const modal = document.getElementById('goal-modal');
        const form = document.getElementById('goal-form');

        // Reset form
        form.reset();
        document.getElementById('goal-uuid').value = '';
        document.getElementById('goal-category-uuid').value = categoryUuid;
        document.getElementById('goal-category-name').textContent = categoryName;
        document.getElementById('goal-modal-title').textContent = 'Set Goal for ' + categoryName;
        document.getElementById('goal-submit-btn').textContent = 'Create Goal';

        // Show modal
        this.showModal('goal-modal');
    }

    async openEditGoal(goalUuid) {
        try {
            const response = await fetch(`../api/goals.php?goal_uuid=${encodeURIComponent(goalUuid)}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error);
            }

            this.currentGoal = data.goal;
            const modal = document.getElementById('goal-modal');
            const form = document.getElementById('goal-form');

            // Populate form
            document.getElementById('goal-uuid').value = this.currentGoal.goal_uuid;
            document.getElementById('goal-category-uuid').value = this.currentGoal.category_uuid;
            document.getElementById('goal-category-name').textContent = this.currentGoal.category_name;
            document.getElementById('target-amount').value = (this.currentGoal.target_amount / 100).toFixed(2);

            // Set goal type
            document.querySelector(`input[name="goal_type"][value="${this.currentGoal.goal_type}"]`).checked = true;
            this.handleGoalTypeChange();

            // Set target date if applicable
            if (this.currentGoal.target_date) {
                document.getElementById('target-date').value = this.currentGoal.target_date;
            }

            document.getElementById('goal-modal-title').textContent = 'Edit Goal';
            document.getElementById('goal-submit-btn').textContent = 'Update Goal';

            this.showModal('goal-modal');
            this.updateGoalPreview();

        } catch (error) {
            this.showNotification('Failed to load goal: ' + error.message, 'error');
        }
    }

    openDeleteGoal(goalUuid) {
        this.currentGoal = { goal_uuid: goalUuid };
        this.showModal('delete-goal-modal');
    }

    async confirmDeleteGoal() {
        if (!this.currentGoal) return;

        try {
            const response = await fetch(`../api/goals.php?goal_uuid=${encodeURIComponent(this.currentGoal.goal_uuid)}`, {
                method: 'DELETE'
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error);
            }

            this.showNotification('Goal deleted successfully', 'success');
            this.closeModal('delete-goal-modal');

            // Reload page to refresh goal displays
            setTimeout(() => window.location.reload(), 1000);

        } catch (error) {
            this.showNotification('Failed to delete goal: ' + error.message, 'error');
        }
    }

    handleGoalTypeChange() {
        const selectedType = document.querySelector('input[name="goal_type"]:checked')?.value;
        const targetDateGroup = document.getElementById('target-date-group');
        const repeatFreqGroup = document.getElementById('repeat-frequency-group');
        const targetDateInput = document.getElementById('target-date');

        // Reset visibility
        targetDateGroup.style.display = 'none';
        repeatFreqGroup.style.display = 'none';
        targetDateInput.removeAttribute('required');

        // Show relevant fields based on type
        if (selectedType === 'target_by_date') {
            targetDateGroup.style.display = 'block';
            targetDateInput.setAttribute('required', 'required');
            repeatFreqGroup.style.display = 'block';
        } else if (selectedType === 'monthly_funding') {
            repeatFreqGroup.style.display = 'block';
        }

        this.updateGoalPreview();
    }

    updateGoalPreview() {
        const preview = document.getElementById('goal-preview');
        const previewText = document.getElementById('goal-preview-text');
        const goalType = document.querySelector('input[name="goal_type"]:checked')?.value;
        const amount = parseFloat(document.getElementById('target-amount').value || 0);
        const targetDate = document.getElementById('target-date').value;

        if (!goalType || amount <= 0) {
            preview.style.display = 'none';
            return;
        }

        let text = '';
        const formattedAmount = '$' + amount.toFixed(2);

        switch (goalType) {
            case 'monthly_funding':
                text = `Budget ${formattedAmount} every month for this category.`;
                break;
            case 'target_balance':
                text = `Save up to a total of ${formattedAmount} in this category.`;
                break;
            case 'target_by_date':
                if (targetDate) {
                    const date = new Date(targetDate);
                    const formattedDate = date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                    text = `Save ${formattedAmount} by ${formattedDate}.`;
                } else {
                    text = `Save ${formattedAmount} by a specific date.`;
                }
                break;
        }

        previewText.textContent = text;
        preview.style.display = 'block';
    }

    async handleGoalSubmit(e) {
        e.preventDefault();

        const form = e.target;
        const goalUuid = document.getElementById('goal-uuid').value;
        const isEdit = !!goalUuid;

        const formData = {
            category_uuid: document.getElementById('goal-category-uuid').value,
            goal_type: document.querySelector('input[name="goal_type"]:checked').value,
            target_amount: Math.round(parseFloat(document.getElementById('target-amount').value) * 100), // Convert to cents
            target_date: document.getElementById('target-date').value || null,
            repeat_frequency: document.getElementById('repeat-frequency').value || null
        };

        if (isEdit) {
            formData.goal_uuid = goalUuid;
            delete formData.category_uuid; // Can't change category on update
            delete formData.goal_type; // Can't change goal type on update
        }

        try {
            const response = await fetch('../api/goals.php', {
                method: isEdit ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error);
            }

            this.showNotification(isEdit ? 'Goal updated successfully!' : 'Goal created successfully!', 'success');
            this.closeModal('goal-modal');

            // Reload page to refresh goal displays
            setTimeout(() => window.location.reload(), 1000);

        } catch (error) {
            this.showNotification('Error: ' + error.message, 'error');
        }
    }

    showModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.style.display = 'flex';
        requestAnimationFrame(() => {
            modal.classList.add('show');
        });
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.classList.remove('show');
        setTimeout(() => {
            modal.style.display = 'none';
        }, 300);
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `goal-notification notification-${type}`;
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
    static formatCurrency(cents) {
        return '$' + (cents / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Calculate progress percentage
    static calculateProgress(current, target) {
        if (target === 0) return 0;
        return Math.min(100, Math.round((current / target) * 100));
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on a page with goals
    const ledgerUuidElement = document.querySelector('[data-ledger-uuid]');
    if (ledgerUuidElement) {
        const ledgerUuid = ledgerUuidElement.dataset.ledgerUuid;
        window.goalsManager = new GoalsManager(ledgerUuid);
    }
});
