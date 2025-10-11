/**
 * Quick Fund Goals - JavaScript for auto-funding suggestions
 * Phase 2.5: Goal Calculations
 */

class QuickFundGoals {
    constructor(ledgerUuid) {
        this.ledgerUuid = ledgerUuid;
        this.suggestions = [];
        this.createModal();
        this.attachEventListeners();
    }

    attachEventListeners() {
        // Quick Fund button click
        document.addEventListener('click', (e) => {
            if (e.target.closest('.quick-fund-goals-btn')) {
                this.openQuickFundModal();
            }
        });
    }

    createModal() {
        const modal = document.createElement('div');
        modal.id = 'quick-fund-modal';
        modal.className = 'modal-backdrop';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>⚡ Quick Fund Goals</h2>
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="quick-fund-loading" style="display: none;">
                        <p>Calculating funding suggestions...</p>
                    </div>

                    <div id="quick-fund-content" style="display: none;">
                        <div class="quick-fund-summary">
                            <div class="summary-item">
                                <span class="summary-label">Available to Assign:</span>
                                <span class="summary-value" id="qf-available"></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Suggested Total:</span>
                                <span class="summary-value qf-suggested" id="qf-total"></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Remaining After:</span>
                                <span class="summary-value" id="qf-remaining"></span>
                            </div>
                        </div>

                        <div id="quick-fund-suggestions"></div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                            <button type="button" class="btn btn-primary" id="apply-quick-fund">Apply Suggestions</button>
                        </div>
                    </div>

                    <div id="quick-fund-error" style="display: none;" class="error-message"></div>

                    <div id="quick-fund-empty" style="display: none;" class="empty-state">
                        <div class="success-icon">🎯</div>
                        <p><strong>All goals are fully funded!</strong></p>
                        <p>Great job staying on top of your budget.</p>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Attach event listeners
        modal.querySelector('.modal-close').addEventListener('click', () => this.closeModal());
        modal.querySelector('.modal-cancel')?.addEventListener('click', () => this.closeModal());
        modal.querySelector('#apply-quick-fund')?.addEventListener('click', () => this.applyFunding());
    }

    async openQuickFundModal() {
        this.showModal();
        this.showLoading();

        try {
            const response = await fetch('../api/quick-fund-goals.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    ledger_uuid: this.ledgerUuid
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error);
            }

            this.suggestions = data.suggestions || [];

            if (this.suggestions.length === 0) {
                this.showEmpty(data.message);
            } else {
                this.displaySuggestions(data);
            }

        } catch (error) {
            this.showError('Failed to load funding suggestions: ' + error.message);
        }
    }

    displaySuggestions(data) {
        document.getElementById('quick-fund-loading').style.display = 'none';
        document.getElementById('quick-fund-error').style.display = 'none';
        document.getElementById('quick-fund-empty').style.display = 'none';
        document.getElementById('quick-fund-content').style.display = 'block';

        // Update summary
        const availableEl = document.getElementById('qf-available');
        availableEl.textContent = this.formatCurrency(data.available_amount);
        availableEl.dataset.amount = data.available_amount; // Store for updateTotals
        document.getElementById('qf-total').textContent = this.formatCurrency(data.total_suggested);
        document.getElementById('qf-remaining').textContent = this.formatCurrency(data.remaining_after);

        // Display suggestions
        const suggestionsContainer = document.getElementById('quick-fund-suggestions');
        suggestionsContainer.innerHTML = `
            <h4>Suggested Assignments (${data.goals_count} goal${data.goals_count !== 1 ? 's' : ''})</h4>
            <div class="quick-fund-list">
                ${this.suggestions.map((suggestion, index) => this.renderSuggestion(suggestion, index)).join('')}
            </div>
        `;

        // Attach checkbox listeners
        suggestionsContainer.querySelectorAll('.qf-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', () => this.updateTotals());
        });

        // Attach amount input listeners
        suggestionsContainer.querySelectorAll('.qf-amount-input').forEach(input => {
            input.addEventListener('input', () => this.updateTotals());
        });
    }

    renderSuggestion(suggestion, index) {
        const goalTypeLabel = {
            'monthly_funding': 'Monthly Funding',
            'target_balance': 'Target Balance',
            'target_by_date': 'Target by Date'
        }[suggestion.goal_type] || suggestion.goal_type;

        return `
            <div class="qf-suggestion-item">
                <div class="qf-checkbox-container">
                    <input type="checkbox" class="qf-checkbox" id="qf-check-${index}"
                           data-index="${index}" checked>
                </div>
                <div class="qf-details">
                    <div class="qf-category-name">
                        <label for="qf-check-${index}">${this.escapeHtml(suggestion.category_name)}</label>
                        <span class="qf-goal-type">${goalTypeLabel}</span>
                    </div>
                    <div class="qf-reason">${this.escapeHtml(suggestion.reason)}</div>
                </div>
                <div class="qf-amount">
                    <input type="number" class="qf-amount-input"
                           data-index="${index}"
                           value="${(suggestion.suggested_amount / 100).toFixed(2)}"
                           min="0"
                           max="${(suggestion.needed_amount / 100).toFixed(2)}"
                           step="0.01">
                </div>
            </div>
        `;
    }

    updateTotals() {
        let total = 0;
        const availableAmount = parseInt(document.getElementById('qf-available').dataset.amount || 0);

        document.querySelectorAll('.qf-checkbox:checked').forEach(checkbox => {
            const index = parseInt(checkbox.dataset.index);
            const amountInput = document.querySelector(`.qf-amount-input[data-index="${index}"]`);
            const amount = parseFloat(amountInput.value || 0) * 100;
            total += amount;
        });

        document.getElementById('qf-total').textContent = this.formatCurrency(total);
        document.getElementById('qf-remaining').textContent = this.formatCurrency(
            (availableAmount || 0) - total
        );
    }

    async applyFunding() {
        const selectedSuggestions = [];

        document.querySelectorAll('.qf-checkbox:checked').forEach(checkbox => {
            const index = parseInt(checkbox.dataset.index);
            const amountInput = document.querySelector(`.qf-amount-input[data-index="${index}"]`);
            const amount = parseFloat(amountInput.value || 0);

            if (amount > 0) {
                selectedSuggestions.push({
                    ...this.suggestions[index],
                    suggested_amount: Math.round(amount * 100) // Convert to cents
                });
            }
        });

        if (selectedSuggestions.length === 0) {
            this.showError('Please select at least one goal to fund');
            return;
        }

        // Apply assignments
        const applyButton = document.getElementById('apply-quick-fund');
        applyButton.disabled = true;
        applyButton.textContent = 'Applying...';

        let successCount = 0;
        let failCount = 0;

        for (const suggestion of selectedSuggestions) {
            try {
                const response = await fetch('../api/assign.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        ledger_uuid: this.ledgerUuid,
                        category_uuid: suggestion.category_uuid,
                        amount: suggestion.suggested_amount
                    })
                });

                const data = await response.json();
                if (data.success) {
                    successCount++;
                } else {
                    failCount++;
                    console.error('Failed to assign to', suggestion.category_name, data.error);
                }
            } catch (error) {
                failCount++;
                console.error('Error assigning to', suggestion.category_name, error);
            }
        }

        if (successCount > 0) {
            this.showNotification(
                `Successfully funded ${successCount} goal${successCount !== 1 ? 's' : ''}!`,
                'success'
            );
            this.closeModal();
            // Reload page to refresh budget
            setTimeout(() => window.location.reload(), 1000);
        } else {
            this.showError('Failed to apply funding suggestions');
            applyButton.disabled = false;
            applyButton.textContent = 'Apply Suggestions';
        }
    }

    showLoading() {
        document.getElementById('quick-fund-loading').style.display = 'block';
        document.getElementById('quick-fund-content').style.display = 'none';
        document.getElementById('quick-fund-error').style.display = 'none';
        document.getElementById('quick-fund-empty').style.display = 'none';
    }

    showError(message) {
        document.getElementById('quick-fund-loading').style.display = 'none';
        document.getElementById('quick-fund-content').style.display = 'none';
        document.getElementById('quick-fund-empty').style.display = 'none';
        document.getElementById('quick-fund-error').style.display = 'block';
        document.getElementById('quick-fund-error').textContent = message;
    }

    showEmpty(message) {
        document.getElementById('quick-fund-loading').style.display = 'none';
        document.getElementById('quick-fund-content').style.display = 'none';
        document.getElementById('quick-fund-error').style.display = 'none';
        document.getElementById('quick-fund-empty').style.display = 'block';

        const emptyDiv = document.getElementById('quick-fund-empty');
        emptyDiv.querySelector('p:last-child').textContent = message || 'Great job staying on top of your budget.';
    }

    showModal() {
        const modal = document.getElementById('quick-fund-modal');
        modal.style.display = 'flex';
        requestAnimationFrame(() => {
            modal.classList.add('show');
        });
    }

    closeModal() {
        const modal = document.getElementById('quick-fund-modal');
        modal.classList.remove('show');
        setTimeout(() => {
            modal.style.display = 'none';
        }, 300);
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `qf-notification notification-${type}`;
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

    formatCurrency(cents) {
        return '$' + (cents / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const ledgerUuidElement = document.querySelector('[data-ledger-uuid]');
    if (ledgerUuidElement) {
        const ledgerUuid = ledgerUuidElement.dataset.ledgerUuid;
        window.quickFundGoals = new QuickFundGoals(ledgerUuid);
    }
});
