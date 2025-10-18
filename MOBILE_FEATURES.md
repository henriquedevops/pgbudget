# PGBudget Mobile Features

## Phase 6.5: Mobile Optimization - Implementation Guide

This document describes the mobile optimization features implemented in PGBudget as part of Phase 6.5 from the YNAB Comparison and Enhancement Plan.

---

## Features Overview

### 1. Progressive Web App (PWA)

PGBudget can now be installed as a Progressive Web App on mobile devices and desktops.

**Benefits:**
- Install to home screen like a native app
- Offline functionality with data caching
- Background sync when connection restored
- Push notifications (ready for implementation)
- Standalone mode without browser chrome

**Files:**
- `/public/manifest.json` - PWA manifest configuration
- `/public/service-worker.js` - Service worker for offline support
- `/public/offline.html` - Offline fallback page

**Installation:**
1. Open PGBudget in Chrome/Edge/Safari on mobile
2. Tap "Add to Home Screen" when prompted
3. Or use the browser menu → "Install App"

---

### 2. Touch-Friendly Interface

All interactive elements meet the 44x44px minimum touch target size for accessibility.

**Improvements:**
- Larger buttons (min 44px height)
- Increased tap targets for checkboxes and links
- Mobile-optimized form inputs (16px font to prevent zoom)
- Proper spacing between interactive elements

**File:** `/public/css/mobile.css`

---

### 3. Swipe Gestures

Swipe gestures for quick actions on transaction rows (mobile only).

**Gestures:**
- **Swipe Left** → Shows Edit and Delete buttons
- **Swipe Right** → Quick action (future: mark cleared/uncleared)
- Tap outside swiped element to close

**Implementation:**
- JavaScript class: `MobileGestures` in `/public/js/mobile-gestures.js`
- CSS: Swipe action styles in `/public/css/mobile.css`
- Touch event handling with passive listeners for performance

**Usage:**
```javascript
// Automatically initialized on touch devices
// Add 'swipeable' class to enable on elements
<tr class="transaction-row swipeable">
```

---

### 4. Mobile Navigation

Two navigation systems for optimal mobile UX:

#### Hamburger Menu (Top)
- Responsive hamburger menu for main navigation
- Slides in from side on mobile
- Auto-closes on link click or outside tap

#### Bottom Navigation Bar
- Fixed bottom navigation with 5 main actions
- Always visible on mobile devices
- Active state highlighting
- Icons with labels for clarity

**Navigation Items:**
- Home 🏠
- Budget 💰
- Add Transaction ➕
- Transactions 📋
- Settings ⚙️

**File:** Updated in `/includes/footer.php`

---

### 5. Responsive Design

Comprehensive responsive breakpoints:

- **Mobile:** < 768px
  - Single column layouts
  - Card-based transaction view
  - Stacked forms
  - Bottom navigation

- **Tablet:** 768px - 1024px
  - Two-column layouts
  - Optimized spacing
  - Desktop menu visible

- **Desktop:** > 1024px
  - Full desktop experience
  - Multi-column layouts

**File:** `/public/css/mobile.css`

---

### 6. Offline Support

Service worker provides offline functionality:

**Caching Strategy:**
- **Static files:** Cache-first (CSS, JS, images)
- **API requests:** Network-first with cache fallback
- **Dynamic content:** Stale-while-revalidate

**Offline Features:**
- View cached budget data
- Browse cached transactions
- Offline page when no cache available
- Auto-sync when connection restored

**Background Sync:**
```javascript
// Queued transactions sync when online
navigator.serviceWorker.ready.then(registration => {
    return registration.sync.register('sync-transactions');
});
```

---

### 7. Performance Optimizations

**Hardware Acceleration:**
- CSS transforms use `translateZ(0)` for GPU acceleration
- `will-change` property on animated elements

**Reduced Motion:**
- Respects `prefers-reduced-motion` media query
- Disables animations for accessibility

**Passive Event Listeners:**
- Touch events use passive listeners
- Improves scroll performance

---

## Installation & Setup

### 1. Enable Mobile Features

Mobile features are automatically enabled. Ensure these files are accessible:

```
/public/manifest.json
/public/service-worker.js
/public/offline.html
/public/css/mobile.css
/public/js/mobile-gestures.js
```

### 2. Add PWA Icons

Create app icons in `/public/images/`:
- icon-72x72.png
- icon-96x96.png
- icon-128x128.png
- icon-144x144.png
- icon-152x152.png
- icon-192x192.png
- icon-384x384.png
- icon-512x512.png

**Icon Requirements:**
- PNG format
- Square aspect ratio
- Transparent or solid background
- Simple, recognizable design

**Quick Generate:**
```bash
# Use ImageMagick to generate from SVG/large PNG
convert logo.png -resize 192x192 icon-192x192.png
convert logo.png -resize 512x512 icon-512x512.png
# etc.
```

### 3. HTTPS Requirement

PWA features require HTTPS in production:
- Service workers only work on HTTPS
- Or localhost for development

---

## Browser Support

### Full Support
- Chrome/Edge 90+ (Android/Desktop)
- Safari 14+ (iOS/macOS)
- Firefox 90+ (Android/Desktop)

### Partial Support
- Older browsers: Basic responsive design only
- No PWA installation
- No offline mode
- Gestures still work

---

## Testing Mobile Features

### 1. Responsive Design
```
Chrome DevTools → Toggle Device Toolbar (Ctrl+Shift+M)
Test at: 375px (mobile), 768px (tablet), 1024px (desktop)
```

### 2. Touch Gestures
- Use Chrome DevTools touch emulation
- Or test on real mobile device

### 3. PWA Installation
```
Chrome DevTools → Application → Manifest
Check for errors
Test "Add to Home Screen"
```

### 4. Service Worker
```
Chrome DevTools → Application → Service Workers
Verify registration
Test offline mode (Network → Offline)
```

### 5. Performance
```
Chrome DevTools → Lighthouse
Run Mobile audit
Target scores: 90+ Performance, 100 PWA
```

---

## Mobile UX Best Practices

### Touch Targets
✅ **Do:**
- Use min 44x44px for all tappable elements
- Add padding to increase tap area
- Space interactive elements 8px apart

❌ **Don't:**
- Place small buttons close together
- Use text links without padding
- Rely on hover states

### Forms
✅ **Do:**
- Use 16px font size (prevents iOS zoom)
- Use appropriate input types (tel, email, number)
- Provide clear labels above inputs
- Use large, prominent submit buttons

❌ **Don't:**
- Use tiny fonts (< 16px)
- Place labels inside inputs only
- Use generic text inputs for specialized data

### Navigation
✅ **Do:**
- Keep primary actions at bottom (thumb-friendly)
- Use clear, recognizable icons
- Provide visual active states
- Enable swipe-back gestures

❌ **Don't:**
- Put important actions at top only
- Use icon-only navigation without labels
- Rely solely on hamburger menu

### Performance
✅ **Do:**
- Use passive event listeners
- Lazy load images and content
- Minimize reflows and repaints
- Cache static assets

❌ **Don't:**
- Block the main thread
- Load all content upfront
- Use heavy animations
- Ignore offline scenarios

---

## Keyboard & Accessibility

Mobile optimizations maintain accessibility:

- **Focus indicators:** Visible on all interactive elements
- **Skip to content:** Link for screen readers
- **ARIA labels:** On icon-only buttons
- **Semantic HTML:** Proper heading hierarchy
- **Touch targets:** Meet WCAG 2.1 Level AAA (44x44px)

---

## Future Enhancements

Planned mobile improvements:

1. **Biometric Authentication**
   - Face ID / Touch ID / Fingerprint
   - Quick unlock on app open

2. **Camera Integration**
   - Receipt scanning
   - OCR for transaction details

3. **Geolocation**
   - Auto-tag transactions by location
   - Merchant suggestions

4. **Share Target API**
   - Share receipts to PGBudget
   - Quick add from other apps

5. **Haptic Feedback**
   - Vibration on swipe actions
   - Touch feedback for confirmations

6. **Voice Input**
   - "Add $50 to groceries"
   - Hands-free transaction entry

---

## Troubleshooting

### Service Worker Not Registering
- Check browser console for errors
- Ensure HTTPS (or localhost)
- Clear browser cache
- Check manifest.json syntax

### PWA Install Prompt Not Showing
- Must be HTTPS
- Requires service worker
- User must visit site 2+ times
- Chrome: Check chrome://flags for PWA settings

### Offline Mode Not Working
- Verify service worker is active
- Check cached resources in DevTools
- Ensure network is truly offline
- Clear cache and re-register worker

### Swipe Gestures Not Working
- Verify 'swipeable' class is applied
- Check if touch events are supported
- Ensure mobile-gestures.js is loaded
- Test in Chrome DevTools touch mode

---

## Architecture

### Service Worker Lifecycle

```
Install → Wait → Activate → Fetch
   ↓        ↓        ↓         ↓
 Cache   Update   Cleanup   Serve
```

### PWA Caching Strategy

```
Request → Service Worker
            ↓
       Cache First?
       /          \
     Yes          No
      ↓            ↓
   Cache      Network First
      ↓            ↓
  Fallback    Cache Response
      ↓
   Network
```

---

## Performance Metrics

Target scores for mobile:

- **Lighthouse Performance:** 90+
- **PWA Score:** 100
- **Accessibility:** 100
- **Best Practices:** 95+
- **SEO:** 100

**First Contentful Paint:** < 1.8s
**Time to Interactive:** < 3.8s
**Speed Index:** < 3.4s
**Total Blocking Time:** < 200ms

---

## Resources

- [PWA Documentation](https://web.dev/progressive-web-apps/)
- [Service Worker API](https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API)
- [Touch Events](https://developer.mozilla.org/en-US/docs/Web/API/Touch_events)
- [Web App Manifest](https://developer.mozilla.org/en-US/docs/Web/Manifest)
- [Mobile UX Best Practices](https://developers.google.com/web/fundamentals/design-and-ux/principles)

---

## Credits

Mobile optimization implemented as part of Phase 6.5 following the YNAB Comparison and Enhancement Plan.

**Key Technologies:**
- Service Workers for offline support
- Web App Manifest for PWA
- Touch Events API for gestures
- CSS Grid/Flexbox for responsive layout
- IndexedDB for offline data storage
