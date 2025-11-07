<?php
require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$card_uuid = $_GET['card'] ?? '';

if (empty($ledger_uuid) || empty($card_uuid)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Get credit card account details
    $stmt = $db->prepare("SELECT * FROM api.accounts WHERE uuid = ? AND ledger_uuid = ? AND type = 'liability'");
    $stmt->execute([$card_uuid, $ledger_uuid]);
    $card = $stmt->fetch();

    if (!$card) {
        $_SESSION['error'] = 'Credit card not found.';
        header('Location: ../budget/dashboard.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Verify this is actually a credit card (not just any liability)
    $stmt = $db->prepare("SELECT utils.is_credit_card((SELECT id FROM data.accounts WHERE uuid = ?))");
    $stmt->execute([$card_uuid]);
    $is_credit_card = $stmt->fetchColumn();

    if (!$is_credit_card) {
        $_SESSION['error'] = 'This account is not a credit card. Only credit card accounts can have credit limits configured.';
        header('Location: ../accounts/list.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Get current credit card limits (if exists)
    $stmt = $db->prepare("
        SELECT ccl.*,
               ABS(COALESCE(utils.get_account_balance(
                   (SELECT id FROM data.ledgers WHERE uuid = ?),
                   (SELECT id FROM data.accounts WHERE uuid = ?)
               ), 0)) as current_balance
        FROM data.credit_card_limits ccl
        WHERE ccl.credit_card_account_id = (SELECT id FROM data.accounts WHERE uuid = ?)
        AND ccl.is_active = true
        LIMIT 1
    ");
    $stmt->execute([$ledger_uuid, $card_uuid, $card_uuid]);
    $current_limit = $stmt->fetch();

    // Calculate utilization if limit exists
    $utilization_percent = 0;
    if ($current_limit) {
        $balance = floatval($current_limit['current_balance']);
        $limit = floatval($current_limit['credit_limit']);
        $utilization_percent = $limit > 0 ? ($balance / $limit * 100) : 0;
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $credit_limit = parseCurrency($_POST['credit_limit']);
        $apr = floatval($_POST['annual_percentage_rate']);
        $warning_threshold = intval($_POST['warning_threshold_percent']);
        $interest_type = sanitizeInput($_POST['interest_type']);
        $compounding_frequency = sanitizeInput($_POST['compounding_frequency']);
        $statement_day = intval($_POST['statement_day_of_month']);
        $due_date_offset = intval($_POST['due_date_offset_days']);
        $grace_period = intval($_POST['grace_period_days']);
        $min_payment_percent = floatval($_POST['minimum_payment_percent']);
        $min_payment_flat = parseCurrency($_POST['minimum_payment_flat']);
        $auto_payment_enabled = isset($_POST['auto_payment_enabled']) ? 't' : 'f';
        $auto_payment_type = sanitizeInput($_POST['auto_payment_type'] ?? null);
        $auto_payment_amount = !empty($_POST['auto_payment_amount']) ? parseCurrency($_POST['auto_payment_amount']) : null;
        $auto_payment_date = !empty($_POST['auto_payment_date']) ? intval($_POST['auto_payment_date']) : null;

        // API call to set/update credit card limit
        $stmt = $db->prepare("
            SELECT api.set_credit_card_limit(
                p_account_uuid := ?,
                p_credit_limit := ?,
                p_annual_percentage_rate := ?,
                p_warning_threshold_percent := ?,
                p_interest_type := ?,
                p_compounding_frequency := ?,
                p_statement_day_of_month := ?,
                p_due_date_offset_days := ?,
                p_grace_period_days := ?,
                p_minimum_payment_percent := ?,
                p_minimum_payment_flat := ?,
                p_auto_payment_enabled := ?,
                p_auto_payment_type := ?,
                p_auto_payment_amount := ?,
                p_auto_payment_date := ?
            ) as uuid
        ");

        $stmt->execute([
            $card_uuid,
            $credit_limit,
            $apr,
            $warning_threshold,
            $interest_type,
            $compounding_frequency,
            $statement_day,
            $due_date_offset,
            $grace_period,
            $min_payment_percent,
            $min_payment_flat,
            $auto_payment_enabled,
            $auto_payment_type,
            $auto_payment_amount,
            $auto_payment_date
        ]);

        $_SESSION['success'] = 'Credit card settings updated successfully!';
        header('Location: settings.php?ledger=' . urlencode($ledger_uuid) . '&card=' . urlencode($card_uuid));
        exit;
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
}

require_once '../../includes/header.php';
?>

<link rel="stylesheet" href="../css/credit-cards.css">

<div class="container credit-card-settings-container">
    <div class="page-header">
        <h1>💳 <?= htmlspecialchars($card['name']) ?> - Settings</h1>
        <div class="page-actions">
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="statements.php?ledger=<?= urlencode($ledger_uuid) ?>&card=<?= urlencode($card_uuid) ?>" class="btn btn-secondary">📄 View Statements</a>
        </div>
    </div>

    <?php if ($current_limit): ?>
        <div class="settings-section">
            <h3>Current Status</h3>
            <div class="current-limit-display">
                <div class="limit-display-row">
                    <span class="limit-display-label">Current Balance:</span>
                    <span class="limit-display-value amount negative"><?= formatCurrency($current_limit['current_balance']) ?></span>
                </div>
                <div class="limit-display-row">
                    <span class="limit-display-label">Credit Limit:</span>
                    <span class="limit-display-value"><?= formatCurrency($current_limit['credit_limit']) ?></span>
                </div>
                <div class="limit-display-row">
                    <span class="limit-display-label">Available Credit:</span>
                    <span class="limit-display-value amount positive"><?= formatCurrency(max(0, floatval($current_limit['credit_limit']) - floatval($current_limit['current_balance']))) ?></span>
                </div>

                <div class="utilization-bar-container">
                    <div class="utilization-bar">
                        <div class="utilization-bar-fill <?= $utilization_percent >= 80 ? 'critical' : ($utilization_percent >= 50 ? 'high' : ($utilization_percent >= 30 ? 'medium' : 'low')) ?>"
                             style="width: <?= min(100, $utilization_percent) ?>%"></div>
                    </div>
                    <div style="text-align: center; color: #718096; font-size: 0.875rem; font-weight: 600;">
                        <?= number_format($utilization_percent, 1) ?>% Utilization
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" class="settings-form">
        <div class="settings-section">
            <h3>Credit Limit Settings</h3>

            <div class="form-grid">
                <div class="form-group">
                    <label for="credit_limit">Credit Limit *</label>
                    <input type="text" id="credit_limit" name="credit_limit" class="form-control"
                           value="<?= $current_limit ? formatCurrency($current_limit['credit_limit']) : '' ?>" required>
                    <small class="form-hint">Maximum credit limit for this card</small>
                </div>

                <div class="form-group">
                    <label for="warning_threshold_percent">Warning Threshold (%) *</label>
                    <input type="number" id="warning_threshold_percent" name="warning_threshold_percent" class="form-control"
                           min="1" max="99" value="<?= $current_limit ? intval($current_limit['warning_threshold_percent']) : 80 ?>" required>
                    <small class="form-hint">Alert when utilization reaches this percentage</small>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <h3>Interest & APR Settings</h3>

            <div class="form-grid">
                <div class="form-group">
                    <label for="annual_percentage_rate">Annual Percentage Rate (APR) *</label>
                    <input type="number" id="annual_percentage_rate" name="annual_percentage_rate" class="form-control"
                           min="0" max="100" step="0.01" value="<?= $current_limit ? number_format(floatval($current_limit['annual_percentage_rate']), 2) : '0.00' ?>" required>
                    <small class="form-hint">Annual interest rate (e.g., 18.99 for 18.99%)</small>
                </div>

                <div class="form-group">
                    <label for="interest_type">Interest Type *</label>
                    <select id="interest_type" name="interest_type" class="form-control" required>
                        <option value="fixed" <?= $current_limit && $current_limit['interest_type'] === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                        <option value="variable" <?= !$current_limit || $current_limit['interest_type'] === 'variable' ? 'selected' : '' ?>>Variable</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="compounding_frequency">Compounding Frequency *</label>
                    <select id="compounding_frequency" name="compounding_frequency" class="form-control" required>
                        <option value="daily" <?= !$current_limit || $current_limit['compounding_frequency'] === 'daily' ? 'selected' : '' ?>>Daily</option>
                        <option value="monthly" <?= $current_limit && $current_limit['compounding_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <h3>Billing Cycle Settings</h3>

            <div class="form-grid">
                <div class="form-group">
                    <label for="statement_day_of_month">Statement Day of Month *</label>
                    <input type="number" id="statement_day_of_month" name="statement_day_of_month" class="form-control"
                           min="1" max="31" value="<?= $current_limit ? intval($current_limit['statement_day_of_month']) : 1 ?>" required>
                    <small class="form-hint">Day of month when statements are generated (1-31)</small>
                </div>

                <div class="form-group">
                    <label for="due_date_offset_days">Due Date Offset (Days) *</label>
                    <input type="number" id="due_date_offset_days" name="due_date_offset_days" class="form-control"
                           min="1" max="60" value="<?= $current_limit ? intval($current_limit['due_date_offset_days']) : 21 ?>" required>
                    <small class="form-hint">Days after statement date until payment is due</small>
                </div>

                <div class="form-group">
                    <label for="grace_period_days">Grace Period (Days)</label>
                    <input type="number" id="grace_period_days" name="grace_period_days" class="form-control"
                           min="0" max="30" value="<?= $current_limit ? intval($current_limit['grace_period_days']) : 0 ?>">
                    <small class="form-hint">Days after due date before late fees apply</small>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <h3>Minimum Payment Settings</h3>

            <div class="form-grid">
                <div class="form-group">
                    <label for="minimum_payment_percent">Minimum Payment Percentage *</label>
                    <input type="number" id="minimum_payment_percent" name="minimum_payment_percent" class="form-control"
                           min="0.1" max="100" step="0.1" value="<?= $current_limit ? number_format(floatval($current_limit['minimum_payment_percent']), 1) : '1.0' ?>" required>
                    <small class="form-hint">Percentage of balance for minimum payment</small>
                </div>

                <div class="form-group">
                    <label for="minimum_payment_flat">Minimum Payment Floor *</label>
                    <input type="text" id="minimum_payment_flat" name="minimum_payment_flat" class="form-control"
                           value="<?= $current_limit ? formatCurrency($current_limit['minimum_payment_flat']) : '$25.00' ?>" required>
                    <small class="form-hint">Minimum payment amount (floor)</small>
                </div>
            </div>
        </div>

        <div class="settings-section">
            <h3>Auto-Payment Settings</h3>

            <div class="checkbox-group">
                <input type="checkbox" id="auto_payment_enabled" name="auto_payment_enabled"
                       <?= $current_limit && $current_limit['auto_payment_enabled'] === 't' ? 'checked' : '' ?>
                       onchange="toggleAutoPaymentSettings()">
                <label for="auto_payment_enabled">Enable Automatic Payments</label>
            </div>

            <div id="auto-payment-settings-container" class="auto-payment-settings"
                 style="display: <?= $current_limit && $current_limit['auto_payment_enabled'] === 't' ? 'block' : 'none' ?>;">
                <h4>Auto-Payment Configuration</h4>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="auto_payment_type">Payment Type</label>
                        <select id="auto_payment_type" name="auto_payment_type" class="form-control" onchange="toggleAutoPaymentAmount()">
                            <option value="minimum" <?= $current_limit && $current_limit['auto_payment_type'] === 'minimum' ? 'selected' : '' ?>>Minimum Payment</option>
                            <option value="full_balance" <?= $current_limit && $current_limit['auto_payment_type'] === 'full_balance' ? 'selected' : '' ?>>Full Balance</option>
                            <option value="fixed_amount" <?= $current_limit && $current_limit['auto_payment_type'] === 'fixed_amount' ? 'selected' : '' ?>>Fixed Amount</option>
                        </select>
                    </div>

                    <div class="form-group" id="auto-payment-amount-group"
                         style="display: <?= $current_limit && $current_limit['auto_payment_type'] === 'fixed_amount' ? 'block' : 'none' ?>;">
                        <label for="auto_payment_amount">Fixed Payment Amount</label>
                        <input type="text" id="auto_payment_amount" name="auto_payment_amount" class="form-control"
                               value="<?= $current_limit && $current_limit['auto_payment_amount'] ? formatCurrency($current_limit['auto_payment_amount']) : '' ?>">
                    </div>

                    <div class="form-group">
                        <label for="auto_payment_date">Payment Date (Day of Month)</label>
                        <input type="number" id="auto_payment_date" name="auto_payment_date" class="form-control"
                               min="1" max="31" value="<?= $current_limit && $current_limit['auto_payment_date'] ? intval($current_limit['auto_payment_date']) : '' ?>" placeholder="Use due date if blank">
                        <small class="form-hint">Leave blank to use statement due date</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">💾 Save Settings</button>
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
function toggleAutoPaymentSettings() {
    const enabled = document.getElementById('auto_payment_enabled').checked;
    const container = document.getElementById('auto-payment-settings-container');
    container.style.display = enabled ? 'block' : 'none';
}

function toggleAutoPaymentAmount() {
    const type = document.getElementById('auto_payment_type').value;
    const amountGroup = document.getElementById('auto-payment-amount-group');
    amountGroup.style.display = (type === 'fixed_amount') ? 'block' : 'none';
}
</script>

<?php require_once '../../includes/footer.php'; ?>
