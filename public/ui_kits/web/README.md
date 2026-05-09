# PgBudget — Web UI Kit

Hi-fi recreation of the PgBudget desktop web app. Tokens come from the real codebase
(`public/css/core.css` in `henriquedevops/pgbudget`); the screens reflect the YNAB-style
zero-sum budgeting model the app actually uses (Ledger → Account → Category, with
Budgeted / Activity / Available per category).

## Layout

3-column shell: 240 px sidebar (with ledger picker + grouped nav) / fluid main / 320 px
right rail. The sidebar mirrors the structure in `views/dashboard.php`, including the
Ledger switcher, primary nav, and a "Plan" group for Goals / Recurring / Loans.

## Files

| File              | Purpose                                                                       |
|-------------------|-------------------------------------------------------------------------------|
| `index.html`      | Entry point. Routes between Dashboard / Budget / Transactions / Analytics.    |
| `app.css`         | Imports `colors_and_type.css` and adds shell + component styles.              |
| `Data.js`         | Realistic fixtures: ledgers, asset/liability accounts, grouped categories.    |
| `Primitives.jsx`  | `Money`, `Bar`, `Sparkbar`, `Icon` (inline-SVG stroke icons).                 |
| `Charts.jsx`      | `BarChart`, `GroupedBars`, `Donut` — pure SVG, no deps.                       |
| `Sidebar.jsx`     | Left nav with Ledger picker + user chip.                                      |
| `Rail.jsx`        | April-at-a-glance stats, recent activity, contextual tip.                     |
| `Dashboard.jsx`   | Overspending banner, Ready-to-Assign hero, setup checklist, accounts list.   |
| `Budget.jsx`      | Summary strip + Grouped/Flat Budget Categories table + Goals.                 |
| `Transactions.jsx`| Search, tabs (All/Income/Expenses), account filter, full register table.     |
| `Analytics.jsx`   | Period selector, summary cards, income-vs-expenses, group spend donut.        |

## Click-thru

- Sidebar nav switches screens.
- Budget view toggles **Grouped / Flat**.
- Transactions has working search, tab, and account filter.
- Analytics period buttons cycle ranges (data is static).

## Faithful to the codebase

- **Color scale** — `--color-primary` is `#2563eb`; cards use `--color-surface` on
  `--color-bg-page` `#f7fafc`; status banners use the `--success/--warning/--danger` 50/500/700 ramps.
- **Welcome card / hero** — gradient `linear-gradient(135deg, var(--color-primary), var(--color-primary-700))`,
  white text, mirroring `.welcome-card` in `core.css`.
- **Budget table** — Grouped layout matches `views/dashboard.php` "Budget Categories"
  table: group header rows + per-category rows showing `Budgeted / Activity / Available`.
- **Quick action buttons** — emoji glyphs (⚡ Quick Add · 💵 Assign Money · ⇄ Transfer) match the buttons in dashboard.php.
- **Vocabulary** — "Ready to Assign", "Inflow: Ready to Assign", "Overspent" mirror the
  app's terminology, not invented copy.

## Out of scope

- Accounts, Settings, Goals, Recurring, Loans screens — sidebar entries route to
  placeholder content so the shell is explorable.
- No real auth, no real data, no real form validation.
- Responsive breakpoints below ~1100 px — see `ui_kits/mobile/`.
