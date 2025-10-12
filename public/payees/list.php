<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get all payees
    $stmt = $db->prepare("SELECT * FROM api.get_payees()");
    $stmt->execute();
    $payees = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Payee Management</h1>
        <a href="add.php" class="btn btn-primary">+ Add Payee</a>
    </div>

    <?php if (empty($payees)): ?>
        <div class="empty-state">
            <p>No payees yet. Payees will be automatically created when you add transactions.</p>
            <a href="add.php" class="btn btn-primary">Create Your First Payee</a>
        </div>
    <?php else: ?>
        <div class="payees-section">
            <table class="table">
                <thead>
                    <tr>
                        <th>Payee Name</th>
                        <th>Default Category</th>
                        <th>Auto-Categorize</th>
                        <th>Transactions</th>
                        <th>Last Used</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payees as $payee): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($payee['name']) ?></strong></td>
                            <td>
                                <?php if ($payee['default_category_name']): ?>
                                    <?= htmlspecialchars($payee['default_category_name']) ?>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($payee['auto_categorize']): ?>
                                    <span class="badge badge-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">No</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $payee['transaction_count'] ?></td>
                            <td>
                                <?php if ($payee['last_used']): ?>
                                    <?= date('M j, Y', strtotime($payee['last_used'])) ?>
                                <?php else: ?>
                                    <span class="text-muted">Never</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a href="edit.php?payee=<?= urlencode($payee['uuid']) ?>" class="btn btn-small btn-secondary">Edit</a>
                                <a href="delete.php?payee=<?= urlencode($payee['uuid']) ?>" class="btn btn-small btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this payee? This will unlink it from <?= $payee['transaction_count'] ?> transaction(s).');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.page-header h1 {
    margin: 0;
}

.payees-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    text-align: left;
    padding: 0.75rem;
    background-color: #f7fafc;
    border-bottom: 2px solid #e2e8f0;
    font-weight: 600;
}

.table td {
    padding: 0.75rem;
    border-bottom: 1px solid #f1f5f9;
}

.table tr:hover {
    background-color: #f7fafc;
}

.actions {
    display: flex;
    gap: 0.5rem;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-success {
    background-color: #c6f6d5;
    color: #22543d;
}

.badge-secondary {
    background-color: #e2e8f0;
    color: #4a5568;
}

.text-muted {
    color: #a0aec0;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.empty-state p {
    color: #718096;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }

    .table {
        font-size: 0.875rem;
    }

    .actions {
        flex-direction: column;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
