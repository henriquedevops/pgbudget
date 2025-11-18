/**
 * Obligation Payment Handlers
 * Handles mark as paid and edit payment functionality
 * Used by both index.php and view.php
 */

document.addEventListener('DOMContentLoaded', function() {
    // Mark as Paid Modal
    const markPaidModal = document.getElementById('markPaidModal');
    const confirmMarkPaidBtn = document.getElementById('confirmMarkPaid');
    const cancelMarkPaidBtn = document.getElementById('cancelMarkPaid');

    let currentPaymentUuid = null;

    // Handle mark as paid button clicks
    document.querySelectorAll('.mark-paid-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentPaymentUuid = this.dataset.paymentUuid;
            const paymentAmount = parseFloat(this.dataset.paymentAmount);

            document.getElementById('modalPaymentName').textContent = this.dataset.paymentName;
            document.getElementById('modalDueDate').textContent = new Date(this.dataset.dueDate).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            document.getElementById('modalActualAmount').value = paymentAmount.toFixed(2);
            document.getElementById('modalNotes').value = '';
            document.getElementById('modalPaymentMethod').value = '';
            document.getElementById('modalConfirmationNumber').value = '';

            markPaidModal.style.display = 'flex';
            document.getElementById('modalActualAmount').focus();
        });
    });

    // Handle cancel
    cancelMarkPaidBtn.addEventListener('click', function() {
        markPaidModal.style.display = 'none';
        currentPaymentUuid = null;
    });

    // Handle confirm mark as paid
    confirmMarkPaidBtn.addEventListener('click', async function() {
        if (!currentPaymentUuid) return;

        const actualAmount = parseFloat(document.getElementById('modalActualAmount').value);
        const paidDate = document.getElementById('modalPaidDate').value;
        const notes = document.getElementById('modalNotes').value;
        const paymentMethod = document.getElementById('modalPaymentMethod').value;
        const confirmationNumber = document.getElementById('modalConfirmationNumber').value;

        if (!actualAmount || actualAmount <= 0) {
            alert('Please enter a valid payment amount.');
            return;
        }

        if (!paidDate) {
            alert('Please select a payment date.');
            return;
        }

        try {
            confirmMarkPaidBtn.disabled = true;
            confirmMarkPaidBtn.textContent = 'Processing...';

            const formData = new FormData();
            formData.append('action', 'mark_paid');
            formData.append('payment_uuid', currentPaymentUuid);
            formData.append('paid_date', paidDate);
            formData.append('actual_amount', actualAmount);
            if (notes) formData.append('notes', notes);
            if (paymentMethod) formData.append('payment_method', paymentMethod);
            if (confirmationNumber) formData.append('confirmation_number', confirmationNumber);

            const response = await fetch('../api/obligations.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                window.location.reload();
            } else {
                alert('Error marking payment as paid: ' + result.error);
                confirmMarkPaidBtn.disabled = false;
                confirmMarkPaidBtn.textContent = 'Mark as Paid';
            }
        } catch (error) {
            alert('Error marking payment as paid: ' + error.message);
            confirmMarkPaidBtn.disabled = false;
            confirmMarkPaidBtn.textContent = 'Mark as Paid';
        }
    });

    // Close modal on background click
    markPaidModal.addEventListener('click', function(e) {
        if (e.target === markPaidModal) {
            markPaidModal.style.display = 'none';
            currentPaymentUuid = null;
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && markPaidModal.style.display === 'flex') {
            markPaidModal.style.display = 'none';
            currentPaymentUuid = null;
        }
    });

    // Edit Payment Modal (only on view.php)
    const editPaymentModal = document.getElementById('editPaymentModal');
    if (editPaymentModal) {
        const confirmEditPaymentBtn = document.getElementById('confirmEditPayment');
        const cancelEditPaymentBtn = document.getElementById('cancelEditPayment');

        let currentEditPaymentUuid = null;

        // Handle edit payment button clicks
        document.querySelectorAll('.edit-payment-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const paymentData = JSON.parse(this.dataset.paymentData);
                currentEditPaymentUuid = paymentData.uuid;

                document.getElementById('editPaymentUuid').value = currentEditPaymentUuid;
                document.getElementById('editActualAmount').value = paymentData.actual_amount_paid || '';
                document.getElementById('editPaidDate').value = paymentData.paid_date || '';
                document.getElementById('editPaymentMethod').value = paymentData.payment_method || '';
                document.getElementById('editConfirmationNumber').value = paymentData.confirmation_number || '';
                document.getElementById('editNotes').value = paymentData.notes || '';
                document.getElementById('editStatus').value = paymentData.status || 'paid';

                editPaymentModal.style.display = 'flex';
            });
        });

        // Handle cancel
        cancelEditPaymentBtn.addEventListener('click', function() {
            editPaymentModal.style.display = 'none';
            currentEditPaymentUuid = null;
        });

        // Handle confirm edit
        confirmEditPaymentBtn.addEventListener('click', async function() {
            if (!currentEditPaymentUuid) return;

            const actualAmount = parseFloat(document.getElementById('editActualAmount').value);
            const paidDate = document.getElementById('editPaidDate').value;
            const paymentMethod = document.getElementById('editPaymentMethod').value;
            const confirmationNumber = document.getElementById('editConfirmationNumber').value;
            const notes = document.getElementById('editNotes').value;
            const status = document.getElementById('editStatus').value;

            if (!actualAmount || actualAmount <= 0) {
                alert('Please enter a valid payment amount.');
                return;
            }

            try {
                confirmEditPaymentBtn.disabled = true;
                confirmEditPaymentBtn.textContent = 'Updating...';

                const formData = new FormData();
                formData.append('action', 'edit_payment');
                formData.append('payment_uuid', currentEditPaymentUuid);
                formData.append('actual_amount', actualAmount);
                formData.append('paid_date', paidDate);
                formData.append('payment_method', paymentMethod);
                formData.append('confirmation_number', confirmationNumber);
                formData.append('notes', notes);
                formData.append('status', status);

                const response = await fetch('../api/obligations.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    window.location.reload();
                } else {
                    alert('Error updating payment: ' + result.error);
                    confirmEditPaymentBtn.disabled = false;
                    confirmEditPaymentBtn.textContent = 'Update Payment';
                }
            } catch (error) {
                alert('Error updating payment: ' + error.message);
                confirmEditPaymentBtn.disabled = false;
                confirmEditPaymentBtn.textContent = 'Update Payment';
            }
        });

        // Close modal on background click
        editPaymentModal.addEventListener('click', function(e) {
            if (e.target === editPaymentModal) {
                editPaymentModal.style.display = 'none';
                currentEditPaymentUuid = null;
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && editPaymentModal.style.display === 'flex') {
                editPaymentModal.style.display = 'none';
                currentEditPaymentUuid = null;
            }
        });
    }
});
