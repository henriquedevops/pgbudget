<?php
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth(false);

// Set PostgreSQL user context
$db = getDbConnection();
setUserContext($db);

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get all budget templates
    $stmt = $db->prepare("SELECT uuid, name, description, target_audience, categories FROM api.budget_templates ORDER BY name");
    $stmt->execute();
    
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode JSON categories for each template
    foreach ($templates as &$template) {
        if (isset($template['categories'])) {
            $template['categories'] = json_decode($template['categories'], true);
        }
    }
    
    echo json_encode($templates);
} catch (PDOException $e) {
    error_log("Error fetching templates: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
