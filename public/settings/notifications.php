<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$successMessage = '';
$errorMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDbConnection();

        // Set user context
        $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
        $stmt->execute([$_SESSION['user_id']]);

        // Get checkbox values (unchecked checkboxes don't submit, so we default to false)
        $emailOnRecurring = isset($_POST['email_on_recurring_transaction']) ? 'true' : 'false';
        $emailDailySummary = isset($_POST['email_daily_summary']) ? 'true' : 'false';
        $emailWeeklySummary = isset($_POST['email_weekly_summary']) ? 'true' : 'false';
        $emailOnFailed = isset($_POST['email_on_recurring_transaction_failed']) ? 'true' : 'false';

        // Update preferences
        $stmt = $db->prepare("
            SELECT api.update_notification_preferences(?, ?, ?, ?)
        ");
        $stmt->execute([
            $emailOnRecurring,
            $emailDailySummary,
            $emailWeeklySummary,
            $emailOnFailed
        ]);

        $result = $stmt->fetch();

        if ($result && $result[0] === true) {
            $successMessage = 'Notification preferences updated successfully!';
        } else {
            $errorMessage = 'Failed to update notification preferences.';
        }
    } catch (PDOException $e) {
        $errorMessage = 'Database error: ' . $e->getMessage();
    }
}

// Load current preferences
try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get user info
    $stmt = $db->prepare("SELECT email, first_name, last_name FROM data.users WHERE username = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userInfo = $stmt->fetch();

    // Get notification preferences
    $stmt = $db->prepare("SELECT * FROM api.get_notification_preferences()");
    $stmt->execute();
    $preferences = $stmt->fetch();

    // Check if email is enabled in system
    $mailEnabled = ($_ENV['MAIL_ENABLED'] ?? 'false') === 'true';

} catch (PDOException $e) {
    $errorMessage = 'Database error: ' . $e->getMessage();
    $preferences = null;
    $userInfo = null;
    $mailEnabled = false;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="settings-header">
        <h1>Notification Settings</h1>
        <p>Manage your email notification preferences</p>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <span class="alert-icon">✓</span>
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert alert-error">
            <span class="alert-icon">✗</span>
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (!$mailEnabled): ?>
        <div class="alert alert-warning">
            <span class="alert-icon">⚠️</span>
            <strong>Email notifications are currently disabled.</strong><br>
            System administrators need to enable email in the configuration file to send notifications.
        </div>
    <?php endif; ?>

    <div class="settings-grid">
        <!-- Left Column: Settings Form -->
        <div class="settings-main">
            <div class="card">
                <div class="card-header">
                    <h2>Email Notifications</h2>
                    <?php if ($userInfo): ?>
                        <p class="email-display">
                            Notifications will be sent to: <strong><?= htmlspecialchars($userInfo['email']) ?></strong>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($preferences): ?>
                    <form method="POST" class="settings-form">
                        <div class="setting-group">
                            <div class="setting-item">
                                <div class="setting-info">
                                    <label class="setting-label">
                                        <input
                                            type="checkbox"
                                            name="email_on_recurring_transaction"
                                            value="1"
                                            <?= $preferences['email_on_recurring_transaction'] ? 'checked' : '' ?>
                                            class="setting-checkbox"
                                        >
                                        <span class="setting-title">Recurring Transaction Created</span>
                                    </label>
                                    <p class="setting-description">
                                        Receive an email each time a recurring transaction is automatically created.
                                        Includes transaction details and impact on your budget.
                                    </p>
                                </div>
                                <span class="setting-badge <?= $preferences['email_on_recurring_transaction'] ? 'badge-enabled' : 'badge-disabled' ?>">
                                    <?= $preferences['email_on_recurring_transaction'] ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </div>

                            <div class="setting-item">
                                <div class="setting-info">
                                    <label class="setting-label">
                                        <input
                                            type="checkbox"
                                            name="email_on_recurring_transaction_failed"
                                            value="1"
                                            <?= $preferences['email_on_recurring_transaction_failed'] ? 'checked' : '' ?>
                                            class="setting-checkbox"
                                        >
                                        <span class="setting-title">Recurring Transaction Failed</span>
                                    </label>
                                    <p class="setting-description">
                                        Get notified when a recurring transaction fails to be created.
                                        Includes error details and troubleshooting steps.
                                    </p>
                                </div>
                                <span class="setting-badge <?= $preferences['email_on_recurring_transaction_failed'] ? 'badge-enabled' : 'badge-disabled' ?>">
                                    <?= $preferences['email_on_recurring_transaction_failed'] ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </div>

                            <div class="setting-divider"></div>

                            <div class="setting-item">
                                <div class="setting-info">
                                    <label class="setting-label">
                                        <input
                                            type="checkbox"
                                            name="email_daily_summary"
                                            value="1"
                                            <?= $preferences['email_daily_summary'] ? 'checked' : '' ?>
                                            class="setting-checkbox"
                                        >
                                        <span class="setting-title">Daily Summary</span>
                                    </label>
                                    <p class="setting-description">
                                        Receive a daily summary of all recurring transactions created that day.
                                        <span class="badge-coming-soon">Coming Soon</span>
                                    </p>
                                </div>
                                <span class="setting-badge <?= $preferences['email_daily_summary'] ? 'badge-enabled' : 'badge-disabled' ?>">
                                    <?= $preferences['email_daily_summary'] ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </div>

                            <div class="setting-item">
                                <div class="setting-info">
                                    <label class="setting-label">
                                        <input
                                            type="checkbox"
                                            name="email_weekly_summary"
                                            value="1"
                                            <?= $preferences['email_weekly_summary'] ? 'checked' : '' ?>
                                            class="setting-checkbox"
                                        >
                                        <span class="setting-title">Weekly Summary</span>
                                    </label>
                                    <p class="setting-description">
                                        Receive a weekly digest of all recurring transactions created that week.
                                        <span class="badge-coming-soon">Coming Soon</span>
                                    </p>
                                </div>
                                <span class="setting-badge <?= $preferences['email_weekly_summary'] ? 'badge-enabled' : 'badge-disabled' ?>">
                                    <?= $preferences['email_weekly_summary'] ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <span class="btn-icon">💾</span>
                                Save Preferences
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="window.location.reload()">
                                Cancel
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-state">
                        <p>Unable to load notification preferences.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Info & Help -->
        <div class="settings-sidebar">
            <div class="card info-card">
                <h3>About Email Notifications</h3>
                <p>Email notifications help you stay informed about automatically created recurring transactions.</p>

                <div class="info-section">
                    <h4>📧 When You'll Receive Emails</h4>
                    <ul>
                        <li>When recurring transactions are auto-created</li>
                        <li>When transaction creation fails</li>
                        <li>Daily/weekly summaries (optional)</li>
                    </ul>
                </div>

                <div class="info-section">
                    <h4>🔒 Privacy & Control</h4>
                    <ul>
                        <li>You can disable any notification type</li>
                        <li>Your email is never shared</li>
                        <li>Unsubscribe at any time</li>
                    </ul>
                </div>

                <div class="info-section">
                    <h4>⚙️ Email Settings</h4>
                    <ul>
                        <li><strong>Email Address:</strong> <?= htmlspecialchars($userInfo['email'] ?? 'Not set') ?></li>
                        <li><strong>System Status:</strong> <?= $mailEnabled ? '<span class="status-enabled">Enabled</span>' : '<span class="status-disabled">Disabled</span>' ?></li>
                    </ul>
                </div>
            </div>

            <div class="card help-card">
                <h3>Need Help?</h3>
                <p>If you're not receiving emails:</p>
                <ol>
                    <li>Check your spam folder</li>
                    <li>Verify your email address is correct</li>
                    <li>Ensure system email is enabled</li>
                    <li>Contact support if issues persist</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}

.settings-header {
    margin-bottom: 2rem;
}

.settings-header h1 {
    margin: 0 0 0.5rem 0;
    color: #2d3748;
}

.settings-header p {
    margin: 0;
    color: #718096;
}

.settings-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.card-header {
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e2e8f0;
    background: #f7fafc;
}

.card-header h2 {
    margin: 0 0 0.5rem 0;
    color: #2d3748;
    font-size: 1.25rem;
}

.email-display {
    margin: 0;
    color: #718096;
    font-size: 0.875rem;
}

.settings-form {
    padding: 2rem;
}

.setting-group {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.setting-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.setting-info {
    flex: 1;
}

.setting-label {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
    margin-bottom: 0.5rem;
}

.setting-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: #3182ce;
}

.setting-title {
    font-weight: 600;
    font-size: 1rem;
    color: #2d3748;
}

.setting-description {
    margin: 0;
    color: #718096;
    font-size: 0.875rem;
    line-height: 1.5;
    padding-left: 2rem;
}

.setting-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
    letter-spacing: 0.05em;
}

.badge-enabled {
    background: #c6f6d5;
    color: #22543d;
}

.badge-disabled {
    background: #e2e8f0;
    color: #718096;
}

.badge-coming-soon {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    background: #fed7d7;
    color: #742a2a;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 0.5rem;
}

.setting-divider {
    height: 1px;
    background: #e2e8f0;
    margin: 0.5rem 0;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 1px solid #e2e8f0;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    font-size: 0.875rem;
}

.btn-primary {
    background: #3182ce;
    color: white;
}

.btn-primary:hover {
    background: #2c5aa0;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(49, 130, 206, 0.3);
}

.btn-secondary {
    background: #f7fafc;
    color: #4a5568;
    border: 1px solid #e2e8f0;
}

.btn-secondary:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.btn-icon {
    font-size: 1rem;
}

/* Sidebar Cards */
.settings-sidebar {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.info-card,
.help-card {
    padding: 1.5rem;
}

.info-card h3,
.help-card h3 {
    margin: 0 0 1rem 0;
    color: #2d3748;
    font-size: 1.125rem;
}

.info-card p,
.help-card p {
    margin: 0 0 1rem 0;
    color: #718096;
    font-size: 0.875rem;
    line-height: 1.6;
}

.info-section {
    margin-bottom: 1.5rem;
}

.info-section:last-child {
    margin-bottom: 0;
}

.info-section h4 {
    margin: 0 0 0.75rem 0;
    color: #2d3748;
    font-size: 0.875rem;
    font-weight: 600;
}

.info-section ul,
.help-card ol {
    margin: 0;
    padding-left: 1.5rem;
    color: #718096;
    font-size: 0.875rem;
    line-height: 1.6;
}

.info-section li,
.help-card li {
    margin-bottom: 0.5rem;
}

.status-enabled {
    color: #38a169;
    font-weight: 600;
}

.status-disabled {
    color: #e53e3e;
    font-weight: 600;
}

/* Alerts */
.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.875rem;
    line-height: 1.5;
}

.alert-icon {
    font-size: 1.25rem;
    flex-shrink: 0;
}

.alert-success {
    background: #c6f6d5;
    color: #22543d;
    border-left: 4px solid #38a169;
}

.alert-error {
    background: #fed7d7;
    color: #742a2a;
    border-left: 4px solid #e53e3e;
}

.alert-warning {
    background: #fefcbf;
    color: #744210;
    border-left: 4px solid #f59e0b;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #718096;
}

/* Responsive Design */
@media (max-width: 968px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }

    .setting-item {
        flex-direction: column;
        align-items: flex-start;
    }

    .setting-badge {
        align-self: flex-start;
        margin-left: 2rem;
    }
}

@media (max-width: 640px) {
    .container {
        padding: 1rem;
    }

    .card-header,
    .settings-form {
        padding: 1rem;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
// Update badge status when checkbox changes
document.querySelectorAll('.setting-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const settingItem = this.closest('.setting-item');
        const badge = settingItem.querySelector('.setting-badge');

        if (this.checked) {
            badge.textContent = 'Enabled';
            badge.classList.remove('badge-disabled');
            badge.classList.add('badge-enabled');
        } else {
            badge.textContent = 'Disabled';
            badge.classList.remove('badge-enabled');
            badge.classList.add('badge-disabled');
        }
    });
});

// Auto-dismiss success messages after 5 seconds
setTimeout(() => {
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        successAlert.style.transition = 'opacity 0.5s, transform 0.5s';
        successAlert.style.opacity = '0';
        successAlert.style.transform = 'translateY(-10px)';
        setTimeout(() => successAlert.remove(), 500);
    }
}, 5000);
</script>

<?php require_once '../../includes/footer.php'; ?>
