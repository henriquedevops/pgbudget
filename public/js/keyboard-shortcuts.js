/**
 * Keyboard Shortcuts Manager
 * Phase 6.4: Power user keyboard navigation and actions
 */

class KeyboardShortcuts {
    constructor() {
        this.shortcuts = {};
        this.sequenceBuffer = [];
        this.sequenceTimeout = null;
        this.sequenceDelay = 1000; // 1 second to complete sequence
        this.enabled = true;
        this.selectedIndex = -1;
        this.selectableItems = [];

        this.init();
    }

    init() {
        this.registerDefaultShortcuts();
        this.attachEventListeners();
        this.loadCustomShortcuts();
    }

    registerDefaultShortcuts() {
        // Navigation shortcuts (G + key)
        this.registerSequence(['g', 'b'], () => this.goToBudget(), 'Go to Budget');
        this.registerSequence(['g', 'a'], () => this.goToAccounts(), 'Go to Accounts');
        this.registerSequence(['g', 't'], () => this.goToTransactions(), 'Go to Transactions');
        this.registerSequence(['g', 'r'], () => this.goToReports(), 'Go to Reports');
        this.registerSequence(['g', 'h'], () => this.goToHome(), 'Go to Home');

        // Action shortcuts (single key)
        this.register('t', () => this.newTransaction(), 'New transaction', { ctrl: false });
        this.register('a', () => this.assignMoney(), 'Assign money', { ctrl: false });
        this.register('m', () => this.moveMoney(), 'Move money between categories', { ctrl: false });
        this.register('c', () => this.createCategory(), 'Create category', { ctrl: false });
        this.register('/', () => this.focusSearch(), 'Focus search box', { ctrl: false, shift: false });

        // Budget screen shortcuts
        this.register('ArrowUp', () => this.navigateUp(), 'Navigate up', { ctrl: false });
        this.register('ArrowDown', () => this.navigateDown(), 'Navigate down', { ctrl: false });
        this.register('Enter', () => this.editSelected(), 'Edit selected item', { ctrl: false });
        this.register('Escape', () => this.closeModal(), 'Close modal/cancel', { ctrl: false });

        // Transaction list shortcuts
        this.register('j', () => this.nextItem(), 'Next transaction', { ctrl: false });
        this.register('k', () => this.previousItem(), 'Previous transaction', { ctrl: false });
        this.register('e', () => this.editTransaction(), 'Edit selected transaction', { ctrl: false });
        this.register('d', () => this.deleteTransaction(), 'Delete selected transaction', { ctrl: false });
        this.register('x', () => this.toggleCleared(), 'Toggle cleared status', { ctrl: false });

        // Help
        this.register('?', () => this.showHelp(), 'Show keyboard shortcuts help', { ctrl: false, shift: true });

        // Ctrl+K for search (alternative to /)
        this.register('k', () => this.focusSearch(), 'Focus search box', { ctrl: true });
    }

    register(key, callback, description, options = {}) {
        const defaults = { ctrl: false, shift: false, alt: false };
        const opts = { ...defaults, ...options };

        const shortcutKey = this.getShortcutKey(key, opts);
        this.shortcuts[shortcutKey] = { callback, description, options: opts, key };
    }

    registerSequence(keys, callback, description) {
        const sequenceKey = keys.join('+');
        this.shortcuts[sequenceKey] = {
            callback,
            description,
            isSequence: true,
            keys
        };
    }

    getShortcutKey(key, options) {
        const parts = [];
        if (options.ctrl) parts.push('ctrl');
        if (options.alt) parts.push('alt');
        if (options.shift) parts.push('shift');
        parts.push(key.toLowerCase());
        return parts.join('+');
    }

    attachEventListeners() {
        document.addEventListener('keydown', (e) => this.handleKeyPress(e));

        // Track selectable items on page load and updates
        this.updateSelectableItems();

        // Re-scan when DOM changes
        const observer = new MutationObserver(() => this.updateSelectableItems());
        observer.observe(document.body, { childList: true, subtree: true });
    }

    handleKeyPress(e) {
        if (!this.enabled) return;

        // Don't trigger shortcuts when typing in inputs
        if (this.isTypingInInput(e.target)) {
            // Exception: Escape key should work in inputs to cancel
            if (e.key === 'Escape') {
                e.target.blur();
                this.closeModal();
            }
            return;
        }

        const key = e.key.toLowerCase();
        const shortcutKey = this.getShortcutKey(e.key, {
            ctrl: e.ctrlKey || e.metaKey,
            shift: e.shiftKey,
            alt: e.altKey
        });

        // Try sequence shortcuts first
        this.sequenceBuffer.push(key);
        clearTimeout(this.sequenceTimeout);

        this.sequenceTimeout = setTimeout(() => {
            this.sequenceBuffer = [];
        }, this.sequenceDelay);

        const sequenceKey = this.sequenceBuffer.join('+');
        const sequenceShortcut = this.shortcuts[sequenceKey];

        if (sequenceShortcut && sequenceShortcut.isSequence) {
            e.preventDefault();
            sequenceShortcut.callback();
            this.sequenceBuffer = [];
            clearTimeout(this.sequenceTimeout);
            this.showShortcutFeedback(sequenceShortcut.description);
            return;
        }

        // Try regular shortcuts
        const shortcut = this.shortcuts[shortcutKey];
        if (shortcut && !shortcut.isSequence) {
            e.preventDefault();
            shortcut.callback();
            this.showShortcutFeedback(shortcut.description);
        }
    }

    isTypingInInput(element) {
        const tagName = element.tagName.toLowerCase();
        const type = element.type ? element.type.toLowerCase() : '';

        return (
            tagName === 'input' && !['checkbox', 'radio', 'submit', 'button'].includes(type) ||
            tagName === 'textarea' ||
            tagName === 'select' ||
            element.isContentEditable
        );
    }

    updateSelectableItems() {
        // Update based on current page
        if (window.location.pathname.includes('/transactions/list')) {
            this.selectableItems = Array.from(document.querySelectorAll('.transaction-row'));
        } else if (window.location.pathname.includes('/budget/dashboard')) {
            this.selectableItems = Array.from(document.querySelectorAll('.category-item, .budget-category-row'));
        }
    }

    // Navigation actions
    goToBudget() {
        const ledger = this.getCurrentLedger();
        if (ledger) {
            window.location.href = `/pgbudget/budget/dashboard.php?ledger=${ledger}`;
        } else {
            window.location.href = '/pgbudget/';
        }
    }

    goToAccounts() {
        window.location.href = '/pgbudget/';
    }

    goToTransactions() {
        const ledger = this.getCurrentLedger();
        if (ledger) {
            window.location.href = `/pgbudget/transactions/list.php?ledger=${ledger}`;
        }
    }

    goToReports() {
        const ledger = this.getCurrentLedger();
        if (ledger) {
            window.location.href = `/pgbudget/reports/spending-by-category.php?ledger=${ledger}`;
        }
    }

    goToHome() {
        window.location.href = '/pgbudget/';
    }

    // Action shortcuts
    newTransaction() {
        // Open quick-add modal if available, otherwise redirect to add page
        if (typeof QuickAddModal !== 'undefined' && QuickAddModal.open) {
            QuickAddModal.open();
        } else {
            const ledger = this.getCurrentLedger();
            const url = ledger
                ? `/pgbudget/transactions/add.php?ledger=${ledger}`
                : '/pgbudget/transactions/add.php';
            window.location.href = url;
        }
    }

    assignMoney() {
        const ledger = this.getCurrentLedger();
        if (ledger) {
            window.location.href = `/pgbudget/transactions/assign.php?ledger=${ledger}`;
        }
    }

    moveMoney() {
        // Trigger move money modal if available
        const moveMoneyBtn = document.querySelector('[data-action="move-money"]');
        if (moveMoneyBtn) {
            moveMoneyBtn.click();
        } else {
            console.log('Move money feature not available on this page');
        }
    }

    createCategory() {
        const ledger = this.getCurrentLedger();
        if (ledger) {
            window.location.href = `/pgbudget/categories/create.php?ledger=${ledger}`;
        }
    }

    focusSearch() {
        const searchInput = document.querySelector('.nav-search-input, [name="q"], [type="search"], #search');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }

    // Budget screen navigation
    navigateUp() {
        if (this.selectableItems.length === 0) return;

        this.selectedIndex = Math.max(0, this.selectedIndex - 1);
        this.highlightSelected();
    }

    navigateDown() {
        if (this.selectableItems.length === 0) return;

        if (this.selectedIndex === -1) {
            this.selectedIndex = 0;
        } else {
            this.selectedIndex = Math.min(this.selectableItems.length - 1, this.selectedIndex + 1);
        }
        this.highlightSelected();
    }

    nextItem() {
        this.navigateDown();
    }

    previousItem() {
        this.navigateUp();
    }

    highlightSelected() {
        // Remove previous highlight
        this.selectableItems.forEach(item => item.classList.remove('keyboard-selected'));

        if (this.selectedIndex >= 0 && this.selectedIndex < this.selectableItems.length) {
            const selected = this.selectableItems[this.selectedIndex];
            selected.classList.add('keyboard-selected');
            selected.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    editSelected() {
        if (this.selectedIndex >= 0 && this.selectedIndex < this.selectableItems.length) {
            const selected = this.selectableItems[this.selectedIndex];
            const editBtn = selected.querySelector('.btn-edit, [data-action="edit"]');
            if (editBtn) {
                editBtn.click();
            }
        }
    }

    // Transaction actions
    editTransaction() {
        this.editSelected();
    }

    deleteTransaction() {
        if (this.selectedIndex >= 0 && this.selectedIndex < this.selectableItems.length) {
            const selected = this.selectableItems[this.selectedIndex];
            const deleteBtn = selected.querySelector('.btn-delete, [data-action="delete"]');
            if (deleteBtn) {
                if (confirm('Delete this transaction?')) {
                    deleteBtn.click();
                }
            }
        }
    }

    toggleCleared() {
        if (this.selectedIndex >= 0 && this.selectedIndex < this.selectableItems.length) {
            const selected = this.selectableItems[this.selectedIndex];
            const checkbox = selected.querySelector('[type="checkbox"]');
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

    closeModal() {
        // Close any open modals
        const modals = document.querySelectorAll('.modal, .bulk-modal, [role="dialog"]');
        modals.forEach(modal => {
            if (!modal.classList.contains('hidden')) {
                modal.classList.add('hidden');
            }
        });

        // Trigger close buttons
        const closeButtons = document.querySelectorAll('.modal-close, .close-btn, [data-dismiss="modal"]');
        closeButtons.forEach(btn => {
            if (btn.offsetParent !== null) { // Only visible buttons
                btn.click();
            }
        });
    }

    showHelp() {
        const helpModal = document.getElementById('keyboard-shortcuts-help');
        if (helpModal) {
            helpModal.classList.remove('hidden');
        } else {
            this.createHelpModal();
        }
    }

    createHelpModal() {
        const modal = document.createElement('div');
        modal.id = 'keyboard-shortcuts-help';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content keyboard-shortcuts-modal">
                <div class="modal-header">
                    <h2>⌨️ Keyboard Shortcuts</h2>
                    <button class="modal-close" onclick="document.getElementById('keyboard-shortcuts-help').classList.add('hidden')">&times;</button>
                </div>
                <div class="modal-body">
                    ${this.generateHelpContent()}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="document.getElementById('keyboard-shortcuts-help').classList.add('hidden')">Close</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.classList.remove('hidden');
    }

    generateHelpContent() {
        const categories = {
            'Navigation': ['g+b', 'g+a', 'g+t', 'g+r', 'g+h'],
            'Actions': ['t', 'a', 'm', 'c', '/', 'ctrl+k'],
            'Budget Screen': ['arrowup', 'arrowdown', 'enter', 'escape'],
            'Transaction List': ['j', 'k', 'e', 'd', 'x'],
            'Help': ['shift+?']
        };

        let html = '';
        for (const [category, keys] of Object.entries(categories)) {
            html += `<div class="shortcut-category">
                <h3>${category}</h3>
                <div class="shortcut-list">`;

            for (const key of keys) {
                const shortcut = this.shortcuts[key];
                if (shortcut) {
                    html += `
                        <div class="shortcut-item">
                            <kbd class="shortcut-key">${this.formatKey(key)}</kbd>
                            <span class="shortcut-desc">${shortcut.description}</span>
                        </div>
                    `;
                }
            }

            html += `</div></div>`;
        }

        return html;
    }

    formatKey(key) {
        return key
            .split('+')
            .map(k => {
                if (k === 'ctrl') return 'Ctrl';
                if (k === 'shift') return 'Shift';
                if (k === 'alt') return 'Alt';
                if (k === 'arrowup') return '↑';
                if (k === 'arrowdown') return '↓';
                if (k === 'arrowleft') return '←';
                if (k === 'arrowright') return '→';
                if (k === '/') return '/';
                return k.toUpperCase();
            })
            .join(' + ');
    }

    showShortcutFeedback(message) {
        // Show brief feedback when shortcut is used
        const existing = document.querySelector('.shortcut-feedback');
        if (existing) existing.remove();

        const feedback = document.createElement('div');
        feedback.className = 'shortcut-feedback';
        feedback.textContent = message;
        document.body.appendChild(feedback);

        setTimeout(() => feedback.classList.add('show'), 10);
        setTimeout(() => {
            feedback.classList.remove('show');
            setTimeout(() => feedback.remove(), 300);
        }, 1500);
    }

    getCurrentLedger() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('ledger');
    }

    loadCustomShortcuts() {
        // Load user-customized shortcuts from localStorage
        const custom = localStorage.getItem('pgbudget-shortcuts');
        if (custom) {
            try {
                const customShortcuts = JSON.parse(custom);
                // Merge with defaults (implementation for future settings page)
                console.log('Custom shortcuts loaded:', customShortcuts);
            } catch (e) {
                console.error('Failed to load custom shortcuts:', e);
            }
        }
    }

    enable() {
        this.enabled = true;
    }

    disable() {
        this.enabled = false;
    }

    toggle() {
        this.enabled = !this.enabled;
    }
}

// Initialize keyboard shortcuts when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.keyboardShortcuts = new KeyboardShortcuts();
    console.log('⌨️  Keyboard shortcuts enabled. Press ? for help.');
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = KeyboardShortcuts;
}
