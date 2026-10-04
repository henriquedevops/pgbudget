/**
 * pgbEscapeHtml — escape text before interpolating it into HTML strings
 * (innerHTML, template literals, attribute values).
 *
 * Names, descriptions, payees and memos are stored raw in the database, so any
 * value coming from the DB/API must go through this before building markup.
 * Prefer textContent when building DOM nodes directly.
 */
(function () {
    var MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    window.pgbEscapeHtml = function (value) {
        return value == null ? '' : String(value).replace(/[&<>"']/g, function (c) { return MAP[c]; });
    };
})();
