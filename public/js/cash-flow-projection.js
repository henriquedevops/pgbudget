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
       2. Source-type + direction filters + summary recalculation
         Uses data-filtered attribute so collapse logic doesn't conflict.
         A detail row is hidden (filtered) when its type OR direction is
         deselected. Group headers/subtotals/spacers are hidden when all
         detail rows in that group are filtered.
    ------------------------------------------------------------------ */

    function getActiveTypes() {
        const active = new Set();
        document.querySelectorAll('.cfp-type-filter').forEach(function (cb) {
            if (cb.checked) active.add(cb.dataset.type);
        });
        return active;
    }

    function getActiveDirections() {
        const active = new Set();
        document.querySelectorAll('.cfp-dir-filter').forEach(function (cb) {
            if (cb.checked) active.add(cb.dataset.direction);
        });
        return active;
    }

    function getActiveAccounts() {
        const cbs = document.querySelectorAll('.cfp-account-filter');
        if (!cbs.length) return null;  // no account filter rendered — show all
        const active = new Set();
        cbs.forEach(function (cb) { if (cb.checked) active.add(cb.dataset.account); });
        return active;
    }

    function applyFilters() {
        const activeTypes    = getActiveTypes();
        const activeDirs     = getActiveDirections();
        const activeAccounts = getActiveAccounts();  // null = no filter

        // First pass: show/hide detail rows and track which groups have any visible rows
        const groupHasVisible = {};

        document.querySelectorAll('.cfp-detail').forEach(function (row) {
            const type      = row.dataset.type;
            const direction = row.dataset.direction;
            const account   = row.dataset.account || '';
            // Account filter: rows without an account are always shown
            const accountOk = !activeAccounts || account === '' || activeAccounts.has(account);
            const visible   = activeTypes.has(type) && activeDirs.has(direction) && accountOk;

            row.dataset.filtered = visible ? 'false' : 'true';
            if (visible) {
                if (row.dataset.collapsed !== 'true') row.style.display = '';
            } else {
                row.style.display = 'none';
            }

            if (visible) groupHasVisible[type] = true;
        });

        // Second pass: show/hide group headers/spacers; recalculate subtotals per group
        const numCols = (window.CFP && CFP.numCols) || 0;

        document.querySelectorAll('.cfp-group-hdr, .cfp-spacer').forEach(function (row) {
            row.style.display = groupHasVisible[row.dataset.type] ? '' : 'none';
        });

        document.querySelectorAll('.cfp-subtotal').forEach(function (subtotalRow) {
            const type = subtotalRow.dataset.type;
            if (!groupHasVisible[type]) {
                subtotalRow.style.display = 'none';
                return;
            }
            subtotalRow.style.display = '';

            // Sum visible detail rows for each column
            for (let i = 0; i < numCols; i++) {
                let colSum = 0;
                document.querySelectorAll(
                    '.cfp-detail[data-group="' + type + '"] .cfp-td-amt[data-col-idx="' + i + '"]'
                ).forEach(function (cell) {
                    const row = cell.closest('tr');
                    if (row && row.dataset.filtered !== 'true') {
                        colSum += parseInt(cell.dataset.cents, 10) || 0;
                    }
                });
                const subtotalCell = subtotalRow.querySelector(
                    '.cfp-subtotal-amt[data-col-idx="' + i + '"]'
                );
                if (subtotalCell) updateCell(subtotalCell, colSum);
            }

            // Update total cell
            let rowTotal = 0;
            subtotalRow.querySelectorAll('.cfp-subtotal-amt[data-col-idx]').forEach(function (c) {
                rowTotal += parseInt(c.dataset.cents, 10) || 0;
            });
            const totalCell = subtotalRow.querySelector('.cfp-subtotal-total');
            if (totalCell) updateCell(totalCell, rowTotal);
        });

        // Update direction chip styles
        document.querySelectorAll('.cfp-dir-filter').forEach(function (cb) {
            const chip = cb.closest('.cfp-filter-chip');
            if (chip) chip.classList.toggle('active', cb.checked);
        });

        recalculateSummary();
    }

    function initAccountDropdown() {
        const btn     = document.getElementById('cfp-acct-btn');
        const popover = document.getElementById('cfp-acct-popover');
        const allCb   = document.getElementById('cfp-acct-all');
        if (!btn || !popover) return;

        const itemCbs = Array.from(document.querySelectorAll('.cfp-account-filter'));

        function updateBtn() {
            const total    = itemCbs.length;
            const checked  = itemCbs.filter(cb => cb.checked).length;
            const filtered = checked < total;
            btn.classList.toggle('filtered', filtered);
            btn.firstChild.textContent = filtered
                ? '📂 ' + checked + ' of ' + total + ' accounts '
                : '📂 All accounts ';
        }

        function syncAllCheckbox() {
            const checked = itemCbs.filter(cb => cb.checked).length;
            allCb.checked       = checked === itemCbs.length;
            allCb.indeterminate = checked > 0 && checked < itemCbs.length;
        }

        // Toggle popover
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const open = !popover.hidden;
            popover.hidden = open;
            btn.classList.toggle('open', !open);
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!popover.hidden && !popover.contains(e.target) && e.target !== btn) {
                popover.hidden = true;
                btn.classList.remove('open');
            }
        });

        // "All accounts" master checkbox
        if (allCb) {
            allCb.addEventListener('change', function () {
                itemCbs.forEach(cb => { cb.checked = allCb.checked; });
                updateBtn();
                applyFilters();
            });
        }

        // Individual checkboxes
        itemCbs.forEach(function (cb) {
            cb.addEventListener('change', function () {
                syncAllCheckbox();
                updateBtn();
                applyFilters();
            });
        });
    }

    function initFilters() {
        // Type filters
        document.querySelectorAll('.cfp-type-filter').forEach(function (cb) {
            cb.addEventListener('change', function () {
                // Update chip style for type
                const chip = document.querySelector('.cfp-filter-chip[data-type="' + cb.dataset.type + '"]');
                if (chip) chip.classList.toggle('active', cb.checked);
                applyFilters();
            });
        });

        // Direction filters
        document.querySelectorAll('.cfp-dir-filter').forEach(function (cb) {
            cb.addEventListener('change', applyFilters);
        });

        // Account dropdown
        initAccountDropdown();
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
       3. Highlight a specific row by source UUID (used when linking
          from obligation/loan view pages via ?highlight=uuid)
    ------------------------------------------------------------------ */

    function initHighlight() {
        const uuid = window.CFP && CFP.highlightUuid;
        if (!uuid) return;
        const first = document.querySelector('.cfp-highlighted');
        if (first) {
            setTimeout(function () {
                first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 150);
        }
    }

    /* ------------------------------------------------------------------
       4. CSV Export — exports all data (unfiltered) for completeness
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
       5. Cell tooltip — projected/done status + account on hover
          Fires on individual amount cells so each column can report
          whether that specific month's value is actual, realized, or
          projected, alongside the row-level source account.
    ------------------------------------------------------------------ */

    function initRowTooltips() {
        const tooltip = document.getElementById('cfp-row-tooltip');
        const meta    = (window.CFP && CFP.sourceMeta) || {};
        if (!tooltip) return;

        let hideTimer = null;

        // Map data-type → default status label (used when sourceMeta has no entry)
        const TYPE_LABELS = {
            income:              'Projected income',
            deduction:           'Projected deduction',
            obligation:          'Projected bill',
            loan_amort:          'Projected loan payment',
            loan_interest:       'Projected loan interest',
            installment:         'Projected installment',
            past_installment:    'Past installment',
            event:               'Projected event',
            realized_event:      'Realized event',
            realized_occurrence: 'Realized occurrence',
            transaction:         'Actual transaction',
            recurring:           'Recurring transaction',
            reconciliation:      'Reconciliation',
        };

        // Map data-cell-status → override label + done flag
        const CELL_STATUS = {
            actual:    { label: 'Actual transaction', done: true  },
            realized:  { label: 'Realized',           done: true  },
            projected: { label: 'Projected',          done: false },
        };

        function escHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function showTooltip(cell, e) {
            const row        = cell.closest('tr');
            if (!row) return;

            const uuid       = cell.dataset.source || (row.dataset.sourceUuid);
            const txnUuid    = cell.dataset.txnUuid || '';
            const type       = row.dataset.type  || '';
            const cellStatus = cell.dataset.cellStatus || '';  // actual | realized | projected | ''
            const colLabel   = cell.dataset.colLabel   || '';
            const cents      = parseInt(cell.dataset.cents, 10) || 0;

            // For cells with a known transaction UUID (actual months in merged event rows),
            // prefer that entry so we show the real bank account instead of the event type.
            const lookupUuid = (txnUuid && meta[txnUuid]) ? txnUuid : uuid;

            // Source-level meta (account name etc.)
            const info    = meta[lookupUuid] || {};
            const account = info.account || '';
            const detail  = info.detail  || '';

            // Determine status label and done flag
            let statusLabel, isDone;
            if (cellStatus && CELL_STATUS[cellStatus]) {
                statusLabel = CELL_STATUS[cellStatus].label;
                isDone      = CELL_STATUS[cellStatus].done;
            } else if (info.status) {
                statusLabel = info.status;
                isDone      = /✓|done|past|actual|realized/i.test(info.status);
            } else {
                statusLabel = TYPE_LABELS[type] || type;
                isDone      = /done|past|actual|realized/i.test(statusLabel);
            }

            // Don't show tooltip for zero/empty cells with no status
            if (cents === 0 && !cellStatus) return;

            const icon = isDone ? '✓' : '~';
            const cls  = isDone ? 'cfp-tt-done' : 'cfp-tt-projected';

            let html = '<span class="cfp-tt-icon ' + cls + '">' + icon + '</span>'
                     + '<span class="cfp-tt-status">' + escHtml(statusLabel) + '</span>';

            if (colLabel) {
                html += '<span class="cfp-tt-sep">·</span>'
                      + '<span class="cfp-tt-col">' + escHtml(colLabel) + '</span>';
            }

            if (account) {
                html += '<span class="cfp-tt-sep">·</span>'
                      + '<span class="cfp-tt-account">📂 ' + escHtml(account) + '</span>';
            }

            if (detail && detail !== account) {
                html += '<span class="cfp-tt-detail">' + escHtml(detail) + '</span>';
            }

            tooltip.innerHTML = html;
            tooltip.classList.add('cfp-tt-visible');
            positionTooltip(e);
        }

        function positionTooltip(e) {
            const ttW = tooltip.offsetWidth || 240;
            const ttH = tooltip.offsetHeight || 48;
            let x = e.clientX + 6;
            let y = e.clientY + 6;
            if (x + ttW > window.innerWidth  - 4) x = e.clientX - ttW - 4;
            if (y + ttH > window.innerHeight - 4) y = e.clientY - ttH - 4;
            tooltip.style.left = x + 'px';
            tooltip.style.top  = y + 'px';
        }

        function hideTooltip() {
            tooltip.classList.remove('cfp-tt-visible');
        }

        // Attach to every amount cell inside a detail row
        document.querySelectorAll('.cfp-detail .cfp-td-amt').forEach(function (cell) {
            cell.addEventListener('mouseenter', function (e) {
                clearTimeout(hideTimer);
                showTooltip(cell, e);
            });
            cell.addEventListener('mousemove', positionTooltip);
            cell.addEventListener('mouseleave', function () {
                hideTimer = setTimeout(hideTooltip, 80);
            });
        });

        tooltip.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
        tooltip.addEventListener('mouseleave', function () { hideTimer = setTimeout(hideTooltip, 80); });
    }

    /* ------------------------------------------------------------------
       Initialise on DOMContentLoaded
    ------------------------------------------------------------------ */

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('cfp-table')) return;
        initCollapse();
        initFilters();
        initCsvExport();
        initHighlight();
        initRowTooltips();
        // Recompute balance from active chips so realized events
        // are included/excluded based on their initial toggle state.
        applyFilters();
    });

})();
