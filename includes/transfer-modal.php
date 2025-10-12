<!--
    Account Transfer Modal
    Phase 3.5 - Account Transfers Simplified

    Simple modal for transferring money between accounts
-->

<style>
/* Transfer Modal Styles */
.transfer-modal {
    max-width: 500px;
    margin: 50px auto;
}

.transfer-modal .modal-body {
    padding: 30px;
}

.transfer-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.transfer-form .form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.transfer-form label {
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.transfer-form label .required {
    color: #dc2626;
}

.transfer-form input,
.transfer-form select,
.transfer-form textarea {
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.transfer-form input:focus,
.transfer-form select:focus,
.transfer-form textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.transfer-form textarea {
    resize: vertical;
    min-height: 60px;
    max-height: 120px;
}

.transfer-visual {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: #f9fafb;
    border-radius: 8px;
    margin: 10px 0;
}

.transfer-account-box {
    flex: 1;
    padding: 15px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    text-align: center;
    position: relative;
}

.transfer-account-box.from {
    border-color: #ef4444;
}

.transfer-account-box.to {
    border-color: #10b981;
}

.transfer-account-box .label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #6b7280;
    margin-bottom: 5px;
}

.transfer-account-box.from .label {
    color: #dc2626;
}

.transfer-account-box.to .label {
    color: #059669;
}

.transfer-account-box .account-name {
    font-weight: 600;
    color: #111827;
    font-size: 14px;
}

.transfer-arrow {
    font-size: 24px;
    color: #6b7280;
    flex-shrink: 0;
}

.transfer-amount-display {
    text-align: center;
    padding: 15px;
    background: #eff6ff;
    border: 2px solid #2563eb;
    border-radius: 8px;
    margin: 10px 0;
}

.transfer-amount-display .label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #1e40af;
    margin-bottom: 5px;
}

.transfer-amount-display .amount {
    font-size: 28px;
    font-weight: 700;
    color: #1e3a8a;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 10px;
}

.form-actions button {
    flex: 1;
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: background-color 0.2s, transform 0.1s;
}

.form-actions button:active {
    transform: scale(0.98);
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-primary:disabled {
    background: #93c5fd;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    background: #e5e7eb;
    color: #374151;
}

.btn-secondary:hover {
    background: #d1d5db;
}

.error-message {
    padding: 12px 16px;
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    color: #991b1b;
    font-size: 14px;
    margin-bottom: 15px;
    display: none;
}

.error-message.show {
    display: block;
}

.success-message {
    padding: 12px 16px;
    background: #d1fae5;
    border: 1px solid #a7f3d0;
    border-radius: 6px;
    color: #065f46;
    font-size: 14px;
    margin-bottom: 15px;
    display: none;
}

.success-message.show {
    display: block;
}

/* Date picker quick buttons */
.date-quick-buttons {
    display: flex;
    gap: 8px;
    margin-top: 5px;
}

.date-quick-buttons button {
    padding: 6px 12px;
    font-size: 12px;
    border: 1px solid #d1d5db;
    background: white;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.date-quick-buttons button:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.date-quick-buttons button.active {
    background: #2563eb;
    color: white;
    border-color: #2563eb;
}

/* Loading spinner */
.spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    margin-right: 8px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<div id="transfer-modal" class="modal-overlay" style="display: none;">
    <div class="modal-container transfer-modal">
        <div class="modal-header">
            <h2>Transfer Money</h2>
            <button type="button" class="modal-close" onclick="TransferModal.close()">×</button>
        </div>
        <div class="modal-body">
            <div class="error-message" id="transfer-error"></div>
            <div class="success-message" id="transfer-success"></div>

            <form id="transfer-form" class="transfer-form">
                <!-- From Account -->
                <div class="form-group">
                    <label for="transfer-from-account">
                        From Account <span class="required">*</span>
                    </label>
                    <select id="transfer-from-account" name="from_account_uuid" required>
                        <option value="">Select source account...</option>
                    </select>
                </div>

                <!-- To Account -->
                <div class="form-group">
                    <label for="transfer-to-account">
                        To Account <span class="required">*</span>
                    </label>
                    <select id="transfer-to-account" name="to_account_uuid" required>
                        <option value="">Select destination account...</option>
                    </select>
                </div>

                <!-- Visual Transfer Display -->
                <div class="transfer-visual" id="transfer-visual" style="display: none;">
                    <div class="transfer-account-box from">
                        <div class="label">From</div>
                        <div class="account-name" id="visual-from-account">—</div>
                    </div>
                    <div class="transfer-arrow">→</div>
                    <div class="transfer-account-box to">
                        <div class="label">To</div>
                        <div class="account-name" id="visual-to-account">—</div>
                    </div>
                </div>

                <!-- Amount -->
                <div class="form-group">
                    <label for="transfer-amount">
                        Amount <span class="required">*</span>
                    </label>
                    <input
                        type="text"
                        id="transfer-amount"
                        name="amount"
                        placeholder="0.00"
                        required
                        autocomplete="off"
                    >
                </div>

                <!-- Amount Display -->
                <div class="transfer-amount-display" id="amount-display" style="display: none;">
                    <div class="label">Transfer Amount</div>
                    <div class="amount" id="visual-amount">$0.00</div>
                </div>

                <!-- Date -->
                <div class="form-group">
                    <label for="transfer-date">
                        Date <span class="required">*</span>
                    </label>
                    <input
                        type="date"
                        id="transfer-date"
                        name="date"
                        required
                    >
                    <div class="date-quick-buttons">
                        <button type="button" onclick="TransferModal.setDateToday()">Today</button>
                        <button type="button" onclick="TransferModal.setDateYesterday()">Yesterday</button>
                    </div>
                </div>

                <!-- Memo (Optional) -->
                <div class="form-group">
                    <label for="transfer-memo">
                        Memo <span style="font-weight: normal; color: #6b7280;">(optional)</span>
                    </label>
                    <textarea
                        id="transfer-memo"
                        name="memo"
                        placeholder="Add a note about this transfer..."
                    ></textarea>
                </div>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="TransferModal.close()">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary" id="transfer-submit-btn">
                        Transfer Money
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
