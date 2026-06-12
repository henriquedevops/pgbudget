<?php
// Right rail — monthly stats + recent activity.
// Requires: $db, $current_ledger (ledger UUID), $_SESSION['user_id']

$rail_period = date('Y-m');
$rail_totals  = null;
$rail_recent  = [];

try {
    $stmt = $db->prepare("SELECT * FROM api.get_budget_totals($1, $2)");
    $stmt->execute([$current_ledger, $rail_period]);
    $rail_totals = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Rail is non-critical — fail silently
}

try {
    $stmt = $db->prepare("
        SELECT t.date,
               t.description,
               t.amount,
               CASE WHEN da.name = 'Income' THEN 'inflow' ELSE 'outflow' END AS type
        FROM data.transactions t
        JOIN data.accounts da ON t.debit_account_id = da.id
        JOIN data.ledgers l ON t.ledger_id = l.id
        WHERE l.uuid = $1
          AND t.deleted_at IS NULL
        ORDER BY t.date DESC, t.id DESC
        LIMIT 5
    ");
    $stmt->execute([$current_ledger]);
    $rail_recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fail silently
}

$rail_month_label = date('F');
?>
<aside class="app-rail">
    <!-- Month at a glance -->
    <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-3);">
            <h3 class="card-title" style="font-size:var(--text-base);margin:0;"><?= htmlspecialchars($rail_month_label) ?> at a glance</h3>
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--space-2);">
            <?php if ($rail_totals): ?>
            <div class="rail-stat">
                <div class="rail-stat-label">Income</div>
                <div class="rail-stat-value tnum money pos">$<?= number_format($rail_totals['income'] / 100, 2) ?></div>
            </div>
            <div class="rail-stat">
                <div class="rail-stat-label">Budgeted</div>
                <div class="rail-stat-value tnum">$<?= number_format($rail_totals['budgeted'] / 100, 2) ?></div>
            </div>
            <div class="rail-stat primary">
                <div class="rail-stat-label">Left to Budget</div>
                <div class="rail-stat-value tnum"><?php
                    $ltb = $rail_totals['left_to_budget'] ?? 0;
                    echo ($ltb >= 0 ? '' : '-') . formatCurrency(abs($ltb));
                ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($rail_recent)): ?>
    <!-- Recent activity -->
    <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-3);">
            <h3 class="card-title" style="font-size:var(--text-base);margin:0;">Recent activity</h3>
            <a href="/pgbudget/transactions/list.php?ledger=<?= urlencode($current_ledger) ?>" class="btn btn-ghost btn-sm">View</a>
        </div>
        <div>
            <?php foreach ($rail_recent as $rt): ?>
            <div class="rail-activity-row">
                <div class="cat-icon" style="width:30px;height:30px;font-size:13px;flex-shrink:0;">
                    <?= htmlspecialchars(strtoupper(substr($rt['description'] ?: '?', 0, 1))) ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:var(--text-sm);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($rt['description'] ?: '—') ?>
                    </div>
                    <div style="font-size:var(--text-xs);color:var(--color-fg-muted);">
                        <?= htmlspecialchars($rt['date']) ?>
                    </div>
                </div>
                <?php
                $rv = $rt['type'] === 'inflow' ? (int)$rt['amount'] : -(int)$rt['amount'];
                $rv_class = $rv > 0 ? 'pos' : ($rv < 0 ? 'neg' : 'zero');
                ?>
                <span class="money <?= $rv_class ?> tnum" style="font-size:var(--text-sm);flex-shrink:0;">
                    <?= ($rv >= 0 ? '' : '-') . formatCurrency(abs($rv)) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="banner info" style="flex-direction:column;gap:var(--space-2);">
        <div style="font-weight:600;">💡 Tip</div>
        <div style="font-size:var(--text-xs);line-height:1.5;">
            Use <strong>Quick Add</strong> to log transactions from any page. The budget updates instantly.
        </div>
        <?php if (!empty($current_ledger)): ?>
        <button class="btn btn-primary btn-sm" style="align-self:flex-start;" onclick="QuickAddModal.open();return false;">Quick Add</button>
        <?php endif; ?>
    </div>
</aside>
