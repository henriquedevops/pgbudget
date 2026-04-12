# PGBudget UX & Mobile-First Modernization Plan

> **Status:** Proposed  
> **Created:** 2026-04-11  
> **Scope:** Responsive layout, visual refresh, and interaction improvements

---

## 1. Current State Assessment

### What We Have
- Custom CSS only (no Tailwind, Bootstrap, or other framework)
- System font stack (`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, …`)
- Flexbox + CSS Grid for layout
- PWA meta tags (`mobile-web-app-capable`, viewport with `viewport-fit=cover`)
- **Two CSS files:** `style.css` (1,067 lines) and `cash-flow-projection.css` (985 lines)
- **One main breakpoint:** `@media (max-width: 768px)` in `style.css`
- `cash-flow-projection.css` adds `@media (max-width: 640px)` but only hides some columns

### UX Debt Identified

| Area | Issue | Severity |
|------|-------|----------|
| Cash-flow table | Horizontal scroll only — no card fallback on mobile | 🔴 High |
| Transaction tables | `.table td` font is 14px, touch targets are too small | 🔴 High |
| Breakpoints | Only one breakpoint (768px) — no sm/md/lg/xl scale | 🟡 Medium |
| Budget grid | `grid-template-columns: 2fr 1fr` collapses to `1fr` on mobile but right panel is still very dense | 🟡 Medium |
| Link/complete workflow | Multi-step action (select projection → confirm → link) requires too many taps | 🔴 High |
| Color palette | Primary blue (`#2b6cb0`) is functional but dated; no dark-mode support | 🟠 Low-Medium |
| Typography | No variable font; no fluid type scale | 🟠 Low |
| Modals | Modals are fixed-size; on small screens they overflow or need manual scrolling | 🟡 Medium |
| Navigation | Sidebar nav is hidden on mobile with no clear menu toggle indicator | 🟡 Medium |

---

## 2. Responsive Strategy

### 2.1 Breakpoint Scale

Replace the single `768px` breakpoint with a named scale in `:root` and a shared `_breakpoints.css` partial:

```css
/* Proposed breakpoint tokens */
--screen-sm:  480px;   /* large phones landscape */
--screen-md:  768px;   /* tablets */
--screen-lg:  1024px;  /* small laptops */
--screen-xl:  1280px;  /* desktops */
--screen-2xl: 1536px;  /* wide monitors */
```

Use **mobile-first** `min-width` queries instead of `max-width` throughout new code.

### 2.2 Cash-Flow Projection Table

The pivot table (`cash-flow-projection.php`) is the hardest screen to mobilize. Strategy:

**≥ 1024px (desktop):** Keep the current sticky-column spreadsheet layout.

**768px – 1023px (tablet):** Reduce column count to 6 months, allow horizontal scroll with a visible scroll shadow.

**< 768px (phone):** Switch from `<table>` to a **card-per-row** layout:

```
┌─────────────────────────────┐
│ SALARIO PADRAO              │
│ Income · Recurring          │
├────────┬────────┬────────────┤
│ Jan    │ Feb    │ Mar …      │
│ R$8042 │ R$8042 │ R$8042     │
└────────┴────────┴────────────┘
```

Implementation approach:
- Use CSS `display: block` on `<tr>` and `<td>` for mobile.
- Use `data-label` attributes on `<td>` for accessible card headers.
- Limit visible months to 3 on phone, with a "Show more" toggle.

### 2.3 Transaction Tables

Apply the same card pattern to `transactions/list.php` and `transactions/account.php`:

- **Desktop:** Full table with all columns.
- **Mobile:** Each row becomes a card:
  ```
  ┌─────────────────────────────────┐
  │ 20 Jan 2025        R$ 13,037.18 │
  │ SALARIO PADRAO                  │
  │ CEF Checking → Income     [Edit]│
  └─────────────────────────────────┘
  ```
- Secondary metadata (account pair, category) shown in smaller text below the description.

### 2.4 Modals

- Add `max-height: 90dvh; overflow-y: auto;` to all modals.
- On screens < 480px, modals should slide up from the bottom (bottom sheet pattern) instead of center-floating.
- Minimum touch target size: **44 × 44px** for all buttons inside modals (WCAG 2.5.5).

### 2.5 Navigation

- The current sidebar should collapse to a **bottom tab bar** on mobile (≤ 768px):
  - Tabs: Budget | Accounts | Reports | Settings
  - Active page highlighted with the primary color.
- The hamburger/menu icon should be visually prominent (≥ 32px icon, labeled).

---

## 3. Visual Refresh

### 3.1 Proposed Color Palette

Keep the existing semantic tokens but refine the brand primaries for better contrast and a fresher feel:

| Token | Current | Proposed | Rationale |
|-------|---------|----------|-----------|
| `--color-primary` | `#2b6cb0` | `#2563eb` | Vivid blue, passes WCAG AA |
| `--color-primary-hover` | `#245a9c` | `#1d4ed8` | Darker shade for hover |
| `--color-primary-bg` | `#ebf8ff` | `#eff6ff` | Slightly cooler tint |
| `--color-surface` | _(missing)_ | `#ffffff` | Explicit surface token |
| `--color-surface-alt` | _(missing)_ | `#f8fafc` | Zebra rows, card bg |
| `--color-border` | _(missing)_ | `#e2e8f0` | Consistent border color |
| `--color-text-primary` | _(missing)_ | `#0f172a` | Near-black for headings |
| `--color-text-muted` | _(missing)_ | `#64748b` | Secondary labels |

Add a **dark mode** token layer:

```css
@media (prefers-color-scheme: dark) {
  :root {
    --color-surface:     #0f172a;
    --color-surface-alt: #1e293b;
    --color-border:      #334155;
    --color-text-primary:#f1f5f9;
    --color-text-muted:  #94a3b8;
  }
}
```

### 3.2 Typography

Adopt **Inter** (free, variable font) as the primary typeface for improved legibility:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
```

Alternatively use the local system stack with `font-feature-settings: "tnum"` on all monetary values (tabular numerals) to fix column alignment:

```css
.amount, .currency { font-variant-numeric: tabular-nums; }
```

Fluid type scale using `clamp()`:

```css
--text-base: clamp(0.875rem, 1vw + 0.5rem, 1rem);
--text-lg:   clamp(1rem,     1.2vw + 0.5rem, 1.125rem);
```

### 3.3 Spacing & Density

- Table cells: increase padding from `8px` to `10px 12px` for better touch comfort.
- Card padding: `16px` on mobile, `20px` on desktop.
- Section headers: add `border-bottom: 2px solid var(--color-primary)` for visual hierarchy.

---

## 4. Interaction Improvements

### 4.1 Transaction-to-Projection Linking Workflow

**Current:** User clicks a projected row → a modal opens → selects one real transaction → confirms.

**Proposed (multi-link):**

1. User clicks the **link icon** on any projected event row.
2. The report enters **"selection mode"** (a subtle blue highlight on the table border).
3. Checkboxes appear on projected rows. The user can select 1-N projected events.
4. A **sticky footer bar** shows:
   - `Selected: R$ X,XXX.XX  (3 items)`
   - `Real transaction: R$ Y,YYY.YY`
   - `Remaining: R$ Z,ZZZ.ZZ` (green if 0, orange if > 0)
5. "Confirm Link" button submits the array of `projection_ids` to the backend.
6. If remaining > 0, a secondary prompt asks: "Record difference as interest/fee?"

**Mobile adaptation:** The sticky footer becomes a bottom sheet on phones.

### 4.2 Quick-Add Improvements

- After adding a transaction via the Telegram bot or quick-add modal, show a **undo toast** for 5 seconds ("Transaction added · Undo").
- Pre-fill the account field based on the last-used account per category.

### 4.3 Keyboard & Accessibility

- All interactive table rows should be focusable (`tabindex="0"`) with Enter/Space to open.
- Escape closes any open modal.
- Amount fields should use `inputmode="decimal"` on mobile for numeric keyboard.
- All icon-only buttons must have `aria-label`.

---

## 5. Implementation Checklist

### Phase A — Foundation (No visual change, pure structure)
- [ ] Extract breakpoint tokens to `public/css/_variables.css`
- [ ] Switch all media queries to mobile-first (`min-width`)
- [ ] Add `font-variant-numeric: tabular-nums` to all `.amount` and `<td>` in money tables
- [ ] Add `max-height: 90dvh; overflow-y: auto` to all modals
- [ ] Add `44px` minimum touch targets to all action buttons
- [ ] Add `data-label` attributes to all `<td>` in transaction tables
- [ ] Verify `<meta viewport>` is present on every page (already done in `includes/header.php`)

### Phase B — Mobile Card Layouts
- [ ] `transactions/list.php` — card layout below 768px
- [ ] `transactions/account.php` — card layout below 768px
- [ ] `reports/cash-flow-projection.php` — card layout below 768px, 3-month limit
- [ ] `installments/index.php` — card layout below 768px
- [ ] `credit-cards/statements.php` — card layout below 768px

### Phase C — Navigation
- [ ] Bottom tab bar for ≤ 768px (Budget, Accounts, Reports, Settings)
- [ ] Sticky page-level header with back-button on drill-down pages
- [ ] Improve sidebar collapse indicator (visible hamburger with label)

### Phase D — Visual Refresh
- [ ] Update color tokens in `:root` (see Section 3.1)
- [ ] Add dark mode token layer
- [ ] Add `font-variant-numeric: tabular-nums` (overlap with Phase A)
- [ ] Increase table cell padding
- [ ] Add Inter font or confirm system-font stack is sufficient

### Phase E — Interaction Upgrades
- [ ] Multi-select projection linking (see Section 4.1)
- [ ] Sticky footer "allocation bar" for link workflow
- [ ] Undo toast for quick-add
- [ ] `inputmode="decimal"` on all amount inputs
- [ ] `aria-label` audit on all icon buttons

---

## 6. Proposed Timeline

| Week | Phase | Focus |
|------|-------|-------|
| 1 | A | Breakpoint tokens, modal fixes, touch targets |
| 2 | B | Transaction list + account card layouts |
| 3 | B | Cash-flow projection card layout |
| 4 | C | Bottom tab bar navigation |
| 5 | D | Color refresh + dark mode tokens |
| 6 | E | Multi-link workflow + allocation bar |
| 7 | E | Undo toast + accessibility audit |
| 8 | — | QA across devices (phone, tablet, desktop) |

---

## 7. Design Principles to Follow

1. **Data first.** Never hide financial data behind extra taps just for aesthetics. Density is acceptable when it communicates clearly.
2. **One-handed phone use.** Primary actions should be reachable in the bottom 60% of the screen.
3. **Predictable layout.** The same page should look like the same page at every breakpoint — just rearranged, not redesigned.
4. **No framework lock-in.** Continue with custom CSS to avoid bundle weight. Use CSS custom properties as the design system primitive.
5. **Progressive enhancement.** Every feature must work at 320px width before being enhanced for wider screens.
