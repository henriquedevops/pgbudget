# Frontend UX/UI Review — PGBudget
**Reviewer:** Senior Frontend Developer
**Date:** March 2026
**Scope:** Full frontend audit — usability, design consistency, mobile readiness, code quality

---

## Executive Summary

PGBudget is a genuinely ambitious personal finance app built with PHP + vanilla JS + custom CSS. The developer has clearly put serious effort in: there's a PWA setup, a mobile bottom nav, keyboard shortcuts, undo/redo, inline editing, and a service worker. That foundation is real work.

The core problems are about **consistency and scalability**, not fundamentals. The app works, but it works differently in different places — three shades of the same blue, inline styles on auth pages while everything else uses stylesheets, 16 separate CSS files loaded on every page, and navigation so dense it collapses under its own weight on smaller screens.

The following review prioritizes high-impact changes first.

---

## 1. Usability (UX)

### 1.1 Navigation Overload

**Problem:** The top navigation has 15+ items when a ledger is active:
`Dashboard · Accounts · Categories · Add Transaction · Credit Cards · Loans · Installments · Bills · Income · Events · Projection · What-If · Settings`

This exceeds what any horizontal nav can hold cleanly. On a 1280px monitor it already wraps. On anything smaller it collapses — but the collapsed mobile menu just dumps all 15 items in a vertical list with no grouping.

**What to do:**
Group related items under dropdown menus or a sidebar with sections.

```
Before (flat list of 15):
Dashboard | Accounts | Categories | Add Transaction | Credit Cards |
Loans | Installments | Bills | Income | Events | Projection | What-If | Settings

After (grouped):
Dashboard | Accounts ▾ | Planning ▾ | Reports ▾ | Settings
                          └ Categories    └ Projection
                          └ Income        └ What-If
                          └ Payroll       └ Cash Flow
```

The mobile bottom nav (5 items) is the right approach — bring that same restraint to the desktop nav.

---

### 1.2 The "Add Transaction" Flow

**Problem:** The quick-add modal is good, but it's only reachable via the top nav link or keyboard shortcut `T`. New users won't know about `T`. The floating action button on mobile (the `+` in the bottom nav) goes to `transactions/add.php` — a full-page form — instead of the quick modal. This breaks the expected mobile pattern where the big `+` opens an inline sheet.

**What to do:**
- Make the `+` button in the bottom nav trigger the quick-add modal, not navigate to a new page.
- Add a persistent, visually prominent FAB (floating action button) on the budget dashboard.
- On desktop, the "Add Transaction" nav link should also open the modal, not a page.

---

### 1.3 Empty States

**Problem:** When a user has no transactions, no accounts, or no budget categories, pages render empty tables or blank card grids with no guidance. A blank screen doesn't tell the user what to do next.

**What to do — add empty state components:**

```html
<!-- Example: empty transactions list -->
<div class="empty-state">
  <div class="empty-state-icon">📋</div>
  <h3>No transactions yet</h3>
  <p>Start tracking your spending by adding your first transaction.</p>
  <a href="#" class="btn btn-primary" onclick="openQuickAdd()">Add Transaction</a>
</div>
```

Apply this pattern to: transactions list, accounts list, budget categories, goals, loans, and credit cards.

---

### 1.4 Destructive Actions

**Problem:** Delete buttons throughout the app (accounts, ledgers, transactions) use `confirm()` — the native browser dialog — for confirmation. This is jarring, inconsistent with the app's visual style, and can't be styled for mobile.

The ledger deletion page (`delete-ledger.php`) correctly uses a styled confirmation UI with a text-match challenge. That same pattern should be used app-wide for all deletions.

**What to do:**
- Create a reusable confirmation modal in `includes/confirm-modal.php`.
- Remove all `confirm()` calls from JavaScript.
- For destructive actions on important data (accounts, ledgers), require typing the name to confirm.
- For low-stakes deletions (a single transaction), a simple modal with "Cancel / Delete" is sufficient.

---

### 1.5 Form Feedback and Loading States

**Problem:** Forms submit and the page reloads. If the server takes 1–2 seconds (common on shared hosting), the user sees nothing happen and may click Submit again, creating duplicate entries.

**What to do:**
```javascript
// Disable submit button on form submit, show spinner
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', () => {
        const btn = form.querySelector('[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving…';
        }
    });
});
```

Add this to `main.js` globally. It prevents double-submission on every form in the app with 8 lines.

---

### 1.6 Onboarding

**Positive:** The 5-step onboarding wizard exists and is a good idea.
**Problem:** It can be exited at any time, and there's no visible progress indicator on the main app to nudge incomplete setups. Users who skip step 3 (budget categories) end up on a blank budget dashboard.

**What to do:**
- Show a setup checklist widget on the dashboard when `onboarding_completed = false`.
- Make each checklist item a direct link to the relevant step.
- Persist completion state per step so partially-done users see what's left.

---

## 2. UI Design

### 2.1 Color Inconsistency — Three Blues

**Problem:** Three distinct shades of blue are used as "primary" across the app, with no clear rule for which is correct:

| Location | Color | Hex |
|---|---|---|
| Auth pages (login, register buttons) | Indigo | `#667eea` |
| Main app buttons, focus rings | Blue | `#3182ce` |
| Navigation bar | Dark blue | `#2b6cb0` |

This signals the app was built in phases without a shared design token for "primary color."

**What to do:**
Pick one. Given the navbar is `#2b6cb0` and it's the most visible element, standardize on that as `--color-primary`. Update `style.css` CSS variables and the inline styles on the auth pages.

```css
/* style.css — single source of truth */
:root {
    --color-primary:       #2b6cb0;
    --color-primary-light: #3182ce;
    --color-primary-dark:  #1a4a7a;
    --color-primary-bg:    #ebf8ff;
}
```

---

### 2.2 Border Radius Inconsistency

**Problem:** The app uses at least three different border-radius values with no rule governing which to use:

- Auth page inputs: `6px`
- Main app inputs (`.form-input`): `4px`
- Cards and modals: `8px`
- Some buttons: `4px`, others `6px`

**What to do:**
Add border-radius tokens to the CSS variables:

```css
:root {
    --radius-sm:  4px;   /* inputs, small buttons */
    --radius-md:  8px;   /* cards, modals, panels */
    --radius-lg:  12px;  /* large cards, sheets */
    --radius-pill: 9999px; /* tags, badges, pill buttons */
}
```

Apply consistently. This is a 2-hour find-and-replace task that immediately makes the app feel more polished.

---

### 2.3 Typography Scale

**Problem:** Font sizes are defined ad-hoc. A page might have a `3rem` h1, a `1.5rem` h2, a `1.2rem` subtitle, a `1rem` body, `0.875rem` small text, and `0.75rem` labels — all defined in different CSS files. There's no documented type scale.

**What to do:**
```css
:root {
    --text-xs:   0.75rem;   /* 12px — labels, badges */
    --text-sm:   0.875rem;  /* 14px — secondary info, help text */
    --text-base: 1rem;      /* 16px — body */
    --text-lg:   1.125rem;  /* 18px — lead text */
    --text-xl:   1.25rem;   /* 20px — card titles */
    --text-2xl:  1.5rem;    /* 24px — section headers */
    --text-3xl:  2rem;      /* 32px — page titles */
}
```

---

### 2.4 Auth Pages — Inline Styles

**Problem:** `login.php` and `register.php` contain ~180 lines of inline `<style>` each. Every selector, color, and spacing value is duplicated between the two files. When the brand color changes, it needs updating in 3+ places.

**What to do:**
- Create `public/css/auth.css` with the shared auth styles.
- Replace the `<style>` blocks in both files with `<link rel="stylesheet" href="/pgbudget/css/auth.css">`.
- The button, input, and card styles in auth.css will likely overlap 80% with `style.css` — unify them.

---

### 2.5 Emoji as Icons — Accessibility

**Problem:** Navigation and UI use emoji as icons (`💰 💳 📋 📅 🎯 📊 ⚙️`). These render differently across operating systems (Apple emoji vs. Android vs. Windows), have no consistent sizing, can't be styled with CSS (color, stroke), and are invisible to screen readers without aria-label.

**What to do (pragmatic option — no icon library needed):**
```html
<!-- Before -->
<a href="/loans">💰 Loans</a>

<!-- After — emoji with aria-hidden, text carries the meaning -->
<a href="/loans">
    <span aria-hidden="true">💰</span>
    <span class="nav-label">Loans</span>
</a>
```

If willing to add one dependency, [Lucide Icons](https://lucide.dev) (MIT license, tree-shakeable SVG) would give consistent, styleable icons at ~2kb per icon.

---

### 2.6 Visual Hierarchy on Dense Pages

**Problem:** The budget dashboard and transaction list display a lot of data. Everything has similar visual weight — the same gray borders, same font sizes, same padding. Important numbers (account balance, budget remaining) don't stand out.

**What to do:**
- Make key numbers larger and bolder (`font-size: var(--text-2xl); font-weight: 700`).
- Use color purposefully: green for positive balances, red for overdrafts/overspending — but only on the numbers, not the entire row background.
- Add subtle section dividers or whitespace to break up dense tables rather than relying solely on row borders.

---

## 3. Mobile-First Readiness

### 3.1 What's Already Good

- Viewport meta tag with `viewport-fit=cover` (notch support) ✓
- `mobile.css` (661 lines) with a `768px` breakpoint ✓
- Mobile bottom navigation bar (5 items) ✓
- Touch target minimum sizes (44×44px) ✓
- 16px font size on mobile inputs (prevents iOS auto-zoom) ✓
- PWA manifest + service worker ✓

This is a solid base. The mobile work is real and intentional.

---

### 3.2 Problems That Need Fixing

**Tap targets in tables:**
Transaction list rows have Edit/Delete buttons that are small (`padding: 4px 8px`). On mobile, these are nearly impossible to tap accurately. Either move actions to a swipe-gesture (right-swipe to delete, left-swipe to edit) or use a row tap that opens a bottom sheet with actions.

**The report pages on mobile:**
The cash-flow-projection, net-worth, and spending-by-category reports use wide tables and charts that overflow horizontally. This is only partially addressed. Tables need either:
- Horizontal scroll with `overflow-x: auto` on a wrapper div
- Or a card-based stacking layout on mobile (each row becomes a card)

**The modal size on mobile:**
The quick-add modal is `max-width: 500px` centered. On a 390px iPhone screen, modals should be full-width bottom sheets, not centered floating windows:

```css
@media (max-width: 640px) {
    .modal-overlay {
        align-items: flex-end;  /* pin to bottom */
    }
    .modal-content {
        width: 100%;
        max-width: 100%;
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        padding-bottom: env(safe-area-inset-bottom); /* iPhone notch */
    }
}
```

**The hamburger menu reveals a wall of links:**
15+ nav items in a vertical list on mobile is unusable. The mobile bottom nav (5 items) already handles the primary actions. The hamburger should only expose secondary items the bottom nav doesn't cover, or use a drawer-style slide-in with grouped sections.

---

### 3.3 Toward a Native App Feel

The PWA groundwork is already laid. To make the app actually feel native:

1. **Add a `<meta name="mobile-web-app-capable" content="yes">` tag** (already have the Apple version, add the generic Chrome one).

2. **Prevent overscroll bounce** on inner scrollable areas (iOS rubber-banding effect):
   ```css
   .modal-content, .sidebar, [data-scroll] {
       overscroll-behavior: contain;
   }
   ```

3. **Add CSS transitions between pages** — even a simple fade makes the app feel less like a website:
   ```css
   .main-content {
       animation: fadeIn 0.15s ease;
   }
   @keyframes fadeIn {
       from { opacity: 0; transform: translateY(4px); }
       to   { opacity: 1; transform: translateY(0); }
   }
   ```

4. **Use `position: sticky` for table headers** so column headers stay visible when scrolling long transaction lists.

---

## 4. Code & Frontend Best Practices

### 4.1 CSS Architecture — Too Many Files

**Problem:** 16 CSS files are loaded via individual `<link>` tags in `header.php`. On HTTP/1.1 this causes 16 sequential requests. Even on HTTP/2, it's unnecessary fragmentation that makes it hard to understand where a given style is defined.

**Current load order in header.php:**
```html
<link rel="stylesheet" href=".../style.css">
<link rel="stylesheet" href=".../mobile.css">
<link rel="stylesheet" href=".../modals.css">
<link rel="stylesheet" href=".../undo.css">
<link rel="stylesheet" href=".../bulk-operations.css">
... (11 more)
```

**What to do:**
Consolidate into three files:
- `core.css` — variables, reset, typography, layout, buttons, forms, alerts, cards
- `components.css` — modals, tables, navigation, undo controls, help sidebar
- `[page-name].css` — loaded only on the page that needs it (reports, onboarding, etc.)

This is a refactor task, not a rewrite. Move existing CSS rules into the new structure.

---

### 4.2 JavaScript — No Module System

**Problem:** 44 JavaScript files are loaded as individual `<script>` tags, many of which declare global variables and functions. There's no `import`/`export`, no bundler. This works, but it means:
- Functions clash if two files use the same name
- Execution order matters and is determined by `<script>` tag order
- No tree-shaking — all JS loads even if unused on that page

**Pragmatic fix (no build step required):**
- Wrap each JS file in an IIFE to prevent global scope pollution:
```javascript
// Before (global leak)
function openQuickAdd() { ... }

// After (IIFE — no global leak, but still callable via event listeners)
(function() {
    function openQuickAdd() { ... }
    window.openQuickAdd = openQuickAdd; // only expose what's needed
})();
```
- Move to native ES modules (`type="module"`) on any green-field pages going forward.

---

### 4.3 Z-Index Management

**Problem:** Z-index values found in the codebase: `1`, `10`, `100`, `1000`, `9999`, `10000`. These are scattered across 16 CSS files with no documented layering system. This leads to modals appearing under navbars, tooltips clipped by cards, etc.

**What to do:**
```css
/* style.css — z-index scale, one place, always */
:root {
    --z-base:       0;
    --z-dropdown:   100;
    --z-sticky:     200;
    --z-overlay:    300;
    --z-modal:      400;
    --z-toast:      500;
    --z-tooltip:    600;
}
```

Replace every hardcoded `z-index` with a variable.

---

### 4.4 Repeated Inline Styles in PHP Templates

**Problem:** Many PHP files add inline styles directly in the HTML for one-off adjustments:

```php
<!-- Found throughout the codebase -->
<div style="margin-top: 20px;">
<span style="color: #e53e3e; font-weight: bold;">
<div style="display: flex; gap: 10px; align-items: center;">
```

These are untestable, non-reusable, and override the stylesheet without context.

**What to do:**
Move these into utility classes in `style.css`:
```css
/* Utility classes — use instead of inline styles */
.mt-4   { margin-top: 1rem; }
.mt-8   { margin-top: 2rem; }
.flex   { display: flex; }
.gap-2  { gap: 0.5rem; }
.gap-4  { gap: 1rem; }
.items-center { align-items: center; }
.text-danger  { color: var(--danger-500); }
.font-bold    { font-weight: 700; }
```

---

### 4.5 No Loading / Skeleton States

**Problem:** API calls (transactions, reports, projections) load data synchronously via PHP. When the data is heavy (large date ranges, complex projections), the page either loads slowly or the user sees nothing until the full page renders.

**What to do:**
- For report pages, add a `<div class="skeleton-loader">` placeholder that shows while the page loads.
- For any AJAX calls in JS, show a spinner in the container, not just disabling the button.
- CSS skeleton screens are lightweight and require no library:

```css
.skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: var(--radius-sm);
}
@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
```

---

## 5. Practical Improvements — Priority Order

### Quick Wins (1–2 hours each)

| # | Task | Impact |
|---|---|---|
| 1 | Extract `login.php` + `register.php` inline styles → `auth.css` | Maintainability |
| 2 | Add disabled state + "Saving…" text to all form submit buttons | Prevents double-submission |
| 3 | Standardize to one primary blue using `--color-primary` CSS variable | Visual consistency |
| 4 | Add `aria-hidden="true"` to all emoji icons | Accessibility |
| 5 | Wrap quick-add modal in bottom-sheet CSS on mobile | Mobile UX |
| 6 | Add `overflow-x: auto` wrapper to all tables | Mobile usability |
| 7 | Add `overscroll-behavior: contain` to modals and inner scroll areas | Native app feel |
| 8 | Add `position: sticky; top: 0;` to `<thead>` in long tables | Usability |

---

### Medium Effort (half-day each)

| # | Task | Impact |
|---|---|---|
| 9 | Add empty state components to all list/table pages | Onboarding clarity |
| 10 | Consolidate 16 CSS files → 3 (core, components, per-page) | Performance, maintainability |
| 11 | Replace all `confirm()` calls with custom confirmation modal | Consistency, mobile UX |
| 12 | Define and apply CSS variables for border-radius, z-index, type scale | Consistency |
| 13 | Group navigation into dropdowns; reduce top nav to 5–6 items | Navigation usability |
| 14 | Make mobile `+` FAB open quick-add modal instead of navigating | Mobile UX |

---

### Larger Refactors (1–3 days each)

| # | Task | Impact |
|---|---|---|
| 15 | Replace emoji icons with Lucide SVG icons | Visual consistency, accessibility |
| 16 | Refactor JS files into ES modules (start with new features) | Code quality, maintainability |
| 17 | Add page-transition animations | Native app feel |
| 18 | Redesign report tables as responsive card-stacks on mobile | Mobile readiness |
| 19 | Add skeleton/shimmer loading states to data-heavy pages | Perceived performance |
| 20 | Build a setup checklist widget for onboarding completion | User retention |

---

## Appendix — Before/After Examples

### A. Table on Mobile

```html
<!-- Before: table overflows the screen -->
<table class="table">...</table>

<!-- After: contained horizontal scroll -->
<div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
    <table class="table">...</table>
</div>
```

---

### B. Form Submit Button

```html
<!-- Before: plain submit, no feedback -->
<button type="submit" class="btn btn-primary">Save</button>

<!-- After: loading state via JS in main.js (no HTML change needed) -->
<!-- The main.js global handler takes care of it -->
```

---

### C. Empty State

```html
<!-- Before: empty tbody, user sees a blank table -->
<tbody></tbody>

<!-- After -->
<?php if (empty($transactions)): ?>
<div class="empty-state">
    <span class="empty-state-icon" aria-hidden="true">📋</span>
    <h3>No transactions yet</h3>
    <p>Add your first transaction to start tracking your budget.</p>
    <button class="btn btn-primary" onclick="openQuickAdd()">
        Add Transaction
    </button>
</div>
<?php else: ?>
<table class="table">...</table>
<?php endif; ?>
```

---

### D. Mobile Modal → Bottom Sheet

```css
/* Before: centered modal on all screen sizes */
.modal-overlay {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* After: bottom sheet on mobile */
@media (max-width: 640px) {
    .modal-overlay {
        align-items: flex-end;
    }
    .modal-content {
        width: 100%;
        max-width: 100%;
        border-radius: 16px 16px 0 0;
        padding-bottom: env(safe-area-inset-bottom);
    }
}
```

---

*This review was written against the codebase state as of March 2026.*
