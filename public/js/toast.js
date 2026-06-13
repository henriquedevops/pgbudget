/**
 * Toast — styled replacement for window.alert()
 *
 * Reuses the same .messages-container / .message markup and CSS that the
 * server-side flash-message system (error-handler.php) renders, so toasts
 * look identical to PHP messages and respect dark mode / mobile.
 *
 * Usage:
 *   Toast.success('Saved!');
 *   Toast.error('Something went wrong');
 *   Toast.show('Custom', 'warning', { duration: 0 }); // 0 = sticky
 *   toast('shorthand info message');                  // global alias → info
 */
window.Toast = (function () {
    var ICONS = {
        success: '✅', // ✅
        error:   '⚠️', // ⚠️
        warning: '⚠️',
        info:    'ℹ️', // ℹ️
    };

    function container() {
        var el = document.querySelector('.messages-container');
        if (!el) {
            el = document.createElement('div');
            el.className = 'messages-container';
            document.body.appendChild(el);
        }
        return el;
    }

    function dismiss(node) {
        if (!node || !node.parentElement) return;
        node.style.transition = 'opacity 0.3s, transform 0.3s';
        node.style.opacity = '0';
        node.style.transform = 'translateX(100%)';
        setTimeout(function () {
            if (node.parentElement) node.remove();
        }, 300);
    }

    function show(message, type, opts) {
        type = type || 'info';
        opts = opts || {};

        var node = document.createElement('div');
        node.className = 'message message-' + type;
        node.setAttribute('role', 'alert');

        var content = document.createElement('div');
        content.className = 'message-content';

        var icon = document.createElement('span');
        icon.className = 'message-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = ICONS[type] || ICONS.info;

        var text = document.createElement('span');
        text.className = 'message-text';
        text.style.whiteSpace = 'pre-line'; // preserve \n from old alert() strings
        text.textContent = message == null ? '' : String(message);

        content.appendChild(icon);
        content.appendChild(text);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'message-close';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '×'; // ×
        close.addEventListener('click', function () { dismiss(node); });

        node.appendChild(content);
        node.appendChild(close);
        container().appendChild(node);

        // Auto-dismiss after `duration` ms (default 5s). Errors stick a bit
        // longer; pass duration:0 to keep the toast until manually closed.
        var duration = opts.duration;
        if (duration === undefined) {
            duration = (type === 'error' || type === 'warning') ? 8000 : 5000;
        }
        if (duration > 0) {
            setTimeout(function () { dismiss(node); }, duration);
        }

        return node;
    }

    var FLASH_KEY = 'pgb_flash_toast';

    // Queue a toast to appear after the next navigation/reload — use this
    // instead of show() when the code redirects or reloads right after, since
    // a non-blocking toast would otherwise vanish with the page.
    function flash(message, type) {
        try {
            sessionStorage.setItem(FLASH_KEY, JSON.stringify({
                message: message == null ? '' : String(message),
                type: type || 'info',
            }));
        } catch (e) { /* sessionStorage unavailable — best effort */ }
    }

    // On load, replay any queued flash toast.
    document.addEventListener('DOMContentLoaded', function () {
        var raw;
        try { raw = sessionStorage.getItem(FLASH_KEY); } catch (e) { return; }
        if (!raw) return;
        try { sessionStorage.removeItem(FLASH_KEY); } catch (e) {}
        try {
            var data = JSON.parse(raw);
            show(data.message, data.type);
        } catch (e) {}
    });

    return {
        show:    show,
        success: function (m, o) { return show(m, 'success', o); },
        error:   function (m, o) { return show(m, 'error', o); },
        warning: function (m, o) { return show(m, 'warning', o); },
        info:    function (m, o) { return show(m, 'info', o); },
        flash:   flash,
        dismiss: dismiss,
    };
})();

// Global shorthand: toast('message') or toast('message', 'success')
window.toast = function (message, type, opts) {
    return window.Toast.show(message, type || 'info', opts);
};
