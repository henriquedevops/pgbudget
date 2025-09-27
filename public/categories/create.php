<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';

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
        $category_name = sanitizeInput($_POST['name'] ?? '');

        // Validation
        if (empty($category_name)) {
            $_SESSION['error'] = 'Category name is required.';
        } elseif (strlen($category_name) > 255) {
            $_SESSION['error'] = 'Category name must be 255 characters or less.';
        } else {
            try {
                // Create new category using the API function
                $stmt = $db->prepare("SELECT uuid FROM api.add_category(?, ?)");
                $stmt->execute([$ledger_uuid, $category_name]);
                $result = $stmt->fetch();

                if ($result && $result['uuid']) {
                    $_SESSION['success'] = 'Category "' . htmlspecialchars($category_name) . '" created successfully!';
                    header('Location: manage.php?ledger=' . urlencode($ledger_uuid));
                    exit;
                } else {
                    $_SESSION['error'] = 'Failed to create category. Please try again.';
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    $_SESSION['error'] = 'A category with this name already exists in this budget.';
                } else {
                    $_SESSION['error'] = 'Failed to create category. Please try again.';
                    error_log("Category creation error: " . $e->getMessage());
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
?>

<div class="container">
    <div class="header">
        <h1>Create New Category</h1>
        <p>Add a new budget category to <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <div class="form-container">
        <form method="POST" class="category-form">
            <div class="form-group">
                <label for="name">Category Name *</label>
                <input type="text"
                       id="name"
                       name="name"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                       required
                       maxlength="255"
                       placeholder="e.g., Groceries, Rent, Entertainment, Emergency Fund">
                <small class="form-hint">Choose a descriptive name for this budget category</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Category</button>
                <a href="manage.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="category-info">
        <h3>About Budget Categories</h3>
        <div class="info-content">
            <p>Budget categories help you organize and track your spending. Each category represents a specific area where you plan to spend money.</p>

            <h4>Popular Category Examples:</h4>
            <div class="examples-grid">
                <div class="example-group">
                    <h5>Monthly Expenses</h5>
                    <ul>
                        <li>Rent/Mortgage</li>
                        <li>Utilities</li>
                        <li>Groceries</li>
                        <li>Transportation</li>
                    </ul>
                </div>
                <div class="example-group">
                    <h5>Lifestyle</h5>
                    <ul>
                        <li>Entertainment</li>
                        <li>Dining Out</li>
                        <li>Clothing</li>
                        <li>Hobbies</li>
                    </ul>
                </div>
                <div class="example-group">
                    <h5>Savings & Goals</h5>
                    <ul>
                        <li>Emergency Fund</li>
                        <li>Vacation</li>
                        <li>Car Replacement</li>
                        <li>Home Improvement</li>
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

.category-form .form-group {
    margin-bottom: 1.5rem;
}

.category-form label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.category-form input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.category-form input:focus {
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

.category-info {
    max-width: 800px;
    margin: 3rem auto;
    padding: 2rem;
    background: #f7fafc;
    border-radius: 8px;
}

.category-info h3 {
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
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-top: 1rem;
}

.example-group {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #3182ce;
}

.example-group h5 {
    color: #2d3748;
    margin-bottom: 0.5rem;
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
    color: #3182ce;
    margin-right: 0.5rem;
}
</style>

<?php require_once '../../includes/footer.php'; ?>