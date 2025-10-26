<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
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

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $group_name = sanitizeInput($_POST['group_name'] ?? '');
        $sort_order = intval($_POST['sort_order'] ?? 0);

        // Validation
        if (empty($group_name)) {
            $_SESSION['error'] = 'Group name is required.';
        } elseif (strlen($group_name) > 255) {
            $_SESSION['error'] = 'Group name must be 255 characters or less.';
        } else {
            try {
                // Create new category group using the API function
                $stmt = $db->prepare("SELECT * FROM api.create_category_group(?, ?, ?)");
                $stmt->execute([$ledger_uuid, $group_name, $sort_order]);
                $result = $stmt->fetch();

                if ($result && $result['group_uuid']) {
                    $_SESSION['success'] = 'Category group "' . htmlspecialchars($group_name) . '" created successfully!';
                    header('Location: manage.php?ledger=' . urlencode($ledger_uuid));
                    exit;
                } else {
                    $_SESSION['error'] = 'Failed to create category group. Please try again.';
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    $_SESSION['error'] = 'A category group with this name already exists in this budget.';
                } else {
                    $_SESSION['error'] = 'Failed to create category group. Please try again.';
                    error_log("Category group creation error: " . $e->getMessage());
                }
            }
        }
    }
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
        <h1>Create Category Group</h1>
        <p>Organize your budget categories in <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <div class="form-container">
        <form method="POST" class="group-form">
            <div class="form-group">
                <label for="group_name">Group Name *</label>
                <input type="text"
                       id="group_name"
                       name="group_name"
                       value="<?= htmlspecialchars($_POST['group_name'] ?? '') ?>"
                       required
                       maxlength="255"
                       placeholder="e.g., Monthly Bills, Lifestyle & Entertainment, Savings Goals">
                <small class="form-hint">Choose a descriptive name to group related categories</small>
            </div>

            <div class="form-group">
                <label for="sort_order">Sort Order</label>
                <input type="number"
                       id="sort_order"
                       name="sort_order"
                       value="<?= htmlspecialchars($_POST['sort_order'] ?? '0') ?>"
                       min="0"
                       step="1">
                <small class="form-hint">Lower numbers appear first (0 is default)</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Group</button>
                <a href="manage.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="group-info">
        <h3>About Category Groups</h3>
        <div class="info-content">
            <p>Category groups help you organize related budget categories together for better visibility and management.</p>

            <h4>Popular Group Examples:</h4>
            <div class="examples-grid">
                <div class="example-group">
                    <h5>Monthly Bills</h5>
                    <ul>
                        <li>Rent/Mortgage</li>
                        <li>Utilities</li>
                        <li>Internet & Phone</li>
                        <li>Insurance</li>
                    </ul>
                </div>
                <div class="example-group">
                    <h5>Lifestyle & Entertainment</h5>
                    <ul>
                        <li>Dining Out</li>
                        <li>Entertainment</li>
                        <li>Hobbies</li>
                        <li>Subscriptions</li>
                    </ul>
                </div>
                <div class="example-group">
                    <h5>Savings Goals</h5>
                    <ul>
                        <li>Emergency Fund</li>
                        <li>Vacation</li>
                        <li>Car Replacement</li>
                        <li>Home Improvement</li>
                    </ul>
                </div>
                <div class="example-group">
                    <h5>Debt & Obligations</h5>
                    <ul>
                        <li>Credit Card Payments</li>
                        <li>Student Loans</li>
                        <li>Car Loans</li>
                        <li>Medical Bills</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.form-container {
    max-width: 600px;
    margin: 2rem auto;
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.group-form .form-group {
    margin-bottom: 1.5rem;
}

.group-form label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.group-form input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.group-form input:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-hint {
    display: block;
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: #718096;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.group-info {
    max-width: 900px;
    margin: 3rem auto;
    padding: 2rem;
    background: #f7fafc;
    border-radius: 8px;
}

.group-info h3 {
    margin-bottom: 1rem;
    color: #2d3748;
}

.info-content h4 {
    margin-top: 1.5rem;
    margin-bottom: 1rem;
    color: #4a5568;
}

.examples-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
    margin-top: 1rem;
}

.example-group {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #805ad5;
}

.example-group h5 {
    color: #2d3748;
    margin-bottom: 0.5rem;
    font-weight: 600;
}

.example-group ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.example-group li {
    padding: 0.25rem 0;
    color: #4a5568;
    font-size: 0.875rem;
}

.example-group li:before {
    content: "•";
    color: #805ad5;
    margin-right: 0.5rem;
    font-weight: bold;
}

@media (max-width: 768px) {
    .examples-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
