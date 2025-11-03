<?php
require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/error-handler.php';

// Require authentication
requireAuth();

$transaction_uuid = $_GET['transaction'] ?? '';
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($transaction_uuid) || empty($ledger_uuid)) {
    $_SESSION['error'] = 'Invalid transaction or budget specified.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();
    setUserContext($db);

    // Get ledger details to verify access
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found or access denied.';
        header('Location: ../index.php');
        exit;
    }

    // Get original transaction details
    $stmt = $db->prepare("
        SELECT t.uuid, t.description, t.amount, t.date,
               da.uuid as debit_account_uuid, da.name as debit_account_name,
               ca.uuid as credit_account_uuid, ca.name as credit_account_name,
               p.name as payee_name,
               CASE
                   WHEN da.name = 'Income' THEN 'inflow'
                   ELSE 'outflow'
               END as type,
               CASE
                   WHEN da.name = 'Income' THEN ca.uuid
                   ELSE da.uuid
               END as account_uuid,
               CASE
                   WHEN da.name = 'Income' THEN da.uuid
                   ELSE ca.uuid
               END as category_uuid
        FROM data.transactions t
        JOIN data.accounts da ON t.debit_account_id = da.id
        JOIN data.accounts ca ON t.credit_account_id = ca.id
        JOIN data.ledgers l ON t.ledger_id = l.id
        LEFT JOIN data.payees p ON t.payee_id = p.id
        WHERE t.uuid = ? AND l.uuid = ? AND t.user_data = utils.get_user()
    ");
    $stmt->execute([$transaction_uuid, $ledger_uuid]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
        $_SESSION['error'] = 'Transaction not found or access denied.';
        header('Location: ../budget/dashboard.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Handle form submission for correction
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'correct') {
            // Enhanced validation using error handler
            $description = sanitizeAndValidate($_POST['description'] ?? '', 'Description', 'text', 255);
            $amount_input = $_POST['amount'] ?? '';
            $date = $_POST['date'] ?? '';
            $type = $_POST['type'] ?? '';
            $account_uuid = $_POST['account'] ?? '';
            $category_uuid = $_POST['category'] ?? '';
            $reason = sanitizeAndValidate($_POST['reason'] ?? 'Transaction correction', 'Reason', 'text', 255);

            // Validate all required fields
            $isValid = true;
            if (!$description) $isValid = false;
            if (!validateCurrency($amount_input, 'Amount')) $isValid = false;
            if (!validateDate($date, 'Date')) $isValid = false;
            if (!validateRequired($type, 'Transaction type')) $isValid = false;
            if (!validateRequired($account_uuid, 'Account')) $isValid = false;
            if (!validateRequired($category_uuid, 'Category')) $isValid = false;

            if ($isValid) {
                $amount = parseCurrency($amount_input);
                try {
                    // Correct transaction using the API function
                    $stmt = $db->prepare("SELECT api.correct_transaction(?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $transaction_uuid,
                        $type,
                        $account_uuid,
                        $category_uuid,
                        $amount,
                        $description,
                        $date,
                        $reason
                    ]);

                    $result = $stmt->fetch();
                    if ($result) {
                        safeRedirect('../budget/dashboard.php?ledger=' . urlencode($ledger_uuid),
                                   'Transaction corrected successfully!', 'success');
                    } else {
                        setErrorMessage('Failed to correct transaction. Please try again.');
                    }
                } catch (PDOException $e) {
                    handleDatabaseError($e, 'transaction correction');
                }
            }
        } elseif ($action === 'delete') {
            $reason = sanitizeInput($_POST['delete_reason'] ?? 'Transaction deleted');

            try {
                // Delete transaction using the API function
                $stmt = $db->prepare("SELECT api.delete_transaction(?, ?)");
                $stmt->execute([$transaction_uuid, $reason]);

                $result = $stmt->fetch();
                if ($result) {
                    $_SESSION['success'] = 'Transaction deleted successfully!';
                    header('Location: ../budget/dashboard.php?ledger=' . urlencode($ledger_uuid));
                    exit;
                } else {
                    $_SESSION['error'] = 'Failed to delete transaction. Please try again.';
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Failed to delete transaction: ' . $e->getMessage();
                error_log("Transaction deletion error: " . $e->getMessage());
            }
        }
    }

    // Get available accounts for the form
    $stmt = $db->prepare("SELECT uuid, name, type FROM api.accounts WHERE ledger_uuid = ? ORDER BY name");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

    // Get available categories (excluding system accounts, groups, and CC payment categories)
    $stmt = $db->prepare("
        SELECT uuid, name FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        AND (is_group = false OR is_group IS NULL)
        AND (metadata->>'is_cc_payment_category' IS NULL OR metadata->>'is_cc_payment_category' != 'true')
        ORDER BY
            CASE WHEN name = 'Income' THEN 1
                 WHEN name = 'Unassigned' THEN 2
                 WHEN name = 'Off-budget' THEN 3
                 ELSE 4 END,
            name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="header">
        <h1>Edit Transaction</h1>
        <p>Correct or delete transaction in <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <div class="transaction-original">
        <h3>Original Transaction</h3>
        <div class="original-details">
            <div class="detail-row">
                <span class="detail-label">Date:</span>
                <span class="detail-value"><?= date('M j, Y', strtotime($transaction['date'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Description:</span>
                <span class="detail-value"><?= htmlspecialchars($transaction['description']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount:</span>
                <span class="detail-value"><?= formatCurrency($transaction['amount']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Type:</span>
                <span class="detail-value transaction-type <?= $transaction['type'] ?>"><?= ucfirst($transaction['type']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Account:</span>
                <span class="detail-value"><?= htmlspecialchars($transaction['type'] === 'inflow' ? $transaction['credit_account_name'] : $transaction['debit_account_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Category:</span>
                <span class="detail-value"><?= htmlspecialchars($transaction['type'] === 'inflow' ? $transaction['debit_account_name'] : $transaction['credit_account_name']) ?></span>
            </div>
        </div>
    </div>

    <div class="edit-forms">
        <!-- Correction Form -->
        <div class="form-section">
            <h3>Correct Transaction</h3>
            <form method="POST" class="transaction-form">
                <input type="hidden" name="action" value="correct">

                <div class="form-row">
                    <div class="form-group">
                        <label for="date">Date *</label>
                        <input type="date" id="date" name="date" value="<?= $transaction['date'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="type">Type *</label>
                        <select id="type" name="type" required>
                            <option value="inflow" <?= $transaction['type'] === 'inflow' ? 'selected' : '' ?>>Income (Inflow)</option>
                            <option value="outflow" <?= $transaction['type'] === 'outflow' ? 'selected' : '' ?>>Expense (Outflow)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description *</label>
                    <input type="text" id="description" name="description" value="<?= htmlspecialchars($transaction['description']) ?>" required maxlength="255">
                </div>

                <div class="form-group">
                    <label for="amount">Amount *</label>
                    <input type="text" id="amount" name="amount" value="<?= number_format($transaction['amount'] / 100, 2) ?>" required pattern="^\d+(\.\d{2})?$" placeholder="0.00">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="account">Account *</label>
                        <select id="account" name="account" required>
                            <option value="">Select account...</option>
                            <?php foreach ($accounts as $account): ?>
                                <?php if ($account['type'] !== 'equity'): ?>
                                    <option value="<?= $account['uuid'] ?>" <?= $account['uuid'] === $transaction['account_uuid'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($account['name']) ?> (<?= ucfirst($account['type']) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="category">Category *</label>
                        <select id="category" name="category" required>
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['uuid'] ?>" <?= $category['uuid'] === $transaction['category_uuid'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="reason">Reason for Correction</label>
                    <input type="text" id="reason" name="reason" placeholder="e.g., Amount correction, Wrong category" maxlength="255">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Correct Transaction</button>
                    <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <!-- Deletion Form -->
        <div class="form-section danger-section">
            <h3>Delete Transaction</h3>
            <p class="danger-warning">⚠️ This action cannot be undone. The transaction will be reversed with an audit trail.</p>

            <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this transaction? This action cannot be undone.')">
                <input type="hidden" name="action" value="delete">

                <div class="form-group">
                    <label for="delete_reason">Reason for Deletion *</label>
                    <input type="text" id="delete_reason" name="delete_reason" placeholder="e.g., Duplicate transaction, Entered by mistake" required maxlength="255">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-danger">Delete Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.transaction-original {
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    border-left: 4px solid #3182ce;
}

.transaction-original h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    color: #2d3748;
}

.original-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.detail-row {
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
    font-weight: 600;
}

.detail-value {
    color: #2d3748;
    font-weight: 500;
}

.transaction-type.inflow {
    color: #38a169;
}

.transaction-type.outflow {
    color: #e53e3e;
}

.edit-forms {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
}

.form-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.danger-section {
    border-left: 4px solid #e53e3e;
    background: #fef5e7;
}

.danger-warning {
    color: #c53030;
    font-weight: 500;
    margin-bottom: 1rem;
    padding: 0.75rem;
    background: #fed7d7;
    border-radius: 4px;
}

.transaction-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.btn-danger {
    background-color: #e53e3e;
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
    transition: background-color 0.2s;
}

.btn-danger:hover {
    background-color: #c53030;
}

@media (max-width: 768px) {
    .edit-forms {
        grid-template-columns: 1fr;
    }

    .transaction-form .form-row {
        grid-template-columns: 1fr;
    }

    .original-details {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>