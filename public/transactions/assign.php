<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$category_uuid = $_GET['category'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = parseCurrency($_POST['amount']);
    $description = sanitizeInput($_POST['description']) ?: 'Budget assignment';
    $date = $_POST['date'];
    $target_category = sanitizeInput($_POST['category']);

    if ($amount <= 0 || empty($target_category)) {
        $_SESSION['error'] = 'Please enter a valid amount and select a category.';
    } else {
        try {
            $db = getDbConnection();

            // Set user context
            $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
            $stmt->execute([$_SESSION['user_id']]);

            // Use the assign_to_category function
            $stmt = $db->prepare("SELECT uuid FROM api.assign_to_category(?, ?, ?, ?, ?)");
            $stmt->execute([
                $ledger_uuid,
                $date,
                $description,
                $amount,
                $target_category
            ]);

            $result = $stmt->fetch();
            if ($result) {
                $_SESSION['success'] = 'Money assigned to category successfully!';
                header("Location: ../budget/dashboard.php?ledger=" . $ledger_uuid);
                exit;
            } else {
                $_SESSION['error'] = 'Failed to assign money to category.';
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

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Get budget totals to show available funds
    $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?)");
    $stmt->execute([$ledger_uuid]);
    $budget_totals = $stmt->fetch();

    // Get categories (equity accounts that aren't special)
    $stmt = $db->prepare("
        SELECT uuid, name FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        AND name NOT IN ('Income', 'Off-budget', 'Unassigned')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

    // Get the selected category details if provided
    $selected_category = null;
    if ($category_uuid) {
        $stmt = $db->prepare("SELECT * FROM api.accounts WHERE uuid = ? AND ledger_uuid = ?");
        $stmt->execute([$category_uuid, $ledger_uuid]);
        $selected_category = $stmt->fetch();
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="header">
        <h1>Assign Money to Category</h1>
        <p>Move money from Income to budget categories in <?= htmlspecialchars($ledger['name']) ?></p>
    </div>

    <?php if ($budget_totals && $budget_totals['left_to_budget'] > 0): ?>
        <div class="available-funds">
            <h3>Available to Budget</h3>
            <div class="funds-amount">
                <?= formatCurrency($budget_totals['left_to_budget']) ?>
            </div>
            <p>This is your unassigned income that can be allocated to categories.</p>
        </div>
    <?php elseif ($budget_totals && $budget_totals['left_to_budget'] === 0): ?>
        <div class="alert alert-info">
            <strong>All income assigned!</strong> You have successfully allocated all your income to budget categories.
        </div>
    <?php else: ?>
        <div class="alert alert-error">
            <strong>No funds available!</strong> You need to add income before you can assign money to categories.
            <a href="../transactions/add.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary btn-small">Add Income</a>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" class="assign-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="amount" class="form-label">Amount to Assign *</label>
                    <input type="text" id="amount" name="amount" class="form-input" required
                           placeholder="$0.00"
                           value="<?= isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : '' ?>">
                    <?php if ($budget_totals && $budget_totals['left_to_budget'] > 0): ?>
                        <small class="form-help">
                            Maximum available: <?= formatCurrency($budget_totals['left_to_budget']) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="date" class="form-label">Date *</label>
                    <input type="date" id="date" name="date" class="form-input" required
                           value="<?= isset($_POST['date']) ? $_POST['date'] : date('Y-m-d') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="category" class="form-label">Assign to Category *</label>
                <select id="category" name="category" class="form-select" required>
                    <option value="">Choose category...</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['uuid'] ?>"
                                <?php if ($selected_category && $selected_category['uuid'] === $category['uuid']): ?>selected<?php endif; ?>
                                <?= (isset($_POST['category']) && $_POST['category'] === $category['uuid']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($categories)): ?>
                    <small class="form-help">
                        <a href="../categories/manage.php?ledger=<?= $ledger_uuid ?>">Create your first category</a> to start budgeting.
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description</label>
                <input type="text" id="description" name="description" class="form-input"
                       placeholder="e.g., Weekly grocery budget, Monthly rent allocation"
                       value="<?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?>">
                <small class="form-help">Optional description for this budget assignment</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"
                        <?= ($budget_totals && $budget_totals['left_to_budget'] <= 0) ? 'disabled' : '' ?>>
                    Assign Money
                </button>
                <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="assignment-help">
        <h3>How Budget Assignment Works</h3>
        <div class="help-content">
            <div class="help-item">
                <h4>💰 Zero-Sum Budgeting</h4>
                <p>Every dollar of income must be assigned to a category. This ensures you have a plan for all your money.</p>
            </div>
            <div class="help-item">
                <h4>🔄 Double-Entry Accounting</h4>
                <p>Money moves from your Income account to the selected category. Both accounts are updated to maintain balance.</p>
            </div>
            <div class="help-item">
                <h4>📊 Real-Time Updates</h4>
                <p>Your budget status updates immediately, showing how much you've allocated vs. spent in each category.</p>
            </div>
        </div>
    </div>
</div>

<style>
.available-funds {
    background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    margin-bottom: 2rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.available-funds h3 {
    margin: 0 0 1rem 0;
    color: white;
}

.funds-amount {
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 0.5rem;
}

.available-funds p {
    margin: 0;
    opacity: 0.9;
}

.form-container {
    max-width: 600px;
    margin: 0 auto 3rem;
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.assign-form {
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

.assignment-help {
    max-width: 800px;
    margin: 0 auto;
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #3182ce;
}

.assignment-help h3 {
    margin-bottom: 1.5rem;
    color: #2d3748;
}

.help-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.help-item h4 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.help-item p {
    color: #4a5568;
    margin: 0;
}

.alert-info {
    background-color: #e6fffa;
    color: #234e52;
    border: 1px solid #81e6d9;
}

.btn-small {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    margin-left: 1rem;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .help-content {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .form-actions {
        flex-direction: column;
    }

    .funds-amount {
        font-size: 2rem;
    }
}
</style>

<script>
// Format amount input and validate against available funds
document.getElementById('amount').addEventListener('input', function(e) {
    let value = e.target.value.replace(/[^0-9.]/g, '');
    if (value && !value.startsWith('$')) {
        const numValue = parseFloat(value);
        if (!isNaN(numValue)) {
            e.target.value = '$' + numValue.toFixed(2);

            // Check against available funds
            <?php if ($budget_totals): ?>
            const available = <?= $budget_totals['left_to_budget'] ?>;
            const entered = Math.round(numValue * 100);

            if (entered > available) {
                e.target.style.borderColor = '#e53e3e';
                e.target.nextElementSibling.style.color = '#e53e3e';
            } else {
                e.target.style.borderColor = '#d2d6dc';
                if (e.target.nextElementSibling) {
                    e.target.nextElementSibling.style.color = '#718096';
                }
            }
            <?php endif; ?>
        }
    }
});

// Pre-fill description based on selected category
document.getElementById('category').addEventListener('change', function(e) {
    const categoryName = e.target.options[e.target.selectedIndex].text;
    const descriptionField = document.getElementById('description');

    if (categoryName && categoryName !== 'Choose category...') {
        if (!descriptionField.value) {
            descriptionField.value = `Budget: ${categoryName}`;
        }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>