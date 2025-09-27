<?php
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);

    if (empty($name)) {
        $_SESSION['error'] = 'Budget name is required.';
    } else {
        try {
            $db = getDbConnection();

            // Set user context
            $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
            $stmt->execute([$_SESSION['user_id']]);

            // Create ledger
            $stmt = $db->prepare("INSERT INTO api.ledgers (name, description) VALUES (?, ?) RETURNING uuid");
            $stmt->execute([$name, $description]);
            $result = $stmt->fetch();

            if ($result) {
                $_SESSION['success'] = 'Budget created successfully!';
                header("Location: ../budget/dashboard.php?ledger=" . $result['uuid']);
                exit;
            } else {
                $_SESSION['error'] = 'Failed to create budget.';
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="header">
        <h1>Create New Budget</h1>
        <p>Set up a new zero-sum budget ledger</p>
    </div>

    <div class="form-container">
        <form method="POST" class="budget-form">
            <div class="form-group">
                <label for="name" class="form-label">Budget Name *</label>
                <input type="text" id="name" name="name" class="form-input" required
                       placeholder="e.g., Monthly Budget, Vacation Fund"
                       value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" class="form-textarea"
                          placeholder="Optional description for this budget"><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Budget</button>
                <a href="../index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="info-section">
        <h3>What happens next?</h3>
        <ul>
            <li>Your new budget will be created with default Income, Off-budget, and Unassigned accounts</li>
            <li>You can add bank accounts, categories, and start tracking transactions</li>
            <li>All amounts are tracked in cents for precision (e.g., $10.00 = 1000 cents)</li>
            <li>Every transaction follows double-entry accounting principles</li>
        </ul>
    </div>
</div>

<style>
.form-container {
    max-width: 600px;
    margin: 0 auto 3rem;
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.budget-form {
    width: 100%;
}

.form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 2rem;
}

.info-section {
    max-width: 600px;
    margin: 0 auto;
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #3182ce;
}

.info-section h3 {
    margin-bottom: 1rem;
    color: #2d3748;
}

.info-section ul {
    padding-left: 1.5rem;
}

.info-section li {
    margin-bottom: 0.5rem;
    color: #4a5568;
}
</style>

<?php require_once '../../includes/footer.php'; ?>