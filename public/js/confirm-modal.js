/**
 * ConfirmModal — styled replacement for window.confirm()
 *
 * Usage:
 *   ConfirmModal.show({
 *     title:        'Delete Transaction?',       // required
 *     message:      'This cannot be undone.',    // required
 *     confirmText:  'Delete',                    // default: 'Confirm'
 *     confirmClass: 'btn-danger',               // default: 'btn-danger'
 *     onConfirm:    () => { doTheThing(); },     // required
 *     onCancel:     () => {},                    // optional
 *   });
 */
window.ConfirmModal = (function () {
    var overlay, titleEl, messageEl, okBtn, cancelBtn;
    var _onConfirm = null;
    var _onCancel  = null;

    function init() {
        overlay    = document.getElementById('confirm-modal');
        titleEl    = document.getElementById('confirm-modal-title');
        messageEl  = document.getElementById('confirm-modal-message');
        okBtn      = document.getElementById('confirm-modal-ok');
        cancelBtn  = document.getElementById('confirm-modal-cancel');

        if (!overlay) return;

        okBtn.addEventListener('click', function () {
            hide();
            if (typeof _onConfirm === 'function') _onConfirm();
        });

        cancelBtn.addEventListener('click', function () {
            hide();
            if (typeof _onCancel === 'function') _onCancel();
        });

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                hide();
                if (typeof _onCancel === 'function') _onCancel();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('show')) {
                hide();
                if (typeof _onCancel === 'function') _onCancel();
            }
        });
    }

    function show(opts) {
        if (!overlay) { init(); }
        if (!overlay) return; // still not found — modal HTML missing

        titleEl.textContent   = opts.title   || 'Confirm';
        messageEl.textContent = opts.message || '';

        // Configure confirm button
        okBtn.textContent = opts.confirmText  || 'Confirm';
        okBtn.className   = 'btn ' + (opts.confirmClass || 'btn-danger');

        _onConfirm = opts.onConfirm || null;
        _onCancel  = opts.onCancel  || null;

        overlay.classList.add('show');
        // Focus cancel by default (safer for destructive actions)
        cancelBtn.focus();
    }

    function hide() {
        if (overlay) overlay.classList.remove('show');
        _onConfirm = null;
        _onCancel  = null;
    }

    document.addEventListener('DOMContentLoaded', init);

    return { show: show, hide: hide };
})();
