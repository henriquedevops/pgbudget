<?php
/**
 * Edit Projected Event Page
 * Also supports marking as realized and linking to a transaction
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$event_uuid  = $_GET['event'] ?? '';

if (empty($ledger_uuid) || empty($event_uuid)) {
    $_SESSION['error'] = 'Invalid parameters.';
    header('Location: ../index.php');
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

    if (!$event) {
        $_SESSION['error'] = 'Projected event not found.';
        header("Location: index.php?ledger=$ledger_uuid");
        exit;
    }

    // Get equity accounts for category dropdown
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type = 'equity'
        AND is_group = false
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

    // Get recent transactions for linking (last 6 months)
    $stmt = $db->prepare("
        SELECT t.uuid, t.description, t.amount, t.date, a.name as account_name
        FROM api.transactions t
        JOIN api.accounts a ON a.uuid = t.account_uuid
        WHERE t.ledger_uuid = ?
        AND t.date >= CURRENT_DATE - INTERVAL '6 months'
        ORDER BY t.date DESC
        LIMIT 100
    ");
    $stmt->execute([$ledger_uuid]);
    $transactions = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="page-title">
            <h1>Edit Projected Event</h1>
            <p><?= htmlspecialchars($event['name']) ?> — <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Events</a>
        </div>
    </div>

    <div class="form-container">
        <form id="editEventForm" class="event-form">
            <input type="hidden" name="event_uuid" value="<?= htmlspecialchars($event_uuid) ?>">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Event Details</h3>

                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="255"
                           value="<?= htmlspecialchars($event['name']) ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="event_type">Event Type *</label>
                        <select id="event_type" name="event_type" required>
                            <?php
                            $types = ['bonus','tax_refund','settlement','asset_sale','gift','large_purchase','vacation','medical','other'];
                            $labels = ['Bonus','Tax Refund','Settlement / Acerto','Asset Sale','Gift','Large Purchase','Vacation','Medical','Other'];
                            foreach ($types as $i => $t): ?>
                                <option value="<?= $t ?>" <?= $event['event_type'] === $t ? 'selected' : '' ?>>
                                    <?= $labels[$i] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="direction">Direction *</label>
                        <select id="direction" name="direction" required>
                            <option value="inflow"  <?= $event['direction'] === 'inflow'  ? 'selected' : '' ?>>Inflow (Money In)</option>
                            <option value="outflow" <?= $event['direction'] === 'outflow' ? 'selected' : '' ?>>Outflow (Money Out)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" rows="2" maxlength="500"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Amount & Date -->
            <div class="form-section">
                <h3>Amount & Date</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="amount">Amount *</label>
                        <input type="number" id="amount" name="amount" required min="0.01" step="0.01"
                               value="<?= number_format($event['amount'] / 100, 2, '.', '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="event_date" id="event-date-label">Event Date *</label>
                        <input type="date" id="event_date" name="event_date" required
                               value="<?= $event['event_date'] ?>">
                        <small class="form-hint" id="event-date-hint"><?= ($event['frequency'] !== 'one_time') ? 'Date of the first occurrence' : 'When this event is expected to occur' ?></small>
                    </div>
                </div>
            </div>

            <!-- Recurrence -->
            <div class="form-section">
                <h3>Recurrence</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="frequency">Frequency *</label>
                        <select id="frequency" name="frequency" required onchange="updateRecurrenceUI()">
                            <option value="one_time"   <?= $event['frequency'] === 'one_time'   ? 'selected' : '' ?>>One-time</option>
                            <option value="monthly"    <?= $event['frequency'] === 'monthly'    ? 'selected' : '' ?>>Monthly</option>
                            <option value="annual"     <?= $event['frequency'] === 'annual'     ? 'selected' : '' ?>>Annual</option>
                            <option value="semiannual" <?= $event['frequency'] === 'semiannual' ? 'selected' : '' ?>>Semiannual (twice a year)</option>
                        </select>
                    </div>

                    <div class="form-group" id="recurrence-end-group" style="<?= $event['frequency'] !== 'one_time' ? '' : 'display:none;' ?>">
                        <label for="recurrence_end_date">Repeat Until (Optional)</label>
                        <input type="date" id="recurrence_end_date" name="recurrence_end_date"
                               value="<?= htmlspecialchars($event['recurrence_end_date'] ?? '') ?>">
                        <small class="form-hint">Leave blank to repeat indefinitely within the projection window.</small>
                    </div>
                </div>

                <div id="recurrence-hint" style="<?= $event['frequency'] !== 'one_time' ? '' : 'display:none;' ?>">
                    <small class="form-hint" id="recurrence-hint-text">
                        <?php
                        if ($event['frequency'] === 'monthly') echo 'This event will repeat every month starting from the date above.';
                        elseif ($event['frequency'] === 'annual') echo 'This event will repeat every year in the same calendar month.';
                        elseif ($event['frequency'] === 'semiannual') echo 'This event will repeat twice a year, 6 months apart from the first occurrence.';
                        ?>
                    </small>
                </div>
            </div>

            <!-- Status -->
            <div class="form-section">
                <h3>Status</h3>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_confirmed" name="is_confirmed" value="1"
                               <?= $event['is_confirmed'] ? 'checked' : '' ?>>
                        <span>This event is confirmed (not speculative)</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_realized" name="is_realized" value="1"
                               <?= $event['is_realized'] ? 'checked' : '' ?>
                               onchange="toggleTransactionLink()">
                        <span>Mark as realized (event has already occurred)</span>
                    </label>
                    <small class="form-hint">Realized events are excluded from the cash flow projection.</small>
                </div>

                <!-- Link to transaction (shown when realized) -->
                <div id="transaction_link_section" style="<?= $event['is_realized'] ? '' : 'display:none;' ?>">
                    <div class="form-group">
                        <label for="linked_transaction_uuid">Link to Actual Transaction (Optional)</label>
                        <select id="linked_transaction_uuid" name="linked_transaction_uuid">
                            <option value="">None</option>
                            <?php foreach ($transactions as $txn): ?>
                                <option value="<?= htmlspecialchars($txn['uuid']) ?>"
                                    <?= ($event['linked_transaction_uuid'] === $txn['uuid']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($txn['date']) ?>
                                    — <?= htmlspecialchars($txn['description']) ?>
                                    (<?= formatCurrency($txn['amount']) ?>)
                                    [<?= htmlspecialchars($txn['account_name']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Optionally link this event to the transaction that realized it.</small>
                    </div>
                </div>
            </div>

            <!-- Category -->
            <div class="form-section">
                <h3>Category</h3>

                <div class="form-group">
                    <label for="default_category_uuid">Category (Optional)</label>
                    <select id="default_category_uuid" name="default_category_uuid">
                        <option value="">None</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['uuid']) ?>"
                                <?= ($event['default_category_uuid'] === $category['uuid']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="1000"><?= htmlspecialchars($event['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Save Changes</button>
                <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-lg">Cancel</a>
                <button type="button" class="btn btn-danger btn-lg"
                        onclick="deleteEvent('<?= $event_uuid ?>', '<?= htmlspecialchars(addslashes($event['name'])) ?>')">
                    Delete Event
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.form-container { max-width: 800px; margin: 0 auto; }
.event-form { background: white; border-radius: 8px; border: 1px solid #e0e0e0; }
.form-section { padding: 2rem; border-bottom: 1px solid #e0e0e0; }
.form-section:last-of-type { border-bottom: none; }
.form-section h3 { margin-top: 0; margin-bottom: 1.5rem; color: #333; font-size: 1.25rem; }
.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; font-weight: 500; margin-bottom: 0.5rem; color: #333; }
.form-group input[type="text"],
.form-group input[type="number"],
.form-group input[type="date"],
.form-group select,
.form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1); }
.form-hint { display: block; margin-top: 0.25rem; font-size: 0.875rem; color: #666; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.checkbox-label { display: flex; align-items: flex-start; font-weight: normal; cursor: pointer; gap: 0.5rem; }
.checkbox-label input[type="checkbox"] { margin-top: 0.15rem; flex-shrink: 0; }
.form-actions { padding: 2rem; background: #f9f9f9; border-top: 1px solid #e0e0e0; display: flex; gap: 1rem; justify-content: flex-end; flex-wrap: wrap; }
.btn-lg { padding: 0.75rem 2rem; font-size: 1rem; }
@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column-reverse; }
    .form-actions .btn { width: 100%; }
}
</style>

<script>
function updateRecurrenceUI() {
    const freq = document.getElementById('frequency').value;
    const endGroup = document.getElementById('recurrence-end-group');
    const hintEl   = document.getElementById('recurrence-hint');
    const hintText = document.getElementById('recurrence-hint-text');
    const dateLabel = document.getElementById('event-date-label');
    const dateHint  = document.getElementById('event-date-hint');

    if (freq === 'one_time') {
        endGroup.style.display = 'none';
        hintEl.style.display   = 'none';
        dateLabel.textContent  = 'Event Date *';
        dateHint.textContent   = 'When this event is expected to occur';
    } else {
        endGroup.style.display = '';
        hintEl.style.display   = '';
        dateLabel.textContent  = 'First Occurrence Date *';
        if (freq === 'monthly') {
            dateHint.textContent  = 'Date of the first occurrence; repeats on this day monthly';
            hintText.textContent  = 'This event will repeat every month starting from the date above.';
        } else if (freq === 'annual') {
            dateHint.textContent  = 'Date of the first occurrence; repeats every year in the same month';
            hintText.textContent  = 'This event will repeat every year in the same calendar month.';
        } else if (freq === 'semiannual') {
            dateHint.textContent  = 'Date of the first occurrence; repeats every 6 months';
            hintText.textContent  = 'This event will repeat twice a year, 6 months apart from the first occurrence.';
        }
    }
}

function toggleTransactionLink() {
    const isRealized = document.getElementById('is_realized').checked;
    document.getElementById('transaction_link_section').style.display = isRealized ? 'block' : 'none';
}

document.getElementById('editEventForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'update');

    formData.set('is_confirmed', document.getElementById('is_confirmed').checked ? '1' : '0');
    formData.set('is_realized', document.getElementById('is_realized').checked ? '1' : '0');

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';

    try {
        const response = await fetch('../api/projected-events.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Event updated successfully';
        } else {
            alert('Error updating event: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error updating event: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});

async function deleteEvent(eventUuid, eventName) {
    if (!confirm(`Delete projected event "${eventName}"? This action cannot be undone.`)) return;

    try {
        const response = await fetch('../api/projected-events.php?event_uuid=' + encodeURIComponent(eventUuid), {
            method: 'DELETE'
        });
        const result = await response.json();
        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Event deleted successfully';
        } else {
            alert('Error deleting event: ' + result.error);
        }
    } catch (err) {
        alert('Error deleting event: ' + err.message);
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
