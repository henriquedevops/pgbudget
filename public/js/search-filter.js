/**
 * Search and Filter JavaScript
 * Provides client-side enhancements for search and filtering functionality
 */

// Filter preset management
const FilterPresets = {
    STORAGE_KEY: 'pgbudget_filter_presets',

    /**
     * Get all saved filter presets for a ledger
     */
    getPresets(ledgerUuid) {
        const allPresets = JSON.parse(localStorage.getItem(this.STORAGE_KEY) || '{}');
        return allPresets[ledgerUuid] || [];
    },

    /**
     * Save a new filter preset
     */
    savePreset(ledgerUuid, name, filters) {
        const allPresets = JSON.parse(localStorage.getItem(this.STORAGE_KEY) || '{}');
        if (!allPresets[ledgerUuid]) {
            allPresets[ledgerUuid] = [];
        }

        // Remove any existing preset with the same name
        allPresets[ledgerUuid] = allPresets[ledgerUuid].filter(p => p.name !== name);

        // Add the new preset
        allPresets[ledgerUuid].push({
            name: name,
            filters: filters,
            created: new Date().toISOString()
        });

        localStorage.setItem(this.STORAGE_KEY, JSON.stringify(allPresets));
        return true;
    },

    /**
     * Delete a filter preset
     */
    deletePreset(ledgerUuid, name) {
        const allPresets = JSON.parse(localStorage.getItem(this.STORAGE_KEY) || '{}');
        if (!allPresets[ledgerUuid]) {
            return false;
        }

        allPresets[ledgerUuid] = allPresets[ledgerUuid].filter(p => p.name !== name);
        localStorage.setItem(this.STORAGE_KEY, JSON.stringify(allPresets));
        return true;
    },

    /**
     * Get a specific preset by name
     */
    getPreset(ledgerUuid, name) {
        const presets = this.getPresets(ledgerUuid);
        return presets.find(p => p.name === name);
    }
};

// Filter form helper
const FilterFormHelper = {
    /**
     * Get current filters from the form
     */
    getCurrentFilters(form) {
        const formData = new FormData(form);
        const filters = {};

        for (const [key, value] of formData.entries()) {
            if (value && key !== 'ledger' && key !== 'page') {
                filters[key] = value;
            }
        }

        return filters;
    },

    /**
     * Apply filters to the form
     */
    applyFilters(form, filters) {
        for (const [key, value] of Object.entries(filters)) {
            const element = form.elements[key];
            if (element) {
                element.value = value;
            }
        }
    },

    /**
     * Clear all filters
     */
    clearAllFilters(form) {
        const ledgerInput = form.elements['ledger'];
        const ledgerValue = ledgerInput ? ledgerInput.value : '';

        form.reset();

        if (ledgerInput) {
            ledgerInput.value = ledgerValue;
        }
    },

    /**
     * Get filter summary (human-readable)
     */
    getFilterSummary(filters) {
        const parts = [];

        if (filters.search) {
            parts.push(`Search: "${filters.search}"`);
        }

        if (filters.type) {
            parts.push(`Type: ${filters.type === 'inflow' ? 'Income' : 'Expense'}`);
        }

        if (filters.account) {
            parts.push('Filtered by account');
        }

        if (filters.category) {
            parts.push('Filtered by category');
        }

        if (filters.date_from || filters.date_to) {
            if (filters.date_from && filters.date_to) {
                parts.push(`${filters.date_from} to ${filters.date_to}`);
            } else if (filters.date_from) {
                parts.push(`From ${filters.date_from}`);
            } else {
                parts.push(`Until ${filters.date_to}`);
            }
        }

        if (filters.amount_min || filters.amount_max) {
            if (filters.amount_min && filters.amount_max) {
                parts.push(`$${filters.amount_min} - $${filters.amount_max}`);
            } else if (filters.amount_min) {
                parts.push(`Min: $${filters.amount_min}`);
            } else {
                parts.push(`Max: $${filters.amount_max}`);
            }
        }

        return parts.length > 0 ? parts.join(' • ') : 'No active filters';
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const filterForm = document.querySelector('.filters-form');

    if (!filterForm) {
        return;
    }

    const ledgerInput = filterForm.elements['ledger'];
    const ledgerUuid = ledgerInput ? ledgerInput.value : null;

    if (!ledgerUuid) {
        return;
    }

    // Add "Save Filter Preset" button
    const filtersActions = document.querySelector('.filters-actions');
    if (filtersActions) {
        const savePresetBtn = document.createElement('button');
        savePresetBtn.type = 'button';
        savePresetBtn.className = 'btn btn-secondary';
        savePresetBtn.textContent = '💾 Save Preset';
        savePresetBtn.addEventListener('click', function() {
            showSavePresetModal(ledgerUuid, filterForm);
        });
        filtersActions.appendChild(savePresetBtn);

        // Add "Load Preset" dropdown if there are saved presets
        const presets = FilterPresets.getPresets(ledgerUuid);
        if (presets.length > 0) {
            const loadPresetSelect = document.createElement('select');
            loadPresetSelect.className = 'form-select';
            loadPresetSelect.style.marginLeft = '1rem';

            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Load Preset...';
            loadPresetSelect.appendChild(defaultOption);

            presets.forEach(preset => {
                const option = document.createElement('option');
                option.value = preset.name;
                option.textContent = preset.name;
                loadPresetSelect.appendChild(option);
            });

            loadPresetSelect.addEventListener('change', function() {
                if (this.value) {
                    const preset = FilterPresets.getPreset(ledgerUuid, this.value);
                    if (preset) {
                        FilterFormHelper.applyFilters(filterForm, preset.filters);
                        filterForm.submit();
                    }
                }
            });

            filtersActions.appendChild(loadPresetSelect);
        }
    }

    // Add filter summary display
    const currentFilters = FilterFormHelper.getCurrentFilters(filterForm);
    const hasActiveFilters = Object.keys(currentFilters).length > 0;

    if (hasActiveFilters) {
        const summary = FilterFormHelper.getFilterSummary(currentFilters);
        const summaryElement = document.createElement('div');
        summaryElement.className = 'filter-summary-banner';
        summaryElement.innerHTML = `
            <div class="filter-summary-content">
                <span class="filter-summary-label">Active Filters:</span>
                <span class="filter-summary-text">${summary}</span>
            </div>
        `;

        const filtersSection = document.querySelector('.filters-section');
        if (filtersSection) {
            filtersSection.insertAdjacentElement('afterend', summaryElement);
        }
    }

    // Keyboard shortcut: / to focus search box
    document.addEventListener('keydown', function(e) {
        if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            const searchInput = document.getElementById('search') || document.getElementById('global-search-input');
            if (searchInput && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        }
    });
});

/**
 * Show save preset modal
 */
function showSavePresetModal(ledgerUuid, form) {
    const currentFilters = FilterFormHelper.getCurrentFilters(form);

    if (Object.keys(currentFilters).length === 0) {
        alert('No filters are currently active. Apply some filters first, then save them as a preset.');
        return;
    }

    const presetName = prompt('Enter a name for this filter preset:', 'My Filter');

    if (presetName && presetName.trim()) {
        const success = FilterPresets.savePreset(ledgerUuid, presetName.trim(), currentFilters);

        if (success) {
            alert(`Filter preset "${presetName}" saved successfully!`);
            location.reload(); // Reload to show the new preset in the dropdown
        } else {
            alert('Failed to save filter preset. Please try again.');
        }
    }
}

/**
 * Quick date range selectors
 */
function setDateRange(range) {
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');

    if (!dateFrom || !dateTo) {
        return;
    }

    const today = new Date();
    let fromDate = new Date();

    switch (range) {
        case 'today':
            fromDate = new Date(today);
            break;
        case 'yesterday':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - 1);
            dateTo.value = fromDate.toISOString().split('T')[0];
            dateFrom.value = fromDate.toISOString().split('T')[0];
            return;
        case 'last7days':
            fromDate.setDate(today.getDate() - 7);
            break;
        case 'last30days':
            fromDate.setDate(today.getDate() - 30);
            break;
        case 'thismonth':
            fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
            break;
        case 'lastmonth':
            fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth(), 0);
            dateTo.value = lastDay.toISOString().split('T')[0];
            dateFrom.value = fromDate.toISOString().split('T')[0];
            return;
        case 'thisyear':
            fromDate = new Date(today.getFullYear(), 0, 1);
            break;
        default:
            return;
    }

    dateFrom.value = fromDate.toISOString().split('T')[0];
    dateTo.value = today.toISOString().split('T')[0];
}
