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
        $_SESSION['error'] = 'This account is not a credit card.';
        header('Location: ../accounts/list.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Get billing cycle config
    $stmt = $db->prepare("
        SELECT statement_day_of_month, due_date_offset_days
        FROM data.credit_card_limits
        WHERE credit_card_account_id = (SELECT id FROM data.accounts WHERE uuid = ?)
          AND user_data = utils.get_user() AND is_active = true
    ");
    $stmt->execute([$card_uuid]);
    $billing = $stmt->fetch();

    // Build virtual statements from transactions grouped by billing period
    $statements = [];
    $tx_by_period = [];
    if ($billing) {
        $stmt_day = (int)$billing['statement_day_of_month'];
        $due_offset = (int)$billing['due_date_offset_days'];

        $stmt = $db->prepare("
            WITH periods AS (
                SELECT
                    utils.calculate_next_statement_date(t.date, ?) AS closing_date,
                    SUM(t.amount)  AS purchases_cents,
                    COUNT(*)       AS transaction_count
                FROM data.transactions t
                WHERE t.credit_account_id = (SELECT id FROM data.accounts WHERE uuid = ?)
                  AND t.user_data = utils.get_user()
                GROUP BY closing_date
            ),
            ordered AS (
                SELECT *,
                    LAG(closing_date) OVER (ORDER BY closing_date) AS prev_closing
                FROM periods
            )
            SELECT
                closing_date,
                COALESCE(prev_closing + INTERVAL '1 day',
                         closing_date - INTERVAL '2 months') AS period_start,
                closing_date AS period_end,
                (closing_date + (? || ' days')::interval)::date AS due_date,
                purchases_cents,
                transaction_count,
                closing_date = utils.calculate_next_statement_date(CURRENT_DATE, ?) AS is_current
            FROM ordered
            ORDER BY closing_date DESC
        ");
        $stmt->execute([$stmt_day, $card_uuid, $due_offset, $stmt_day]);
        $statements = $stmt->fetchAll();

        // Fetch transactions with their period closing date for grouping
        $stmt = $db->prepare("
            SELECT
                utils.calculate_next_statement_date(t.date, ?) AS closing_date,
                t.date, t.description, t.amount
            FROM data.transactions t
            WHERE t.credit_account_id = (SELECT id FROM data.accounts WHERE uuid = ?)
              AND t.user_data = utils.get_user()
            ORDER BY closing_date, t.date DESC
        ");
        $stmt->execute([$stmt_day, $card_uuid]);
        foreach ($stmt->fetchAll() as $tx) {
            $tx_by_period[$tx['closing_date']][] = $tx;
        }
    }

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
                <p>No transactions found for this card.</p>
                <?php if (!$billing): ?>
                    <p>Configure billing cycle settings to see statements.</p>
                    <a href="settings.php?ledger=<?= urlencode($ledger_uuid) ?>&card=<?= urlencode($card_uuid) ?>" class="btn btn-primary">
                        Configure Billing Settings
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="statements-list">
                <?php foreach ($statements as $statement): ?>
                    <?php
                    $today   = new DateTime();
                    $dueDate = new DateTime($statement['due_date']);
                    $diff    = $today->diff($dueDate);
                    $isPastDue = $dueDate < $today;
                    $daysUntilDue = (int)$diff->days;
                    $txs = $tx_by_period[$statement['closing_date']] ?? [];
                    ?>
                    <div class="statement-card <?= $statement['is_current'] === 't' ? 'current-statement' : '' ?>">
                        <div class="statement-header">
                            <div class="statement-period">
                                <?= date('M j, Y', strtotime($statement['period_start'])) ?> –
                                <?= date('M j, Y', strtotime($statement['period_end'])) ?>
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
                                <span class="summary-label">Purchases</span>
                                <span class="summary-value negative"><?= formatCurrency($statement['purchases_cents']) ?></span>
                            </div>
                            <div class="statement-summary-item">
                                <span class="summary-label"># Transactions</span>
                                <span class="summary-value"><?= $statement['transaction_count'] ?></span>
                            </div>
                            <div class="statement-summary-item">
                                <span class="summary-label">Payment Due</span>
                                <span class="summary-value"><?= date('M j, Y', strtotime($statement['due_date'])) ?></span>
                            </div>
                            <div class="statement-summary-item">
                                <span class="summary-label">Status</span>
                                <span class="summary-value">
                                    <?php if ($isPastDue): ?>
                                        <span style="color:#e53e3e;">⚠️ Past Due</span>
                                    <?php elseif ($daysUntilDue <= 7): ?>
                                        <span style="color:#dd6b20;">Due in <?= $daysUntilDue ?> day<?= $daysUntilDue !== 1 ? 's' : '' ?></span>
                                    <?php else: ?>
                                        <span style="color:#48bb78;">Due in <?= $daysUntilDue ?> days</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($txs)): ?>
                        <div class="statement-detail-section">
                            <h4>Transactions</h4>
                            <?php foreach ($txs as $tx): ?>
                                <div class="transaction-summary-row">
                                    <span><?= date('M j', strtotime($tx['date'])) ?> — <?= htmlspecialchars($tx['description']) ?></span>
                                    <strong><?= formatCurrency($tx['amount']) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Include payment scheduling modal -->
<script src="../js/schedule-payment-modal.js"></script>

<?php require_once '../../includes/footer.php'; ?>
