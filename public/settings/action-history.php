<?php
/**
 * Action History Page
 * Phase 6.6: View action history and undo/redo log
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

try {
    $db = getDbConnection();
    setUserContext($db);

    // Get ledger details if specified
    $ledger = null;
    if (!empty($ledger_uuid)) {
        $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
        $stmt->execute([$ledger_uuid]);
        $ledger = $stmt->fetch();
    }

    // Get total count
    $count_query = "
        SELECT COUNT(*)
        FROM data.action_history ah
        LEFT JOIN data.ledgers l ON ah.ledger_id = l.id
        WHERE ah.user_data = utils.get_user()
          AND (? IS NULL OR l.uuid = ?)
    ";
    $stmt = $db->prepare($count_query);
    $stmt->execute([$ledger_uuid ?: null, $ledger_uuid ?: null]);
    $total_actions = $stmt->fetchColumn();
    $total_pages = ceil($total_actions / $per_page);

    // Get action history
    $history_query = "
        SELECT
            ah.uuid,
            ah.action_type,
            ah.entity_type,
            ah.entity_uuid,
            ah.description,
            ah.created_at,
            ah.old_data,
            ah.new_data,
            l.name as ledger_name,
            l.uuid as ledger_uuid
        FROM data.action_history ah
        LEFT JOIN data.ledgers l ON ah.ledger_id = l.id
        WHERE ah.user_data = utils.get_user()
          AND (? IS NULL OR l.uuid = ?)
        ORDER BY ah.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($history_query);
    $stmt->execute([$ledger_uuid ?: null, $ledger_uuid ?: null, $per_page, $offset]);
    $actions = $stmt->fetchAll();

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
}

// Helper function to format relative time
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';

    return date('M j, Y g:i A', $timestamp);
}

// Helper function to get action icon
function getActionIcon($actionType) {
    $icons = [
        'create' => '➕',
        'update' => '✏️',
        'delete' => '🗑️',
        'assign' => '💰',
        'move' => '↔️',
        'transfer' => '🔄',
        'bulk_categorize' => '📂',
        'bulk_delete' => '🗑️',
        'bulk_update' => '✏️'
    ];

    return $icons[$actionType] ?? '📝';
}
?>

<div class="action-history-container">
    <div class="action-history-header">
        <div>
            <h1>Action History</h1>
            <p style="color: #718096; margin-top: 0.5rem;">
                View your recent actions and activity log
                <?php if ($ledger): ?>
                    for <strong><?= htmlspecialchars($ledger['name']) ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <div class="action-history-controls">
            <?php if ($ledger_uuid): ?>
                <a href="action-history.php" class="btn btn-secondary">All Ledgers</a>
            <?php endif; ?>
            <button onclick="clearActionHistory()" class="btn btn-secondary">Clear History</button>
            <a href="/pgbudget/" class="btn btn-primary">Back to Dashboard</a>
        </div>
    </div>

    <?php if (empty($actions)): ?>
        <div class="action-history-empty">
            <div class="icon">📋</div>
            <h3>No Action History</h3>
            <p>Your actions will appear here as you use PGBudget.</p>
            <p style="font-size: 14px; color: #a0aec0; margin-top: 1rem;">
                Actions are stored for 30 days and can be undone with <kbd>Ctrl+Z</kbd>
            </p>
        </div>
    <?php else: ?>
        <div class="action-history-list">
            <?php foreach ($actions as $action): ?>
                <div class="action-history-item">
                    <div class="action-icon <?= htmlspecialchars($action['action_type']) ?>">
                        <?= getActionIcon($action['action_type']) ?>
                    </div>

                    <div class="action-details">
                        <div class="action-description">
                            <?= htmlspecialchars($action['description'] ?: ucfirst($action['action_type']) . ' ' . $action['entity_type']) ?>
                        </div>
                        <div class="action-meta">
                            <span><?= ucfirst($action['entity_type']) ?></span>
                            <?php if ($action['ledger_name']): ?>
                                • <span><?= htmlspecialchars($action['ledger_name']) ?></span>
                            <?php endif; ?>
                            <?php if ($action['entity_uuid']): ?>
                                • <span class="action-uuid"><?= htmlspecialchars($action['entity_uuid']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="action-time">
                        <?= timeAgo($action['created_at']) ?>
                    </div>

                    <?php if ($action['old_data'] || $action['new_data']): ?>
                        <div class="action-data-preview">
                            <?php if ($action['old_data']): ?>
                                <strong>Before:</strong>
                                <pre><?= htmlspecialchars(json_encode(json_decode($action['old_data']), JSON_PRETTY_PRINT)) ?></pre>
                            <?php endif; ?>

                            <?php if ($action['new_data']): ?>
                                <strong>After:</strong>
                                <pre><?= htmlspecialchars(json_encode(json_decode($action['new_data']), JSON_PRETTY_PRINT)) ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination" style="margin-top: 2rem;">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-secondary">Previous</a>
                <?php endif; ?>

                <div class="page-numbers">
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="page-link current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-link"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-secondary">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 2rem; padding: 1rem; background: #f7fafc; border-radius: 8px; text-align: center; color: #718096; font-size: 14px;">
            <strong>Note:</strong> Actions are automatically deleted after 30 days.
            Use <kbd>Ctrl+Z</kbd> to undo recent actions.
        </div>
    <?php endif; ?>
</div>

<script>
function clearActionHistory() {
    if (confirm('Are you sure you want to clear all action history? This cannot be undone.')) {
        // Clear session storage
        sessionStorage.removeItem('pgbudget-undo-stack');
        sessionStorage.removeItem('pgbudget-redo-stack');

        // Call cleanup API (optional: implement this endpoint)
        fetch('/pgbudget/api/cleanup-action-history.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ days: 0 }) // Clear all
        })
        .then(() => {
            window.location.reload();
        })
        .catch(err => {
            console.error('Failed to clear history:', err);
            alert('Failed to clear history. Please try again.');
        });
    }
}
</script>

<style>
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
}

.page-numbers {
    display: flex;
    gap: 0.5rem;
}

.page-link {
    padding: 0.5rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    text-decoration: none;
    color: #4a5568;
    transition: all 0.2s;
}

.page-link:hover {
    background: #f7fafc;
    border-color: #cbd5e0;
}

.page-link.current {
    background: #3182ce;
    color: white;
    border-color: #3182ce;
}

.action-uuid {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    background: #e2e8f0;
    padding: 2px 6px;
    border-radius: 3px;
}

pre {
    margin: 0.5rem 0;
    white-space: pre-wrap;
    word-wrap: break-word;
}
</style>

<?php require_once '../../includes/footer.php'; ?>
