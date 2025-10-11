<?php
/**
 * Goal Dashboard Integration
 * Fetches goals for the current ledger and provides helper functions for display
 * Include this file in dashboard.php after setting $ledger_uuid
 */

// This file should be included after database connection and user context are set

// Get all goals for the ledger with current status
$current_month = $selected_period ?? date('Ym');

try {
    $stmt = $db->prepare("SELECT * FROM api.get_ledger_goals(?, ?)");
    $stmt->execute([$ledger_uuid, $current_month]);
    $ledger_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create a lookup array by category_uuid for easy access
    $goals_by_category = [];
    foreach ($ledger_goals as $goal) {
        $goals_by_category[$goal['category_uuid']] = $goal;
    }

    // Get underfunded goals for sidebar display
    $stmt = $db->prepare("SELECT * FROM api.get_underfunded_goals(?, ?)");
    $stmt->execute([$ledger_uuid, $current_month]);
    $underfunded_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Silent fail - goals are optional
    $ledger_goals = [];
    $goals_by_category = [];
    $underfunded_goals = [];
}

/**
 * Get goal for a specific category
 */
function getCategoryGoal($category_uuid) {
    global $goals_by_category;
    return $goals_by_category[$category_uuid] ?? null;
}

/**
 * Render goal indicator HTML for a category
 */
function renderGoalIndicator($category_uuid) {
    $goal = getCategoryGoal($category_uuid);

    if (!$goal) {
        return '';
    }

    $progress = min(100, $goal['percent_complete']);
    $progressClass = $progress >= 100 ? 'complete' : ($progress >= 75 ? 'good' : ($progress >= 50 ? 'fair' : 'low'));

    $goalText = '';
    $statusIcon = '';

    switch ($goal['goal_type']) {
        case 'monthly_funding':
            if ($goal['is_complete']) {
                $goalText = 'Goal: ' . formatCurrency($goal['target_amount']) . '/month ✓';
                $statusIcon = '✓';
            } else {
                $goalText = 'Goal: ' . formatCurrency($goal['target_amount']) . '/month';
                if ($goal['needed_this_month'] > 0) {
                    $goalText .= ' (Need ' . formatCurrency($goal['needed_this_month']) . ')';
                }
            }
            break;

        case 'target_balance':
            $goalText = 'Goal: Save ' . formatCurrency($goal['target_amount']);
            if ($goal['is_complete']) {
                $goalText .= ' ✓';
                $statusIcon = '✓';
            } else {
                $goalText .= ' (' . formatCurrency($goal['remaining_amount']) . ' remaining)';
            }
            break;

        case 'target_by_date':
            $targetDate = date('M j, Y', strtotime($goal['target_date']));
            $goalText = 'Goal: ' . formatCurrency($goal['target_amount']) . ' by ' . $targetDate;

            if ($goal['is_complete']) {
                $goalText .= ' ✓';
                $statusIcon = '✓';
            } else {
                if ($goal['months_remaining'] > 0 && $goal['needed_per_month'] > 0) {
                    $goalText .= ' (' . formatCurrency($goal['needed_per_month']) . '/month needed)';
                }
                if (!$goal['is_on_track']) {
                    $statusIcon = '⚠️';
                }
            }
            break;
    }

    ob_start();
    ?>
    <div class="goal-indicator">
        <div class="goal-info">
            <span class="goal-text"><?= htmlspecialchars($goalText) ?></span>
            <?php if ($statusIcon): ?>
                <span class="goal-status-icon"><?= $statusIcon ?></span>
            <?php endif; ?>
        </div>
        <div class="goal-progress-container">
            <div class="goal-progress-bar <?= $progressClass ?>">
                <div class="goal-progress-fill" style="width: <?= $progress ?>%"></div>
            </div>
            <div class="goal-progress-text"><?= number_format($progress, 1) ?>%</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Check if category has a goal
 */
function hasGoal($category_uuid) {
    return getCategoryGoal($category_uuid) !== null;
}

/**
 * Render goal action button (Set Goal or Edit Goal)
 */
function renderGoalButton($category_uuid, $category_name) {
    $goal = getCategoryGoal($category_uuid);

    if ($goal) {
        // Edit/Delete buttons
        return sprintf(
            '<button type="button" class="btn btn-small btn-goal edit-goal-btn" data-goal-uuid="%s" title="Edit Goal">🎯 Edit</button>' .
            '<button type="button" class="btn btn-small btn-goal-delete delete-goal-btn" data-goal-uuid="%s" title="Delete Goal">✕</button>',
            htmlspecialchars($goal['goal_uuid']),
            htmlspecialchars($goal['goal_uuid'])
        );
    } else {
        // Set Goal button
        return sprintf(
            '<button type="button" class="btn btn-small btn-goal set-goal-btn" data-category-uuid="%s" data-category-name="%s" title="Set Goal">🎯 Set Goal</button>',
            htmlspecialchars($category_uuid),
            htmlspecialchars($category_name)
        );
    }
}
?>
