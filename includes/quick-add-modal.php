<!-- Quick-Add Transaction Modal -->
<div id="quick-add-modal" class="modal-overlay" style="display: none;">
    <div class="modal-container quick-add-modal">
        <div class="modal-header">
            <h2>Quick Add Transaction</h2>
            <button type="button" class="modal-close" onclick="QuickAddModal.close()">×</button>
        </div>

        <div class="modal-body">
            <form id="quick-add-form" class="quick-add-form">
                <input type="hidden" id="qa-ledger-uuid" name="ledger_uuid">

                <div class="form-group">
                    <label for="qa-type" class="form-label">Type *</label>
                    <div class="type-toggle">
                        <button type="button" class="type-btn" data-type="outflow">Expense</button>
                        <button type="button" class="type-btn" data-type="inflow">Income</button>
                    </div>
                    <input type="hidden" id="qa-type" name="type" value="outflow">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="qa-amount" class="form-label">Amount *</label>
                        <input type="text" id="qa-amount" name="amount" class="form-input" required
                               placeholder="0.00" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="qa-date" class="form-label">Date *</label>
                        <div class="date-quick-select">
                            <button type="button" class="date-quick-btn" data-days="0">Today</button>
                            <button type="button" class="date-quick-btn" data-days="-1">Yesterday</button>
                            <button type="button" class="date-quick-btn" data-days="custom">Custom</button>
                        </div>
                        <input type="date" id="qa-date" name="date" class="form-input"
                               style="display: none;">
                    </div>
                </div>

                <div class="form-group">
                    <label for="qa-description" class="form-label">Description *</label>
                    <input type="text" id="qa-description" name="description" class="form-input" required
                           placeholder="e.g., Grocery shopping, Paycheck"
                           autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="qa-payee" class="form-label">Payee</label>
                    <div class="payee-autocomplete-wrapper">
                        <input type="text" id="qa-payee" name="payee" class="form-input"
                               placeholder="Optional"
                               autocomplete="off">
                        <div id="qa-payee-suggestions" class="autocomplete-suggestions" style="display: none;"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="qa-account" class="form-label">Account *</label>
                    <select id="qa-account" name="account" class="form-select" required>
                        <option value="">Choose account...</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="qa-category" class="form-label">Category</label>
                    <select id="qa-category" name="category" class="form-select">
                        <option value="">Choose category...</option>
                    </select>
                    <small class="form-help" id="qa-category-help">Leave blank for Income account</small>
                </div>

                <div class="form-group checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="qa-save-and-add" name="save_and_add">
                        Save & Add Another
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="QuickAddModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="qa-submit-btn">Add Transaction</button>
                </div>

                <div id="qa-error" class="form-error" style="display: none;"></div>
                <div id="qa-success" class="form-success" style="display: none;"></div>
            </form>
        </div>
    </div>
</div>

<style>
/* Modal Overlay */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.modal-overlay.active {
    opacity: 1;
}

/* Modal Container */
.modal-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    transform: scale(0.9) translateY(-20px);
    transition: transform 0.2s ease;
}

.modal-overlay.active .modal-container {
    transform: scale(1) translateY(0);
}

/* Modal Header */
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem 2rem;
    border-bottom: 2px solid #e2e8f0;
}

.modal-header h2 {
    margin: 0;
    color: #2d3748;
    font-size: 1.5rem;
}

.modal-close {
    background: none;
    border: none;
    font-size: 2rem;
    color: #718096;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}

.modal-close:hover {
    background: #f7fafc;
    color: #2d3748;
}

/* Modal Body */
.modal-body {
    padding: 2rem;
}

/* Quick Add Form Styles */
.quick-add-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* Type Toggle */
.type-toggle {
    display: flex;
    gap: 0.5rem;
    width: 100%;
}

.type-btn {
    flex: 1;
    padding: 0.75rem;
    border: 2px solid #e2e8f0;
    background: white;
    color: #4a5568;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.type-btn:hover {
    border-color: #cbd5e0;
    background: #f7fafc;
}

.type-btn.active {
    border-color: #3182ce;
    background: #ebf8ff;
    color: #2c5282;
}

.type-btn[data-type="inflow"].active {
    border-color: #38a169;
    background: #f0fff4;
    color: #22543d;
}

.type-btn[data-type="outflow"].active {
    border-color: #e53e3e;
    background: #fff5f5;
    color: #742a2a;
}

/* Date Quick Select */
.date-quick-select {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.date-quick-btn {
    flex: 1;
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
    background: white;
    color: #4a5568;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.date-quick-btn:hover {
    border-color: #cbd5e0;
    background: #f7fafc;
}

.date-quick-btn.active {
    border-color: #3182ce;
    background: #ebf8ff;
    color: #2c5282;
}

/* Checkbox Group */
.checkbox-group {
    margin-top: 1rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    color: #2d3748;
    font-size: 0.95rem;
}

.checkbox-label input[type="checkbox"] {
    cursor: pointer;
    width: 18px;
    height: 18px;
}

/* Modal Actions */
.modal-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 1.5rem;
}

/* Form Messages */
.form-error {
    background: #fff5f5;
    border: 1px solid #fc8181;
    color: #742a2a;
    padding: 0.75rem 1rem;
    border-radius: 6px;
    margin-top: 1rem;
    font-size: 0.9rem;
}

.form-success {
    background: #f0fff4;
    border: 1px solid #68d391;
    color: #22543d;
    padding: 0.75rem 1rem;
    border-radius: 6px;
    margin-top: 1rem;
    font-size: 0.9rem;
}

/* Payee Autocomplete in Modal */
.quick-add-modal .payee-autocomplete-wrapper {
    position: relative;
}

.quick-add-modal .autocomplete-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e2e8f0;
    border-top: none;
    border-radius: 0 0 6px 6px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    max-height: 200px;
    overflow-y: auto;
    z-index: 10000;
    margin-top: -1px;
}

.quick-add-modal .autocomplete-suggestion {
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s;
}

.quick-add-modal .autocomplete-suggestion:last-child {
    border-bottom: none;
}

.quick-add-modal .autocomplete-suggestion:hover,
.quick-add-modal .autocomplete-suggestion.active {
    background-color: #ebf8ff;
}

.quick-add-modal .autocomplete-payee-name {
    font-weight: 500;
    color: #2d3748;
    display: block;
}

.quick-add-modal .autocomplete-payee-meta {
    font-size: 0.75rem;
    color: #718096;
    margin-top: 0.25rem;
}

.quick-add-modal .autocomplete-empty {
    padding: 0.75rem 1rem;
    color: #a0aec0;
    font-style: italic;
    text-align: center;
    font-size: 0.85rem;
}

/* Responsive */
@media (max-width: 768px) {
    .modal-container {
        width: 95%;
        max-height: 95vh;
    }

    .modal-header {
        padding: 1rem 1.5rem;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .quick-add-form .form-row {
        grid-template-columns: 1fr;
    }

    .modal-actions {
        flex-direction: column-reverse;
    }

    .modal-actions .btn {
        width: 100%;
    }
}

/* Loading State */
.btn.loading {
    position: relative;
    color: transparent;
    pointer-events: none;
}

.btn.loading::after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid #fff;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spinner 0.6s linear infinite;
}

@keyframes spinner {
    to {
        transform: rotate(360deg);
    }
}
</style>
