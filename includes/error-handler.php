<?php
// Enhanced error handling and user feedback system

/**
 * Set a success message in the session
 */
function setSuccessMessage($message) {
    $_SESSION['success'] = $message;
}

/**
 * Set an error message in the session
 */
function setErrorMessage($message) {
    $_SESSION['error'] = $message;
}

/**
 * Set a warning message in the session
 */
function setWarningMessage($message) {
    $_SESSION['warning'] = $message;
}

/**
 * Set an info message in the session
 */
function setInfoMessage($message) {
    $_SESSION['info'] = $message;
}

/**
 * Get and clear all messages from the session
 */
function getMessages() {
    $messages = [
        'success' => $_SESSION['success'] ?? null,
        'error' => $_SESSION['error'] ?? null,
        'warning' => $_SESSION['warning'] ?? null,
        'info' => $_SESSION['info'] ?? null
    ];

    // Clear messages after retrieving them
    unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning'], $_SESSION['info']);

    return array_filter($messages); // Remove null values
}

/**
 * Display messages HTML
 */
function displayMessages() {
    $messages = getMessages();
    if (empty($messages)) {
        return '';
    }

    $html = '<div class="messages-container">';

    foreach ($messages as $type => $message) {
        $icon = getMessageIcon($type);
        $html .= sprintf(
            '<div class="message message-%s" role="alert">
                <div class="message-content">
                    <span class="message-icon">%s</span>
                    <span class="message-text">%s</span>
                </div>
                <button type="button" class="message-close" onclick="this.parentElement.remove()" aria-label="Close">×</button>
            </div>',
            $type,
            $icon,
            htmlspecialchars($message)
        );
    }

    $html .= '</div>';
    return $html;
}

/**
 * Get icon for message type
 */
function getMessageIcon($type) {
    $icons = [
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️'
    ];
    return $icons[$type] ?? 'ℹ️';
}

/**
 * Handle database errors with user-friendly messages
 */
function handleDatabaseError($e, $context = 'operation') {
    $message = $e->getMessage();

    // Log the actual error for debugging
    error_log("Database error in $context: " . $message);

    // Provide user-friendly error messages
    if (strpos($message, 'not found') !== false) {
        setErrorMessage("The requested item was not found or you don't have permission to access it.");
    } elseif (strpos($message, 'unique') !== false) {
        setErrorMessage("This item already exists. Please choose a different name or value.");
    } elseif (strpos($message, 'foreign key') !== false) {
        setErrorMessage("Cannot complete this action because it would affect related data.");
    } elseif (strpos($message, 'check constraint') !== false) {
        setErrorMessage("The provided data doesn't meet the required criteria. Please check your input.");
    } else {
        setErrorMessage("An unexpected error occurred. Please try again or contact support if the problem persists.");
    }
}

/**
 * Validate form input with enhanced feedback
 */
function validateRequired($value, $fieldName) {
    if (empty(trim($value))) {
        setErrorMessage("$fieldName is required and cannot be empty.");
        return false;
    }
    return true;
}

/**
 * Validate currency amount
 */
function validateCurrency($amount, $fieldName = 'Amount') {
    if (empty($amount)) {
        setErrorMessage("$fieldName is required.");
        return false;
    }

    if (!is_numeric(str_replace(['$', ','], '', $amount))) {
        setErrorMessage("$fieldName must be a valid number.");
        return false;
    }

    $numericAmount = floatval(str_replace(['$', ','], '', $amount));
    if ($numericAmount < 0) {
        setErrorMessage("$fieldName cannot be negative.");
        return false;
    }

    if ($numericAmount > 999999999.99) {
        setErrorMessage("$fieldName is too large. Maximum allowed is $999,999,999.99.");
        return false;
    }

    return true;
}

/**
 * Validate date input
 */
function validateDate($date, $fieldName = 'Date') {
    if (empty($date)) {
        setErrorMessage("$fieldName is required.");
        return false;
    }

    $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        setErrorMessage("$fieldName must be a valid date in YYYY-MM-DD format.");
        return false;
    }

    // Check if date is not too far in the future (more than 1 year)
    $oneYearFromNow = new DateTime('+1 year');
    if ($parsedDate > $oneYearFromNow) {
        setErrorMessage("$fieldName cannot be more than one year in the future.");
        return false;
    }

    // Check if date is not too far in the past (more than 10 years)
    $tenYearsAgo = new DateTime('-10 years');
    if ($parsedDate < $tenYearsAgo) {
        setErrorMessage("$fieldName cannot be more than 10 years in the past.");
        return false;
    }

    return true;
}

/**
 * Validate string length
 */
function validateLength($value, $fieldName, $maxLength = 255, $minLength = 1) {
    $length = strlen($value);

    if ($length < $minLength) {
        setErrorMessage("$fieldName must be at least $minLength character(s) long.");
        return false;
    }

    if ($length > $maxLength) {
        setErrorMessage("$fieldName cannot be longer than $maxLength characters.");
        return false;
    }

    return true;
}

/**
 * Sanitize and validate user input comprehensively
 */
function sanitizeAndValidate($input, $fieldName, $type = 'text', $maxLength = 255) {
    $input = trim($input);

    // Basic required validation
    if (!validateRequired($input, $fieldName)) {
        return false;
    }

    // Type-specific validation
    switch ($type) {
        case 'currency':
            if (!validateCurrency($input, $fieldName)) {
                return false;
            }
            break;

        case 'date':
            if (!validateDate($input, $fieldName)) {
                return false;
            }
            break;

        case 'text':
        default:
            if (!validateLength($input, $fieldName, $maxLength)) {
                return false;
            }
            break;
    }

    return sanitizeInput($input);
}

/**
 * Check user permissions for resource access
 */
function checkResourceAccess($resource_uuid, $resource_type, $db) {
    try {
        switch ($resource_type) {
            case 'ledger':
                $stmt = $db->prepare("SELECT uuid FROM api.ledgers WHERE uuid = ?");
                break;
            case 'account':
                $stmt = $db->prepare("SELECT uuid FROM api.accounts WHERE uuid = ?");
                break;
            case 'transaction':
                $stmt = $db->prepare("
                    SELECT t.uuid
                    FROM data.transactions t
                    WHERE t.uuid = ? AND t.user_data = utils.get_user()
                ");
                break;
            default:
                setErrorMessage("Invalid resource type specified.");
                return false;
        }

        $stmt->execute([$resource_uuid]);
        $result = $stmt->fetch();

        if (!$result) {
            setErrorMessage("The requested $resource_type was not found or you don't have permission to access it.");
            return false;
        }

        return true;

    } catch (Exception $e) {
        handleDatabaseError($e, "checking $resource_type access");
        return false;
    }
}

/**
 * Safe redirect with message preservation
 */
function safeRedirect($url, $message = null, $type = 'info') {
    if ($message) {
        switch ($type) {
            case 'success':
                setSuccessMessage($message);
                break;
            case 'error':
                setErrorMessage($message);
                break;
            case 'warning':
                setWarningMessage($message);
                break;
            default:
                setInfoMessage($message);
                break;
        }
    }

    header('Location: ' . $url);
    exit;
}

/**
 * Validate CSRF token (basic implementation)
 */
function validateCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            setErrorMessage("Security validation failed. Please try again.");
            return false;
        }
    }
    return true;
}

/**
 * Generate CSRF token
 */
function generateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Format validation errors for JSON responses
 */
function getValidationErrors() {
    $messages = getMessages();
    return isset($messages['error']) ? [$messages['error']] : [];
}
?>