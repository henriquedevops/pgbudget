<?php
/**
 * What-If Projection
 * Lets the user temporarily adjust income/obligation amounts and see the
 * impact on the 12-month net cash flow — all computed client-side.
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

$months_ahead = 12;
$start_month  = date('Y-m-01');

try {
    $db = getDbConnection();

    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Income sources (active)
    $stmt = $db->prepare("
        SELECT uuid, name, amount, frequency, employer_name
        FROM api.income_sources
        WHERE ledger_uuid = ? AND is_active = true
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $income_sources = $stmt->fetchAll();

    // Payroll deductions
    $stmt = $db->prepare("
        SELECT uuid, name,
               COALESCE(fixed_amount, estimated_amount) AS amount,
               frequency, deduction_type
        FROM api.payroll_deductions
        WHERE ledger_uuid = ?
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $deductions = $stmt->fetchAll();

    // Obligations (active)
    $stmt = $db->prepare("
        SELECT uuid, name, is_fixed_amount,
               COALESCE(fixed_amount, estimated_amount) AS amount,
               frequency
        FROM api.obligations
        WHERE ledger_uuid = ? AND is_active = true
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $obligations = $stmt->fetchAll();

    // 12-month projection detail rows
    $stmt = $db->prepare("SELECT * FROM api.generate_cash_flow_projection(?, ?::date, ?)");
    $stmt->execute([$ledger_uuid, $start_month, $months_ahead]);
    $detail_rows = $stmt->fetchAll();

    // 12-month projection summary (baseline)
    $stmt = $db->prepare("SELECT * FROM api.get_projection_summary(?, ?::date, ?)");
    $stmt->execute([$ledger_uuid, $start_month, $months_ahead]);
    $summary_rows = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

// Build base amounts map {source_uuid: base_amount_cents} for JS
$base_amounts = [];
foreach ($income_sources as $s) {
    $base_amounts[$s['uuid']] = (int)$s['amount'];
}
foreach ($deductions as $d) {
    // deductions.amount is already bigint cents
    $base_amounts[$d['uuid']] = (int)$d['amount'];
}
foreach ($obligations as $o) {
    // obligations.amount is numeric(15,2) dollars → convert to cents
    $base_amounts[$o['uuid']] = (int)round((float)$o['amount'] * 100);
}

require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="/pgbudget/css/cash-flow-projection.css">

<div class="container">

    <div class="report-header">
        <div>
            <h1>What-If Scenario</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?> &mdash; 12-month cash flow impact</p>
        </div>
        <div class="report-actions">
            <button id="wif-reset-btn" class="btn btn-secondary">Reset All</button>
            <a href="cash-flow-projection.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Full Projection</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <p class="wif-intro">
        Adjust income and expense amounts below to see how changes would affect your 12-month cash flow.
        All calculations happen instantly in your browser — nothing is saved.
    </p>

    <?php if (empty($detail_rows)): ?>
    <div class="empty-state">
        <h3>No projection data found</h3>
        <p>Add income sources, bills, or loans to generate a projection first.</p>
        <div class="empty-state-links">
            <a href="../income-sources/?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Add Income</a>
            <a href="../obligations/?ledger=<?= $ledger_uuid ?>"     class="btn btn-secondary">Add Bills</a>
        </div>
    </div>
    <?php else: ?>

    <!-- Adjustable items -->
    <div class="wif-adjusters">

        <?php if (!empty($income_sources)): ?>
        <div class="wif-section wif-income">
            <h3>Income Sources</h3>
            <?php foreach ($income_sources as $src): ?>
            <div class="wif-item">
                <label class="wif-label" title="<?= htmlspecialchars($src['employer_name'] ?? '') ?>">
                    <?= htmlspecialchars($src['name']) ?>
                    <small class="wif-freq"><?= htmlspecialchars($src['frequency']) ?></small>
                </label>
                <div class="wif-input-wrap">
                    <span class="wif-curr">$</span>
                    <input type="number" class="wif-input" step="0.01" min="0"
                           data-uuid="<?= htmlspecialchars($src['uuid']) ?>"
                           data-type="income"
                           data-base="<?= (int)$src['amount'] ?>"
                           value="<?= number_format((int)$src['amount'] / 100, 2, '.', '') ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($deductions)): ?>
        <div class="wif-section wif-deduction">
            <h3>Payroll Deductions</h3>
            <?php foreach ($deductions as $ded): ?>
            <div class="wif-item">
                <label class="wif-label">
                    <?= htmlspecialchars($ded['name']) ?>
                    <small class="wif-freq"><?= htmlspecialchars($ded['frequency']) ?></small>
                </label>
                <div class="wif-input-wrap">
                    <span class="wif-curr">$</span>
                    <input type="number" class="wif-input" step="0.01" min="0"
                           data-uuid="<?= htmlspecialchars($ded['uuid']) ?>"
                           data-type="deduction"
                           data-base="<?= (int)$ded['amount'] ?>"
                           value="<?= number_format((int)$ded['amount'] / 100, 2, '.', '') ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($obligations)): ?>
        <div class="wif-section wif-obligation">
            <h3>Bills &amp; Obligations</h3>
            <?php foreach ($obligations as $ob): ?>
            <?php $base_cents = (int)round((float)$ob['amount'] * 100); ?>
            <div class="wif-item">
                <label class="wif-label">
                    <?= htmlspecialchars($ob['name']) ?>
                    <small class="wif-freq"><?= htmlspecialchars($ob['frequency']) ?></small>
                </label>
                <div class="wif-input-wrap">
                    <span class="wif-curr">$</span>
                    <input type="number" class="wif-input" step="0.01" min="0"
                           data-uuid="<?= htmlspecialchars($ob['uuid']) ?>"
                           data-type="obligation"
                           data-base="<?= $base_cents ?>"
                           value="<?= number_format($base_cents / 100, 2, '.', '') ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.wif-adjusters -->

    <!-- Comparison Table -->
    <div class="wif-table-wrap">
        <table class="wif-table" id="wif-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="num">Baseline Net</th>
                    <th class="num">Adjusted Net</th>
                    <th class="num">Difference</th>
                    <th class="num">Cum. Baseline</th>
                    <th class="num">Cum. Adjusted</th>
                </tr>
            </thead>
            <tbody id="wif-tbody">
                <!-- filled by JS -->
            </tbody>
            <tfoot id="wif-tfoot"></tfoot>
        </table>
    </div>

    <?php endif; ?>
</div><!-- /.container -->

<style>
.wif-intro {
    font-size: 0.9rem;
    color: #64748b;
    margin-bottom: 1.5rem;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
    gap: 1rem;
    flex-wrap: wrap;
}

.report-subtitle {
    color: #64748b;
    margin-top: 0.25rem;
    font-size: 0.9rem;
}

.report-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

/* Adjuster grid */
.wif-adjusters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.wif-section {
    background: white;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    border-top: 3px solid #e2e8f0;
}

.wif-income    { border-top-color: #4ade80; }
.wif-deduction { border-top-color: #f87171; }
.wif-obligation{ border-top-color: #fbbf24; }

.wif-section h3 {
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #475569;
    margin: 0 0 0.75rem;
}

.wif-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.6rem;
}

.wif-label {
    flex: 1;
    font-size: 0.85rem;
    color: #334155;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.wif-freq {
    font-size: 0.72rem;
    color: #94a3b8;
    font-weight: 400;
}

.wif-input-wrap {
    display: flex;
    align-items: center;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    overflow: hidden;
    width: 110px;
}

.wif-curr {
    padding: 0.3rem 0.4rem;
    background: #f8fafc;
    border-right: 1px solid #cbd5e0;
    font-size: 0.8rem;
    color: #64748b;
}

.wif-input {
    border: none;
    padding: 0.3rem 0.4rem;
    font-size: 0.82rem;
    width: 100%;
    font-variant-numeric: tabular-nums;
    outline: none;
}

.wif-input:focus {
    background: #eff6ff;
}

.wif-input.changed {
    background: #fefce8;
    font-weight: 600;
}

/* Comparison table */
.wif-table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    box-shadow: 0 1px 6px rgba(0,0,0,0.06);
}

.wif-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.wif-table th {
    background: #1e293b;
    color: #f1f5f9;
    padding: 0.6rem 0.75rem;
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
}

.wif-table th.num { text-align: right; }

.wif-table td {
    padding: 0.45rem 0.75rem;
    border-bottom: 1px solid #f0f4f8;
    font-variant-numeric: tabular-nums;
    font-family: 'Courier New', monospace;
    font-size: 0.8rem;
}

.wif-table td.num { text-align: right; }
.wif-table td.label { color: #334155; font-family: inherit; }

.wif-table tbody tr:hover { background: #f8fafc; }

.wif-table tfoot td {
    background: #0f172a;
    color: #94a3b8;
    font-weight: 700;
    font-size: 0.78rem;
    border-top: 2px solid #1e293b;
    padding: 0.6rem 0.75rem;
    font-family: 'Courier New', monospace;
    font-variant-numeric: tabular-nums;
}

.wif-table tfoot td.label {
    font-family: inherit;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.pos  { color: #166534; }
.neg  { color: #991b1b; }
.zero { color: #94a3b8; }
.delta-pos { color: #166534; font-weight: 600; }
.delta-neg { color: #991b1b; font-weight: 600; }

/* Override dark footer colours */
tfoot .pos  { color: #4ade80; }
tfoot .neg  { color: #f87171; }
tfoot .zero { color: #64748b; }
tfoot .delta-pos { color: #4ade80; }
tfoot .delta-neg { color: #f87171; }

@media (max-width: 768px) {
    .wif-adjusters { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
    'use strict';

    /* ---- Data passed from PHP ---------------------------------------- */
    var DETAIL   = <?= json_encode(array_map(function($r) {
        return [
            'source_uuid' => $r['source_uuid'],
            'source_type' => $r['source_type'],
            'month'       => $r['month'],
            'amount'      => (int)$r['amount'],
        ];
    }, $detail_rows)) ?>;

    var SUMMARY  = <?= json_encode(array_map(function($s) {
        return [
            'month'               => $s['month'],
            'net_monthly_balance' => (int)$s['net_monthly_balance'],
        ];
    }, $summary_rows)) ?>;

    var BASE_AMOUNTS = <?= json_encode($base_amounts) ?>;

    /* ---- Build month list from summary -------------------------------- */
    var months = SUMMARY.map(function(s) { return s.month; });
    var baselineNet = {};
    SUMMARY.forEach(function(s) {
        baselineNet[s.month] = s.net_monthly_balance;
    });

    /* ---- Build detail lookup: {uuid: {month: amount}} ---------------- */
    var detailByUuid = {};
    DETAIL.forEach(function(row) {
        if (!detailByUuid[row.source_uuid]) detailByUuid[row.source_uuid] = {};
        detailByUuid[row.source_uuid][row.month] = (detailByUuid[row.source_uuid][row.month] || 0) + row.amount;
    });

    /* ---- Format helpers ---------------------------------------------- */
    function fmt(cents) {
        if (cents === 0) return '—';
        var sign = cents < 0 ? '-' : '';
        return sign + '$' + (Math.abs(cents) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function cls(cents) {
        return cents > 0 ? 'pos' : (cents < 0 ? 'neg' : 'zero');
    }

    function deltaCls(cents) {
        return cents > 0 ? 'delta-pos' : (cents < 0 ? 'delta-neg' : 'zero');
    }

    function fmtMonth(m) {
        var d = new Date(m + 'T00:00:00');
        return d.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
    }

    /* ---- Read current overrides -------------------------------------- */
    function getOverrides() {
        var overrides = {};
        document.querySelectorAll('.wif-input').forEach(function(inp) {
            var uuid = inp.dataset.uuid;
            var valCents = Math.round(parseFloat(inp.value || '0') * 100);
            var baseCents = parseInt(inp.dataset.base, 10) || 0;
            if (valCents !== baseCents) {
                overrides[uuid] = { newCents: valCents, baseCents: baseCents };
            }
        });
        return overrides;
    }

    /* ---- Recompute adjusted net per month ---------------------------- */
    function computeAdjusted(overrides) {
        var adjusted = {};
        months.forEach(function(m) { adjusted[m] = baselineNet[m] || 0; });

        Object.keys(overrides).forEach(function(uuid) {
            var ov = overrides[uuid];
            var detail = detailByUuid[uuid] || {};
            var baseCents = ov.baseCents;
            var newCents  = ov.newCents;

            months.forEach(function(m) {
                var orig = detail[m] || 0;
                if (orig === 0) return; // source not active this month

                var delta;
                if (baseCents !== 0) {
                    // Scale proportionally
                    delta = Math.round(orig * newCents / baseCents) - orig;
                } else {
                    // Base was zero; treat override as absolute monthly addition
                    delta = newCents;
                }
                adjusted[m] += delta;
            });
        });

        return adjusted;
    }

    /* ---- Render table ------------------------------------------------ */
    function renderTable(adjusted) {
        var tbody = document.getElementById('wif-tbody');
        var tfoot = document.getElementById('wif-tfoot');
        if (!tbody || !tfoot) return;

        var cumBase = 0, cumAdj = 0;
        var rows = [];

        months.forEach(function(m, i) {
            var base = baselineNet[m] || 0;
            var adj  = adjusted[m] || 0;
            var diff = adj - base;
            cumBase += base;
            cumAdj  += adj;

            rows.push(
                '<tr>' +
                '<td class="label">' + fmtMonth(m) + '</td>' +
                '<td class="num ' + cls(base) + '">' + fmt(base) + '</td>' +
                '<td class="num ' + cls(adj) + '">' + fmt(adj)  + '</td>' +
                '<td class="num ' + deltaCls(diff) + '">' + fmt(diff) + '</td>' +
                '<td class="num ' + cls(cumBase) + '">' + fmt(cumBase) + '</td>' +
                '<td class="num ' + cls(cumAdj)  + '">' + fmt(cumAdj) + '</td>' +
                '</tr>'
            );
        });

        tbody.innerHTML = rows.join('');

        // Footer totals
        var totalBase = months.reduce(function(s,m) { return s + (baselineNet[m]||0); }, 0);
        var totalAdj  = months.reduce(function(s,m) { return s + (adjusted[m]||0); }, 0);
        var totalDiff = totalAdj - totalBase;

        tfoot.innerHTML =
            '<tr>' +
            '<td class="label">12-Month Total</td>' +
            '<td class="num ' + cls(totalBase) + '">' + fmt(totalBase) + '</td>' +
            '<td class="num ' + cls(totalAdj) + '">' + fmt(totalAdj) + '</td>' +
            '<td class="num ' + deltaCls(totalDiff) + '">' + fmt(totalDiff) + '</td>' +
            '<td class="num ' + cls(totalBase) + '">' + fmt(totalBase) + '</td>' +
            '<td class="num ' + cls(totalAdj) + '">' + fmt(totalAdj) + '</td>' +
            '</tr>';
    }

    /* ---- Recalculate (called on every input change) ------------------ */
    function recalc() {
        var overrides = getOverrides();
        var adjusted  = computeAdjusted(overrides);
        renderTable(adjusted);

        // Mark changed inputs
        document.querySelectorAll('.wif-input').forEach(function(inp) {
            var valCents  = Math.round(parseFloat(inp.value || '0') * 100);
            var baseCents = parseInt(inp.dataset.base, 10) || 0;
            inp.classList.toggle('changed', valCents !== baseCents);
        });
    }

    /* ---- Reset ------------------------------------------------------- */
    function resetAll() {
        document.querySelectorAll('.wif-input').forEach(function(inp) {
            var baseCents = parseInt(inp.dataset.base, 10) || 0;
            inp.value = (baseCents / 100).toFixed(2);
        });
        recalc();
    }

    /* ---- Init -------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        // Initial render with no overrides
        recalc();

        // Wire inputs
        document.querySelectorAll('.wif-input').forEach(function(inp) {
            inp.addEventListener('input', recalc);
        });

        // Reset button
        var resetBtn = document.getElementById('wif-reset-btn');
        if (resetBtn) resetBtn.addEventListener('click', resetAll);
    });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
