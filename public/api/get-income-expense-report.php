<?php
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
        $action = $_GET['action'] ?? 'monthly';
        $ledger_uuid = $_GET['ledger'] ?? '';
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        if (empty($ledger_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ledger UUID is required']);
            exit;
        }

        if ($action === 'monthly') {
            // Get monthly income vs expense data
            if ($start_date && $end_date) {
                $stmt = $db->prepare("SELECT * FROM api.get_income_vs_expense_by_month(?, ?::timestamptz, ?::timestamptz)");
                $stmt->execute([$ledger_uuid, $start_date, $end_date]);
            } else {
                // Default to last 12 months
                $stmt = $db->prepare("SELECT * FROM api.get_income_vs_expense_by_month(?)");
                $stmt->execute([$ledger_uuid]);
            }
            $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $monthly_data
            ]);

        } elseif ($action === 'summary') {
            // Get summary statistics
            if ($start_date && $end_date) {
                $stmt = $db->prepare("SELECT * FROM api.get_income_expense_summary(?, ?::timestamptz, ?::timestamptz)");
                $stmt->execute([$ledger_uuid, $start_date, $end_date]);
            } else {
                // Default to last 12 months
                $stmt = $db->prepare("SELECT * FROM api.get_income_expense_summary(?)");
                $stmt->execute([$ledger_uuid]);
            }
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'summary' => $summary
            ]);

        } elseif ($action === 'csv') {
            // Export to CSV
            if ($start_date && $end_date) {
                $stmt = $db->prepare("SELECT * FROM api.get_income_vs_expense_by_month(?, ?::timestamptz, ?::timestamptz)");
                $stmt->execute([$ledger_uuid, $start_date, $end_date]);
            } else {
                $stmt = $db->prepare("SELECT * FROM api.get_income_vs_expense_by_month(?)");
                $stmt->execute([$ledger_uuid]);
            }
            $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Set CSV headers
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="income-vs-expense-' . date('Y-m-d') . '.csv"');

            // Output CSV
            $output = fopen('php://output', 'w');

            // Write header row
            if (!empty($monthly_data)) {
                fputcsv($output, ['Month', 'Income', 'Expense', 'Net', 'Savings Rate %']);

                // Write data rows
                foreach ($monthly_data as $row) {
                    fputcsv($output, [
                        $row['month_name'],
                        formatCurrency($row['total_income']),
                        formatCurrency($row['total_expense']),
                        formatCurrency($row['net']),
                        $row['savings_rate'] . '%'
                    ]);
                }
            }

            fclose($output);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "monthly", "summary", or "csv"']);
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
