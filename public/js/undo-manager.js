/**
 * Undo Manager
 * Phase 6.6: Track and undo/redo user actions
 */

class UndoManager {
    constructor() {
        this.maxHistorySize = 10; // Last 10 actions in memory
        this.undoStack = [];
        this.redoStack = [];
        this.enabled = true;
        this.persistToServer = true; // Also save to database

        this.init();
    }

    init() {
        this.loadFromSessionStorage();
        this.attachKeyboardListeners();
        this.attachEventListeners();
        this.updateUI();
    }

    attachKeyboardListeners() {
        document.addEventListener('keydown', (e) => {
            // Ctrl+Z / Cmd+Z - Undo
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey) {
                if (!this.isTypingInInput(e.target)) {
                    e.preventDefault();
                    this.undo();
                }
            }

            // Ctrl+Shift+Z / Cmd+Shift+Z - Redo
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && e.shiftKey) {
                if (!this.isTypingInInput(e.target)) {
                    e.preventDefault();
                    this.redo();
                }
            }

            // Ctrl+Y / Cmd+Y - Redo (alternative)
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
                if (!this.isTypingInInput(e.target)) {
                    e.preventDefault();
                    this.redo();
                }
            }
        });
    }

    attachEventListeners() {
        // Listen for custom events to track actions
        document.addEventListener('action:transaction-created', (e) => {
            this.recordAction('create', 'transaction', e.detail);
        });

        document.addEventListener('action:transaction-updated', (e) => {
            this.recordAction('update', 'transaction', e.detail);
        });

        document.addEventListener('action:transaction-deleted', (e) => {
            this.recordAction('delete', 'transaction', e.detail);
        });

        document.addEventListener('action:money-assigned', (e) => {
            this.recordAction('assign', 'budget', e.detail);
        });

        document.addEventListener('action:money-moved', (e) => {
            this.recordAction('move', 'budget', e.detail);
        });
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

    recordAction(actionType, entityType, data) {
        if (!this.enabled) return;

        const action = {
            id: this.generateId(),
            type: actionType,
            entityType: entityType,
            timestamp: new Date().toISOString(),
            data: data,
            description: this.getActionDescription(actionType, entityType, data)
        };

        // Add to undo stack
        this.undoStack.push(action);

        // Limit stack size
        if (this.undoStack.length > this.maxHistorySize) {
            this.undoStack.shift();
        }

        // Clear redo stack (new action invalidates redo)
        this.redoStack = [];

        // Save to session storage
        this.saveToSessionStorage();

        // Persist to server if enabled
        if (this.persistToServer) {
            this.persistActionToServer(action);
        }

        // Update UI
        this.updateUI();

        // Show notification
        this.showNotification(`${action.description} (Ctrl+Z to undo)`);

        console.log('Action recorded:', action);
    }

    async undo() {
        if (this.undoStack.length === 0) {
            this.showNotification('Nothing to undo', 'info');
            return;
        }

        const action = this.undoStack.pop();

        try {
            await this.performUndo(action);

            // Move to redo stack
            this.redoStack.push(action);

            // Save state
            this.saveToSessionStorage();
            this.updateUI();

            this.showNotification(`Undid: ${action.description}`, 'success');

            // Reload page to reflect changes
            setTimeout(() => {
                window.location.reload();
            }, 500);
        } catch (error) {
            console.error('Undo failed:', error);
            this.showNotification(`Failed to undo: ${error.message}`, 'error');

            // Put action back on undo stack
            this.undoStack.push(action);
            this.updateUI();
        }
    }

    async redo() {
        if (this.redoStack.length === 0) {
            this.showNotification('Nothing to redo', 'info');
            return;
        }

        const action = this.redoStack.pop();

        try {
            await this.performRedo(action);

            // Move back to undo stack
            this.undoStack.push(action);

            // Save state
            this.saveToSessionStorage();
            this.updateUI();

            this.showNotification(`Redid: ${action.description}`, 'success');

            // Reload page to reflect changes
            setTimeout(() => {
                window.location.reload();
            }, 500);
        } catch (error) {
            console.error('Redo failed:', error);
            this.showNotification(`Failed to redo: ${error.message}`, 'error');

            // Put action back on redo stack
            this.redoStack.push(action);
            this.updateUI();
        }
    }

    async performUndo(action) {
        switch (action.type) {
            case 'create':
                return await this.undoCreate(action);
            case 'update':
                return await this.undoUpdate(action);
            case 'delete':
                return await this.undoDelete(action);
            case 'assign':
                return await this.undoAssign(action);
            case 'move':
                return await this.undoMove(action);
            default:
                throw new Error(`Unknown action type: ${action.type}`);
        }
    }

    async performRedo(action) {
        switch (action.type) {
            case 'create':
                return await this.redoCreate(action);
            case 'update':
                return await this.redoUpdate(action);
            case 'delete':
                return await this.redoDelete(action);
            case 'assign':
                return await this.redoAssign(action);
            case 'move':
                return await this.redoMove(action);
            default:
                throw new Error(`Unknown action type: ${action.type}`);
        }
    }

    async undoCreate(action) {
        // To undo a create, we delete the entity
        const { entityType, data } = action;

        if (entityType === 'transaction') {
            const response = await fetch(`/pgbudget/api/delete-transaction.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ transaction_uuid: data.uuid })
            });

            if (!response.ok) {
                throw new Error('Failed to delete transaction');
            }
        }
    }

    async undoUpdate(action) {
        // To undo an update, restore old data
        const { entityType, data } = action;

        if (entityType === 'transaction') {
            const response = await fetch(`/pgbudget/api/update-transaction.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_uuid: data.uuid,
                    ...data.oldData
                })
            });

            if (!response.ok) {
                throw new Error('Failed to restore transaction');
            }
        }
    }

    async undoDelete(action) {
        // To undo a delete, recreate the entity
        const { entityType, data } = action;

        if (entityType === 'transaction') {
            const response = await fetch(`/pgbudget/api/create-transaction.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data.oldData)
            });

            if (!response.ok) {
                throw new Error('Failed to recreate transaction');
            }
        }
    }

    async undoAssign(action) {
        // To undo assignment, reverse the assignment
        const { data } = action;

        // Implementation depends on your assign API
        console.log('Undo assign:', data);
    }

    async undoMove(action) {
        // To undo move, move money back
        const { data } = action;

        // Reverse the move
        if (data.fromCategory && data.toCategory && data.amount) {
            const response = await fetch(`/pgbudget/api/move-money.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ledger_uuid: data.ledger_uuid,
                    from_category_uuid: data.toCategory, // Reversed
                    to_category_uuid: data.fromCategory, // Reversed
                    amount: data.amount
                })
            });

            if (!response.ok) {
                throw new Error('Failed to reverse money move');
            }
        }
    }

    async redoCreate(action) {
        // Redo create = create again
        const { entityType, data } = action;

        if (entityType === 'transaction') {
            const response = await fetch(`/pgbudget/api/create-transaction.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                throw new Error('Failed to recreate transaction');
            }
        }
    }

    async redoUpdate(action) {
        // Redo update = apply new data
        const { entityType, data } = action;

        if (entityType === 'transaction') {
            const response = await fetch(`/pgbudget/api/update-transaction.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_uuid: data.uuid,
                    ...data.newData
                })
            });

            if (!response.ok) {
                throw new Error('Failed to reapply update');
            }
        }
    }

    async redoDelete(action) {
        // Redo delete = delete again
        return await this.undoCreate(action); // Same as undo create
    }

    async redoAssign(action) {
        // Redo assign = assign again
        console.log('Redo assign:', action.data);
    }

    async redoMove(action) {
        // Redo move = move money forward again
        const { data } = action;

        if (data.fromCategory && data.toCategory && data.amount) {
            const response = await fetch(`/pgbudget/api/move-money.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ledger_uuid: data.ledger_uuid,
                    from_category_uuid: data.fromCategory,
                    to_category_uuid: data.toCategory,
                    amount: data.amount
                })
            });

            if (!response.ok) {
                throw new Error('Failed to redo money move');
            }
        }
    }

    getActionDescription(actionType, entityType, data) {
        const actions = {
            'create': 'Created',
            'update': 'Updated',
            'delete': 'Deleted',
            'assign': 'Assigned money to',
            'move': 'Moved money'
        };

        const verb = actions[actionType] || actionType;
        const entity = entityType;
        const detail = data.description || data.name || '';

        return `${verb} ${entity}${detail ? ': ' + detail : ''}`;
    }

    async persistActionToServer(action) {
        try {
            // Note: This requires implementing the server endpoint
            await fetch('/pgbudget/api/record-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(action)
            });
        } catch (error) {
            console.error('Failed to persist action to server:', error);
        }
    }

    updateUI() {
        // Update undo button state
        const undoBtn = document.getElementById('undo-btn');
        const redoBtn = document.getElementById('redo-btn');

        if (undoBtn) {
            undoBtn.disabled = this.undoStack.length === 0;
            undoBtn.title = this.undoStack.length > 0
                ? `Undo: ${this.undoStack[this.undoStack.length - 1].description} (Ctrl+Z)`
                : 'Nothing to undo';
        }

        if (redoBtn) {
            redoBtn.disabled = this.redoStack.length === 0;
            redoBtn.title = this.redoStack.length > 0
                ? `Redo: ${this.redoStack[this.redoStack.length - 1].description} (Ctrl+Shift+Z)`
                : 'Nothing to redo';
        }

        // Update counter
        const undoCount = document.getElementById('undo-count');
        if (undoCount) {
            undoCount.textContent = this.undoStack.length;
            undoCount.style.display = this.undoStack.length > 0 ? 'inline' : 'none';
        }
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `undo-notification undo-notification-${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => notification.classList.add('show'), 10);
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    saveToSessionStorage() {
        try {
            sessionStorage.setItem('pgbudget-undo-stack', JSON.stringify(this.undoStack));
            sessionStorage.setItem('pgbudget-redo-stack', JSON.stringify(this.redoStack));
        } catch (error) {
            console.error('Failed to save undo state:', error);
        }
    }

    loadFromSessionStorage() {
        try {
            const undoData = sessionStorage.getItem('pgbudget-undo-stack');
            const redoData = sessionStorage.getItem('pgbudget-redo-stack');

            if (undoData) {
                this.undoStack = JSON.parse(undoData);
            }

            if (redoData) {
                this.redoStack = JSON.parse(redoData);
            }
        } catch (error) {
            console.error('Failed to load undo state:', error);
            this.undoStack = [];
            this.redoStack = [];
        }
    }

    clearHistory() {
        this.undoStack = [];
        this.redoStack = [];
        this.saveToSessionStorage();
        this.updateUI();
        this.showNotification('Undo history cleared', 'info');
    }

    getHistory() {
        return {
            undo: [...this.undoStack],
            redo: [...this.redoStack]
        };
    }

    generateId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    }

    enable() {
        this.enabled = true;
    }

    disable() {
        this.enabled = false;
    }
}

// Initialize undo manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.undoManager = new UndoManager();
    console.log('↩️  Undo manager initialized. Press Ctrl+Z to undo.');
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = UndoManager;
}
