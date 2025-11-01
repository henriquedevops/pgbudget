<?php
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
        $_SESSION['error'] = 'This account is not a credit card.';
        header('Location: ../accounts/list.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Get all statements for this credit card
    $stmt = $db->prepare("
        SELECT
            st.uuid,
            st.statement_period_start,
            st.statement_period_end,
            st.previous_balance,
            st.purchases_amount,
            st.payments_amount,
            st.interest_charged,
            st.fees_charged,
            st.ending_balance,
            st.minimum_payment_due,
            st.due_date,
            st.is_current,
            st.created_at
        FROM data.credit_card_statements st
        WHERE st.credit_card_account_id = (SELECT id FROM data.accounts WHERE uuid = ?)
        ORDER BY st.statement_period_end DESC
    ");
    $stmt->execute([$card_uuid]);
    $statements = $stmt->fetchAll();

    // Get scheduled payments for this card
    $stmt = $db->prepare("
        SELECT
            sp.uuid,
            sp.scheduled_date,
            sp.payment_type,
            sp.payment_amount,
            sp.status,
            sp.actual_amount_paid,
            st.uuid as statement_uuid,
            ba.name as bank_account_name
        FROM data.scheduled_payments sp
        LEFT JOIN data.credit_card_statements st ON sp.statement_id = st.id
        LEFT JOIN data.accounts ba ON sp.bank_account_id = ba.id
        WHERE sp.credit_card_account_id = (SELECT id FROM data.accounts WHERE uuid = ?)
        ORDER BY sp.scheduled_date DESC
        LIMIT 10
    ");
    $stmt->execute([$card_uuid]);
    $scheduled_payments = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
}

require_once '../../includes/header.php';
?>

<link rel="stylesheet" href="../css/credit-cards.css">

<div class="container statements-container">
    <div class="page-header">
        <h1>📄 <?= htmlspecialchars($card['name']) ?> - Statements</h1>
        <div class="page-actions">
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="settings.php?ledger=<?= urlencode($ledger_uuid) ?>&card=<?= urlencode($card_uuid) ?>" class="btn btn-secondary">⚙️ Settings</a>
            <button type="button" class="btn btn-primary" onclick="openSchedulePaymentModal('<?= htmlspecialchars($card_uuid) ?>', '<?= htmlspecialchars($card['name']) ?>')">
                💵 Schedule Payment
            </button>
        </div>
    </div>

    <?php if (!empty($scheduled_payments)): ?>
        <div class="settings-section">
            <h3>Scheduled Payments</h3>
            <div class="scheduled-payments-list">
                <?php foreach ($scheduled_payments as $payment): ?>
                    <div class="upcoming-cc-payment-item status-<?= $payment['status'] ?>">
                        <div class="payment-info">
                            <div class="payment-type">
                                <?= ucfirst(str_replace('_', ' ', $payment['payment_type'])) ?>
                                <?php if ($payment['payment_amount']): ?>
                                    - <?= formatCurrency($payment['payment_amount']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="payment-date">
                                Scheduled: <?= date('F j, Y', strtotime($payment['scheduled_date'])) ?>
                            </div>
                            <?php if ($payment['bank_account_name']): ?>
                                <div class="payment-date">From: <?= htmlspecialchars($payment['bank_account_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="payment-status">
                            <span class="status-badge status-<?= $payment['status'] ?>">
                                <?= ucfirst($payment['status']) ?>
                            </span>
                            <?php if ($payment['actual_amount_paid']): ?>
                                <div class="payment-amount"><?= formatCurrency($payment['actual_amount_paid']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="settings-section">
        <h3>Statement History</h3>

        <?php if (empty($statements)): ?>
            <div class="empty-state">
                <p>No statements generated yet for this card.</p>
                <p>Statements are automatically generated based on your billing cycle settings.</p>
                <a href="settings.php?ledger=<?= urlencode($ledger_uuid) ?>&card=<?= urlencode($card_uuid) ?>" class="btn btn-primary">
                    Configure Billing Settings
                </a>
            </div>
        <?php else: ?>
            <div class="statements-list">
                <?php foreach ($statements as $statement): ?>
                    <div class="statement-card <?= $statement['is_current'] === 't' ? 'current-statement' : '' ?>">
                        <div class="statement-header">
                            <div class="statement-period">
                                <?= date('M j, Y', strtotime($statement['statement_period_start'])) ?> -
                                <?= date('M j, Y', strtotime($statement['statement_period_end'])) ?>
                            </div>
                            <div class="statement-badges">
                                <?php if ($statement['is_current'] === 't'): ?>
                                    <span class="statement-status-badge current">Current Statement</span>
                                <?php else: ?>
                                    <span class="statement-status-badge past">Past Statement</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="statement-summary">
                            <div class="statement-summary-item">
                                <span class="summary-label">Previous Balance</span>
                                <span class="summary-value amount"><?= formatCurrency($statement['previous_balance']) ?></span>
                            </div>
                            <div class="statement-summary-item">
                                <span class="summary-label">Purchases</span>
                                <span class="summary-value negative"><?= formatCurrency($statement['purchases_amount']) ?></span>
                            </div>
                            <div class="statement-summary-item">
                                <span class="summary-label">Payments</span>
                                <span class="summary-value positive"><?= formatCurrency($statement['payments_amount']) ?></span>
                            </div>
                            <?php if (floatval($statement['interest_charged']) > 0): ?>
                                <div class="statement-summary-item">
                                    <span class="summary-label">Interest Charged</span>
                                    <span class="summary-value negative"><?= formatCurrency($statement['interest_charged']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (floatval($statement['fees_charged']) > 0): ?>
                                <div class="statement-summary-item">
                                    <span class="summary-label">Fees Charged</span>
                                    <span class="summary-value negative"><?= formatCurrency($statement['fees_charged']) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="statement-summary-item">
                                <span class="summary-label">Ending Balance</span>
                                <span class="summary-value amount negative"><?= formatCurrency($statement['ending_balance']) ?></span>
                            </div>
                        </div>

                        <div class="statement-detail-section">
                            <h4>Payment Information</h4>
                            <div class="transaction-summary-row">
                                <span>Minimum Payment Due:</span>
                                <strong><?= formatCurrency($statement['minimum_payment_due']) ?></strong>
                            </div>
                            <div class="transaction-summary-row">
                                <span>Payment Due Date:</span>
                                <strong><?= date('F j, Y', strtotime($statement['due_date'])) ?></strong>
                            </div>
                            <?php
                            $today = new DateTime();
                            $dueDate = new DateTime($statement['due_date']);
                            $daysUntilDue = $today->diff($dueDate)->days;
                            $isPastDue = $dueDate < $today;
                            ?>
                            <div class="transaction-summary-row">
                                <span>Status:</span>
                                <?php if ($isPastDue): ?>
                                    <strong style="color: #e53e3e;">⚠️ Past Due</strong>
                                <?php elseif ($daysUntilDue <= 7): ?>
                                    <strong style="color: #dd6b20;">Due in <?= $daysUntilDue ?> day<?= $daysUntilDue > 1 ? 's' : '' ?></strong>
                                <?php else: ?>
                                    <strong style="color: #48bb78;">Due in <?= $daysUntilDue ?> days</strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="statement-actions">
                            <button type="button" class="btn btn-primary btn-small"
                                    onclick="openSchedulePaymentModal('<?= htmlspecialchars($card_uuid) ?>', '<?= htmlspecialchars($card['name']) ?>')">
                                💵 Schedule Payment
                            </button>
                            <a href="../transactions/list.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($card_uuid) ?>&start=<?= $statement['statement_period_start'] ?>&end=<?= $statement['statement_period_end'] ?>"
                               class="btn btn-secondary btn-small">
                                📊 View Transactions
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Include payment scheduling modal -->
<script src="../js/schedule-payment-modal.js"></script>

<?php require_once '../../includes/footer.php'; ?>
