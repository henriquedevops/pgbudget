<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$recurring_uuid = $_GET['uuid'] ?? '';
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($recurring_uuid) || empty($ledger_uuid)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = sanitizeInput($_POST['description']);
    $amount = parseCurrency($_POST['amount']);
    $frequency = $_POST['frequency'];
    $next_date = $_POST['next_date'];
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $type = $_POST['type'];
    $account_uuid = sanitizeInput($_POST['account']);
    $category_uuid = sanitizeInput($_POST['category']);
    $auto_create = isset($_POST['auto_create']) && $_POST['auto_create'] === '1';
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

    if (empty($description) || $amount <= 0 || empty($frequency) || empty($next_date) || empty($account_uuid)) {
        $_SESSION['error'] = 'Please fill in all required fields.';
    } else {
        try {
            $db = getDbConnection();

            // Set user context
            $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
            $stmt->execute([$_SESSION['user_id']]);

            // Update recurring transaction
            $stmt = $db->prepare("SELECT api.update_recurring_transaction(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $recurring_uuid,
                $description,
                $amount,
                $frequency,
                $next_date,
                $end_date,
                $account_uuid,
                ($category_uuid && $category_uuid !== 'unassigned') ? $category_uuid : null,
                $type,
                $auto_create ? 'true' : 'false',
                $enabled ? 'true' : 'false'
            ]);

            $result = $stmt->fetch();
            if ($result) {
                $_SESSION['success'] = 'Recurring transaction updated successfully!';
                header("Location: list.php?ledger=" . $ledger_uuid);
                exit;
            } else {
                $_SESSION['error'] = 'Failed to update recurring transaction.';
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
    }
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get recurring transaction details
    $stmt = $db->prepare("SELECT * FROM api.get_recurring_transaction(?)");
    $stmt->execute([$recurring_uuid]);
    $recurring = $stmt->fetch();

    if (!$recurring) {
        $_SESSION['error'] = 'Recurring transaction not found.';
        header('Location: list.php?ledger=' . $ledger_uuid);
        exit;
    }

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Get accounts for this ledger
    $stmt = $db->prepare("SELECT uuid, name, type FROM api.accounts WHERE ledger_uuid = ? ORDER BY name");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

    // Get categories (excluding groups and CC payment categories)
    $stmt = $db->prepare("
        SELECT uuid, name FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        AND name NOT IN ('Income', 'Off-budget', 'Unassigned')
        AND (is_group = false OR is_group IS NULL)
        AND (metadata->>'is_cc_payment_category' IS NULL OR metadata->>'is_cc_payment_category' != 'true')
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
    <div class="header">
        <h1>Edit Recurring Transaction</h1>
        <p>Update scheduled transaction for <?= htmlspecialchars($ledger['name']) ?></p>
    </div>

    <div class="form-container">
        <form method="POST" class="recurring-form">
            <div class="form-group">
                <label for="description" class="form-label">Description *</label>
                <input type="text" id="description" name="description" class="form-input" required
                       placeholder="e.g., Monthly Rent, Bi-weekly Salary"
                       value="<?= htmlspecialchars($recurring['description']) ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="type" class="form-label">Transaction Type *</label>
                    <select id="type" name="type" class="form-select" required onchange="updateFormForType()">
                        <option value="">Choose type...</option>
                        <option value="inflow" <?= $recurring['transaction_type'] === 'inflow' ? 'selected' : '' ?>>
                            Income (Money In)
                        </option>
                        <option value="outflow" <?= $recurring['transaction_type'] === 'outflow' ? 'selected' : '' ?>>
                            Expense (Money Out)
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount" class="form-label">Amount *</label>
                    <input type="text" id="amount" name="amount" class="form-input" required
                           placeholder="0.00"
                           value="<?= formatCurrency($recurring['amount']) ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="frequency" class="form-label">Frequency *</label>
                    <select id="frequency" name="frequency" class="form-select" required>
                        <option value="">Choose frequency...</option>
                        <option value="daily" <?= $recurring['frequency'] === 'daily' ? 'selected' : '' ?>>Daily</option>
                        <option value="weekly" <?= $recurring['frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                        <option value="biweekly" <?= $recurring['frequency'] === 'biweekly' ? 'selected' : '' ?>>Bi-weekly</option>
                        <option value="monthly" <?= $recurring['frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="yearly" <?= $recurring['frequency'] === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="next_date" class="form-label">Next Date *</label>
                    <input type="date" id="next_date" name="next_date" class="form-input" required
                           value="<?= $recurring['next_date'] ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="end_date" class="form-label">End Date (Optional)</label>
                <input type="date" id="end_date" name="end_date" class="form-input"
                       value="<?= $recurring['end_date'] ?? '' ?>">
                <small class="form-help">Leave blank for ongoing recurring transactions</small>
            </div>

            <div class="form-group">
                <label for="account" class="form-label">Account *</label>
                <select id="account" name="account" class="form-select" required>
                    <option value="">Choose account...</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= $account['uuid'] ?>"
                                <?= $account['uuid'] === $recurring['account_uuid'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($account['name']) ?> (<?= htmlspecialchars($account['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="category-group">
                <label for="category" class="form-label">Category</label>
                <select id="category" name="category" class="form-select">
                    <option value="">Choose category...</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['uuid'] ?>"
                                <?= $category['uuid'] === $recurring['category_uuid'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="unassigned" <?= empty($recurring['category_uuid']) ? 'selected' : '' ?>>Unassigned</option>
                </select>
                <small class="form-help">Leave blank to use default Income account for inflows</small>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="auto_create" value="1"
                           <?= $recurring['auto_create'] ? 'checked' : '' ?>>
                    Automatically create transactions when due
                    <small class="form-help">If unchecked, you'll need to manually create transactions</small>
                </label>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="enabled" value="1"
                           <?= $recurring['enabled'] ? 'checked' : '' ?>>
                    Enabled
                    <small class="form-help">Uncheck to pause this recurring transaction</small>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Update Recurring Transaction</button>
                <a href="list.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.form-container {
    max-width: 600px;
    margin: 0 auto;
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.recurring-form {
    width: 100%;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 2rem;
}

.form-help {
    font-size: 0.875rem;
    color: #718096;
    margin-top: 0.25rem;
    display: block;
}

.checkbox-label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    cursor: pointer;
    font-weight: 500;
    color: #2d3748;
}

.checkbox-label input[type="checkbox"] {
    cursor: pointer;
    width: 18px;
    height: 18px;
    margin-right: 0.5rem;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }
}
</style>

<script>
function updateFormForType() {
    const type = document.getElementById('type').value;
    const categoryGroup = document.getElementById('category-group');
    const categorySelect = document.getElementById('category');
    const categoryLabel = categoryGroup.querySelector('label');

    if (type === 'inflow') {
        categoryLabel.textContent = 'Category (Optional)';
        categoryGroup.querySelector('.form-help').textContent = 'Leave blank to use default Income account';
        categorySelect.required = false;
    } else if (type === 'outflow') {
        categoryLabel.textContent = 'Category *';
        categoryGroup.querySelector('.form-help').textContent = 'Choose the budget category for this expense';
        categorySelect.required = true;
    }
}

// Handle amount input
document.getElementById('amount').addEventListener('input', function(e) {
    let value = e.target.value;
    value = value.replace(/[^0-9,.]/g, '');

    const commaIndex = value.lastIndexOf(',');
    const periodIndex = value.lastIndexOf('.');

    if (commaIndex !== -1 && periodIndex !== -1) {
        if (commaIndex > periodIndex) {
            value = value.replace(/\./g, '');
        } else {
            value = value.replace(/,/g, '');
        }
    }

    if (value.includes(',')) {
        const parts = value.split(',');
        if (parts.length > 2) {
            value = parts[0] + ',' + parts.slice(1).join('');
        }
        if (parts[1] && parts[1].length > 2) {
            value = parts[0] + ',' + parts[1].substring(0, 2);
        }
    } else if (value.includes('.')) {
        const parts = value.split('.');
        if (parts.length > 2) {
            value = parts[0] + '.' + parts.slice(1).join('');
        }
        if (parts[1] && parts[1].length > 2) {
            value = parts[0] + '.' + parts[1].substring(0, 2);
        }
    }

    e.target.value = value;
});

// Validate dates
document.getElementById('end_date').addEventListener('change', function() {
    const nextDate = document.getElementById('next_date').value;
    const endDate = this.value;

    if (nextDate && endDate && endDate < nextDate) {
        alert('End date must be after next date');
        this.value = '';
    }
});

// Initialize form state
document.addEventListener('DOMContentLoaded', function() {
    updateFormForType();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
