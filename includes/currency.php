<?php
/**
 * Per-ledger currency configuration and formatting.
 *
 * The ledger's currency code lives in api.ledgers.metadata->>'currency'.
 * Pages resolve it lazily from the ?ledger= URL parameter; contexts that
 * know the ledger up front (emails, cron, APIs) can call pgb_set_currency().
 */

function pgb_currencies() {
    return [
        'USD' => ['symbol' => '$',  'decimal' => '.', 'thousands' => ',', 'position' => 'before', 'space' => false, 'label' => 'US Dollar ($)'],
        'BRL' => ['symbol' => 'R$', 'decimal' => ',', 'thousands' => '.', 'position' => 'before', 'space' => true,  'label' => 'Real Brasileiro (R$)'],
        'EUR' => ['symbol' => '€',  'decimal' => ',', 'thousands' => '.', 'position' => 'before', 'space' => false, 'label' => 'Euro (€)'],
        'GBP' => ['symbol' => '£',  'decimal' => '.', 'thousands' => ',', 'position' => 'before', 'space' => false, 'label' => 'British Pound (£)'],
    ];
}

/**
 * Override the currency for the rest of the request (e.g. emails/cron that
 * know the ledger). Invalid codes are ignored.
 */
function pgb_set_currency($code) {
    if (isset(pgb_currencies()[$code])) {
        $GLOBALS['pgb_currency_code'] = $code;
    }
}

/**
 * Currency code for the current request: explicit override, else the
 * ?ledger= budget's metadata, else USD.
 */
function pgb_current_currency() {
    if (isset($GLOBALS['pgb_currency_code'])) {
        return $GLOBALS['pgb_currency_code'];
    }

    $code = 'USD';
    $ledger_uuid = $_GET['ledger'] ?? $_POST['ledger_uuid'] ?? $_GET['ledger_uuid'] ?? null;
    if ($ledger_uuid && function_exists('getDbConnection')) {
        try {
            $db = getDbConnection();
            $stmt = $db->prepare("SELECT metadata->>'currency' FROM api.ledgers WHERE uuid = ?");
            $stmt->execute([$ledger_uuid]);
            $stored = $stmt->fetchColumn();
            if ($stored && isset(pgb_currencies()[$stored])) {
                $code = $stored;
            }
        } catch (Exception $e) {
            // No DB or no user context yet — keep the default
        }
    }

    $GLOBALS['pgb_currency_code'] = $code;
    return $code;
}

function pgb_currency_config($code = null) {
    $currencies = pgb_currencies();
    $code = ($code && isset($currencies[$code])) ? $code : pgb_current_currency();
    return ['code' => $code] + $currencies[$code];
}

/**
 * Format an amount in cents (bigint) using the ledger's currency.
 */
function formatCurrency($cents, $currency = null) {
    $cfg = pgb_currency_config($currency);
    $sign = $cents < 0 ? '-' : '';
    $number = number_format(abs($cents) / 100, 2, $cfg['decimal'], $cfg['thousands']);
    $sep = $cfg['space'] ? ' ' : '';
    return $cfg['position'] === 'after'
        ? $sign . $number . $sep . $cfg['symbol']
        : $sign . $cfg['symbol'] . $sep . $number;
}

/**
 * Format loan amounts which are stored as numeric decimals (not cents).
 * Used for loans table which stores amounts as numeric(19,4)
 */
function formatLoanAmount($amount, $currency = null) {
    return formatCurrency((int) round($amount * 100), $currency);
}
