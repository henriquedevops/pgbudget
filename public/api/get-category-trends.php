<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../includes/session.php';
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
        // Get parameters
        $action = $_GET['action'] ?? 'trend';
        $category_uuid = $_GET['category'] ?? '';
        $months = isset($_GET['months']) ? (int)$_GET['months'] : 12;

        if (empty($category_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Category UUID is required']);
            exit;
        }

        if ($action === 'trend') {
            // Get spending trend for a category
            $stmt = $db->prepare("SELECT * FROM api.get_category_spending_trend(?, ?)");
            $stmt->execute([$category_uuid, $months]);
            $trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $trend_data
            ]);

        } elseif ($action === 'statistics') {
            // Get trend statistics
            $stmt = $db->prepare("SELECT * FROM api.get_category_trend_statistics(?, ?)");
            $stmt->execute([$category_uuid, $months]);
            $statistics = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'statistics' => $statistics
            ]);

        } elseif ($action === 'compare') {
            // Get multi-category comparison
            $ledger_uuid = $_GET['ledger'] ?? '';
            $category_uuids = $_GET['categories'] ?? '';

            if (empty($ledger_uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Ledger UUID is required for comparison']);
                exit;
            }

            if (empty($category_uuids)) {
                http_response_code(400);
                echo json_encode(['error' => 'Category UUIDs are required for comparison']);
                exit;
            }

            // Parse category UUIDs (comma-separated)
            $category_array = explode(',', $category_uuids);
            $category_array = array_map('trim', $category_array);

            // Convert to PostgreSQL array format
            $pg_array = '{' . implode(',', $category_array) . '}';

            $stmt = $db->prepare("SELECT * FROM api.get_multi_category_trends(?, ?::text[], ?)");
            $stmt->execute([$ledger_uuid, $pg_array, $months]);
            $comparison_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $comparison_data
            ]);

        } elseif ($action === 'csv') {
            // Export to CSV
            $stmt = $db->prepare("SELECT * FROM api.get_category_spending_trend(?, ?)");
            $stmt->execute([$category_uuid, $months]);
            $trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get category name for filename
            $stmt = $db->prepare("SELECT name FROM data.accounts WHERE uuid = ? AND user_data = current_setting('app.current_user_id')");
            $stmt->execute([$category_uuid]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            $category_name = $category['name'] ?? 'category';
            $filename = 'category-trend-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($category_name)) . '-' . date('Y-m-d') . '.csv';

            // Set CSV headers
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            // Output CSV
            $output = fopen('php://output', 'w');

            // Write header row
            if (!empty($trend_data)) {
                fputcsv($output, ['Month', 'Actual Spending', 'Budgeted Amount', 'Difference', '% of Budget']);

                // Write data rows
                foreach ($trend_data as $row) {
                    fputcsv($output, [
                        $row['month_name'],
                        formatCurrency($row['actual_spending']),
                        formatCurrency($row['budgeted_amount']),
                        formatCurrency($row['difference']),
                        $row['percent_of_budget'] . '%'
                    ]);
                }
            }

            fclose($output);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "trend", "statistics", "compare", or "csv"']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET.']);
    }

} catch (PDOException $e) {
    error_log('Category Trends PDO Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Exception $e) {
    error_log('Category Trends Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
