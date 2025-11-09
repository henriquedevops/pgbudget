<?php
/**
 * Quick Fund Goals API Endpoint
 * Calculates optimal budget assignments to meet underfunded goals
 * Phase 2.5: Goal Calculations - Auto-funding Suggestions
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $ledger_uuid = $input['ledger_uuid'] ?? null;
    $month = $input['month'] ?? date('Ym');
    $available_amount = $input['available_amount'] ?? null; // Amount available to assign

    if (empty($ledger_uuid)) {
        throw new Exception('Missing required parameter: ledger_uuid');
    }

    // Get ledger to verify ownership
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        throw new Exception('Ledger not found');
    }

    // If available_amount not provided, calculate from budget totals
    if ($available_amount === null) {
        $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?)");
        $stmt->execute([$ledger_uuid]);
        $totals = $stmt->fetch();
        $available_amount = $totals['left_to_budget'] ?? 0;
    }

    // Get underfunded goals sorted by priority
    $stmt = $db->prepare("SELECT * FROM api.get_underfunded_goals(?, ?)");
    $stmt->execute([$ledger_uuid, $month]);
    $underfunded_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($underfunded_goals)) {
        echo json_encode([
            'success' => true,
            'message' => 'All goals are fully funded!',
            'suggestions' => [],
            'total_suggested' => 0,
            'remaining_after' => $available_amount
        ]);
        exit;
    }

    // Calculate funding suggestions
    $suggestions = [];
    $total_suggested = 0;
    $remaining = $available_amount;

    foreach ($underfunded_goals as $goal) {
        if ($remaining <= 0) {
            break;
        }

        $needed_amount = 0;

        // Calculate how much to suggest for this goal
        switch ($goal['goal_type']) {
            case 'monthly_funding':
                // Fund the full needed amount for this month
                $needed_amount = max(0, $goal['needed_this_month']);
                break;

            case 'target_by_date':
                // Fund the monthly needed amount
                $needed_amount = max(0, $goal['needed_per_month']);
                break;

            case 'target_balance':
                // Suggest either 10% of remaining or needed amount, whichever is less
                $needed_amount = min(
                    $goal['remaining_amount'],
                    (int)($remaining * 0.10) // Suggest 10% of available
                );
                break;
        }

        // Don't suggest more than what's available
        $suggested_amount = min($needed_amount, $remaining);

        if ($suggested_amount > 0) {
            $suggestions[] = [
                'category_uuid' => $goal['category_uuid'],
                'category_name' => $goal['category_name'],
                'goal_type' => $goal['goal_type'],
                'goal_uuid' => $goal['goal_uuid'],
                'needed_amount' => $needed_amount,
                'suggested_amount' => $suggested_amount,
                'priority_score' => $goal['priority_score'],
                'reason' => getfundingReason($goal, $suggested_amount)
            ];

            $total_suggested += $suggested_amount;
            $remaining -= $suggested_amount;
        }
    }

    echo json_encode([
        'success' => true,
        'available_amount' => $available_amount,
        'suggestions' => $suggestions,
        'total_suggested' => $total_suggested,
        'remaining_after' => $remaining,
        'goals_count' => count($suggestions),
        'message' => count($suggestions) > 0
            ? sprintf('Suggested funding for %d goal(s)', count($suggestions))
            : 'No funding suggestions available'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate human-readable reason for funding suggestion
 */
function getFundingReason($goal, $amount) {
    switch ($goal['goal_type']) {
        case 'monthly_funding':
            return sprintf('Fund $%s to meet monthly goal', number_format($amount / 100, 2));

        case 'target_by_date':
            $months_remaining = $goal['months_remaining'] ?? 0;
            if ($months_remaining > 0) {
                return sprintf('$%s/month needed to reach goal by %s',
                    number_format($amount / 100, 2),
                    date('M Y', strtotime($goal['target_date']))
                );
            }
            return 'Funding to reach target date goal';

        case 'target_balance':
            $remaining = $goal['remaining_amount'];
            return sprintf('$%s toward $%s target balance',
                number_format($amount / 100, 2),
                number_format($remaining / 100, 2)
            );

        default:
            return 'Suggested funding amount';
    }
}
