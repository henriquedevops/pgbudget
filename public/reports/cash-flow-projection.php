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
    $start_month = date('Y') . '-01-01';
}

$months_ahead = (int)($_GET['months'] ?? 12);
$months_ahead = max(1, min(120, $months_ahead));

$view_mode = $_GET['view'] ?? 'monthly';
if (!in_array($view_mode, ['monthly', 'quarterly', 'annual'])) {
    $view_mode = 'monthly';
}

$group_mode = $_GET['group_mode'] ?? 'detail';
if (!in_array($group_mode, ['detail', 'category'])) {
    $group_mode = 'detail';
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
            'source_type'      => $row['source_type'],
            'source_uuid'      => $row['source_uuid'],
            'category'         => $row['category'],
            'subcategory'      => $row['subcategory'],
            'description'      => $row['description'],
            'amounts'          => [],
            'txn_uuid_by_month'=> [],
            'actual_months'    => [],
            'realized_months'  => [],
        ];
    }
    $m = $row['month'];
    $pivot[$key]['amounts'][$m] = ($pivot[$key]['amounts'][$m] ?? 0) + (int)$row['amount'];
    if ($row['source_type'] === 'transaction') {
        $pivot[$key]['txn_uuid_by_month'][$m] = $row['source_uuid'];
        $pivot[$key]['actual_months'][$m]      = true;
    } elseif ($row['source_type'] === 'realized_occurrence') {
        $pivot[$key]['realized_months'][$m] = true;
    }
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

// Detail mode: merge rows that share the same source_type + base description.
// Strip CC billing suffix "[due Mon DD]" so e.g. "Shop", "Shop [due Feb 23]",
// and "Shop [due Mar 23]" all collapse into a single "Shop" row.
$merged_pivot = [];
foreach ($pivot as $row) {
    $base_desc = preg_replace('/ \[due [A-Za-z]+ \d+\]$/', '', $row['description']);
    $base_desc = preg_replace('/ \([A-Za-z]+ \d{4}\)$/', '', $base_desc);  // strip (Feb 2026) from realized_occurrences
    $base_desc = preg_replace('/ - Parcela \d+\/\d+$/', '', $base_desc);    // strip installment suffix e.g. "- Parcela 3/12"
    $merge_key = $row['source_type'] . ':' . $base_desc;
    if (!isset($merged_pivot[$merge_key])) {
        $merged_pivot[$merge_key] = $row;
        $merged_pivot[$merge_key]['description'] = $base_desc;
    } else {
        foreach ($row['amounts'] as $m => $amt) {
            $merged_pivot[$merge_key]['amounts'][$m] = ($merged_pivot[$merge_key]['amounts'][$m] ?? 0) + $amt;
        }
        foreach ($row['txn_uuid_by_month'] ?? [] as $m => $uuid) {
            $merged_pivot[$merge_key]['txn_uuid_by_month'][$m] = $uuid;
        }
        foreach ($row['actual_months'] ?? [] as $m => $_) {
            $merged_pivot[$merge_key]['actual_months'][$m] = true;
        }
        foreach ($row['realized_months'] ?? [] as $m => $_) {
            $merged_pivot[$merge_key]['realized_months'][$m] = true;
        }
    }
}
$pivot = $merged_pivot;

// Cross-source deduplication: projected rows (event, recurring, realized_occurrence)
// that share a description + month + exact amount with an actual 'transaction' row
// are duplicates — the transaction takes precedence, so drop those months from the
// projected row. Remove the projected row entirely if all its months were deduplicated.
$projected_source_types = ['event', 'recurring', 'realized_event', 'realized_occurrence'];
foreach ($projected_source_types as $proj_type) {
    foreach ($pivot as $key => &$proj_row) {
        if ($proj_row['source_type'] !== $proj_type) continue;
        $trans_key = 'transaction:' . $proj_row['description'];
        if (!isset($pivot[$trans_key])) continue;
        $trans_amounts = $pivot[$trans_key]['amounts'];
        foreach ($proj_row['amounts'] as $m => $proj_amt) {
            // The transaction is always authoritative when:
            //   a) it's a realized_occurrence (projected vs actual amounts naturally differ), OR
            //   b) it's an event/recurring in a past or current month — the actual transaction
            //      has already happened so the projected amount is irrelevant even if it differs
            //      slightly (e.g. IMPOSTO DE RENDA projected -685331 vs actual -685230).
            // For future months keep exact-match to avoid false positives.
            $is_past_or_current = ($m <= $today_month);
            $should_dedup = isset($trans_amounts[$m]) &&
                ($proj_type === 'realized_occurrence' || $is_past_or_current || $trans_amounts[$m] === $proj_amt);
            if ($should_dedup) {
                unset($proj_row['amounts'][$m]);
            }
        }
        if (empty($proj_row['amounts'])) {
            unset($pivot[$key]);
        }
    }
    unset($proj_row);
}

// Merge event/recurring rows into their matching transaction row (same description).
// After dedup the two rows are complementary (no shared months): transaction holds past
// actuals, event holds future projections. Absorbing the transaction months into the
// event row produces a single row showing actuals for the past and forecasts for the
// future — with the delete button still working for past months.
foreach (['event', 'recurring'] as $proj_type) {
    foreach (array_keys($pivot) as $key) {
        if (!isset($pivot[$key])) continue;
        if ($pivot[$key]['source_type'] !== $proj_type) continue;
        $trans_key = 'transaction:' . $pivot[$key]['description'];
        if (!isset($pivot[$trans_key])) continue;
        foreach ($pivot[$trans_key]['amounts'] as $m => $amt) {
            $pivot[$key]['amounts'][$m] = ($pivot[$key]['amounts'][$m] ?? 0) + $amt;
        }
        foreach ($pivot[$trans_key]['txn_uuid_by_month'] ?? [] as $m => $uuid) {
            $pivot[$key]['txn_uuid_by_month'][$m] = $uuid;
        }
        foreach ($pivot[$trans_key]['actual_months'] ?? [] as $m => $_) {
            $pivot[$key]['actual_months'][$m] = true;
        }
        unset($pivot[$trans_key]);
    }
}

// Merge realized_occurrence rows into their matching event row (same description).
// Realized occurrences fire in the realized_date month (may differ from scheduled month)
// and are complementary to the event projections after dedup.
foreach (array_keys($pivot) as $key) {
    if (!isset($pivot[$key])) continue;
    if ($pivot[$key]['source_type'] !== 'realized_occurrence') continue;
    $event_key = 'event:' . $pivot[$key]['description'];
    if (!isset($pivot[$event_key])) continue;
    foreach ($pivot[$key]['amounts'] as $m => $amt) {
        $pivot[$event_key]['amounts'][$m] = ($pivot[$event_key]['amounts'][$m] ?? 0) + $amt;
    }
    foreach ($pivot[$key]['realized_months'] ?? [] as $m => $_) {
        $pivot[$event_key]['realized_months'][$m] = true;
    }
    unset($pivot[$key]);
}

// Inflow / outflow totals — aggregate per raw month here (before category collapse)
// so the values are identical regardless of grouping mode. Column folding happens
// after $columns is built (see below).
$_io_reference_only = ['realized_event', 'past_installment'];
$_month_in  = [];
$_month_out = [];
foreach ($pivot as $_row) {
    if (in_array($_row['source_type'], $_io_reference_only)) continue;
    foreach ($_row['amounts'] as $_m => $_amt) {
        $_amt = (int)$_amt;
        if ($_amt > 0) $_month_in[$_m]  = ($_month_in[$_m]  ?? 0) + $_amt;
        else           $_month_out[$_m] = ($_month_out[$_m] ?? 0) + $_amt;
    }
}
unset($_row, $_m, $_amt);

// Category mode: collapse pivot to one row per (source_type, subcategory)
if ($group_mode === 'category') {
    $cat_pivot = [];
    foreach ($pivot as $row) {
        $sub     = $row['subcategory'] ?: $row['source_type'];
        $cat_key = $row['source_type'] . ':' . $sub;
        if (!isset($cat_pivot[$cat_key])) {
            $cat_pivot[$cat_key] = [
                'source_type' => $row['source_type'],
                'source_uuid' => $cat_key,
                'category'    => $row['category'],
                'subcategory' => $sub,
                'description' => ucwords(str_replace('_', ' ', $sub)),
                'amounts'     => [],
            ];
        }
        foreach ($row['amounts'] as $month => $amount) {
            $cat_pivot[$cat_key]['amounts'][$month] =
                ($cat_pivot[$cat_key]['amounts'][$month] ?? 0) + $amount;
        }
    }
    $pivot = array_values($cat_pivot);
}

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

// Fold per-month inflow/outflow buckets into display columns
$col_inflows  = [];
$col_outflows = [];
foreach ($columns as $_col) {
    $in = 0; $out = 0;
    foreach ($_col['months'] as $_m) {
        $in  += $_month_in[$_m]  ?? 0;
        $out += $_month_out[$_m] ?? 0;
    }
    $col_inflows[$_col['key']]  = $in;
    $col_outflows[$_col['key']] = $out;
}
unset($_io_reference_only, $_month_in, $_month_out, $_col, $_m);

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
$group_order = ['income', 'deduction', 'obligation', 'loan_amort', 'loan_interest', 'installment', 'past_installment', 'recurring', 'event', 'realized_occurrence', 'transaction', 'reconciliation', 'realized_event'];
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
    'transaction'         => 'Actual Transactions',
    'reconciliation'      => 'Reconciliation Adjustments',
    'realized_event'      => 'Realized Events',
];

$groups = [];
foreach ($pivot as $row) {
    $type = $row['source_type'];
    $row['col_amounts'] = aggregateAmounts($row['amounts'], $columns);
    if (empty(array_filter($row['col_amounts']))) continue;
    $row['row_total']   = array_sum($row['col_amounts']);
    $groups[$type][]    = $row;
}

// Sort each group by description
foreach ($groups as $type => &$rows) {
    usort($rows, fn($a, $b) => strcmp($a['description'], $b['description']));
}
unset($rows);

// -------------------------------------------------------------------
// Fetch tooltip metadata: account name + status for each source row
// -------------------------------------------------------------------
$source_meta = [];  // keyed by source_uuid => ['account' => ..., 'status' => ..., 'detail' => ...]

// Collect UUIDs by type
$uuids_by_type = [];
foreach ($groups as $type => $rows) {
    foreach ($rows as $row) {
        $uuids_by_type[$type][] = $row['source_uuid'];
    }
}

// Helper to fetch and index results
$fetchMeta = function(string $sql, array $uuids, callable $mapper) use ($db, &$source_meta) {
    if (empty($uuids)) return;
    $placeholders = implode(',', array_fill(0, count($uuids), '?'));
    $stmt = $db->prepare(str_replace('__IN__', $placeholders, $sql));
    $stmt->execute($uuids);
    foreach ($stmt->fetchAll() as $r) {
        $source_meta[$r['uuid']] = $mapper($r);
    }
};

// income
$fetchMeta(
    "SELECT uuid, name, employer_name, income_type FROM api.income_sources WHERE uuid IN (__IN__)",
    $uuids_by_type['income'] ?? [],
    fn($r) => [
        'status'  => 'Projected income',
        'account' => $r['employer_name'] ? $r['employer_name'] : $r['name'],
        'detail'  => ucfirst(str_replace('_', ' ', $r['income_type'] ?? '')),
    ]
);

// deduction
$fetchMeta(
    "SELECT uuid, name, employer_name, deduction_type FROM api.payroll_deductions WHERE uuid IN (__IN__)",
    $uuids_by_type['deduction'] ?? [],
    fn($r) => [
        'status'  => 'Projected deduction',
        'account' => $r['employer_name'] ? $r['employer_name'] : $r['name'],
        'detail'  => ucfirst(str_replace('_', ' ', $r['deduction_type'] ?? '')),
    ]
);

// obligation
$fetchMeta(
    "SELECT uuid, name, payee_name, default_payment_account_name FROM api.obligations WHERE uuid IN (__IN__)",
    $uuids_by_type['obligation'] ?? [],
    fn($r) => [
        'status'  => 'Projected bill',
        'account' => $r['default_payment_account_name'] ?: ($r['payee_name'] ?: $r['name']),
        'detail'  => $r['payee_name'] ?: '',
    ]
);

// loan_amort + loan_interest share the same source UUIDs
$loanUuids = array_unique(array_merge(
    $uuids_by_type['loan_amort']    ?? [],
    $uuids_by_type['loan_interest'] ?? []
));
$fetchMeta(
    "SELECT uuid, lender_name, account_name FROM api.loans WHERE uuid IN (__IN__)",
    $loanUuids,
    fn($r) => [
        'status'  => 'Projected loan payment',
        'account' => $r['account_name'] ?: $r['lender_name'],
        'detail'  => $r['lender_name'] ?: '',
    ]
);

// installment — account comes from credit card
$fetchMeta(
    "SELECT ip.uuid, a.name AS cc_name
     FROM data.installment_plans ip
     JOIN data.accounts a ON a.id = ip.credit_card_account_id
     WHERE ip.uuid IN (__IN__)",
    $uuids_by_type['installment'] ?? [],
    fn($r) => [
        'status'  => 'Projected installment',
        'account' => $r['cc_name'],
        'detail'  => 'Credit card installment',
    ]
);

// past_installment
$fetchMeta(
    "SELECT ip.uuid, a.name AS cc_name
     FROM data.installment_plans ip
     JOIN data.accounts a ON a.id = ip.credit_card_account_id
     WHERE ip.uuid IN (__IN__)",
    $uuids_by_type['past_installment'] ?? [],
    fn($r) => [
        'status'  => 'Past installment (done)',
        'account' => $r['cc_name'],
        'detail'  => 'Credit card installment',
    ]
);

// projected events
$fetchMeta(
    "SELECT uuid, name, event_type, direction, frequency, linked_transaction_uuid FROM api.projected_events WHERE uuid IN (__IN__)",
    array_unique(array_merge(
        $uuids_by_type['event']               ?? [],
        $uuids_by_type['realized_event']      ?? [],
        $uuids_by_type['realized_occurrence'] ?? []
    )),
    fn($r) => [
        'status'                  => $r['direction'] === 'inflow' ? 'Projected income event' : 'Projected expense event',
        'account'                 => ucfirst(str_replace('_', ' ', $r['event_type'] ?? 'event')),
        'detail'                  => $r['name'],
        'frequency'               => $r['frequency'],
        'direction'               => $r['direction'],
        'linked_transaction_uuid' => $r['linked_transaction_uuid'] ?? '',
    ]
);

// actual transactions — show the asset/checking account involved
$fetchMeta(
    "SELECT t.uuid, t.date::text AS date,
            da.name AS debit_name,  da.type AS debit_type,
            ca.name AS credit_name, ca.type AS credit_type
     FROM data.transactions t
     JOIN data.accounts da ON da.id = t.debit_account_id
     JOIN data.accounts ca ON ca.id = t.credit_account_id
     WHERE t.uuid IN (__IN__)",
    $uuids_by_type['transaction'] ?? [],
    fn($r) => [
        'status'  => 'Actual transaction ✓',
        'account' => $r['debit_type'] === 'asset' ? $r['debit_name'] : ($r['credit_type'] === 'asset' ? $r['credit_name'] : $r['debit_name']),
        'detail'  => $r['date'],
    ]
);

// recurring
$fetchMeta(
    "SELECT rt.uuid, a.name AS account_name
     FROM data.recurring_transactions rt
     JOIN data.accounts a ON a.id = rt.account_id
     WHERE rt.uuid IN (__IN__)",
    $uuids_by_type['recurring'] ?? [],
    fn($r) => [
        'status'  => 'Recurring transaction',
        'account' => $r['account_name'],
        'detail'  => '',
    ]
);

// Per-cell transaction UUIDs from merged event rows — these need account info too
// (their UUIDs are not in $uuids_by_type['transaction'] because the rows were merged)
$cell_txn_uuids = [];
foreach ($groups as $rows) {
    foreach ($rows as $row) {
        foreach ($row['txn_uuid_by_month'] ?? [] as $uuid) {
            if (!isset($source_meta[$uuid])) {
                $cell_txn_uuids[] = $uuid;
            }
        }
    }
}
$cell_txn_uuids = array_unique($cell_txn_uuids);
if (!empty($cell_txn_uuids)) {
    $fetchMeta(
        "SELECT t.uuid, t.date::text AS date,
                da.name AS debit_name,  da.type AS debit_type,
                ca.name AS credit_name, ca.type AS credit_type
         FROM data.transactions t
         JOIN data.accounts da ON da.id = t.debit_account_id
         JOIN data.accounts ca ON ca.id = t.credit_account_id
         WHERE t.uuid IN (__IN__)",
        $cell_txn_uuids,
        fn($r) => [
            'status'  => 'Actual transaction ✓',
            'account' => $r['debit_type'] === 'asset' ? $r['debit_name'] : ($r['credit_type'] === 'asset' ? $r['credit_name'] : $r['debit_name']),
            'detail'  => $r['date'],
        ]
    );
}

// Collect unique account names for the account filter chips
$unique_accounts = [];
foreach ($groups as $type => $rows) {
    foreach ($rows as $row) {
        $acc = $source_meta[$row['source_uuid']]['account'] ?? '';
        // For event/recurring rows that absorbed transactions, use the transaction account
        if (in_array($type, ['event', 'recurring']) && !empty($row['txn_uuid_by_month'])) {
            foreach ($row['txn_uuid_by_month'] as $_uuid) {
                $_a = $source_meta[$_uuid]['account'] ?? '';
                if ($_a !== '') { $acc = $_a; break; }
            }
        }
        if ($acc !== '' && !in_array($acc, $unique_accounts, true)) {
            $unique_accounts[] = $acc;
        }
    }
}
sort($unique_accounts);

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
                <div class="form-group">
                    <label>Grouping</label>
                    <select name="group_mode" class="form-input">
                        <option value="detail"   <?= $group_mode === 'detail'   ? 'selected' : '' ?>>Detail</option>
                        <option value="category" <?= $group_mode === 'category' ? 'selected' : '' ?>>By Category</option>
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
            <span class="cfp-filter-label">Direction:</span>
            <label class="cfp-filter-chip cfp-dir-chip active" data-direction="inflow">
                <input type="checkbox" class="cfp-dir-filter" data-direction="inflow" checked>
                ▲ Inflow
            </label>
            <label class="cfp-filter-chip cfp-dir-chip active" data-direction="outflow">
                <input type="checkbox" class="cfp-dir-filter" data-direction="outflow" checked>
                ▼ Outflow
            </label>
            <span class="cfp-filter-sep">|</span>
            <span class="cfp-filter-label">Type:</span>
            <?php foreach ($group_order as $type): if (!isset($groups[$type])) continue; ?>
                <label class="cfp-filter-chip active" data-type="<?= $type ?>">
                    <input type="checkbox" class="cfp-type-filter" data-type="<?= $type ?>" checked>
                    <?= htmlspecialchars($group_labels[$type]) ?>
                </label>
            <?php endforeach; ?>
            <?php if (count($unique_accounts) > 1): ?>
            <span class="cfp-filter-sep">|</span>
            <div class="cfp-acct-dropdown" id="cfp-acct-dropdown">
                <button class="cfp-acct-btn" id="cfp-acct-btn" type="button">
                    📂 All accounts <span class="cfp-acct-caret">▾</span>
                </button>
                <div class="cfp-acct-popover" id="cfp-acct-popover" hidden>
                    <label class="cfp-acct-all">
                        <input type="checkbox" id="cfp-acct-all" checked> <strong>All accounts</strong>
                    </label>
                    <hr class="cfp-acct-divider">
                    <?php foreach ($unique_accounts as $acc): ?>
                    <label class="cfp-acct-option">
                        <input type="checkbox" class="cfp-account-filter" data-account="<?= htmlspecialchars($acc) ?>" checked>
                        <?= htmlspecialchars($acc) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
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

    <?php $linkable_cells_js = []; ?>

    <!-- Mobile: toggle to show all months -->
    <button class="cfp-show-months-toggle" id="cfp-months-toggle" onclick="cfpToggleAllMonths(this)" aria-pressed="false">
        <i data-lucide="columns-3" aria-hidden="true"></i>
        Show all months
    </button>

    <!-- Projection Table -->
    <div class="cfp-table-wrapper" id="cfp-table-wrapper">
        <table class="cfp-table" id="cfp-table">
            <thead>
                <tr>
                    <th class="cfp-th cfp-th-desc sticky-1">Description</th>
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
                    <td colspan="<?= 1 + count($columns) + 1 ?>" class="cfp-group-hdr-cell">
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
                    $is_highlighted   = ($highlight_uuid !== '' && $row['source_uuid'] === $highlight_uuid);
                    $row_direction    = ($row['row_total'] >= 0) ? 'inflow' : 'outflow';
                    $row_account      = $source_meta[$row['source_uuid']]['account'] ?? '';
                    // For event/recurring rows that absorbed actual transactions, prefer the
                    // transaction's asset account (e.g. "Henrique's Salary") over the generic
                    // event-type label (e.g. "Other") so account filtering works correctly.
                    if (in_array($row['source_type'], ['event', 'recurring']) && !empty($row['txn_uuid_by_month'])) {
                        foreach ($row['txn_uuid_by_month'] as $_txn_uuid) {
                            $_txn_account = $source_meta[$_txn_uuid]['account'] ?? '';
                            if ($_txn_account !== '') { $row_account = $_txn_account; break; }
                        }
                    }
                ?>
                <tr class="cfp-row cfp-detail<?= $is_realized_group ? ' cfp-realized-row' : '' ?><?= $is_highlighted ? ' cfp-highlighted' : '' ?>"
                    data-type="<?= $type ?>"
                    data-group="<?= $type ?>"
                    data-direction="<?= $row_direction ?>"
                    data-account="<?= htmlspecialchars($row_account) ?>"
                    data-source-uuid="<?= htmlspecialchars($row['source_uuid']) ?>">
                    <td class="cfp-td cfp-td-desc sticky-1" title="<?= htmlspecialchars($row['description']) ?>">
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
                        <?= htmlspecialchars($row['description']) ?>
                    </td>
                    <?php foreach ($columns as $i => $col):
                        $val = (int)($row['col_amounts'][$col['key']] ?? 0);
                        $cell_cls = $col['key'] < $today_month ? ' cfp-past-cell' : ($col['key'] === $today_month ? ' cfp-current-cell' : '');
                        $cell_txn_uuid = ($row['txn_uuid_by_month'] ?? [])[$col['key']] ?? null;
                        // Per-cell provenance indicator (monthly view only — one month per column)
                        $cell_indicator = null;
                        if ($val !== 0 && count($col['months']) === 1) {
                            $cm = $col['months'][0];
                            if (isset(($row['actual_months'] ?? [])[$cm]))   $cell_indicator = 'actual';
                            elseif (isset(($row['realized_months'] ?? [])[$cm])) $cell_indicator = 'realized';
                            elseif (in_array($type, ['event','recurring','obligation','loan_amort','loan_interest','installment','deduction','income'])) $cell_indicator = 'projected';
                        }
                    ?>
                        <?php
                            $is_linkable = ($cell_indicator === 'projected' && $type === 'event' && $val !== 0);
                            $cell_extra_cls = $cell_txn_uuid ? ' cfp-td-deletable' : ($is_linkable ? ' cfp-td-linkable' : '');
                            // Collect linkable cells for the JS bundle panel
                            if ($is_linkable) {
                                $linkable_cells_js[] = [
                                    'eventUuid'   => $row['source_uuid'],
                                    'month'       => $col['months'][0],
                                    'amountCents' => abs($val),
                                    'description' => $row['description'],
                                    'direction'   => $source_meta[$row['source_uuid']]['direction'] ?? 'outflow',
                                ];
                            }
                            // For realized event cells, expose linked txn uuid for bundle detection
                            $cell_linked_txn_uuid = '';
                            if ($cell_indicator === 'realized' || $cell_indicator === 'actual') {
                                $cell_linked_txn_uuid = $source_meta[$row['source_uuid']]['linked_transaction_uuid'] ?? '';
                            }
                        ?>
                        <td class="cfp-td cfp-td-amt <?= cellClass($val) . $cell_cls . $cell_extra_cls ?>"
                            data-col-idx="<?= $i ?>"
                            data-cents="<?= $val ?>"
                            data-source="<?= htmlspecialchars($row['source_uuid']) ?>"
                            <?= $cell_txn_uuid ? 'data-txn-uuid="' . htmlspecialchars($cell_txn_uuid) . '"' : '' ?>
                            data-cell-status="<?= $cell_indicator ?? '' ?>"
                            data-col-label="<?= htmlspecialchars($col['label']) ?>"
                            <?= $cell_linked_txn_uuid ? 'data-linked-txn-uuid="' . htmlspecialchars($cell_linked_txn_uuid) . '"' : '' ?>
                            <?php if ($is_linkable): ?>
                            data-event-uuid="<?= htmlspecialchars($row['source_uuid']) ?>"
                            data-month="<?= htmlspecialchars($col['months'][0]) ?>"
                            data-amount="<?= abs($val) ?>"
                            data-desc="<?= htmlspecialchars($row['description']) ?>"
                            title="Click to link to real transaction"
                            <?php endif; ?>>
                            <?= fmtCents($val) ?>
                            <?php if ($cell_indicator === 'actual'): ?><span class="cfp-ci cfp-ci-actual" title="Actual transaction">✓</span><?php endif; ?>
                            <?php if ($cell_indicator === 'realized'): ?><span class="cfp-ci cfp-ci-realized" title="Realized occurrence">↺</span><?php endif; ?>
                            <?php if ($cell_indicator === 'projected' && !$is_linkable): ?><span class="cfp-ci cfp-ci-projected" title="Projection">~</span><?php endif; ?>
                            <?php if ($is_linkable): ?><span class="cfp-ci cfp-ci-linkable" title="Click to link to real transaction">⛓</span><?php endif; ?>
                            <?php if ($cell_txn_uuid && $val !== 0): ?>
                            <button class="cfp-delete-txn-btn"
                                    title="Delete this transaction"
                                    onclick="cfpDeleteTransaction('<?= htmlspecialchars($cell_txn_uuid) ?>', <?= json_encode($row['description']) ?>)">×</button>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-row-total <?= cellClass((int)$row['row_total']) ?>">
                        <?= fmtCents((int)$row['row_total']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- GROUP SUBTOTAL -->
                <tr class="cfp-row cfp-subtotal" data-type="<?= $type ?>" data-group="<?= $type ?>">
                    <td class="cfp-td cfp-td-sublabel sticky-1">
                        <?= htmlspecialchars($glabel) ?> Subtotal
                    </td>
                    <?php foreach ($columns as $i => $col):
                        $val = (int)($subs[$col['key']] ?? 0);
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-subtotal-amt <?= cellClass($val) ?>"
                            data-col-idx="<?= $i ?>" data-cents="<?= $val ?>">
                            <?= fmtCents($val) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-subtotal-amt cfp-subtotal-total <?= cellClass((int)($subs['__total'] ?? 0)) ?>"
                        data-cents="<?= (int)($subs['__total'] ?? 0) ?>">
                        <?= fmtCents((int)($subs['__total'] ?? 0)) ?>
                    </td>
                </tr>

                <!-- SPACER -->
                <tr class="cfp-spacer" data-type="<?= $type ?>">
                    <td colspan="<?= 1 + count($columns) + 1 ?>"></td>
                </tr>

            <?php endforeach; ?>
            </tbody>

            <!-- SUMMARY FOOTER -->
            <tfoot>
                <tr class="cfp-summary cfp-inflows-row">
                    <td class="cfp-td cfp-td-summary-lbl sticky-1">Total Inflows</td>
                    <?php foreach ($columns as $i => $col):
                        $val = (int)($col_inflows[$col['key']] ?? 0);
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-summary-amt <?= $val > 0 ? 'cell-pos' : 'cell-zero' ?>" data-col-idx="<?= $i ?>">
                            <?= fmtCents($val) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-summary-amt <?= array_sum($col_inflows) > 0 ? 'cell-pos' : 'cell-zero' ?>">
                        <?= fmtCents((int)array_sum($col_inflows)) ?>
                    </td>
                </tr>
                <tr class="cfp-summary cfp-outflows-row">
                    <td class="cfp-td cfp-td-summary-lbl sticky-1">Total Outflows</td>
                    <?php foreach ($columns as $i => $col):
                        $val = (int)($col_outflows[$col['key']] ?? 0);
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-summary-amt <?= $val < 0 ? 'cell-neg' : 'cell-zero' ?>" data-col-idx="<?= $i ?>">
                            <?= fmtCents($val) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-summary-amt <?= array_sum($col_outflows) < 0 ? 'cell-neg' : 'cell-zero' ?>">
                        <?= fmtCents((int)array_sum($col_outflows)) ?>
                    </td>
                </tr>
                <?php if ($overdue_cents !== 0): ?>
                <tr class="cfp-summary cfp-overdue-row">
                    <td class="cfp-td cfp-td-summary-lbl sticky-1">
                        Overdue from prev. months
                        <div class="cfp-realized-note cfp-realized-note-block">informational — already counted in past balances</div>
                    </td>
                    <?php foreach ($columns as $i => $col):
                        $contains_today = in_array($today_month, $col['months']);
                        $val = $contains_today ? $overdue_cents : 0;
                    ?>
                        <td class="cfp-td cfp-td-amt cfp-summary-amt <?= $val !== 0 ? cellClass($val) : '' ?>" data-col-idx="<?= $i ?>">
                            <?= $val !== 0 ? fmtCents($val) : '—' ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="cfp-td cfp-td-amt cfp-summary-amt">—</td>
                </tr>
                <?php endif; ?>
                <tr class="cfp-summary cfp-net-row" id="cfp-net-row">
                    <td class="cfp-td cfp-td-summary-lbl sticky-1">Net Monthly Balance</td>
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
                    <td class="cfp-td cfp-td-summary-lbl sticky-1">Cumulative Balance</td>
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

<!-- Row tooltip -->
<div id="cfp-row-tooltip" class="cfp-row-tooltip" role="tooltip" aria-hidden="true"></div>

<script>
    window.CFP = {
        ledger:         '<?= htmlspecialchars($ledger_uuid) ?>',
        startMonth:     '<?= $start_month ?>',
        monthsAhead:    <?= $months_ahead ?>,
        viewMode:       '<?= $view_mode ?>',
        numCols:        <?= count($columns) ?>,
        colLabels:      <?= json_encode(array_column($columns, 'label')) ?>,
        currencySymbol: '$',
        highlightUuid:  '<?= htmlspecialchars($highlight_uuid) ?>',
        sourceMeta:     <?= json_encode($source_meta, JSON_UNESCAPED_UNICODE) ?>,
        linkableCells:  <?= json_encode($linkable_cells_js, JSON_UNESCAPED_UNICODE) ?>,
    };
</script>
<script src="/pgbudget/js/cash-flow-projection.js"></script>

<!-- Link-to-real-transaction modal -->
<div id="cfp-link-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cfp-link-modal-title">
    <div class="modal-container cfp-link-modal-box">

        <!-- Header -->
        <div class="cfp-link-modal-header">
            <h3 id="cfp-link-modal-title">Link to Real Transaction</h3>
            <div class="cfp-link-event-info">
                Month: <strong id="cfp-link-event-month"></strong>
                &nbsp;·&nbsp;
                Projected total: <strong id="cfp-link-event-amount"></strong>
            </div>
        </div>

        <!-- Two-column body -->
        <div class="cfp-link-body">

            <!-- LEFT: bundle projections -->
            <div class="cfp-link-left">
                <div class="cfp-link-panel-label">Projections to bundle</div>
                <div id="cfp-bundle-list" class="cfp-bundle-list"></div>
            </div>

            <!-- RIGHT: real transaction picker -->
            <div class="cfp-link-right">
                <div class="cfp-link-panel-label">Real transaction</div>
                <div class="cfp-link-search">
                    <input type="text" id="cfp-link-search-input" placeholder="Filter…" autocomplete="off">
                </div>
                <div id="cfp-link-txn-list" class="cfp-link-txn-list">
                    <div class="cfp-link-loading">Loading…</div>
                </div>
            </div>

        </div><!-- /.cfp-link-body -->

        <!-- Summary bar -->
        <div class="cfp-link-summary" id="cfp-link-summary">
            <span>Projections: <strong id="cfp-sum-proj">—</strong></span>
            <span>Real: <strong id="cfp-sum-real">—</strong></span>
            <span id="cfp-sum-diff-wrap">Difference: <strong id="cfp-sum-diff">—</strong></span>
        </div>

        <!-- Interest prompt (hidden until needed) -->
        <div id="cfp-interest-prompt" class="cfp-interest-prompt" style="display:none" role="alert">
            <p class="cfp-interest-prompt-msg">
                The actual amount (<strong id="cfp-ip-real"></strong>) is higher than the sum of
                selected projections (<strong id="cfp-ip-proj"></strong>). Should the difference of
                <strong id="cfp-ip-diff"></strong> be recorded as interest or late payment fee?
            </p>
            <div class="cfp-interest-prompt-actions">
                <button id="cfp-ip-yes-btn"    class="btn btn-primary"   type="button">Yes, record as interest</button>
                <button id="cfp-ip-no-btn"     class="btn btn-secondary" type="button">No, just link</button>
                <button id="cfp-ip-cancel-btn" class="btn btn-ghost"     type="button">Cancel</button>
            </div>
        </div>

        <!-- Footer actions -->
        <div class="modal-actions">
            <button id="cfp-link-cancel-btn" class="btn btn-secondary" type="button">Cancel</button>
            <button id="cfp-link-confirm-btn" class="btn btn-primary"  type="button" disabled>Link Transaction</button>
        </div>

    </div>
</div>

<script>
async function cfpDeleteTransaction(uuid, description) {
    ConfirmModal.show({
        title:        'Delete Transaction?',
        message:      `Delete "${description}"? This cannot be undone.`,
        confirmText:  'Delete',
        confirmClass: 'btn-danger',
        onConfirm:    async () => {
            try {
                const response = await fetch(`/pgbudget/api/delete-transaction.php?uuid=${encodeURIComponent(uuid)}`, {
                    method: 'DELETE'
                });
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Error deleting transaction: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Error deleting transaction: ' + err.message);
            }
        }
    });
}
</script>

<script>
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------
    var _linkMonth          = '';
    var _selectedTxnUuid    = '';
    var _selectedTxnAmount  = 0;   // absolute cents of the chosen real txn
    var _allTxns            = [];
    // _bundleItems: array of { eventUuid, month, amountCents, description, checked }
    var _bundleItems        = [];

    // ------------------------------------------------------------------
    // DOM refs
    // ------------------------------------------------------------------
    var modal          = document.getElementById('cfp-link-modal');
    var bundleListEl   = document.getElementById('cfp-bundle-list');
    var listEl         = document.getElementById('cfp-link-txn-list');
    var searchEl       = document.getElementById('cfp-link-search-input');
    var confirmBtn     = document.getElementById('cfp-link-confirm-btn');
    var cancelBtn      = document.getElementById('cfp-link-cancel-btn');
    var interestPrompt = document.getElementById('cfp-interest-prompt');
    var ipYesBtn       = document.getElementById('cfp-ip-yes-btn');
    var ipNoBtn        = document.getElementById('cfp-ip-no-btn');
    var ipCancelBtn    = document.getElementById('cfp-ip-cancel-btn');
    var sumProjEl      = document.getElementById('cfp-sum-proj');
    var sumRealEl      = document.getElementById('cfp-sum-real');
    var sumDiffEl      = document.getElementById('cfp-sum-diff');
    var sumDiffWrap    = document.getElementById('cfp-sum-diff-wrap');

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    function fmtCents(c) {
        if (c === 0) return '—';
        var sym  = (window.CFP && CFP.currencySymbol) || '$';
        var sign = c < 0 ? '-' : '';
        return sign + sym + (Math.abs(c) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Sum of checked bundle items (absolute cents)
    function bundleTotal() {
        return _bundleItems.reduce(function (sum, it) {
            return it.checked ? sum + it.amountCents : sum;
        }, 0);
    }

    // ------------------------------------------------------------------
    // Summary bar
    // ------------------------------------------------------------------
    function updateSummary() {
        var proj = bundleTotal();
        var real = _selectedTxnAmount;
        var diff = real - proj;  // positive = real > projected (overage)

        if (sumProjEl) sumProjEl.textContent = fmtCents(proj);
        if (sumRealEl) sumRealEl.textContent = real > 0 ? fmtCents(real) : '—';

        if (real > 0 && proj > 0) {
            if (sumDiffEl) {
                sumDiffEl.textContent = (diff >= 0 ? '+' : '') + fmtCents(diff);
                sumDiffEl.className   = diff > 0 ? 'cell-neg' : (diff < 0 ? 'cell-pos' : '');
            }
            if (sumDiffWrap) sumDiffWrap.style.display = '';
        } else {
            if (sumDiffWrap) sumDiffWrap.style.display = 'none';
        }
    }

    // ------------------------------------------------------------------
    // Bundle panel
    // ------------------------------------------------------------------
    function renderBundleList() {
        if (!bundleListEl) return;
        if (!_bundleItems.length) {
            bundleListEl.innerHTML = '<div class="cfp-bundle-empty">No other projections for this month.</div>';
            return;
        }
        var html = '';
        _bundleItems.forEach(function (it, idx) {
            var sign    = it.direction === 'inflow' ? '+' : '−';
            var checked = it.checked ? ' checked' : '';
            var cls     = it.checked ? ' cfp-bundle-item--checked' : '';
            var disabled = it.primary ? ' disabled' : '';
            html += '<label class="cfp-bundle-item' + cls + '" data-idx="' + idx + '">'
                  + '<input type="checkbox"' + checked + disabled + ' data-idx="' + idx + '">'
                  + '<span class="cfp-bundle-item-desc">' + escHtml(it.description) + '</span>'
                  + '<span class="cfp-bundle-item-amt">' + sign + fmtCents(it.amountCents) + '</span>'
                  + '</label>';
        });
        bundleListEl.innerHTML = html;

        bundleListEl.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var idx = parseInt(cb.dataset.idx, 10);
                if (!isNaN(idx)) {
                    _bundleItems[idx].checked = cb.checked;
                    var label = cb.closest('.cfp-bundle-item');
                    if (label) label.classList.toggle('cfp-bundle-item--checked', cb.checked);
                }
                updateSummary();
                updateConfirmBtn();
            });
        });
    }

    function updateConfirmBtn() {
        if (!confirmBtn) return;
        confirmBtn.disabled = (_selectedTxnUuid === '' || bundleTotal() === 0);
    }

    // ------------------------------------------------------------------
    // Open / close
    // ------------------------------------------------------------------
    function openModal(eventUuid, month, amountCents, description) {
        _linkMonth         = month;
        _selectedTxnUuid   = '';
        _selectedTxnAmount = 0;
        _allTxns           = [];

        // Build bundle items: the clicked event is first and pre-checked (primary)
        var allCells = (window.CFP && CFP.linkableCells) || [];
        var sameMonth = allCells.filter(function (c) { return c.month === month; });

        _bundleItems = sameMonth.map(function (c) {
            return {
                eventUuid:   c.eventUuid,
                month:       c.month,
                amountCents: c.amountCents,
                description: c.description,
                direction:   c.direction || 'outflow',
                checked:     c.eventUuid === eventUuid,
                primary:     c.eventUuid === eventUuid,
            };
        });
        // If clicked event isn't in linkableCells (edge case), prepend it
        var hasAnchor = _bundleItems.some(function (it) { return it.primary; });
        if (!hasAnchor) {
            _bundleItems.unshift({
                eventUuid:   eventUuid,
                month:       month,
                amountCents: amountCents,
                description: description,
                direction:   'outflow',
                checked:     true,
                primary:     true,
            });
        }

        // Header
        document.getElementById('cfp-link-event-month').textContent  = month.slice(0, 7);
        document.getElementById('cfp-link-event-amount').textContent = fmtCents(bundleTotal());

        searchEl.value      = '';
        confirmBtn.disabled  = true;
        confirmBtn.textContent = 'Link Transaction';
        hideInterestPrompt();

        renderBundleList();
        updateSummary();

        listEl.innerHTML = '<div class="cfp-link-loading">Loading…</div>';
        modal.classList.add('show');
        searchEl.focus();

        loadTransactions();
    }

    function closeModal() {
        modal.classList.remove('show');
        _selectedTxnUuid   = '';
        _selectedTxnAmount = 0;
        _bundleItems        = [];
        hideInterestPrompt();
    }

    // ------------------------------------------------------------------
    // Interest prompt
    // ------------------------------------------------------------------
    function hideInterestPrompt() {
        if (interestPrompt) interestPrompt.style.display = 'none';
        if (confirmBtn)     confirmBtn.style.display     = '';
        if (cancelBtn)      cancelBtn.style.display      = '';
    }

    function showInterestPrompt(realCents, projCents, diffCents) {
        document.getElementById('cfp-ip-real').textContent = fmtCents(realCents);
        document.getElementById('cfp-ip-proj').textContent = fmtCents(projCents);
        document.getElementById('cfp-ip-diff').textContent = fmtCents(diffCents);
        if (interestPrompt) interestPrompt.style.display = '';
        if (confirmBtn)     confirmBtn.style.display     = 'none';
        if (cancelBtn)      cancelBtn.style.display      = 'none';
    }

    // ------------------------------------------------------------------
    // Load & render real transactions
    // ------------------------------------------------------------------
    async function loadTransactions() {
        var ledger = (window.CFP && CFP.ledger) || '';
        // Sort by closeness to the bundle total (not just the clicked amount)
        var targetAmt = bundleTotal() || _bundleItems.reduce(function (s, it) {
            return it.primary ? it.amountCents : s;
        }, 0);
        var url = '/pgbudget/api/transactions-for-link.php'
                + '?ledger_uuid=' + encodeURIComponent(ledger)
                + '&amount_cents=' + encodeURIComponent(targetAmt)
                + '&month='        + encodeURIComponent(_linkMonth);
        try {
            var resp = await fetch(url);
            var data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Load failed');
            _allTxns = data.transactions || [];
            renderList(_allTxns);
        } catch (err) {
            listEl.innerHTML = '<div class="cfp-link-empty">Error loading transactions: ' + err.message + '</div>';
        }
    }

    function renderList(txns) {
        if (!txns.length) {
            listEl.innerHTML = '<div class="cfp-link-empty">No transactions found in this date range.</div>';
            return;
        }
        var html = '';
        txns.forEach(function (t) {
            var amtCents = parseInt(t.amount, 10);
            var amtClass = amtCents >= 0 ? 'cell-pos' : 'cell-neg';
            var diff     = parseInt(t.amount_diff, 10);
            var diffStr  = diff === 0 ? 'exact match' : ('±' + fmtCents(diff));
            var sel      = t.uuid === _selectedTxnUuid ? ' selected' : '';
            html += '<div class="cfp-link-txn-item' + sel + '" data-uuid="' + escHtml(t.uuid) + '" data-amount="' + escHtml(t.amount) + '">'
                  + '<span class="cfp-link-txn-date">'    + escHtml(t.date)        + '</span>'
                  + '<span class="cfp-link-txn-desc">'    + escHtml(t.description) + '</span>'
                  + '<span class="cfp-link-txn-account">' + escHtml(t.account || '') + '</span>'
                  + '<span class="cfp-link-txn-amount ' + amtClass + '">' + fmtCents(amtCents) + '</span>'
                  + '<span class="cfp-link-txn-diff">'    + escHtml(diffStr)       + '</span>'
                  + '</div>';
        });
        listEl.innerHTML = html;

        listEl.querySelectorAll('.cfp-link-txn-item').forEach(function (item) {
            item.addEventListener('click', function () {
                _selectedTxnUuid   = item.dataset.uuid;
                _selectedTxnAmount = Math.abs(parseInt(item.dataset.amount, 10));
                listEl.querySelectorAll('.cfp-link-txn-item').forEach(function (i) {
                    i.classList.toggle('selected', i.dataset.uuid === _selectedTxnUuid);
                });
                updateSummary();
                updateConfirmBtn();
            });
        });
    }

    function applySearch(q) {
        if (!q.trim()) { renderList(_allTxns); return; }
        q = q.toLowerCase();
        renderList(_allTxns.filter(function (t) {
            return (t.description || '').toLowerCase().includes(q)
                || (t.date || '').includes(q)
                || (t.account || '').toLowerCase().includes(q);
        }));
    }

    // ------------------------------------------------------------------
    // Submission
    // ------------------------------------------------------------------
    async function doLink(treatAsInterest) {
        if (!_selectedTxnUuid) return;
        var checked = _bundleItems.filter(function (it) { return it.checked; });
        if (!checked.length) return;

        var proj          = bundleTotal();
        var interestCents = treatAsInterest ? (_selectedTxnAmount - proj) : null;

        [confirmBtn, ipYesBtn, ipNoBtn, ipCancelBtn].forEach(function (b) {
            if (b) b.disabled = true;
        });
        if (confirmBtn) confirmBtn.textContent = 'Linking…';

        try {
            var body = {
                events: checked.map(function (it) {
                    return { event_uuid: it.eventUuid, month: it.month };
                }),
                txn_uuid:              _selectedTxnUuid,
                treat_as_interest:     treatAsInterest,
                interest_amount_cents: interestCents,
            };
            var resp = await fetch('/pgbudget/api/link-projected-event.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(body),
            });
            var data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Link failed');
            closeModal();
            window.location.reload();
        } catch (err) {
            [confirmBtn, ipYesBtn, ipNoBtn, ipCancelBtn].forEach(function (b) {
                if (b) b.disabled = false;
            });
            if (confirmBtn) confirmBtn.textContent = 'Link Transaction';
            alert('Error linking: ' + err.message);
        }
    }

    function confirmLink() {
        if (!_selectedTxnUuid) return;
        var proj = bundleTotal();
        var diff = _selectedTxnAmount - proj;
        if (diff > 0) {
            showInterestPrompt(_selectedTxnAmount, proj, diff);
        } else {
            doLink(false);
        }
    }

    // ------------------------------------------------------------------
    // Bundle indicator post-processing
    // Used after page load to mark cells that share a linked transaction
    // ------------------------------------------------------------------
    function markBundles() {
        var cells = document.querySelectorAll('[data-linked-txn-uuid]');
        var byTxn = {};
        cells.forEach(function (td) {
            var id = td.dataset.linkedTxnUuid;
            if (!id) return;
            byTxn[id] = byTxn[id] || [];
            byTxn[id].push(td);
        });
        Object.keys(byTxn).forEach(function (txnUuid) {
            var group = byTxn[txnUuid];
            if (group.length < 2) return;
            var names = group.map(function (td) {
                var src = td.dataset.source || '';
                var meta = (window.CFP && CFP.sourceMeta && CFP.sourceMeta[src]) || {};
                return meta.detail || src;
            }).filter(Boolean).join(', ');
            group.forEach(function (td) {
                var ci = td.querySelector('.cfp-ci');
                if (ci) {
                    ci.textContent = '🔗';
                    ci.className   = 'cfp-ci cfp-ci-bundle';
                    ci.title       = 'Bundle: ' + names;
                }
            });
        });
    }

    // ------------------------------------------------------------------
    // Wire up
    // ------------------------------------------------------------------
    if (cancelBtn)  cancelBtn.addEventListener('click',  closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', confirmLink);
    if (ipYesBtn)   ipYesBtn.addEventListener('click',   function () { doLink(true);  });
    if (ipNoBtn)    ipNoBtn.addEventListener('click',    function () { doLink(false); });
    if (ipCancelBtn) ipCancelBtn.addEventListener('click', function () {
        hideInterestPrompt();
        updateConfirmBtn();
    });
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('show')) closeModal();
    });
    if (searchEl) {
        searchEl.addEventListener('input', function () { applySearch(searchEl.value); });
    }

    // Delegate click from linkable cells
    document.addEventListener('click', function (e) {
        var td = e.target.closest('.cfp-td-linkable');
        if (!td) return;
        e.stopPropagation();
        openModal(
            td.dataset.eventUuid,
            td.dataset.month,
            parseInt(td.dataset.amount, 10),
            td.dataset.desc
        );
    });

    // Run bundle marking after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', markBundles);
    } else {
        markBundles();
    }

})();
</script>

<script>
function cfpToggleAllMonths(btn) {
    const wrapper = document.getElementById('cfp-table-wrapper');
    const isExpanded = wrapper.classList.toggle('show-all-months');
    btn.setAttribute('aria-pressed', isExpanded);
    btn.innerHTML = isExpanded
        ? '<i data-lucide="columns-3" aria-hidden="true"></i> Show 3 months'
        : '<i data-lucide="columns-3" aria-hidden="true"></i> Show all months';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
