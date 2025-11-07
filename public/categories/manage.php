<?php
require_once '../../includes/session.php';
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

    // Get categories organized by groups
    $stmt = $db->prepare("SELECT * FROM api.get_categories_by_group(?)");
    $stmt->execute([$ledger_uuid]);
    $all_categories = $stmt->fetchAll();

    // Get budget status for categories
    $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?)");
    $stmt->execute([$ledger_uuid]);
    $budget_status = $stmt->fetchAll();

    // Create a lookup array for budget status
    $budget_lookup = [];
    foreach ($budget_status as $status) {
        $budget_lookup[$status['category_uuid']] = $status;
    }

    // Organize categories into structure
    $system_categories = [];
    $groups = [];
    $ungrouped_categories = [];

    foreach ($all_categories as $cat) {
        // Skip special system categories
        if (in_array($cat['category_name'], ['Income', 'Unassigned', 'Off-budget'])) {
            $system_categories[] = $cat;
            continue;
        }

        // Skip auto-created CC Payment categories
        if (strpos($cat['category_name'], 'CC Payment: ') === 0) {
            continue;
        }

        if ($cat['is_group']) {
            // This is a group header
            $groups[$cat['category_uuid']] = [
                'info' => $cat,
                'categories' => []
            ];
        } elseif ($cat['parent_uuid']) {
            // This category belongs to a group
            if (isset($groups[$cat['parent_uuid']])) {
                $groups[$cat['parent_uuid']]['categories'][] = $cat;
            }
        } else {
            // Ungrouped category
            $ungrouped_categories[] = $cat;
        }
    }

    // Calculate group totals
    $group_totals = [];
    foreach ($groups as $group_uuid => $group_data) {
        $totals = [
            'budgeted' => 0,
            'activity' => 0,
            'balance' => 0,
            'count' => count($group_data['categories'])
        ];
        foreach ($group_data['categories'] as $cat) {
            $status = $budget_lookup[$cat['category_uuid']] ?? null;
            if ($status) {
                $totals['budgeted'] += $status['budgeted'];
                $totals['activity'] += $status['activity'];
                $totals['balance'] += $status['balance'];
            }
        }
        $group_totals[$group_uuid] = $totals;
    }

    // Get all groups for move-to-group dropdown
    $all_groups = array_map(function($g) { return $g['info']; }, $groups);

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}
?>

<div class="container">
    <div class="header">
        <h1>Manage Categories</h1>
        <p>Budget categories for <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <div class="actions-bar">
        <a href="create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">+ New Category</a>
        <a href="create-group.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">+ New Group</a>
        <button onclick="collapseAll()" class="btn btn-secondary">Collapse All</button>
        <button onclick="expandAll()" class="btn btn-secondary">Expand All</button>
        <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <?php if (empty($all_categories)): ?>
        <div class="empty-state">
            <h3>No categories yet</h3>
            <p>Get started by creating your first budget category.</p>
            <a href="create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Create First Category</a>
        </div>
    <?php else: ?>
        <!-- System Categories -->
        <?php if (!empty($system_categories)): ?>
            <div class="categories-section">
                <h2>System Categories</h2>
                <div class="categories-list">
                    <?php foreach ($system_categories as $category): ?>
                        <?php $status = $budget_lookup[$category['category_uuid']] ?? null; ?>
                        <div class="category-row system-category">
                            <div class="category-info">
                                <div class="category-name">
                                    <span class="category-icon">🔧</span>
                                    <strong><?= htmlspecialchars($category['category_name']) ?></strong>
                                    <span class="badge badge-system">System</span>
                                </div>
                            </div>
                            <div class="category-stats">
                                <div class="stat">
                                    <span class="stat-label">Budgeted</span>
                                    <span class="stat-value"><?= formatCurrency($status['budgeted'] ?? 0) ?></span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label">Activity</span>
                                    <span class="stat-value <?= ($status['activity'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
                                        <?= formatCurrency($status['activity'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label">Balance</span>
                                    <span class="stat-value <?= ($status['balance'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
                                        <?= formatCurrency($status['balance'] ?? 0) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="category-actions">
                                <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($category['category_uuid']) ?>" class="btn-link">View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Category Groups -->
        <?php if (!empty($groups)): ?>
            <div class="categories-section">
                <h2>Category Groups</h2>
                <?php foreach ($groups as $group_uuid => $group_data): ?>
                    <?php
                        $group = $group_data['info'];
                        $totals = $group_totals[$group_uuid];
                    ?>
                    <div class="category-group" data-group-uuid="<?= htmlspecialchars($group_uuid) ?>">
                        <div class="group-header" onclick="toggleGroup('<?= htmlspecialchars($group_uuid) ?>')">
                            <div class="group-info">
                                <span class="collapse-icon">▼</span>
                                <span class="group-icon">📁</span>
                                <strong><?= htmlspecialchars($group['category_name']) ?></strong>
                                <span class="badge badge-count"><?= $totals['count'] ?> categories</span>
                            </div>
                            <div class="group-totals">
                                <div class="group-total">
                                    <span class="total-label">Budgeted:</span>
                                    <span class="total-value"><?= formatCurrency($totals['budgeted']) ?></span>
                                </div>
                                <div class="group-total">
                                    <span class="total-label">Activity:</span>
                                    <span class="total-value <?= $totals['activity'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= formatCurrency($totals['activity']) ?>
                                    </span>
                                </div>
                                <div class="group-total">
                                    <span class="total-label">Balance:</span>
                                    <span class="total-value <?= $totals['balance'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= formatCurrency($totals['balance']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="group-categories" id="group-<?= htmlspecialchars($group_uuid) ?>">
                            <?php if (empty($group_data['categories'])): ?>
                                <div class="empty-group">
                                    <p>No categories in this group yet.</p>
                                    <a href="create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn-link-small">Add Category</a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($group_data['categories'] as $category): ?>
                                    <?php $status = $budget_lookup[$category['category_uuid']] ?? null; ?>
                                    <div class="category-row child-category">
                                        <div class="category-info">
                                            <div class="category-name">
                                                <span class="indent">└─</span>
                                                <span><?= htmlspecialchars($category['category_name']) ?></span>
                                            </div>
                                        </div>
                                        <div class="category-stats">
                                            <div class="stat">
                                                <span class="stat-label">Budgeted</span>
                                                <span class="stat-value"><?= formatCurrency($status['budgeted'] ?? 0) ?></span>
                                            </div>
                                            <div class="stat">
                                                <span class="stat-label">Activity</span>
                                                <span class="stat-value <?= ($status['activity'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
                                                    <?= formatCurrency($status['activity'] ?? 0) ?>
                                                </span>
                                            </div>
                                            <div class="stat">
                                                <span class="stat-label">Balance</span>
                                                <span class="stat-value <?= ($status['balance'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
                                                    <?= formatCurrency($status['balance'] ?? 0) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="category-actions">
                                            <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($category['category_uuid']) ?>" class="btn-link">View</a>
                                            <button onclick="showMoveDialog('<?= htmlspecialchars($category['category_uuid']) ?>', '<?= htmlspecialchars($category['category_name']) ?>')" class="btn-link">Move</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Ungrouped Categories -->
        <?php if (!empty($ungrouped_categories)): ?>
            <div class="categories-section">
                <h2>Ungrouped Categories</h2>
                <div class="categories-list">
                    <?php foreach ($ungrouped_categories as $category): ?>
                        <?php $status = $budget_lookup[$category['category_uuid']] ?? null; ?>
                        <div class="category-row user-category">
                            <div class="category-info">
                                <div class="category-name">
                                    <span class="category-icon">📊</span>
                                    <span><?= htmlspecialchars($category['category_name']) ?></span>
                                </div>
                            </div>
                            <div class="category-stats">
                                <div class="stat">
                                    <span class="stat-label">Budgeted</span>
                                    <span class="stat-value"><?= formatCurrency($status['budgeted'] ?? 0) ?></span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label">Activity</span>
                                    <span class="stat-value <?= ($status['activity'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
                                        <?= formatCurrency($status['activity'] ?? 0) ?>
                                    </span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label">Balance</span>
                                    <span class="stat-value <?= ($status['balance'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
                                        <?= formatCurrency($status['balance'] ?? 0) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="category-actions">
                                <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($category['category_uuid']) ?>" class="btn-link">View</a>
                                <button onclick="showMoveDialog('<?= htmlspecialchars($category['category_uuid']) ?>', '<?= htmlspecialchars($category['category_name']) ?>')" class="btn-link">Move to Group</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($groups) && empty($ungrouped_categories) && empty($system_categories)): ?>
            <div class="empty-state">
                <h3>No budget categories yet</h3>
                <p>Get started by creating your first category or category group.</p>
                <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 1rem;">
                    <a href="create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Create Category</a>
                    <a href="create-group.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Create Group</a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Move to Group Dialog -->
<div id="moveDialog" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Move Category to Group</h3>
            <button onclick="closeMoveDialog()" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
            <p>Move <strong id="categoryName"></strong> to:</p>
            <select id="targetGroup" class="form-select">
                <option value="">(None - Ungrouped)</option>
                <?php foreach ($all_groups as $grp): ?>
                    <option value="<?= htmlspecialchars($grp['category_uuid']) ?>">
                        <?= htmlspecialchars($grp['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="modal-footer">
            <button onclick="closeMoveDialog()" class="btn btn-secondary">Cancel</button>
            <button onclick="moveCategory()" class="btn btn-primary">Move</button>
        </div>
    </div>
</div>

<script>
let currentCategoryUuid = null;

function toggleGroup(groupUuid) {
    const groupElement = document.getElementById('group-' + groupUuid);
    const groupContainer = document.querySelector(`[data-group-uuid="${groupUuid}"]`);
    const icon = groupContainer.querySelector('.collapse-icon');

    if (groupElement.style.display === 'none') {
        groupElement.style.display = 'block';
        icon.textContent = '▼';
    } else {
        groupElement.style.display = 'none';
        icon.textContent = '▶';
    }
}

function collapseAll() {
    document.querySelectorAll('.group-categories').forEach(group => {
        group.style.display = 'none';
    });
    document.querySelectorAll('.collapse-icon').forEach(icon => {
        icon.textContent = '▶';
    });
}

function expandAll() {
    document.querySelectorAll('.group-categories').forEach(group => {
        group.style.display = 'block';
    });
    document.querySelectorAll('.collapse-icon').forEach(icon => {
        icon.textContent = '▼';
    });
}

function showMoveDialog(categoryUuid, categoryName) {
    currentCategoryUuid = categoryUuid;
    document.getElementById('categoryName').textContent = categoryName;
    document.getElementById('moveDialog').style.display = 'flex';
}

function closeMoveDialog() {
    document.getElementById('moveDialog').style.display = 'none';
    currentCategoryUuid = null;
}

async function moveCategory() {
    if (!currentCategoryUuid) return;

    const targetGroupUuid = document.getElementById('targetGroup').value || null;

    try {
        const response = await fetch('../api/category-groups.php', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'assign',
                category_uuid: currentCategoryUuid,
                group_uuid: targetGroupUuid
            })
        });

        const result = await response.json();

        if (result.success) {
            // Reload page to show updated grouping
            window.location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to move category'));
        }
    } catch (error) {
        console.error('Error moving category:', error);
        alert('Error moving category. Please try again.');
    }

    closeMoveDialog();
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('moveDialog');
    if (event.target === modal) {
        closeMoveDialog();
    }
}
</script>

<style>
.actions-bar {
    display: flex;
    gap: 1rem;
    margin: 2rem 0;
    flex-wrap: wrap;
}

.categories-section {
    margin-top: 2rem;
}

.categories-section h2 {
    margin: 2rem 0 1rem 0;
    color: #2d3748;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0.5rem;
    font-size: 1.25rem;
}

/* Category Groups */
.category-group {
    margin-bottom: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    overflow: hidden;
}

.group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: linear-gradient(to right, #f7fafc, #edf2f7);
    cursor: pointer;
    transition: background 0.2s;
    border-bottom: 1px solid #e2e8f0;
}

.group-header:hover {
    background: linear-gradient(to right, #edf2f7, #e2e8f0);
}

.group-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.1rem;
}

.collapse-icon {
    color: #718096;
    font-size: 0.875rem;
    transition: transform 0.2s;
}

.group-icon {
    font-size: 1.25rem;
}

.group-totals {
    display: flex;
    gap: 2rem;
}

.group-total {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

.total-label {
    font-size: 0.75rem;
    color: #718096;
    text-transform: uppercase;
    font-weight: 600;
}

.total-value {
    font-size: 1rem;
    font-weight: 700;
    color: #2d3748;
}

.group-categories {
    background: #fafafa;
}

.empty-group {
    padding: 2rem;
    text-align: center;
    color: #718096;
}

.empty-group p {
    margin-bottom: 0.5rem;
}

/* Category Rows */
.categories-list {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
}

.category-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    transition: background 0.2s;
}

.category-row:last-child {
    border-bottom: none;
}

.category-row:hover {
    background: #f7fafc;
}

.system-category {
    background: #faf5ff;
    border-left: 4px solid #805ad5;
}

.user-category {
    border-left: 4px solid #38a169;
}

.child-category {
    background: white;
    border-left: 4px solid #3182ce;
}

.category-info {
    flex: 1;
}

.category-name {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
}

.category-icon {
    font-size: 1.25rem;
}

.indent {
    color: #cbd5e0;
    margin-left: 1rem;
    margin-right: 0.5rem;
}

.badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-system {
    background: #e9d8fd;
    color: #553c9a;
}

.badge-count {
    background: #bee3f8;
    color: #2c5282;
}

/* Stats */
.category-stats {
    display: flex;
    gap: 2rem;
    margin: 0 2rem;
}

.stat {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    min-width: 100px;
}

.stat-label {
    font-size: 0.75rem;
    color: #718096;
    text-transform: uppercase;
    font-weight: 600;
}

.stat-value {
    font-size: 1rem;
    font-weight: 600;
    color: #2d3748;
}

.stat-value.positive {
    color: #38a169;
}

.stat-value.negative {
    color: #e53e3e;
}

.total-value.positive {
    color: #38a169;
}

.total-value.negative {
    color: #e53e3e;
}

/* Actions */
.category-actions {
    display: flex;
    gap: 0.75rem;
}

.btn-link,
.btn-link-small {
    background: none;
    border: none;
    color: #3182ce;
    cursor: pointer;
    font-size: 0.875rem;
    text-decoration: none;
    padding: 0.25rem 0.5rem;
    transition: color 0.2s;
}

.btn-link:hover,
.btn-link-small:hover {
    color: #2c5282;
    text-decoration: underline;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.modal-header h3 {
    margin: 0;
    color: #2d3748;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #718096;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
}

.close-btn:hover {
    color: #2d3748;
}

.modal-body {
    padding: 1.5rem;
}

.modal-body p {
    margin-bottom: 1rem;
    color: #4a5568;
}

.form-select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 1rem;
    background: white;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1.5rem;
    border-top: 1px solid #e2e8f0;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    background: #f7fafc;
    border-radius: 8px;
    margin: 2rem 0;
}

.empty-state h3 {
    color: #4a5568;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #718096;
    margin-bottom: 2rem;
}

/* Responsive */
@media (max-width: 768px) {
    .group-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }

    .group-totals {
        width: 100%;
        justify-content: space-between;
        gap: 1rem;
    }

    .category-row {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }

    .category-stats {
        width: 100%;
        justify-content: space-between;
        margin: 0;
        gap: 1rem;
    }

    .category-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .actions-bar {
        flex-direction: column;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
