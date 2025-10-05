<?php
/**
 * Helper Functions
 * Common utility functions used across the application
 *
 * Note: sanitizeInput(), formatCurrency(), and parseCurrency()
 * are already defined in config/database.php
 */

/**
 * Parse currency string to cents (integer)
 * Alias for parseCurrency() from database.php for better naming
 * Accepts formats: 10, 10.50, 10,50, $10.50, etc.
 *
 * @param string $amount Currency string
 * @return int Amount in cents
 */
function parseCurrencyToCents($amount) {
    // Use the existing parseCurrency function from database.php
    return parseCurrency($amount);
}

/**
 * Validate date format (YYYY-MM-DD)
 *
 * @param string $date Date string
 * @return bool True if valid
 */
function isValidDate($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parts = explode('-', $date);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

/**
 * Generate a safe redirect URL
 * Prevents open redirect vulnerabilities
 *
 * @param string $url URL to redirect to
 * @param string $default Default URL if validation fails
 * @return string Safe redirect URL
 */
function getSafeRedirectUrl($url, $default = '/') {
    // Only allow relative URLs or URLs from the same host
    $parsed = parse_url($url);

    if (!isset($parsed['host'])) {
        // Relative URL, safe to use
        return $url;
    }

    if ($parsed['host'] === $_SERVER['HTTP_HOST']) {
        // Same host, safe to use
        return $url;
    }

    // Different host, use default
    return $default;
}

/**
 * Get period label from YYYYMM format
 *
 * @param string $period Period in YYYYMM format (e.g., "202510")
 * @return string Formatted period label (e.g., "October 2025")
 */
function getPeriodLabel($period) {
    if (empty($period) || strlen($period) !== 6) {
        return 'All Time';
    }

    $year = substr($period, 0, 4);
    $month = substr($period, 4, 2);

    $date = DateTime::createFromFormat('Y-m', $year . '-' . $month);
    if (!$date) {
        return 'All Time';
    }

    return $date->format('F Y');
}

/**
 * Validate UUID format (nanoid or standard UUID)
 *
 * @param string $uuid UUID to validate
 * @return bool True if valid
 */
function isValidUuid($uuid) {
    if (empty($uuid)) {
        return false;
    }

    // Accept nanoid format (8 characters, alphanumeric)
    if (preg_match('/^[a-zA-Z0-9]{8}$/', $uuid)) {
        return true;
    }

    // Accept standard UUID format
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) {
        return true;
    }

    return false;
}

/**
 * Get transaction type label
 *
 * @param string $type Transaction type (inflow/outflow)
 * @return string Label
 */
function getTransactionTypeLabel($type) {
    switch ($type) {
        case 'inflow':
            return 'Income';
        case 'outflow':
            return 'Expense';
        default:
            return ucfirst($type);
    }
}

/**
 * Generate breadcrumb navigation
 *
 * @param array $items Breadcrumb items [['label' => '', 'url' => ''], ...]
 * @return string HTML breadcrumb
 */
function generateBreadcrumb($items) {
    if (empty($items)) {
        return '';
    }

    $html = '<nav class="breadcrumb"><ol>';

    foreach ($items as $index => $item) {
        $isLast = ($index === count($items) - 1);

        if ($isLast) {
            $html .= '<li class="breadcrumb-item active">' . htmlspecialchars($item['label']) . '</li>';
        } else {
            $url = htmlspecialchars($item['url']);
            $label = htmlspecialchars($item['label']);
            $html .= '<li class="breadcrumb-item"><a href="' . $url . '">' . $label . '</a></li>';
        }
    }

    $html .= '</ol></nav>';

    return $html;
}

/**
 * Flash message helpers
 */

/**
 * Set a flash message
 *
 * @param string $type Message type (success, error, warning, info)
 * @param string $message Message content
 */
function setFlashMessage($type, $message) {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }

    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash messages
 *
 * @return array Flash messages
 */
function getFlashMessages() {
    if (!isset($_SESSION['flash_messages'])) {
        return [];
    }

    $messages = $_SESSION['flash_messages'];
    unset($_SESSION['flash_messages']);

    return $messages;
}

/**
 * Display flash messages HTML
 *
 * @return string HTML for flash messages
 */
function displayFlashMessages() {
    $messages = getFlashMessages();

    if (empty($messages)) {
        return '';
    }

    $html = '';
    foreach ($messages as $msg) {
        $type = htmlspecialchars($msg['type']);
        $message = htmlspecialchars($msg['message']);
        $html .= '<div class="alert alert-' . $type . '">' . $message . '</div>';
    }

    return $html;
}
