<?php

// Set timezone to GMT-3 (America/Sao_Paulo - Brazil)
date_default_timezone_set('America/Sao_Paulo');

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
}

function getDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        // Get database configuration from environment variables
        $host = $_ENV['DB_HOST'] ?? null;
        $port = $_ENV['DB_PORT'] ?? '5432';
        $dbname = $_ENV['DB_NAME'] ?? null;
        $username = $_ENV['DB_USER'] ?? null;
        $password = $_ENV['DB_PASSWORD'] ?? null;

        // Validate required environment variables
        if (!$host || !$dbname || !$username || !$password) {
            die("Database configuration missing. Please check your .env file contains DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD.");
        }

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
    // Remove currency symbols, keeping only digits, comma, and period
    $amount = preg_replace('/[^0-9,.-]/', '', $amount);

    // Handle both comma and period as decimal separators
    // If both exist, the last one is the decimal separator
    $commaPos = strrpos($amount, ',');
    $periodPos = strrpos($amount, '.');

    if ($commaPos !== false && $periodPos !== false) {
        if ($commaPos > $periodPos) {
            // Comma is decimal separator, remove periods
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        } else {
            // Period is decimal separator, remove commas
            $amount = str_replace(',', '', $amount);
        }
    } else if ($commaPos !== false) {
        // Only comma exists, treat as decimal separator
        $amount = str_replace(',', '.', $amount);
    }
    // If only period exists, leave as is

    // Convert to float and then to cents (multiply by 100)
    return intval(floatval($amount) * 100);
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>