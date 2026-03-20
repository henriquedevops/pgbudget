<!-- Reusable Confirmation Modal — included by footer.php for all authenticated pages -->
<div id="confirm-modal"
     class="modal-overlay"
     role="dialog"
     aria-modal="true"
     aria-labelledby="confirm-modal-title"
     aria-describedby="confirm-modal-message">

    <div class="modal-container confirm-modal-box">
        <h3 id="confirm-modal-title" class="confirm-modal-title"></h3>
        <p  id="confirm-modal-message" class="confirm-modal-message"></p>
        <div class="modal-actions confirm-modal-actions">
            <button id="confirm-modal-cancel" class="btn btn-secondary" type="button">Cancel</button>
            <button id="confirm-modal-ok"     class="btn btn-danger"    type="button">Confirm</button>
        </div>
    </div>
</div>
