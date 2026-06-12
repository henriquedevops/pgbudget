/**
 * Shared currency formatting for the ledger's configured currency.
 *
 * window.PGB_CURRENCY is injected by includes/header.php from the ledger's
 * metadata. Falls back to USD when the page has no ledger context.
 */
(function () {
    'use strict';

    var FALLBACK = { code: 'USD', symbol: '$', decimal: '.', thousands: ',', position: 'before', space: false };

    function config() {
        return window.PGB_CURRENCY || FALLBACK;
    }

    function formatNumber(value, cfg) {
        var fixed = Math.abs(value).toFixed(2);
        var parts = fixed.split('.');
        var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousands);
        return intPart + cfg.decimal + parts[1];
    }

    /**
     * Format an amount in cents as a currency string, e.g. -123456 → "-R$ 1.234,56".
     */
    window.pgbFormatCurrency = function (cents) {
        var cfg = config();
        var sign = cents < 0 ? '-' : '';
        var number = formatNumber(cents / 100, cfg);
        var sep = cfg.space ? ' ' : '';
        return cfg.position === 'after'
            ? sign + number + sep + cfg.symbol
            : sign + cfg.symbol + sep + number;
    };

    /**
     * Same as pgbFormatCurrency but takes a decimal amount (dollars/reais), not cents.
     */
    window.pgbFormatAmount = function (amount) {
        return window.pgbFormatCurrency(Math.round(amount * 100));
    };

    /**
     * Bare number for <input> editing: no symbol, no thousands separator,
     * locale decimal separator (e.g. 123456 → "1234,56" for BRL).
     */
    window.pgbFormatCurrencyInput = function (cents) {
        var cfg = config();
        var sign = cents < 0 ? '-' : '';
        return sign + Math.abs(cents / 100).toFixed(2).replace('.', cfg.decimal);
    };

    /** The currency symbol, for templates that build strings manually. */
    window.pgbCurrencySymbol = function () {
        var cfg = config();
        return cfg.symbol + (cfg.space ? ' ' : '');
    };
})();
