/**
 * Cover Overspending Modal
 * Phase 4.4: Credit Card Overspending Handling
 *
 * Handles the modal for covering overspending by moving budget from another category
 */

const CoverOverspendingModal = {
    // Modal state
    isOpen: false,
    overspentCategoryUuid: null,
    overspentCategoryName: null,
    overspentAmount: 0,
    ledgerUuid: null,
    categories: [],

    /**
     * Initialize the modal
     */
    init() {
        // Get ledger UUID from hidden data element
        const ledgerData = document.getElementById('ledger-accounts-data');
        if (ledgerData) {
            this.ledgerUuid = ledgerData.dataset.ledgerUuid;
        }

        // Set up form submission handler
        const form = document.getElementById('cover-overspending-form');
        if (form) {
            form.addEventListener('submit', (e) => this.handleSubmit(e));
        }

        // Set up source category change handler
        const sourceSelect = document.getElementById('cover-source-category');
        if (sourceSelect) {
            sourceSelect.addEventListener('change', () => this.updateVisualSummary());
        }

        // Set up amount input change handler
        const amountInput = document.getElementById('cover-amount');
        if (amountInput) {
            amountInput.addEventListener('input', () => this.updateVisualSummary());
        }

        // Close modal on backdrop click
        const modal = document.getElementById('cover-overspending-modal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.close();
                }
            });
        }

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
    },

    /**
     * Open the modal for a specific overspent category
     */
    open(categoryUuid, categoryName, overspentAmount) {
        this.overspentCategoryUuid = categoryUuid;
        this.overspentCategoryName = categoryName;
        this.overspentAmount = overspentAmount;
        this.isOpen = true;

        // Reset form
        this.resetForm();

        // Update modal content
        document.getElementById('cover-overspent-category-name').textContent = categoryName;
        document.getElementById('cover-overspent-amount').textContent = this.formatCurrency(overspentAmount);

        // Load categories with positive balances
        this.loadCategories();

        // Show modal
        const modal = document.getElementById('cover-overspending-modal');
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('show'), 10);
    },

    /**
     * Close the modal
     */
    close() {
        this.isOpen = false;
        const modal = document.getElementById('cover-overspending-modal');
        modal.classList.remove('show');
        setTimeout(() => {
            modal.style.display = 'none';
            this.resetForm();
        }, 300);
    },

    /**
     * Reset the form
     */
    resetForm() {
        const form = document.getElementById('cover-overspending-form');
        if (form) {
            form.reset();
        }

        // Hide messages
        this.hideMessage('cover-error');
        this.hideMessage('cover-success');

        // Hide visual summary
        document.getElementById('cover-visual-summary').style.display = 'none';
    },

    /**
     * Load categories with positive balances
     */
    async loadCategories() {
        try {
            // Get all categories from the budget status table
            const categoryRows = document.querySelectorAll('.category-row');
            const categories = [];

            categoryRows.forEach(row => {
                const categoryUuid = row.dataset.categoryUuid;
                const categoryName = row.querySelector('.category-name')?.textContent.trim();
                const balanceCell = row.querySelector('.category-balance');

                if (balanceCell && categoryUuid && categoryName) {
                    const balanceText = balanceCell.textContent.trim();
                    const balance = this.parseCurrency(balanceText);

                    // Only include categories with positive balance that aren't the overspent category
                    if (balance > 0 && categoryUuid !== this.overspentCategoryUuid) {
                        categories.push({
                            uuid: categoryUuid,
                            name: categoryName,
                            balance: balance
                        });
                    }
                }
            });

            this.categories = categories;
            this.populateCategoryDropdown();
        } catch (error) {
            console.error('Error loading categories:', error);
            this.showError('Failed to load categories. Please refresh the page.');
        }
    },

    /**
     * Populate the category dropdown
     */
    populateCategoryDropdown() {
        const select = document.getElementById('cover-source-category');
        if (!select) return;

        // Clear existing options except the first one
        select.innerHTML = '<option value="">Select a category...</option>';

        // Add categories
        this.categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.uuid;
            option.textContent = `${category.name} (${this.formatCurrency(category.balance)} available)`;
            option.dataset.balance = category.balance;
            option.dataset.name = category.name;
            select.appendChild(option);
        });
    },

    /**
     * Update the visual summary
     */
    updateVisualSummary() {
        const sourceSelect = document.getElementById('cover-source-category');
        const amountInput = document.getElementById('cover-amount');
        const visualSummary = document.getElementById('cover-visual-summary');

        if (!sourceSelect.value) {
            visualSummary.style.display = 'none';
            return;
        }

        const selectedOption = sourceSelect.options[sourceSelect.selectedIndex];
        const sourceName = selectedOption.dataset.name;

        // Determine amount to cover
        let amountToCover = this.overspentAmount;
        if (amountInput.value.trim()) {
            const parsed = this.parseCurrency(amountInput.value);
            if (parsed > 0) {
                amountToCover = parsed;
            }
        }

        // Update visual elements
        document.getElementById('cover-visual-from').textContent = sourceName;
        document.getElementById('cover-visual-to').textContent = this.overspentCategoryName;
        document.getElementById('cover-visual-amount').textContent = this.formatCurrency(amountToCover);

        // Show visual summary
        visualSummary.style.display = 'block';
    },

    /**
     * Handle form submission
     */
    async handleSubmit(e) {
        e.preventDefault();

        const sourceSelect = document.getElementById('cover-source-category');
        const amountInput = document.getElementById('cover-amount');
        const submitBtn = document.getElementById('cover-submit-btn');

        // Hide previous messages
        this.hideMessage('cover-error');
        this.hideMessage('cover-success');

        // Validate source category
        if (!sourceSelect.value) {
            this.showError('Please select a source category.');
            return;
        }

        const sourceCategoryUuid = sourceSelect.value;

        // Determine amount (null means cover full amount)
        let amount = null;
        if (amountInput.value.trim()) {
            amount = this.parseCurrency(amountInput.value);
            if (amount <= 0) {
                this.showError('Amount must be positive.');
                return;
            }
        }

        // Disable submit button
        submitBtn.disabled = true;
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Processing...';

        try {
            const response = await fetch('/api/cover-overspending.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    overspent_category_uuid: this.overspentCategoryUuid,
                    source_category_uuid: sourceCategoryUuid,
                    amount: amount
                })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to cover overspending');
            }

            // Success!
            this.showSuccess('Overspending covered successfully!');

            // Wait a moment then close modal and reload page
            setTimeout(() => {
                this.close();
                window.location.reload();
            }, 1500);

        } catch (error) {
            console.error('Error covering overspending:', error);
            this.showError(error.message || 'Failed to cover overspending. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    },

    /**
     * Show error message
     */
    showError(message) {
        this.showMessage('cover-error', message);
    },

    /**
     * Show success message
     */
    showSuccess(message) {
        this.showMessage('cover-success', message);
    },

    /**
     * Show a message
     */
    showMessage(elementId, message) {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = message;
            element.style.display = 'block';
        }
    },

    /**
     * Hide a message
     */
    hideMessage(elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            element.style.display = 'none';
        }
    },

    /**
     * Format currency
     */
    formatCurrency(cents) {
        const dollars = cents / 100;
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(dollars);
    },

    /**
     * Parse currency string to cents
     */
    parseCurrency(str) {
        // Remove currency symbols, commas, spaces
        const cleaned = str.replace(/[$,\s]/g, '');

        // Handle negative values
        const isNegative = cleaned.includes('-');
        const absolute = cleaned.replace('-', '');

        // Parse as float and convert to cents
        const dollars = parseFloat(absolute);
        if (isNaN(dollars)) return 0;

        const cents = Math.round(dollars * 100);
        return isNegative ? -cents : cents;
    }
};

// Global function to open modal (called from onclick in HTML)
function showCoverOverspendingModal(categoryUuid, categoryName, overspentAmount) {
    if (!categoryUuid && !categoryName && !overspentAmount) {
        // Called from warning banner - find first overspent category
        const overspentRow = document.querySelector('.category-row.overspent');
        if (!overspentRow) return;

        const coverBtn = overspentRow.querySelector('.cover-overspending-btn');
        if (!coverBtn) return;

        categoryUuid = coverBtn.dataset.categoryUuid;
        categoryName = coverBtn.dataset.categoryName;
        overspentAmount = parseInt(coverBtn.dataset.overspentAmount);
    }

    CoverOverspendingModal.open(categoryUuid, categoryName, overspentAmount);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => CoverOverspendingModal.init());
} else {
    CoverOverspendingModal.init();
}
