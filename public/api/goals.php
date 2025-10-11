<?php
/**
 * Goals API Endpoint
 * Handles CRUD operations for category goals
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    switch ($method) {
        case 'POST': // Create goal
            if (empty($input['category_uuid']) || empty($input['goal_type']) || empty($input['target_amount'])) {
                throw new Exception('Missing required fields: category_uuid, goal_type, target_amount');
            }

            $stmt = $db->prepare("
                SELECT * FROM api.create_category_goal(
                    p_category_uuid := ?,
                    p_goal_type := ?,
                    p_target_amount := ?,
                    p_target_date := ?,
                    p_repeat_frequency := ?
                )
            ");

            $stmt->execute([
                $input['category_uuid'],
                $input['goal_type'],
                intval($input['target_amount']),
                $input['target_date'] ?? null,
                $input['repeat_frequency'] ?? null
            ]);

            $goal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$goal) {
                throw new Exception('Failed to create goal');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Goal created successfully',
                'goal' => $goal
            ]);
            break;

        case 'PUT': // Update goal
            if (empty($input['goal_uuid'])) {
                throw new Exception('Missing required field: goal_uuid');
            }

            $stmt = $db->prepare("
                SELECT * FROM api.update_category_goal(
                    p_goal_uuid := ?,
                    p_target_amount := ?,
                    p_target_date := ?,
                    p_repeat_frequency := ?
                )
            ");

            $stmt->execute([
                $input['goal_uuid'],
                isset($input['target_amount']) ? intval($input['target_amount']) : null,
                $input['target_date'] ?? null,
                $input['repeat_frequency'] ?? null
            ]);

            $goal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$goal) {
                throw new Exception('Failed to update goal');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Goal updated successfully',
                'goal' => $goal
            ]);
            break;

        case 'DELETE': // Delete goal
            if (empty($_GET['goal_uuid'])) {
                throw new Exception('Missing required parameter: goal_uuid');
            }

            $stmt = $db->prepare("SELECT api.delete_category_goal(?)");
            $stmt->execute([$_GET['goal_uuid']]);
            $result = $stmt->fetchColumn();

            if (!$result) {
                throw new Exception('Failed to delete goal');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Goal deleted successfully'
            ]);
            break;

        case 'GET': // Get goals
            if (isset($_GET['ledger_uuid'])) {
                // Get all goals for ledger
                $month = $_GET['month'] ?? date('Ym');

                $stmt = $db->prepare("SELECT * FROM api.get_ledger_goals(?, ?)");
                $stmt->execute([$_GET['ledger_uuid'], $month]);
                $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'goals' => $goals
                ]);
            } elseif (isset($_GET['goal_uuid'])) {
                // Get single goal status
                $month = $_GET['month'] ?? date('Ym');

                $stmt = $db->prepare("SELECT * FROM api.get_category_goal_status(?, ?)");
                $stmt->execute([$_GET['goal_uuid'], $month]);
                $goal = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$goal) {
                    throw new Exception('Goal not found');
                }

                echo json_encode([
                    'success' => true,
                    'goal' => $goal
                ]);
            } elseif (isset($_GET['underfunded']) && isset($_GET['ledger_uuid'])) {
                // Get underfunded goals
                $month = $_GET['month'] ?? date('Ym');

                $stmt = $db->prepare("SELECT * FROM api.get_underfunded_goals(?, ?)");
                $stmt->execute([$_GET['ledger_uuid'], $month]);
                $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'underfunded_goals' => $goals
                ]);
            } else {
                throw new Exception('Missing required parameters');
            }
            break;

        default:
            throw new Exception('Invalid request method');
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
