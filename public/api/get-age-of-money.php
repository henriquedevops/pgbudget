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
        // Get parameters
        $action = $_GET['action'] ?? 'current';
        $ledger_uuid = $_GET['ledger'] ?? '';
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 90;

        if (empty($ledger_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ledger UUID is required']);
            exit;
        }

        if ($action === 'current') {
            // Get current Age of Money
            $stmt = $db->prepare("SELECT * FROM api.get_current_age_of_money(?)");
            $stmt->execute([$ledger_uuid]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                echo json_encode([
                    'success' => true,
                    'current' => [
                        'age_days' => 0,
                        'calculation_date' => date('Y-m-d'),
                        'transaction_count' => 0,
                        'status' => 'needs_improvement',
                        'status_message' => 'Not enough transaction data to calculate Age of Money.'
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'current' => $current
                ]);
            }

        } elseif ($action === 'trend') {
            // Get Age of Money trend over time
            $stmt = $db->prepare("SELECT * FROM api.get_age_of_money_over_time(?, ?)");
            $stmt->execute([$ledger_uuid, $days]);
            $trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $trend_data
            ]);

        } elseif ($action === 'csv') {
            // Export trend to CSV
            $stmt = $db->prepare("SELECT * FROM api.get_age_of_money_over_time(?, ?)");
            $stmt->execute([$ledger_uuid, $days]);
            $trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Set CSV headers
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="age-of-money-' . date('Y-m-d') . '.csv"');

            // Output CSV
            $output = fopen('php://output', 'w');

            // Write header row
            if (!empty($trend_data)) {
                fputcsv($output, ['Date', 'Age of Money (Days)', 'Transaction Count']);

                // Write data rows
                foreach ($trend_data as $row) {
                    fputcsv($output, [
                        $row['calculation_date'],
                        $row['age_days'],
                        $row['transaction_count']
                    ]);
                }
            }

            fclose($output);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "current", "trend", or "csv"']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET.']);
    }

} catch (PDOException $e) {
    error_log('Age of Money PDO Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Exception $e) {
    error_log('Age of Money Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
