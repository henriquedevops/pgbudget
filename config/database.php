<?php
session_start();

function getDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        $host = $_ENV['DB_HOST'] ?? '191.252.195.118';
        $port = $_ENV['DB_PORT'] ?? '5432';
        $dbname = $_ENV['DB_NAME'] ?? 'pgbudget';
        $username = $_ENV['DB_USER'] ?? 'pgbudget';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    return $pdo;
}

function formatCurrency($cents) {
    return '$' . number_format($cents / 100, 2);
}

function parseCurrency($amount) {
    // Remove currency symbols and convert to cents
    $amount = preg_replace('/[^0-9.-]/', '', $amount);
    return intval(floatval($amount) * 100);
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>