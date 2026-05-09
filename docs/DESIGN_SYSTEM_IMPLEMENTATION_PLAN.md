# Design System Implementation Plan

**Source:** `public/ui_kits/web/` (React prototype from claude.ai/design)  
**Date:** 2026-05-09  
**Status:** Phase 1 complete — foundation CSS done. Phases 2–5 pending.

---

## What Was Done (Phase 1)

- Added ~60 design tokens to `core.css` `:root`: spacing scale (`--space-*`), shadow scale (`--shadow-e*`), motion tokens (`--duration-*`, `--ease-out`), font tokens (`--font-sans`, `--font-mono`, `--font-feature-tnum`), semantic fg aliases (`--color-fg`, `--color-fg-muted`), money semantics (`--color-money-positive/negative/zero`), `--color-primary-700/900`, `--color-bg-page`, `--color-focus-ring`
- Rewrote `.btn` base to `inline-flex` with token-driven transitions
- Fixed `.btn-secondary` (was using muted text color as background — now surface+border pattern)
- Added `.btn-ghost`, `.btn-sm`, `.btn-icon`
- Added component classes: `.card`, `.card-head`, `.card-title`, `.hero-card`, `.eyebrow`, `.money`/`.pos`/`.neg`/`.zero`, `.tnum`, `.badge` (5 variants), `.banner` (4 variants), `.cat-icon`, `.seg-toggle`/`.seg-btn`, `.bar`/`.over`/`.under`
- Extended dark mode overrides for all new tokens and components
- Fixed `style.css` stale primary color (`#2b6cb0` → `#2563eb`) and replaced all hardcoded blue hex values with tokens
- Deployed UI kit prototype to `public/ui_kits/web/` (React, static, no backend)
- Added `public/colors_and_type.css` — extended token file for design tooling

---

## Remaining Work

### Phase 2 — Layout Shell

**Goal:** Add left sidebar + right rail to the main app shell, matching the 3-column design.

The design shows:
```
┌────────────┬──────────────────────────┬──────────────┐
│  Sidebar   │          Main            │     Rail     │
│  240 px    │          1fr             │    320 px    │
│  sticky    │                          │   sticky     │
└────────────┴──────────────────────────┴──────────────┘
```

**Files to change:**

| File | Change |
|------|--------|
| `includes/header.php` | Add `<aside class="app-sidebar">` after `<body>`; wrap existing main content area in `<div class="app-shell">` layout grid |
| `includes/footer.php` | Close the app shell grid; add optional rail slot |
| `public/css/core.css` | Add `.app-shell`, `.app-sidebar`, `.app-rail` layout classes |

**Sidebar content** (from `Sidebar.jsx`):
- Brand mark + "PgBudget" wordmark
- Ledger picker dropdown (current ledger name + chevron)
- Primary nav: Dashboard · Budget · Transactions · Analytics · Accounts
- "Plan" group: Goals · Recurring · Loans
- Settings (bottom)
- User chip (avatar initials · name · email)

**Rail content** (from `Rail.jsx`):
- "Month at a glance" stat tiles: Income / Expenses / Net (with delta badges)
- Recent activity feed (4 most recent transactions)
- Contextual tip banner (`.banner.info`)

**Notes:**
- The top navbar stays — it holds undo/redo, global search, and quick-add. Sidebar handles page navigation.
- The rail is optional per-page: budget + transactions + analytics show it; settings/accounts may not.
- Mobile: sidebar collapses to off-canvas drawer; rail collapses to bottom sheet or disappears.

---

### Phase 3 — Dashboard Page (`public/budget/dashboard.php`)

**Goal:** Apply design-kit components and layout to the budget dashboard.

| Design element | Current | Required change |
|---|---|---|
| "Ready to Assign" hero | Missing | Add `.hero-card` with gradient, amount, quick-action buttons |
| Net worth tile | Missing | Add `.card` with total assets/liabilities + sparkbar |
| Overspending banner | Custom `.overspending-warning-banner` | Replace with `.banner` (warning variant) |
| Budget summary strip | Inline-styled `.budget-summary-card` | Replace with 3× `.card` in a grid row |
| Setup checklist | `.setup-checklist-item` | Replace with `.checklist` / `.check` / `.check.done` |
| View toggle (Grouped/Flat) | `.view-toggle-btn` | Replace with `.seg-toggle` / `.seg-btn` |
| Budget table | `.table` / `.grouped-table` | Replace with `.tbl`; group header rows get `.group-head`; numeric columns get `.num` |
| Progress bars in table | `.progress-bar` with inline width | Replace with `.bar` / `.bar.over` / `.bar.under` |
| Category money values | `.amount.positive` / `.amount.negative` | Add `.money.pos` / `.money.neg` / `.money.zero` + `.tnum` |
| Account cards (sidebar) | Plain list items | Replace with `.account-card` + `.cat-icon` |
| Recent activity (sidebar) | Plain list | Add `.cat-icon` avatar to each row |
| "Monthly outflow" chart | Inline bar widget | Add `.seg-toggle` for 3M/6M/1Y; style with design tokens |

---

### Phase 4 — Transactions Page (`public/transactions/list.php`)

**Goal:** Bring the transactions register in line with Transactions.jsx.

| Design element | Current | Required change |
|---|---|---|
| Search input | Plain `<input>` | Wrap in `.search` with SVG icon |
| Filter tabs | Custom buttons | Replace with `.seg-toggle` · All / Income / Expenses |
| Account filter | `<select>` | Style with `.input` class |
| Category pills | Plain text | Wrap in `.badge badge-success` (inflow) / `.badge badge-neutral` |
| Payee column | Text only | Prepend `.cat-icon` with first-letter initial |
| Money columns | `.amount .positive/.negative` | Add `.money.pos`/`.neg` + `.num` on `<td>` |
| Table class | `.table` | Add `.tbl` alongside (or replace) |
| Numeric column headers | No alignment | Add `.num` class |
| "Add transaction" button | Generic `.btn` | Use `.btn.btn-primary` with `+` icon |

---

### Phase 5 — Reports / Analytics Pages

**Goal:** Apply card/badge/chart styling to all report pages.

Pages: `reports/income-vs-expense.php`, `reports/spending-by-category.php`, `reports/net-worth.php`, `reports/cash-flow-projection.php`, `reports/category-trends.php`, `reports/age-of-money.php`

Common changes across all report pages:
- Wrap summary numbers in `.card` containers
- Add `.eyebrow` labels above section titles
- Trend indicators → `.badge.badge-success` / `.badge.badge-danger`
- Large totals → `.tnum` + appropriate size token
- Period selector → `.seg-toggle`
- Table data → `.tbl`, `.num`, `.money.pos`/`.neg`

---

### Phase 6 — Pervasive Class Sweep

After Phases 3–5, do a pass across all remaining pages:

- Replace all `.amount.positive` / `.amount.negative` → add `.money.pos` / `.money.neg`
- Replace all `font-feature-settings` inline styles → `.tnum` class
- Replace all `font-size: var(--text-xs); text-transform: uppercase` eyebrow patterns → `.eyebrow`
- Replace `.progress-bar` with `.bar` where applicable
- Apply `.badge` classes to status pills site-wide
- Audit all button usages: ensure correct size variant (`.btn-sm` for compact actions, `.btn-icon` for icon-only)

---

## Class Name Mapping Reference

| Old (production) | New (design kit) | Notes |
|---|---|---|
| `.table` | `.tbl` | Keep `.table` for backwards compat; add `.tbl` |
| `.view-toggle-btn` | `.seg-btn` | Replace entirely |
| `.progress-bar` | `.bar` | Keep `.progress-bar`; add `.bar` as parallel |
| `.amount.positive` | `.money.pos` | Additive — don't remove old |
| `.amount.negative` | `.money.neg` | Additive — don't remove old |
| `.setup-checklist-item` | `.check` | Replace |
| `.overspending-warning-banner` | `.banner` | Replace |
| Custom eyebrow spans | `.eyebrow` | Replace |
| Inline `font-feature-settings` | `.tnum` | Replace |

---

## Design Reference Files

| File | Purpose |
|------|---------|
| `public/ui_kits/web/index.html` | Live prototype — open in browser to see target state |
| `public/ui_kits/web/app.css` | Component CSS used in prototype |
| `public/colors_and_type.css` | Full token file |
| `public/ui_kits/web/Dashboard.jsx` | Dashboard screen spec |
| `public/ui_kits/web/Budget.jsx` | Budget screen spec |
| `public/ui_kits/web/Transactions.jsx` | Transactions screen spec |
| `public/ui_kits/web/Analytics.jsx` | Analytics screen spec |
| `public/ui_kits/web/Sidebar.jsx` | Sidebar spec |
| `public/ui_kits/web/Rail.jsx` | Right rail spec |
