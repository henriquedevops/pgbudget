<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

    if ($method === 'GET') {
        // Get categories organized by groups
        $ledger_uuid = $_GET['ledger'] ?? '';

        if (empty($ledger_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ledger UUID is required']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM api.get_categories_by_group(?)");
        $stmt->execute([$ledger_uuid]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'categories' => $categories
        ]);

    } elseif ($method === 'POST') {
        // Create a new category group
        $data = json_decode(file_get_contents('php://input'), true);

        $ledger_uuid = $data['ledger_uuid'] ?? '';
        $group_name = $data['group_name'] ?? '';
        $sort_order = $data['sort_order'] ?? 0;

        if (empty($ledger_uuid) || empty($group_name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ledger UUID and group name are required']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM api.create_category_group(?, ?, ?)");
        $stmt->execute([$ledger_uuid, $group_name, $sort_order]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'group' => $group
        ]);

    } elseif ($method === 'PUT') {
        // Update category group assignment or sort order
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';

        if ($action === 'assign') {
            // Assign category to group
            $category_uuid = $data['category_uuid'] ?? '';
            $group_uuid = $data['group_uuid'] ?? null;

            if (empty($category_uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Category UUID is required']);
                exit;
            }

            $stmt = $db->prepare("SELECT api.assign_category_to_group(?, ?)");
            $stmt->execute([$category_uuid, $group_uuid]);

            echo json_encode([
                'success' => true,
                'message' => 'Category assignment updated'
            ]);

        } elseif ($action === 'sort') {
            // Update sort order
            $category_uuid = $data['category_uuid'] ?? '';
            $sort_order = $data['sort_order'] ?? 0;

            if (empty($category_uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Category UUID is required']);
                exit;
            }

            $stmt = $db->prepare("SELECT api.update_category_sort_order(?, ?)");
            $stmt->execute([$category_uuid, $sort_order]);

            echo json_encode([
                'success' => true,
                'message' => 'Sort order updated'
            ]);

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "assign" or "sort"']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET, POST, or PUT.']);
    }

} catch (PDOException $e) {
    error_log('Category Groups PDO Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Category Groups Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
