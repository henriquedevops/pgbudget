<?php
/**
 * Manage Occurrences for a Recurring Projected Event
 * Shows each scheduled occurrence with its realized/projected status.
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$event_uuid  = $_GET['event']  ?? '';

if (empty($ledger_uuid) || empty($event_uuid)) {
    $_SESSION['error'] = 'Invalid parameters.';
    header('Location: index.php');
    exit;
}

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

    $stmt = $db->prepare("SELECT * FROM api.get_projected_event(?)");
    $stmt->execute([$event_uuid]);
    $event = $stmt->fetch();
    if (!$event || $event['frequency'] === 'one_time') {
        $_SESSION['error'] = 'Recurring projected event not found.';
        header("Location: index.php?ledger=$ledger_uuid");
        exit;
    }

    // Load existing realized occurrences
    $stmt = $db->prepare("SELECT * FROM api.get_projected_event_occurrences(?) ORDER BY scheduled_month");
    $stmt->execute([$event_uuid]);
    $occurrences_raw = $stmt->fetchAll();

    // Index by scheduled_month string
    $occurrences = [];
    foreach ($occurrences_raw as $occ) {
        $occurrences[$occ['scheduled_month']] = $occ;
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

// -------------------------------------------------------------------
// Generate the schedule (PHP-side)
// -------------------------------------------------------------------
$event_date   = new DateTime($event['event_date']);
$event_month  = (int)$event_date->format('n');
$second_month = (($event_month - 1 + 6) % 12) + 1; // semiannual second firing

$today        = new DateTime();
$today->modify('first day of this month');

// Window start: 18 months ago or event_date, whichever is later
$window_start = (clone $today)->modify('-18 months');
$event_first  = (clone $event_date)->modify('first day of this month');
if ($event_first > $window_start) {
    $window_start = $event_first;
}

// Window end: 12 months from now, capped by recurrence_end_date
$window_end = (clone $today)->modify('+12 months');
if (!empty($event['recurrence_end_date'])) {
    $rec_end = (new DateTime($event['recurrence_end_date']))->modify('first day of this month');
    if ($rec_end < $window_end) {
        $window_end = $rec_end;
    }
}

// Generate scheduled months
$schedule = [];
$cur = clone $window_start;
while ($cur <= $window_end) {
    $m   = (int)$cur->format('n');
    $key = $cur->format('Y-m-01');

    $include = match($event['frequency']) {
        'monthly'    => true,
        'annual'     => $m === $event_month,
        'semiannual' => $m === $event_month || $m === $second_month,
        default      => false,
    };

    if ($include) {
        $schedule[$key] = true;
    }
    $cur->modify('+1 month');
}

// Merge any realized occurrences outside the schedule window (edge cases)
foreach ($occurrences as $month_key => $_) {
    $schedule[$month_key] = true;
}

ksort($schedule);
$schedule_months = array_keys($schedule);

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="page-title">
            <h1>Manage Occurrences</h1>
            <p><?= htmlspecialchars($event['name']) ?> — <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Events</a>
            <a href="edit.php?ledger=<?= $ledger_uuid ?>&event=<?= $event_uuid ?>" class="btn btn-secondary">Edit Event</a>
        </div>
    </div>

    <!-- Event summary card -->
    <div class="event-summary-card">
        <div class="event-summary-item">
            <span class="label">Frequency</span>
            <span class="value"><?= ucfirst($event['frequency']) ?></span>
        </div>
        <div class="event-summary-item">
            <span class="label">Direction</span>
            <span class="value <?= $event['direction'] === 'inflow' ? 'positive' : 'negative' ?>">
                <?= $event['direction'] === 'inflow' ? 'Inflow' : 'Outflow' ?>
            </span>
        </div>
        <div class="event-summary-item">
            <span class="label">Default Amount</span>
            <span class="value"><?= formatCurrency($event['amount']) ?></span>
        </div>
        <div class="event-summary-item">
            <span class="label">Starts</span>
            <span class="value"><?= (new DateTime($event['event_date']))->format('M Y') ?></span>
        </div>
        <?php if (!empty($event['recurrence_end_date'])): ?>
        <div class="event-summary-item">
            <span class="label">Ends</span>
            <span class="value"><?= (new DateTime($event['recurrence_end_date']))->format('M Y') ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="occ-table-wrapper">
        <table class="occ-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Status</th>
                    <th>Sched. Amount</th>
                    <th>Actual Date</th>
                    <th>Actual Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="occurrences-tbody">
            <?php foreach ($schedule_months as $month_key):
                $occ      = $occurrences[$month_key] ?? null;
                $is_real  = $occ && $occ['is_realized'];
                $dt       = new DateTime($month_key);
                $is_past  = $dt < $today;
                $is_now   = $dt->format('Y-m') === $today->format('Y-m');
                $row_cls  = $is_real ? 'row-realized' : ($is_past ? 'row-past' : ($is_now ? 'row-now' : ''));
            ?>
                <tr class="occ-row <?= $row_cls ?>" data-month="<?= $month_key ?>" id="row-<?= str_replace('-', '', $month_key) ?>">
                    <td class="occ-td occ-month">
                        <strong><?= $dt->format('M Y') ?></strong>
                        <?php if ($is_now): ?><span class="badge-now">This month</span><?php endif; ?>
                    </td>
                    <td class="occ-td occ-status">
                        <?php if ($is_real): ?>
                            <span class="badge-realized">✓ Realized</span>
                        <?php elseif ($is_past): ?>
                            <span class="badge-overdue">● Overdue</span>
                        <?php else: ?>
                            <span class="badge-projected">● Projected</span>
                        <?php endif; ?>
                    </td>
                    <td class="occ-td occ-amount <?= $event['direction'] === 'inflow' ? 'positive' : 'negative' ?>">
                        <?= $event['direction'] === 'inflow' ? '+' : '-' ?><?= formatCurrency($event['amount']) ?>
                    </td>
                    <td class="occ-td occ-realized-date">
                        <?= $is_real ? htmlspecialchars($occ['realized_date']) : '—' ?>
                    </td>
                    <td class="occ-td occ-realized-amount">
                        <?php if ($is_real && $occ['realized_amount'] !== null): ?>
                            <?= $event['direction'] === 'inflow' ? '+' : '-' ?><?= formatCurrency((int)$occ['realized_amount']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="occ-td occ-actions">
                        <?php if ($is_real): ?>
                            <button class="btn btn-sm btn-secondary"
                                    onclick="undoRealize('<?= $event_uuid ?>', '<?= $month_key ?>', this)">
                                Undo
                            </button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-primary"
                                    onclick="showRealizeForm('<?= $month_key ?>', this)">
                                Mark Realized
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Inline realize form row (hidden) -->
                <tr class="occ-form-row" id="form-row-<?= str_replace('-', '', $month_key) ?>" style="display:none;">
                    <td colspan="6">
                        <div class="inline-realize-form">
                            <div class="form-fields">
                                <div class="form-field">
                                    <label>Actual Date <span class="req">*</span></label>
                                    <input type="date" class="realized-date-input"
                                           id="date-<?= str_replace('-', '', $month_key) ?>"
                                           value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="form-field">
                                    <label>Actual Amount <small>(optional — leave blank to use default)</small></label>
                                    <input type="number" class="realized-amount-input"
                                           id="amount-<?= str_replace('-', '', $month_key) ?>"
                                           placeholder="<?= number_format($event['amount'] / 100, 2, '.', '') ?>"
                                           min="0.01" step="0.01">
                                </div>
                            </div>
                            <div class="form-btns">
                                <button class="btn btn-sm btn-primary"
                                        onclick="submitRealize('<?= $event_uuid ?>', '<?= $month_key ?>', this)">
                                    Confirm
                                </button>
                                <button class="btn btn-sm btn-secondary"
                                        onclick="hideRealizeForm('<?= $month_key ?>')">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($schedule_months)): ?>
                <tr><td colspan="6" class="no-rows">No occurrences in the display window.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.event-summary-card {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
}
.event-summary-item { display: flex; flex-direction: column; gap: 0.25rem; }
.event-summary-item .label { font-size: 0.75rem; color: #666; text-transform: uppercase; letter-spacing: 0.05em; }
.event-summary-item .value { font-size: 1rem; font-weight: 600; color: #333; }
.event-summary-item .value.positive { color: #166534; }
.event-summary-item .value.negative { color: #991b1b; }

.occ-table-wrapper { background: white; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
.occ-table { width: 100%; border-collapse: collapse; }
.occ-table thead th {
    background: #f8f9fa;
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.8rem;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid #e0e0e0;
}
.occ-td { padding: 0.85rem 1rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.occ-row:last-child .occ-td { border-bottom: none; }
.occ-row.row-realized { background: #f0fdf4; }
.occ-row.row-past:not(.row-realized) { background: #fff8f0; }
.occ-row.row-now { background: #eff6ff; }

.occ-amount.positive { color: #166534; font-weight: 500; }
.occ-amount.negative { color: #991b1b; font-weight: 500; }

.badge-realized { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.78rem; font-weight: 600; background: #dcfce7; color: #166534; }
.badge-projected { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.78rem; font-weight: 600; background: #e0e7ff; color: #3730a3; }
.badge-overdue   { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.78rem; font-weight: 600; background: #fef3c7; color: #92400e; }
.badge-now { margin-left: 0.4rem; padding: 0.1rem 0.4rem; border-radius: 99px; font-size: 0.7rem; background: #dbeafe; color: #1e40af; }

.btn-sm { padding: 0.3rem 0.8rem; font-size: 0.85rem; }

.occ-form-row td { padding: 0; border-bottom: 1px solid #e0e0e0; }
.inline-realize-form {
    padding: 1rem 1.5rem;
    background: #f8f9fa;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 1rem;
}
.form-fields { display: flex; flex-wrap: wrap; gap: 1rem; flex: 1; }
.form-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 180px; }
.form-field label { font-size: 0.8rem; font-weight: 500; color: #555; }
.form-field label small { font-weight: normal; color: #888; }
.form-field input {
    padding: 0.45rem 0.65rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.9rem;
}
.form-field input:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 2px rgba(0,102,204,0.12); }
.form-btns { display: flex; gap: 0.5rem; align-items: flex-end; }
.req { color: #dc2626; }
.no-rows { padding: 2rem; text-align: center; color: #999; }
</style>

<script>
const EVENT_UUID  = '<?= $event_uuid ?>';
const LEDGER_UUID = '<?= $ledger_uuid ?>';
const API_URL     = '../api/projected-event-occurrences.php';

function monthKey(month) {
    return month.replace(/-/g, '');
}

function showRealizeForm(month, btn) {
    // Hide any other open forms first
    document.querySelectorAll('.occ-form-row').forEach(r => r.style.display = 'none');
    document.getElementById('form-row-' + monthKey(month)).style.display = '';
    btn.style.display = 'none';
}

function hideRealizeForm(month) {
    document.getElementById('form-row-' + monthKey(month)).style.display = 'none';
    // Restore button
    const row = document.getElementById('row-' + monthKey(month));
    const btn = row.querySelector('.btn-primary');
    if (btn) btn.style.display = '';
}

async function submitRealize(eventUuid, month, btn) {
    const key          = monthKey(month);
    const realizedDate = document.getElementById('date-'   + key).value;
    const amountRaw    = document.getElementById('amount-' + key).value;

    if (!realizedDate) {
        alert('Please enter the actual date.');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Saving…';

    const body = new FormData();
    body.append('action',           'realize');
    body.append('event_uuid',       eventUuid);
    body.append('scheduled_month',  month);
    body.append('realized_date',    realizedDate);
    if (amountRaw.trim()) body.append('realized_amount', amountRaw);

    try {
        const res  = await fetch(API_URL, { method: 'POST', body });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unknown error');

        // Update row in-place
        updateRowToRealized(month, data.occurrence);
    } catch (err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Confirm';
    }
}

async function undoRealize(eventUuid, month, btn) {
    if (!confirm('Remove realized status for ' + formatMonthLabel(month) + '? It will return to projected.')) return;

    btn.disabled = true;
    btn.textContent = '…';

    const body = new FormData();
    body.append('action',          'unrealize');
    body.append('event_uuid',      eventUuid);
    body.append('scheduled_month', month);

    try {
        const res  = await fetch(API_URL, { method: 'POST', body });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unknown error');

        updateRowToProjected(month);
    } catch (err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Undo';
    }
}

function updateRowToRealized(month, occ) {
    const key = monthKey(month);
    const row = document.getElementById('row-' + key);

    // Status
    row.querySelector('.occ-status').innerHTML = '<span class="badge-realized">✓ Realized</span>';
    // Actual date
    row.querySelector('.occ-realized-date').textContent = occ.realized_date || '—';
    // Actual amount
    const amtCell = row.querySelector('.occ-realized-amount');
    if (occ.realized_amount !== null && occ.realized_amount !== undefined) {
        const dollars = (parseInt(occ.realized_amount) / 100).toFixed(2);
        amtCell.textContent = dollars;
    } else {
        amtCell.textContent = '—';
    }
    // Actions
    row.querySelector('.occ-actions').innerHTML =
        `<button class="btn btn-sm btn-secondary" onclick="undoRealize('${EVENT_UUID}', '${month}', this)">Undo</button>`;
    // Row class
    row.classList.remove('row-past', 'row-now');
    row.classList.add('row-realized');
    // Hide form
    document.getElementById('form-row-' + key).style.display = 'none';
}

function updateRowToProjected(month) {
    const key = monthKey(month);
    const row = document.getElementById('row-' + key);

    const today = new Date();
    const dt    = new Date(month + 'T00:00:00');
    const isNow = dt.getFullYear() === today.getFullYear() && dt.getMonth() === today.getMonth();
    const isPast = dt < today && !isNow;

    row.querySelector('.occ-status').innerHTML = isPast
        ? '<span class="badge-overdue">● Overdue</span>'
        : '<span class="badge-projected">● Projected</span>';
    row.querySelector('.occ-realized-date').textContent   = '—';
    row.querySelector('.occ-realized-amount').textContent = '—';
    row.querySelector('.occ-actions').innerHTML =
        `<button class="btn btn-sm btn-primary" onclick="showRealizeForm('${month}', this)">Mark Realized</button>`;
    row.classList.remove('row-realized');
    if (isPast) row.classList.add('row-past');
    if (isNow)  row.classList.add('row-now');
}

function formatMonthLabel(month) {
    const dt = new Date(month + 'T00:00:00');
    return dt.toLocaleString('default', { month: 'long', year: 'numeric' });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
