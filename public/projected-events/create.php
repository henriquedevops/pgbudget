<?php
/**
 * Create Projected Event Page
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
            <h1>Create Projected Event</h1>
            <p>Add a one-time future financial event to <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Events</a>
        </div>
    </div>

    <div class="form-container">
        <form id="createEventForm" class="event-form">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Event Details</h3>

                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="255"
                           placeholder="e.g., FGTS Anniversary Withdrawal">
                    <small class="form-hint">A descriptive name for this event</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="event_type">Event Type *</label>
                        <select id="event_type" name="event_type" required>
                            <option value="bonus">Bonus</option>
                            <option value="tax_refund">Tax Refund</option>
                            <option value="settlement">Settlement / Acerto</option>
                            <option value="asset_sale">Asset Sale</option>
                            <option value="gift">Gift</option>
                            <option value="large_purchase">Large Purchase</option>
                            <option value="vacation">Vacation</option>
                            <option value="medical">Medical</option>
                            <option value="other" selected>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="direction">Direction *</label>
                        <select id="direction" name="direction" required>
                            <option value="inflow">Inflow (Money In)</option>
                            <option value="outflow" selected>Outflow (Money Out)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" rows="2" maxlength="500"
                              placeholder="Additional notes about this event..."></textarea>
                </div>
            </div>

            <!-- Amount & Date -->
            <div class="form-section">
                <h3>Amount & Date</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="amount">Amount *</label>
                        <input type="number" id="amount" name="amount" required min="0.01" step="0.01"
                               placeholder="0.00">
                        <small class="form-hint">Always a positive number; direction determines if it's in or out</small>
                    </div>

                    <div class="form-group">
                        <label for="event_date">Event Date *</label>
                        <input type="date" id="event_date" name="event_date" required value="<?= date('Y-m-d') ?>">
                        <small class="form-hint">When this event is expected to occur</small>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div class="form-section">
                <h3>Confirmation Status</h3>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_confirmed" name="is_confirmed" value="1">
                        <span>This event is confirmed (not speculative)</span>
                    </label>
                    <small class="form-hint">Check if this event is certain to happen. Unconfirmed events are treated as speculative projections.</small>
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
                            <option value="<?= htmlspecialchars($category['uuid']) ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="1000"
                              placeholder="Any additional information..."></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Create Event</button>
                <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-lg">Cancel</a>
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
.form-actions { padding: 2rem; background: #f9f9f9; border-top: 1px solid #e0e0e0; display: flex; gap: 1rem; justify-content: flex-end; }
.btn-lg { padding: 0.75rem 2rem; font-size: 1rem; }
@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column-reverse; }
    .form-actions .btn { width: 100%; }
}
</style>

<script>
document.getElementById('createEventForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'create');

    // Ensure is_confirmed is sent as boolean string
    formData.set('is_confirmed', document.getElementById('is_confirmed').checked ? '1' : '0');

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';

    try {
        const response = await fetch('../api/projected-events.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Event created successfully';
        } else {
            alert('Error creating event: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error creating event: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
