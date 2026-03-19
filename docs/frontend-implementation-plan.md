# Frontend Implementation Plan — PGBudget
**Based on:** `docs/frontend-review.md`
**Created:** March 2026

---

## Phases Overview

| Phase | Scope | Effort | Status |
|---|---|---|---|
| 1 | Quick wins — consistency & mobile basics | 1–2h each | ✅ Complete |
| 2 | Navigation & UX flows | Half-day each | ⏳ Pending |
| 3 | CSS architecture consolidation | 1–2 days | ⏳ Pending |
| 4 | Component quality & accessibility | 1–2 days | ⏳ Pending |
| 5 | Mobile-native polish | 1–2 days | ⏳ Pending |

---

## Phase 1 — Quick Wins

> Goal: Highest visible impact with the least risk. No structural changes.

| # | Task | File(s) | Done |
|---|---|---|---|
| 1.1 | Extract auth inline styles → `auth.css` | `login.php`, `register.php`, new `auth.css` | ✅ |
| 1.2 | Standardize primary color via `--color-primary` CSS variable | `style.css`, `auth.css` | ✅ |
| 1.3 | Add `border-radius` + `z-index` + `type-scale` CSS tokens | `style.css` | ✅ |
| 1.4 | Global form submit: disable button + show "Saving…" on submit | `main.js` | ✅ |
| 1.5 | Add `aria-hidden="true"` to all emoji icons in nav/UI | `header.php`, PHP pages | ✅ |
| 1.6 | Wrap all tables in `overflow-x: auto` container | `style.css` (`.table-responsive`) | ✅ |
| 1.7 | Modal → bottom sheet on mobile (`≤640px`) | `modals.css` | ✅ |
| 1.8 | Add `overscroll-behavior: contain` to modals/inner scrolls | `modals.css` | ✅ |
| 1.9 | Sticky `<thead>` on long tables | `style.css` | ✅ |

---

## Phase 2 — Navigation & UX Flows

| # | Task | File(s) | Done |
|---|---|---|---|
| 2.1 | Collapse top nav into grouped dropdowns (max 5–6 top-level items) | `header.php`, `style.css` | ⬜ |
| 2.2 | Mobile `+` FAB opens quick-add modal (not page navigate) | `header.php`, `footer.php` | ⬜ |
| 2.3 | Replace all `confirm()` dialogs with styled confirmation modal | `includes/confirm-modal.php`, JS files | ⬜ |
| 2.4 | Add empty state components to all list/table pages | Per-page PHP templates | ⬜ |
| 2.5 | Dashboard setup checklist widget for incomplete onboarding | `budget/dashboard.php` | ⬜ |

---

## Phase 3 — CSS Architecture

| # | Task | File(s) | Done |
|---|---|---|---|
| 3.1 | Merge `style.css` + `mobile.css` + `modals.css` → `core.css` | `core.css`, `header.php` | ⬜ |
| 3.2 | Merge component CSS (undo, bulk-ops, help-sidebar, tooltips) → `components.css` | `components.css`, `header.php` | ⬜ |
| 3.3 | Move page-specific CSS to load only on relevant pages | Per-page `<link>` tags | ⬜ |
| 3.4 | Remove inline `style=""` attributes — replace with utility classes | All PHP templates | ⬜ |

---

## Phase 4 — Component Quality & Accessibility

| # | Task | File(s) | Done |
|---|---|---|---|
| 4.1 | Replace emoji icons with Lucide SVG icons in navigation | `header.php`, `style.css` | ⬜ |
| 4.2 | Wrap JS files in IIFE / migrate to ES modules | `public/js/*.js` | ⬜ |
| 4.3 | Define z-index scale in CSS variables, replace all hardcoded values | All CSS files | ⬜ |
| 4.4 | Add visible focus rings to all interactive elements | `style.css` | ⬜ |
| 4.5 | Add skeleton/shimmer loading states to report pages | `reports.css`, report PHP pages | ⬜ |

---

## Phase 5 — Mobile-Native Polish

| # | Task | File(s) | Done |
|---|---|---|---|
| 5.1 | Page-entry animation (fade + slide-up) | `style.css` | ⬜ |
| 5.2 | Swipe-to-delete / swipe-to-edit on transaction rows | `mobile-gestures.js` | ⬜ |
| 5.3 | Redesign report tables as stacked cards on mobile | `reports.css`, report pages | ⬜ |
| 5.4 | `safe-area-inset` padding for iPhone notch on bottom nav | `mobile.css` | ⬜ |

---

## Acceptance Criteria

- **Phase 1 complete when:** All auth page styles are external, one primary color exists, forms don't double-submit, tables scroll on mobile.
- **Phase 2 complete when:** Top nav fits on a 1024px screen without overflow, mobile `+` opens modal, no `confirm()` anywhere in JS.
- **Phase 3 complete when:** `header.php` links ≤ 4 CSS files globally.
- **Phase 4 complete when:** Zero hardcoded z-index values, all interactive elements have focus rings.
- **Phase 5 complete when:** App scores ≥ 90 on Lighthouse Mobile.
