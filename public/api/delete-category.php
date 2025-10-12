<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        // Check deletion impact (preview)
        $input = json_decode(file_get_contents('php://input'), true);
        $category_uuid = $input['category_uuid'] ?? '';

        if (empty($category_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Category UUID is required']);
            exit;
        }

        // Check if this is a preview or actual deletion
        $action = $input['action'] ?? 'preview';

        if ($action === 'preview') {
            // Get deletion impact
            $stmt = $db->prepare("SELECT * FROM api.check_category_deletion_impact(?)");
            $stmt->execute([$category_uuid]);
            $impact = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$impact) {
                http_response_code(404);
                echo json_encode(['error' => 'Category not found or not accessible']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'impact' => $impact
            ]);
        } elseif ($action === 'delete') {
            // Perform actual deletion
            $reassign_to_uuid = $input['reassign_to_category_uuid'] ?? null;

            $stmt = $db->prepare("SELECT api.delete_category(?, ?)");
            $stmt->execute([$category_uuid, $reassign_to_uuid]);
            $result = $stmt->fetchColumn();

            $result_data = json_decode($result, true);

            echo json_encode([
                'success' => true,
                'result' => $result_data
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "preview" or "delete"']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
