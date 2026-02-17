<?php
/**
 * Projected Events Index
 * List all projected one-time future events with status indicators
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

if (isset($_GET['success'])) {
    $_SESSION['success'] = $_GET['success'];
    header("Location: index.php?ledger=$ledger_uuid");
    exit;
}

$today = new DateTime();
$today->setTime(0, 0, 0);

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

    $stmt = $db->prepare("SELECT * FROM api.get_projected_events(?)");
    $stmt->execute([$ledger_uuid]);
    $events = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

// Compute status for each event
function getEventStatus($event, $today) {
    if ($event['is_realized']) return 'realized';
    $event_date = new DateTime($event['event_date']);
    $event_date->setTime(0, 0, 0);
    if ($event['is_confirmed']) {
        return $event_date < $today ? 'overdue' : 'confirmed';
    }
    return $event_date < $today ? 'overdue' : 'upcoming';
}

// Summary stats
$total_inflow = 0;
$total_outflow = 0;
$upcoming_count = 0;
$realized_count = 0;
foreach ($events as $event) {
    $status = getEventStatus($event, $today);
    if ($status === 'realized') {
        $realized_count++;
    } else {
        $upcoming_count++;
        if ($event['direction'] === 'inflow') {
            $total_inflow += $event['amount'];
        } else {
            $total_outflow += $event['amount'];
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="page-title">
            <h1>Projected Events</h1>
            <p>One-time future financial events for <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ New Event</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="event-summary-cards">
        <div class="summary-card">
            <div class="summary-card-label">Upcoming Events</div>
            <div class="summary-card-value"><?= $upcoming_count ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label">Projected Inflows</div>
            <div class="summary-card-value amount positive">+<?= formatCurrency($total_inflow) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label">Projected Outflows</div>
            <div class="summary-card-value amount negative">-<?= formatCurrency($total_outflow) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label">Net Impact</div>
            <?php $net = $total_inflow - $total_outflow; ?>
            <div class="summary-card-value amount <?= $net >= 0 ? 'positive' : 'negative' ?>"><?= $net >= 0 ? '+' : '' ?><?= formatCurrency(abs($net)) ?></div>
        </div>
    </div>

    <?php if (empty($events)): ?>
        <div class="empty-state">
            <h3>No projected events found</h3>
            <p>Track one-time future cash flows like bonuses, tax refunds, settlements, large purchases, and other irregular events.</p>
            <p class="empty-state-hint">Projected events appear in the cash flow projection report.</p>
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Your First Event</a>
        </div>
    <?php else: ?>
        <div class="events-table-container">
            <table class="table events-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Event</th>
                        <th>Type</th>
                        <th>Direction</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event):
                        $status = getEventStatus($event, $today);
                        $event_date = new DateTime($event['event_date']);
                    ?>
                        <tr class="<?= $status === 'realized' ? 'realized' : '' ?>">
                            <td>
                                <strong><?= $event_date->format('M j, Y') ?></strong>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($event['name']) ?></strong>
                                <?php if ($event['description']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($event['description']) ?></small>
                                <?php endif; ?>
                                <?php if ($event['linked_transaction_uuid']): ?>
                                    <br><small class="text-linked">Linked to transaction</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="event-type-badge type-<?= $event['event_type'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $event['event_type'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="direction-badge direction-<?= $event['direction'] ?>">
                                    <?= $event['direction'] === 'inflow' ? '+ Inflow' : '- Outflow' ?>
                                </span>
                            </td>
                            <td>
                                <span class="amount <?= $event['direction'] === 'inflow' ? 'positive' : 'negative' ?>">
                                    <?= $event['direction'] === 'inflow' ? '+' : '-' ?><?= formatCurrency($event['amount']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $status ?>">
                                    <?= match($status) {
                                        'realized' => 'Realized',
                                        'confirmed' => 'Confirmed',
                                        'upcoming'  => 'Upcoming',
                                        'overdue'   => 'Overdue',
                                        default     => ucfirst($status),
                                    } ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <a href="edit.php?ledger=<?= $ledger_uuid ?>&event=<?= $event['uuid'] ?>"
                                   class="btn btn-small btn-secondary">Edit</a>
                                <button class="btn btn-small btn-danger"
                                        onclick="deleteEvent('<?= $event['uuid'] ?>', '<?= htmlspecialchars(addslashes($event['name'])) ?>')">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.event-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    text-align: center;
}

.summary-card-label {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.5rem;
}

.summary-card-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: #333;
}

.summary-card-value.positive { color: #2e7d32; }
.summary-card-value.negative { color: #d32f2f; }

.events-table-container {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    overflow-x: auto;
}

.events-table { width: 100%; }

.events-table th {
    background: #f5f5f5;
    font-weight: 600;
    padding: 0.75rem;
    text-align: left;
    border-bottom: 2px solid #ddd;
    font-size: 0.875rem;
    white-space: nowrap;
}

.events-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #eee;
    font-size: 0.875rem;
}

.events-table tr:hover { background: #f9f9f9; }
.events-table tr.realized { opacity: 0.6; }

.event-type-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.type-bonus          { background: #fff3e0; color: #f57c00; }
.type-tax_refund     { background: #e8f5e9; color: #2e7d32; }
.type-settlement     { background: #e3f2fd; color: #1976d2; }
.type-asset_sale     { background: #f3e5f5; color: #7b1fa2; }
.type-gift           { background: #fce4ec; color: #c2185b; }
.type-large_purchase { background: #ffebee; color: #c62828; }
.type-vacation       { background: #e0f2f1; color: #00796b; }
.type-medical        { background: #fff8e1; color: #f9a825; }
.type-other          { background: #f5f5f5; color: #616161; }

.direction-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.direction-inflow  { background: #e8f5e9; color: #2e7d32; }
.direction-outflow { background: #ffebee; color: #c62828; }

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.status-realized  { background: #e8f5e9; color: #2e7d32; }
.status-confirmed { background: #e3f2fd; color: #1565c0; }
.status-upcoming  { background: #fff3e0; color: #ef6c00; }
.status-overdue   { background: #ffebee; color: #c62828; }

.amount.positive { color: #2e7d32; }
.amount.negative { color: #d32f2f; }

.text-muted   { color: #666; }
.text-linked  { color: #1976d2; font-style: italic; }

.actions-cell { white-space: nowrap; }

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.empty-state h3 { color: #666; margin-bottom: 1rem; }
.empty-state p { color: #999; margin-bottom: 1rem; }
.empty-state-hint { font-style: italic; }

@media (max-width: 768px) {
    .event-summary-cards { grid-template-columns: repeat(2, 1fr); }
}
</style>

<script>
async function deleteEvent(eventUuid, eventName) {
    if (!confirm(`Delete projected event "${eventName}"? This action cannot be undone.`)) return;

    try {
        const response = await fetch('../api/projected-events.php?event_uuid=' + encodeURIComponent(eventUuid), {
            method: 'DELETE'
        });
        const result = await response.json();
        if (result.success) {
            window.location.reload();
        } else {
            alert('Error deleting event: ' + result.error);
        }
    } catch (err) {
        alert('Error deleting event: ' + err.message);
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
