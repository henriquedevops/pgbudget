<?php
/**
 * Cash Flow Projection Report
 * Spreadsheet-like pivot table of projected income vs. expenses month-by-month
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

// Parse start_month (input type="month" returns YYYY-MM)
$start_month_raw = $_GET['start_month'] ?? '';
if (preg_match('/^\d{4}-\d{2}$/', $start_month_raw)) {
    $start_month = $start_month_raw . '-01';
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_month_raw)) {
    $start_month = $start_month_raw;
} else {
    $start_month = date('Y-m-01');
}

$months_ahead = (int)($_GET['months'] ?? 24);
$months_ahead = max(1, min(120, $months_ahead));

$view_mode = $_GET['view'] ?? 'monthly';
if (!in_array($view_mode, ['monthly', 'quarterly', 'annual'])) {
    $view_mode = 'monthly';
}

$highlight_uuid  = $_GET['highlight'] ?? '';
$today_month     = date('Y-m-01');
$current_month   = date('Y-m-01');

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

    // Fetch all projection detail rows
    $stmt = $db->prepare("SELECT * FROM api.generate_cash_flow_projection(?, ?::date, ?)");
    $stmt->execute([$ledger_uuid, $start_month, $months_ahead]);
    $detail_rows = $stmt->fetchAll();

    // Fetch summary (net + cumulative per month)
    $stmt = $db->prepare("SELECT * FROM api.get_projection_summary(?, ?::date, ?)");
    $stmt->execute([$ledger_uuid, $start_month, $months_ahead]);
    $summary_rows = $stmt->fetchAll();

    // Fetch realized one-time events (displayed for reference, not counted in balance)
    $end_month_realized = (new DateTime($start_month))->modify("+{$months_ahead} months")->format('Y-m-d');
    $stmt = $db->prepare("
        SELECT uuid, name, event_type, direction, amount, event_date
        FROM api.projected_events
        WHERE ledger_uuid = ?
          AND is_realized = true
          AND frequency = 'one_time'
          AND event_date >= ?::date
          AND event_date < ?::date
        ORDER BY event_date, name
    ");
    $stmt->execute([$ledger_uuid, $start_month, $end_month_realized]);
    $realized_events = $stmt->fetchAll();

    // Fetch processed installments (displayed for reference, not counted in balance)
    $stmt = $db->prepare("
        SELECT plan_uuid, description, month, amount
        FROM api.past_installments
        WHERE ledger_uuid = ?
          AND month >= ?::date
          AND month < ?::date
        ORDER BY month, description
    ");
    $stmt->execute([$ledger_uuid, $start_month, $end_month_realized]);
    $past_installments = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

// -------------------------------------------------------------------
// Pivot: build rows keyed by "type:uuid", collect unique months
// -------------------------------------------------------------------
$pivot    = [];
$all_months = [];

foreach ($detail_rows as $row) {
    $key = $row['source_type'] . ':' . $row['source_uuid'];
    if (!isset($pivot[$key])) {
        $pivot[$key] = [
            'source_type' => $row['source_type'],
            'source_uuid' => $row['source_uuid'],
            'category'    => $row['category'],
            'subcategory' => $row['subcategory'],
            'description' => $row['description'],
            'amounts'     => [],
        ];
    }
    $m = $row['month'];
    $pivot[$key]['amounts'][$m] = ($pivot[$key]['amounts'][$m] ?? 0) + (int)$row['amount'];
    if (!in_array($m, $all_months)) {
        $all_months[] = $m;
    }
}

// Merge realized events into pivot (reference only — not counted in net balance)
foreach ($realized_events as $e) {
    $month         = (new DateTime($e['event_date']))->format('Y-m-01');
    $amount_signed = $e['direction'] === 'inflow' ? (int)$e['amount'] : -(int)$e['amount'];
    $key           = 'realized_event:' . $e['uuid'];
    if (!isset($pivot[$key])) {
        $pivot[$key] = [
            'source_type' => 'realized_event',
            'source_uuid' => $e['uuid'],
            'category'    => 'realized',
            'subcategory' => $e['event_type'],
            'description' => $e['name'],
            'amounts'     => [],
        ];
    }
    $pivot[$key]['amounts'][$month] = ($pivot[$key]['amounts'][$month] ?? 0) + $amount_signed;
    if (!in_array($month, $all_months)) {
        $all_months[] = $month;
    }
}

// Merge processed installments into pivot (reference only — not counted in net balance)
foreach ($past_installments as $pi) {
    $key = 'past_installment:' . $pi['plan_uuid'];
    if (!isset($pivot[$key])) {
        $pivot[$key] = [
            'source_type' => 'past_installment',
            'source_uuid' => $pi['plan_uuid'],
            'category'    => 'installment',
            'subcategory' => 'past',
            'description' => $pi['description'],
            'amounts'     => [],
        ];
    }
    $m = $pi['month'];
    $pivot[$key]['amounts'][$m] = ($pivot[$key]['amounts'][$m] ?? 0) + (int)$pi['amount'];
    if (!in_array($m, $all_months)) {
        $all_months[] = $m;
    }
}

sort($all_months);

// Summary keyed by month
$summary_by_month = [];
foreach ($summary_rows as $s) {
    $summary_by_month[$s['month']] = $s;
}

// -------------------------------------------------------------------
// Build display columns (monthly / quarterly / annual)
// -------------------------------------------------------------------
function buildColumns(array $months, string $view): array {
    if ($view === 'monthly') {
        return array_map(fn($m) => [
            'key'    => $m,
            'label'  => (new DateTime($m))->format('M y'),
            'months' => [$m],
        ], $months);
    }
    $groups = [];
    foreach ($months as $m) {
        $dt = new DateTime($m);
        if ($view === 'quarterly') {
            $q   = 'Q' . (int)ceil((int)$dt->format('n') / 3);
            $key = $q . ' ' . $dt->format('Y');
        } else {
            $key = $dt->format('Y');
        }
        if (!isset($groups[$key])) {
            $groups[$key] = ['key' => $key, 'label' => $key, 'months' => []];
        }
        $groups[$key]['months'][] = $m;
    }
    return array_values($groups);
}

$columns = buildColumns($all_months, $view_mode);

// -------------------------------------------------------------------
// Aggregate amounts per row per column
// -------------------------------------------------------------------
function aggregateAmounts(array $row_amounts, array $columns): array {
    $result = [];
    foreach ($columns as $col) {
        $sum = 0;
        foreach ($col['months'] as $m) {
            $sum += $row_amounts[$m] ?? 0;
        }
        $result[$col['key']] = $sum;
    }
    return $result;
}

// Group rows by source_type, compute per-column amounts + row total
$group_order = ['income', 'deduction', 'obligation', 'loan_amort', 'loan_interest', 'installment', 'past_installment', 'recurring', 'event', 'realized_occurrence', 'cc_payment', 'transaction', 'realized_event'];
$group_labels = [
    'income'              => 'Income',
    'deduction'           => 'Payroll Deductions',
    'obligation'          => 'Bills & Obligations',
    'loan_amort'          => 'Loan Amortization',
    'loan_interest'       => 'Loan Interest',
    'installment'         => 'Installments',
    'past_installment'    => 'Past Installments',
    'recurring'           => 'Recurring Transactions',
    'event'               => 'Projected Events',
    'realized_occurrence' => 'Realized Occurrences',
    'cc_payment'          => 'Credit Card Payments',
    'transaction'         => 'Actual Transactions',
    'realized_event'      => 'Realized Events',
];

$groups = [];
foreach ($pivot as $row) {
    $type = $row['source_type'];
    $row['col_amounts'] = aggregateAmounts($row['amounts'], $columns);
    $row['row_total']   = array_sum($row['col_amounts']);
    $groups[$type][]    = $row;
}

// Sort each group by description
foreach ($groups as $type => &$rows) {
    usort($rows, fn($a, $b) => strcmp($a['description'], $b['description']));
}
unset($rows);

// Compute group subtotals per column
$group_subtotals = [];
foreach ($groups as $type => $rows) {
    $subs = [];
    foreach ($columns as $col) {
        $subs[$col['key']] = array_sum(array_column(
            array_map(fn($r) => $r['col_amounts'], $rows), $col['key']
        ));
    }
    $subs['__total']        = array_sum($subs);
    $group_subtotals[$type] = $subs;
}

// Summary per column (aggregate monthly net_monthly_balance)
$col_net = [];
foreach ($columns as $col) {
    $sum = 0;
    foreach ($col['months'] as $m) {
        $sum += (int)($summary_by_month[$m]['net_monthly_balance'] ?? 0);
    }
    $col_net[$col['key']] = $sum;
}

// Cumulative per column
$cumulative    = 0;
$col_cumulative = [];
foreach ($columns as $col) {
    $cumulative            += $col_net[$col['key']];
    $col_cumulative[$col['key']] = $cumulative;
}

// Overdue: sum of unrealized projected event/recurring amounts from months before today
// (informational only — past amounts already counted in cumulative)
$overdue_cents = 0;
foreach ($pivot as $row) {
    if (!in_array($row['source_type'], ['event', 'recurring'])) continue;
    foreach ($row['amounts'] as $month => $amount) {
        if ($month < $today_month) {
            $overdue_cents += $amount;
        }
    }
}

// -------------------------------------------------------------------
// Helper functions
// -------------------------------------------------------------------
function fmtCents(int $cents): string {
    if ($cents === 0) return '—';
    $sign = $cents < 0 ? '-' : '';
    return $sign . '$' . number_format(abs($cents) / 100, 2);
}

function cellClass(int $cents): string {
    if ($cents > 0) return 'cell-pos';
    if ($cents < 0) return 'cell-neg';
    return 'cell-zero';
}

require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="/pgbudget/css/cash-flow-projection.css">

<div class="container cfp-container">

    <div class="report-header">
        <div>
            <h1>Cash Flow Projection</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <button id="cfp-export-btn" class="btn btn-secondary">Export CSV</button>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <!-- Controls -->
    <div class="filter-card cfp-controls">
        <form method="GET" class="cfp-controls-form">
            <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">
            <div class="cfp-controls-row">
                <div class="form-group">
                    <label>Start Month</label>
                    <input type="month" name="start_month"
                           value="<?= (new DateTime($start_month))->format('Y-m') ?>"
                           class="form-input">
                </div>
                <div class="form-group">
                    <label>Horizon</label>
                    <select name="months" class="form-input">
                        <?php foreach ([12 => '1 Year', 24 => '2 Years', 36 => '3 Years', 60 => '5 Years', 120 => '10 Years'] as $m => $lbl): ?>
                            <option value="<?= $m ?>" <?= $months_ahead === $m ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>View</label>
                    <select name="view" class="form-input">
                        <option value="monthly"   <?= $view_mode === 'monthly'   ? 'selected' : '' ?>>Monthly</option>
                        <option value="quarterly" <?= $view_mode === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                        <option value="annual"    <?= $view_mode === 'annual'    ? 'selected' : '' ?>>Annual</option>
                    </select>
                </div>
                <div class="form-group form-group-btn">
                    <button type="submit" class="btn btn-primary">Refresh</button>
                </div>
            </div>
        </form>

        <!-- Source type filter toggles -->
        <?php if (!empty($groups)): ?>
        <div class="cfp-filter-bar">
            <span class="cfp-filter-label">Show:</span>
            <?php foreach ($group_order as $type): if (!isset($groups[$type])) continue; ?>
                <label class="cfp-filter-chip active" data-type="<?= $type ?>">
                    <input type="checkbox" class="cfp-type-filter" data-type="<?= $type ?>" checked>
                    <?= htmlspecialchars($group_labels[$type]) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($detail_rows)): ?>
        <div class="empty-state">
            <h3>No projection data found</h3>
            <p>Add income sources, bills, loans, or projected events to generate a cash flow projection.</p>
            <div class="empty-state-links">
                <a href="../income-sources/?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Add Income</a>
                <a href="../obligations/?ledger=<?= $ledger_uuid ?>"     class="btn btn-secondary">Add Bills</a>
                <a href="../loans/?ledger=<?= $ledger_uuid ?>"           class="btn btn-secondary">Add Loans</a>
                <a href="../projected-events/?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Add Events</a>
            </div>
        </div>
    <?php else: ?>

    <!-- Projection Table -->
    <div class="cfp-table-wrapper" id="cfp-table-wrapper">
        <table class="cfp-table" id="cfp-table">
            <thead>
                <tr>
                    <th class="cfp-th cfp-th-type sticky-1">Type</th>
                    <th class="cfp-th cfp-th-desc sticky-2">Description</th>
                    <?php foreach ($columns as $i => $col):
                        $col_is_past    = $col['key'] < $today_month;
                        $col_is_current = $col['key'] === $today_month;
                        $col_cls = $col_is_current ? ' cfp-th-current' : ($col_is_past ? ' cfp-th-past' : '');
                    ?>
                        <th class="cfp-th cfp-th-month<?= $col_cls ?>" data-col-idx="<?= $i ?>"><?= htmlspecialchars($col['label']) ?></th>
                    <?php endforeach; ?>
                    <th class="cfp-th cfp-th-total">Total</th>
                </tr>
            </thead>
            <tbody id="cfp-tbody">

            <?php foreach ($group_order as $type):
                if (!isset($groups[$type])) continue;
                $rows    = $groups[$type];
                $subs    = $group_subtotals[$type];
                $glabel  = $group_labels[$type];
            ?>

                <?php $is_realized_group = in_array($type, ['realized_event', 'realized_occurrence', 'past_installment']); ?>
                <!-- GROUP HEADER -->
                <tr class="cfp-group-hdr<?= $is_realized_group ? ' cfp-realized-group-hdr' : '' ?>" data-type="<?= $type ?>">
                    <td colspan="<?= 2 + count($columns) + 1 ?>" class="cfp-group-hdr-cell">
                        <button class="cfp-collapse-btn" data-group="<?= $type ?>" title="Collapse">&#9660;</button>
                        <span class="cfp-group-name"><?= htmlspecialchars($glabel) ?></span>
                        <span class="cfp-group-count"><?= count($rows) ?> item<?= count($rows) !== 1 ? 's' : '' ?></span>
                        <?php if ($type === 'realized_event'): ?>
                            <span class="cfp-realized-note">historical — not counted in balance</span>
                        <?php elseif ($type === 'realized_occurrence'): ?>
                            <span class="cfp-realized-note">counted in balance at actual date</span>
                        <?php elseif ($type === 'past_installment'): ?>
                            <span class="cfp-realized-note">historical — not counted in balance</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- DETAIL ROWS -->
                <?php foreach ($rows as $row):
                    $is_highlighted = ($highlight_uuid !== '' && $row['source_uuid'] === $highlight_uuid);
                ?>
                <tr class="cfp-row cfp-detail<?= $is_realized_group ? ' cfp-realized-row' : '' ?><?= $is_highlighted ? ' cfp-highlighted' : '' ?>"
                    data-type="<?= $type ?>"
                    data-group="<?= $type ?>"
                    data-source-uuid="<?= htmlspecialchars($row['source_uuid']) ?>">
                    <td class="cfp-td cfp-td-type sticky-1">
                        <?php if ($type === 'realized_event'): ?>
                            <span class="cfp-type-badge cfp-badge-realized_event">Realized</span>
                        <?php elseif ($type === 'realized_occurrence'): ?>
                            <span class="cfp-type-badge cfp-badge-realized_occurrence">Realized ↺</span>
                        <?php elseif ($type === 'past_installment'): ?>
                            <span class="cfp-type-badge cfp-badge-past_installment">Past Inst.</span>
                        <?php elseif ($type === 'transaction'): ?>
                            <span class="cfp-type-badge cfp-badge-transaction">Actual</span>
                        <?php else: ?>
                            <span class="cfp-type-badge cfp-badge-<?= $type ?>"><?= ucfirst(str_replace(['loan_', 'cc_', '_'], ['Ln ', 'CC ', ' '], $type)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="cfp-td cfp-td-desc sticky-2" title="<?= htmlspecialchars($row['subcategory'] . ' · ' . $row['category']) ?>">
                        <?= htmlspecialchars($row['description']) ?>
                    </td>
                    <?php foreach ($columns as $i => $col):
                        $val = (int)($row['col_amounts'][$col['key']] ?? 0);
                        $cell_cls = $col['key'] < $today_month ? ' cfp-past-cell' : ($col['key'] === $today_month ? ' cfp-current-cell' : '');
                    ?>
                        <td class="cfp-td cfp-td-amt <?= cellClass($val) . $cell_cls ?>"
                            data-col-idx="<?= $i ?>"
                            data-cents="<?= $val ?>"
                            data-source="<?= htmlspecialchars($row['source_uuid']) ?>">
                            <?= fmtCents($val) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-row-total <?= cellClass((int)$row['row_total']) ?>">
                        <?= fmtCents((int)$row['row_total']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- GROUP SUBTOTAL -->
                <tr class="cfp-row cfp-subtotal" data-type="<?= $type ?>" data-group="<?= $type ?>">
                    <td class="cfp-td cfp-td-sublabel sticky-1" colspan="2">
                        <?= htmlspecialchars($glabel) ?> Subtotal
                    </td>
                    <?php foreach ($columns as $col):
                        $val = (int)($subs[$col['key']] ?? 0);
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-subtotal-amt <?= cellClass($val) ?>">
                            <?= fmtCents($val) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-subtotal-amt <?= cellClass((int)($subs['__total'] ?? 0)) ?>">
                        <?= fmtCents((int)($subs['__total'] ?? 0)) ?>
                    </td>
                </tr>

                <!-- SPACER -->
                <tr class="cfp-spacer" data-type="<?= $type ?>">
                    <td colspan="<?= 2 + count($columns) + 1 ?>"></td>
                </tr>

            <?php endforeach; ?>
            </tbody>

            <!-- SUMMARY FOOTER -->
            <tfoot>
                <?php if ($overdue_cents !== 0): ?>
                <tr class="cfp-summary cfp-overdue-row">
                    <td class="cfp-td cfp-td-summary-lbl sticky-1" colspan="2">
                        Overdue from prev. months
                        <span class="cfp-realized-note">informational — already counted in past balances</span>
                    </td>
                    <?php foreach ($columns as $col):
                        $contains_today = in_array($today_month, $col['months']);
                        $val = $contains_today ? $overdue_cents : 0;
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-summary-amt <?= $val !== 0 ? cellClass($val) : '' ?>">
                            <?= $val !== 0 ? fmtCents($val) : '—' ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-summary-amt">—</td>
                </tr>
                <?php endif; ?>
                <tr class="cfp-summary cfp-net-row" id="cfp-net-row">
                    <td class="cfp-td cfp-td-summary-lbl sticky-1" colspan="2">Net Monthly Balance</td>
                    <?php foreach ($columns as $i => $col):
                        $val = (int)($col_net[$col['key']] ?? 0);
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-summary-amt <?= cellClass($val) ?>"
                            data-col-idx="<?= $i ?>"
                            data-cents="<?= $val ?>">
                            <?= fmtCents($val) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-summary-amt <?= cellClass(array_sum($col_net)) ?>">
                        <?= fmtCents(array_sum($col_net)) ?>
                    </td>
                </tr>
                <tr class="cfp-summary cfp-cumulative-row" id="cfp-cumulative-row">
                    <td class="cfp-td cfp-td-summary-lbl sticky-1" colspan="2">Cumulative Balance</td>
                    <?php foreach ($columns as $i => $col):
                        $val = (int)($col_cumulative[$col['key']] ?? 0);
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-summary-amt <?= cellClass($val) ?>"
                            data-col-idx="<?= $i ?>"
                            data-cents="<?= $val ?>">
                            <?= fmtCents($val) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-summary-amt cell-zero">—</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="cfp-legend">
        Amounts in USD &nbsp;|&nbsp;
        <span class="cell-pos">Green = inflow</span> &nbsp;
        <span class="cell-neg">Red = outflow</span> &nbsp;|&nbsp;
        <?= count($detail_rows) ?> line items across <?= count($columns) ?> <?= $view_mode === 'monthly' ? 'months' : ($view_mode === 'quarterly' ? 'quarters' : 'years') ?>
    </p>

    <?php endif; ?>
</div>

<script>
    window.CFP = {
        ledger:      '<?= htmlspecialchars($ledger_uuid) ?>',
        startMonth:  '<?= $start_month ?>',
        monthsAhead: <?= $months_ahead ?>,
        viewMode:    '<?= $view_mode ?>',
        numCols:     <?= count($columns) ?>,
        colLabels:   <?= json_encode(array_column($columns, 'label')) ?>,
        currencySymbol: '$',
        highlightUuid: '<?= htmlspecialchars($highlight_uuid) ?>',
    };
</script>
<script src="/pgbudget/js/cash-flow-projection.js"></script>

<?php require_once '../../includes/footer.php'; ?>
