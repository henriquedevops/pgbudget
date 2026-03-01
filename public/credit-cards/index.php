<?php
require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    // Get first ledger if none specified
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $stmt = $db->prepare("SELECT uuid FROM api.ledgers LIMIT 1");
    $stmt->execute();
    $first_ledger = $stmt->fetch();

    if ($first_ledger) {
        header('Location: index.php?ledger=' . urlencode($first_ledger['uuid']));
        exit;
    } else {
        $_SESSION['error'] = 'No budget found.';
        header('Location: ../index.php');
        exit;
    }
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

    // Get all credit card accounts with their limits and balances
    // Only include liability accounts that are specifically marked as credit cards
    $stmt = $db->prepare("
        SELECT
            cc.uuid as account_uuid,
            cc.name as account_name,
            ccl.uuid as limit_uuid,
            ccl.credit_limit,
            ccl.annual_percentage_rate,
            ccl.warning_threshold_percent,
            ccl.auto_payment_enabled,
            ccl.auto_payment_type,
            ccl.is_active,
            ABS(COALESCE(utils.get_account_balance(
                (SELECT id FROM data.ledgers WHERE uuid = ?),
                cc.id
            ), 0)) as current_balance
        FROM data.accounts cc
        LEFT JOIN data.credit_card_limits ccl ON ccl.credit_card_account_id = cc.id AND ccl.is_active = true
        WHERE cc.ledger_id = (SELECT id FROM data.ledgers WHERE uuid = ?)
        AND cc.type = 'liability'
        AND utils.is_credit_card(cc.id) = true
        ORDER BY cc.name
    ");
    $stmt->execute([$ledger_uuid, $ledger_uuid]);
    $credit_cards = $stmt->fetchAll();

    // Calculate statistics
    $total_balances = 0;
    $total_limits = 0;
    $cards_with_limits = 0;
    $cards_near_limit = 0;

    $card_summaries = [];
    foreach ($credit_cards as $card) {
        $balance = floatval($card['current_balance']);
        $limit = floatval($card['credit_limit'] ?? 0);

        $has_limit = $limit > 0;
        $utilization_percent = $has_limit ? ($balance / $limit * 100) : 0;

        $status = 'no_limit';
        if ($has_limit) {
            $cards_with_limits++;
            $total_limits += $limit;

            if ($utilization_percent >= 100) {
                $status = 'over_limit';
                $cards_near_limit++;
            } elseif ($utilization_percent >= 95) {
                $status = 'critical';
                $cards_near_limit++;
            } elseif ($utilization_percent >= floatval($card['warning_threshold_percent'] ?? 80)) {
                $status = 'warning';
                $cards_near_limit++;
            } else {
                $status = 'good';
            }
        }

        $total_balances += $balance;

        $card_summaries[] = [
            'account_uuid' => $card['account_uuid'],
            'account_name' => $card['account_name'],
            'limit_uuid' => $card['limit_uuid'],
            'current_balance' => $balance,
            'credit_limit' => $limit,
            'has_limit' => $has_limit,
            'available_credit' => max(0, $limit - $balance),
            'utilization_percent' => $utilization_percent,
            'status' => $status,
            'apr' => floatval($card['annual_percentage_rate'] ?? 0),
            'auto_payment_enabled' => $card['auto_payment_enabled'] === 't',
            'auto_payment_type' => $card['auto_payment_type']
        ];
    }

    $overall_utilization = $total_limits > 0 ? ($total_balances / $total_limits * 100) : 0;

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
}

require_once '../../includes/header.php';
?>

<link rel="stylesheet" href="../css/credit-cards.css">

<div class="container">
    <div class="page-header">
        <h1>💳 Credit Card Management</h1>
        <div class="page-actions">
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="../accounts/create.php?ledger=<?= urlencode($ledger_uuid) ?>&type=liability" class="btn btn-primary">+ Add Credit Card</a>
        </div>
    </div>

    <?php if (!empty($card_summaries)): ?>
        <div class="settings-section">
            <h3>Overview</h3>
            <div class="credit-summary-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div class="credit-stat">
                    <span class="credit-stat-label">Total Cards</span>
                    <span class="credit-stat-value"><?= count($card_summaries) ?></span>
                </div>
                <div class="credit-stat">
                    <span class="credit-stat-label">Cards with Limits</span>
                    <span class="credit-stat-value"><?= $cards_with_limits ?></span>
                </div>
                <div class="credit-stat">
                    <span class="credit-stat-label">Total Balance</span>
                    <span class="credit-stat-value amount negative"><?= formatCurrency($total_balances) ?></span>
                </div>
                <div class="credit-stat">
                    <span class="credit-stat-label">Total Credit Available</span>
                    <span class="credit-stat-value amount"><?= formatCurrency($total_limits) ?></span>
                </div>
                <div class="credit-stat">
                    <span class="credit-stat-label">Overall Utilization</span>
                    <span class="credit-stat-value <?= $overall_utilization >= 80 ? 'warning' : '' ?>">
                        <?= $cards_with_limits > 0 ? number_format($overall_utilization, 1) . '%' : 'N/A' ?>
                    </span>
                </div>
                <?php if ($cards_near_limit > 0): ?>
                    <div class="credit-stat" style="border-left-color: #f56565;">
                        <span class="credit-stat-label">⚠️ Cards Near Limit</span>
                        <span class="credit-stat-value" style="color: #e53e3e;"><?= $cards_near_limit ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="settings-section">
            <h3>Your Credit Cards</h3>
            <div class="credit-cards-grid">
                <?php foreach ($card_summaries as $card): ?>
                    <div class="credit-card-card">
                        <div class="credit-card-header">
                            <div class="credit-card-name"><?= htmlspecialchars($card['account_name']) ?></div>
                            <div class="credit-card-chip"></div>
                        </div>

                        <div class="credit-card-balance-section">
                            <div class="balance-label">Current Balance</div>
                            <div class="balance-amount"><?= formatCurrency($card['current_balance']) ?></div>

                            <?php if ($card['has_limit']): ?>
                                <div class="credit-limit-info">
                                    Available: <?= formatCurrency($card['available_credit']) ?>
                                    of <?= formatCurrency($card['credit_limit']) ?>
                                </div>

                                <div class="utilization-bar" style="margin-top: 1rem;">
                                    <div class="utilization-bar-fill <?= $card['status'] ?>"
                                         style="width: <?= min(100, $card['utilization_percent']) ?>%"></div>
                                </div>
                            <?php else: ?>
                                <div class="credit-limit-info" style="color: rgba(255,255,255,0.8);">
                                    No limit configured
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="credit-card-footer">
                            <div class="card-utilization">
                                <?php if ($card['has_limit']): ?>
                                    <?= number_format($card['utilization_percent'], 1) ?>% used
                                    <?php if ($card['apr'] > 0): ?>
                                        <br><small><?= number_format($card['apr'], 2) ?>% APR</small>
                                    <?php endif; ?>
                                    <?php if ($card['auto_payment_enabled']): ?>
                                        <br><small>🤖 Auto-pay enabled</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <small>Configure limit →</small>
                                <?php endif; ?>
                            </div>
                            <div class="card-quick-actions">
                                <a href="settings.php?ledger=<?= urlencode($ledger_uuid) ?>&card=<?= urlencode($card['account_uuid']) ?>"
                                   class="icon-btn" title="Settings">⚙️</a>
                                <a href="statements.php?ledger=<?= urlencode($ledger_uuid) ?>&card=<?= urlencode($card['account_uuid']) ?>"
                                   class="icon-btn" title="Statements">📄</a>
                                <button type="button"
                                        class="icon-btn"
                                        title="Schedule Payment"
                                        onclick="openSchedulePaymentModal('<?= htmlspecialchars($card['account_uuid']) ?>', '<?= htmlspecialchars($card['account_name']) ?>')">
                                    💵
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="settings-section">
            <div class="empty-state" style="text-align: center; padding: 3rem;">
                <div style="font-size: 4rem; margin-bottom: 1rem;">💳</div>
                <h3>No Credit Cards Yet</h3>
                <p>Add your first credit card to start managing limits, tracking statements, and scheduling payments.</p>
                <a href="../accounts/create.php?ledger=<?= urlencode($ledger_uuid) ?>&type=liability" class="btn btn-primary" style="margin-top: 1rem;">
                    + Add Your First Credit Card
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Include payment scheduling modal -->
<script src="../js/schedule-payment-modal.js"></script>

<?php require_once '../../includes/footer.php'; ?>
