/**
 * Cash Flow Projection — Client-side Interactivity
 *
 * Handles:
 *  1. Group collapse / expand (visual only — does NOT affect summary)
 *  2. Source-type filter toggles + summary recalculation
 *  3. CSV export
 */

(function () {
    'use strict';

    /* ------------------------------------------------------------------
       Utilities
    ------------------------------------------------------------------ */

    function fmtCents(cents) {
        if (cents === 0) return '—';
        const sym  = (window.CFP && CFP.currencySymbol) || '$';
        const sign = cents < 0 ? '-' : '';
        return sign + sym + (Math.abs(cents) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function cellClass(cents) {
        if (cents > 0) return 'cell-pos';
        if (cents < 0) return 'cell-neg';
        return 'cell-zero';
    }

    function updateCell(td, cents) {
        if (!td) return;
        td.textContent = fmtCents(cents);
        td.dataset.cents = String(cents);
        // Replace any existing cell-* class
        td.className = td.className.replace(/\bcell-\w+\b/g, '').trim() + ' ' + cellClass(cents);
    }

    /* ------------------------------------------------------------------
       1. Collapse / Expand groups
         Uses data-collapsed attribute so the filter logic can distinguish
         between "collapsed for display" and "hidden by filter".
    ------------------------------------------------------------------ */

    function initCollapse() {
        document.querySelectorAll('.cfp-collapse-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const group       = btn.dataset.group;
                const isCollapsed = btn.classList.contains('collapsed');
                const detailRows  = document.querySelectorAll(
                    '.cfp-detail[data-group="' + group + '"]'
                );

                detailRows.forEach(function (row) {
                    // Only show/hide if the row isn't filtered out
                    if (row.dataset.filtered !== 'true') {
                        row.style.display = isCollapsed ? '' : 'none';
                    }
                    row.dataset.collapsed = isCollapsed ? 'false' : 'true';
                });

                btn.classList.toggle('collapsed', !isCollapsed);
                btn.title = isCollapsed ? 'Collapse' : 'Expand';
            });
        });
    }

    /* ------------------------------------------------------------------
       2. Source-type filters + summary recalculation
         Uses data-filtered attribute so collapse logic doesn't conflict.
    ------------------------------------------------------------------ */

    function initFilters() {
        document.querySelectorAll('.cfp-type-filter').forEach(function (cb) {
            cb.addEventListener('change', function () {
                const type    = cb.dataset.type;
                const visible = cb.checked;

                // Show / hide ALL rows for this type (group header, detail, subtotal, spacer)
                document.querySelectorAll('[data-type="' + type + '"]').forEach(function (row) {
                    if (row.tagName === 'TR') {
                        // For detail rows, respect collapsed state when making visible
                        if (!visible) {
                            row.style.display = 'none';
                            if (row.classList.contains('cfp-detail')) {
                                row.dataset.filtered = 'true';
                            }
                        } else {
                            if (row.classList.contains('cfp-detail')) {
                                row.dataset.filtered = 'false';
                                // Only show if not collapsed
                                if (row.dataset.collapsed !== 'true') {
                                    row.style.display = '';
                                }
                            } else {
                                row.style.display = '';
                            }
                        }
                    }
                });

                // Update chip style
                const chip = document.querySelector('.cfp-filter-chip[data-type="' + type + '"]');
                if (chip) chip.classList.toggle('active', visible);

                recalculateSummary();
            });
        });
    }

    /**
     * Recalculate the summary footer based on currently UNfiltered rows.
     * Collapsed rows are still counted — collapse is visual only.
     */
    function recalculateSummary() {
        const numCols = (window.CFP && CFP.numCols) || 0;
        const netRow  = document.getElementById('cfp-net-row');
        const cumRow  = document.getElementById('cfp-cumulative-row');
        if (!netRow || !cumRow || numCols === 0) return;

        const netCells = netRow.querySelectorAll('.cfp-summary-amt');
        const cumCells = cumRow.querySelectorAll('.cfp-summary-amt');

        let cumulative = 0;

        for (let i = 0; i < numCols; i++) {
            let colSum = 0;

            // Sum all detail rows that are NOT filtered (collapsed rows ARE counted)
            document.querySelectorAll(
                '.cfp-detail .cfp-td-amt[data-col-idx="' + i + '"]'
            ).forEach(function (cell) {
                const row = cell.closest('tr');
                if (row && row.dataset.filtered !== 'true') {
                    colSum += parseInt(cell.dataset.cents, 10) || 0;
                }
            });

            updateCell(netCells[i], colSum);
            cumulative += colSum;
            updateCell(cumCells[i], cumulative);
        }

        // Update Total cell of net row (last cell, index numCols)
        const netTotal = Array.from(netCells)
            .slice(0, numCols)
            .reduce(function (s, c) { return s + (parseInt(c.dataset.cents, 10) || 0); }, 0);
        updateCell(netCells[numCols], netTotal);
    }

    /* ------------------------------------------------------------------
       3. CSV Export — exports all data (unfiltered) for completeness
    ------------------------------------------------------------------ */

    function initCsvExport() {
        const btn = document.getElementById('cfp-export-btn');
        if (!btn) return;
        btn.addEventListener('click', exportCsv);
    }

    function escapeCsv(val) {
        if (val === null || val === undefined) return '';
        const s = String(val);
        if (s.search(/[,"\n\r]/) >= 0) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function exportCsv() {
        const table = document.getElementById('cfp-table');
        if (!table) return;

        const csvRows = [];

        // Header row
        const headerRow = Array.from(table.querySelectorAll('thead th')).map(function (th) {
            return escapeCsv(th.textContent.trim());
        });
        csvRows.push(headerRow.join(','));

        // Body rows — skip spacers and group headers; export detail + subtotal
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.classList.contains('cfp-spacer') ||
                tr.classList.contains('cfp-group-hdr')) return;

            const tds = Array.from(tr.querySelectorAll('td'));
            if (!tds.length) return;

            const row = tds.map(function (td) {
                if (td.dataset.cents !== undefined) {
                    const c = parseInt(td.dataset.cents, 10);
                    return isNaN(c) ? '' : escapeCsv((c / 100).toFixed(2));
                }
                // Strip any collapse button glyphs from text
                return escapeCsv(td.textContent.trim().replace(/[▼►▶◀]/g, '').trim());
            });
            csvRows.push(row.join(','));
        });

        // Footer summary rows
        table.querySelectorAll('tfoot tr').forEach(function (tr) {
            const row = Array.from(tr.querySelectorAll('td')).map(function (td) {
                if (td.dataset.cents !== undefined) {
                    const c = parseInt(td.dataset.cents, 10);
                    return isNaN(c) ? '' : escapeCsv((c / 100).toFixed(2));
                }
                return escapeCsv(td.textContent.trim());
            });
            csvRows.push(row.join(','));
        });

        const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'cash-flow-projection.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /* ------------------------------------------------------------------
       Initialise on DOMContentLoaded
    ------------------------------------------------------------------ */

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('cfp-table')) return;
        initCollapse();
        initFilters();
        initCsvExport();
    });

})();
