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

    if ($method === 'GET') {
        // Get parameters
        $action = $_GET['action'] ?? 'spending';
        $ledger_uuid = $_GET['ledger'] ?? '';
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        // Ledger UUID is required for spending, summary, and csv actions
        if (in_array($action, ['spending', 'summary', 'csv']) && empty($ledger_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ledger UUID is required']);
            exit;
        }

        if ($action === 'spending') {
            // Get spending by category
            if ($start_date && $end_date) {
                $stmt = $db->prepare("SELECT * FROM api.get_spending_by_category(?, ?::timestamptz, ?::timestamptz)");
                $stmt->execute([$ledger_uuid, $start_date, $end_date]);
            } else {
                // Default to last 30 days
                $stmt = $db->prepare("SELECT * FROM api.get_spending_by_category(?)");
                $stmt->execute([$ledger_uuid]);
            }
            $spending_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $spending_data
            ]);

        } elseif ($action === 'summary') {
            // Get spending summary
            if ($start_date && $end_date) {
                $stmt = $db->prepare("SELECT * FROM api.get_spending_summary(?, ?::timestamptz, ?::timestamptz)");
                $stmt->execute([$ledger_uuid, $start_date, $end_date]);
            } else {
                // Default to last 30 days
                $stmt = $db->prepare("SELECT * FROM api.get_spending_summary(?)");
                $stmt->execute([$ledger_uuid]);
            }
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'summary' => $summary
            ]);

        } elseif ($action === 'transactions') {
            // Get transactions for a specific category
            $category_uuid = $_GET['category'] ?? '';

            if (empty($category_uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Category UUID is required']);
                exit;
            }

            if ($start_date && $end_date) {
                $stmt = $db->prepare("SELECT * FROM api.get_category_transactions(?, ?::timestamptz, ?::timestamptz)");
                $stmt->execute([$category_uuid, $start_date, $end_date]);
            } else {
                // Default to last 30 days
                $stmt = $db->prepare("SELECT * FROM api.get_category_transactions(?)");
                $stmt->execute([$category_uuid]);
            }
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'transactions' => $transactions
            ]);

        } elseif ($action === 'csv') {
            // Export to CSV
            if ($start_date && $end_date) {
                $stmt = $db->prepare("SELECT * FROM api.get_spending_by_category(?, ?::timestamptz, ?::timestamptz)");
                $stmt->execute([$ledger_uuid, $start_date, $end_date]);
            } else {
                $stmt = $db->prepare("SELECT * FROM api.get_spending_by_category(?)");
                $stmt->execute([$ledger_uuid]);
            }
            $spending_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Set CSV headers
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="spending-by-category-' . date('Y-m-d') . '.csv"');

            // Output CSV
            $output = fopen('php://output', 'w');

            // Write header row
            if (!empty($spending_data)) {
                fputcsv($output, ['Category', 'Total Spent', 'Transaction Count', 'Percentage']);

                // Write data rows
                foreach ($spending_data as $row) {
                    fputcsv($output, [
                        $row['category_name'],
                        formatCurrency($row['total_spent']),
                        $row['transaction_count'],
                        $row['percentage'] . '%'
                    ]);
                }
            }

            fclose($output);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "spending", "summary", "transactions", or "csv"']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
