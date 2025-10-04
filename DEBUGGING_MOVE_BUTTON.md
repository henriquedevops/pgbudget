# Debugging Move Money Button

## Issue
The "💸 Move" button on the budget dashboard is not responding to clicks.

## Diagnostic Steps

### 1. Check Browser Console
Open your browser's developer console (F12 or Right-click → Inspect → Console tab) and look for:

**Expected Console Messages:**
```
Move money modal initialized. Found X move buttons
Ledger UUID: [your-ledger-uuid]
Loaded categories: [array of category objects]
```

**If you click a Move button:**
```
Move button clicked: <button>
Category UUID: [uuid] Name: [category name]
```

### 2. Common Issues & Solutions

#### Issue: No console messages at all
**Cause:** JavaScript file not loading

**Check:**
```bash
# Verify file exists
ls -la public/js/move-money-modal.js

# Check file permissions
# Should be readable: -rw-r--r--
```

**Solution:**
- Clear browser cache (Ctrl+F5)
- Check browser console for 404 errors
- Verify script tag in dashboard.php:
  ```html
  <script src="../js/move-money-modal.js"></script>
  ```

#### Issue: "Ledger UUID not found in URL"
**Cause:** Missing ledger parameter in URL

**Check:**
- URL should be: `/budget/dashboard.php?ledger=XXXXXXXX`
- Not: `/budget/dashboard.php`

**Solution:**
- Always access dashboard through proper navigation
- Add ledger parameter to URL manually if needed

#### Issue: "Found 0 move buttons"
**Cause:** Buttons not rendered or wrong selector

**Check:**
```javascript
// In browser console, run:
document.querySelectorAll('.move-money-btn').length
```

**Solution:**
- Ensure categories exist (add categories first)
- Check HTML source for button elements
- Verify `move-money-btn` class exists on buttons

#### Issue: Click not registered (no "Move button clicked" message)
**Cause:** Event listener not attached or button disabled

**Check:**
```javascript
// In browser console, run:
const btn = document.querySelector('.move-money-btn');
console.log('Button:', btn);
console.log('Disabled:', btn.disabled);
console.log('Dataset:', btn.dataset);
```

**Solution:**
- Ensure category has positive balance (button disabled if balance ≤ 0)
- Check if button is actually a button element
- Verify no JavaScript errors blocking execution

#### Issue: Modal doesn't open even with click message
**Cause:** Error in `openMoveMoneyModal` function

**Check browser console for errors:**
- TypeError
- ReferenceError
- DOM errors

### 3. Manual Test

Open browser console and run:

```javascript
// Test if modal function is accessible
if (typeof window.openMoveMoneyModal === 'function') {
    console.log('✓ Modal function exists');
    // Try to open modal manually
    window.openMoveMoneyModal('test-uuid', 'Test Category');
} else {
    console.log('✗ Modal function not found');
}
```

### 4. Check Network Requests

When modal opens and you submit:

**Expected:**
1. POST request to: `/api/move_money.php`
2. Status: 200 OK
3. Response: JSON with success/error

**If API call fails:**
- Check Network tab in dev tools
- Look for 404, 500, or other errors
- Verify API endpoint exists: `public/api/move_money.php`

### 5. Verify Button HTML

View page source and find a Move button. Should look like:

```html
<button type="button"
        class="btn btn-small btn-move move-money-btn"
        data-category-uuid="XXXXXXXX"
        data-category-name="Category Name"
        title="Move money from this category">
    💸 Move
</button>
```

**If button is disabled:**
```html
<button ... disabled="">
```
This means category balance is ≤ $0. You must have money in the category to move it.

### 6. Test with Different Browser

Try in:
- Chrome/Chromium
- Firefox
- Safari
- Edge

If it works in one but not another, it's a browser compatibility issue.

### 7. Check JavaScript Conflicts

**Look for errors like:**
- `$ is not defined` (jQuery conflict)
- `Uncaught TypeError`
- `ReferenceError`

**Solution:**
- Check if other scripts are interfering
- Verify no conflicting event listeners
- Ensure scripts load in correct order

## Quick Fix Checklist

- [ ] Browser cache cleared (Ctrl+F5)
- [ ] JavaScript console open to see messages
- [ ] URL contains `?ledger=XXXXXXXX` parameter
- [ ] Categories exist on the page
- [ ] At least one category has positive balance (not $0.00)
- [ ] Button is not disabled (inspect element)
- [ ] No JavaScript errors in console
- [ ] Script file exists at `public/js/move-money-modal.js`
- [ ] Script tag present at end of dashboard.php

## Working Example

### Successful Flow:

1. **Page Load:**
   ```
   Console: Move money modal initialized. Found 5 move buttons
   Console: Ledger UUID: WkJxi8aN
   Console: Loaded categories: [{uuid: "...", name: "Groceries", balance: 5000}, ...]
   ```

2. **Click Move Button:**
   ```
   Console: Move button clicked: <button.move-money-btn>
   Console: Category UUID: XYZ123 Name: Groceries
   ```

3. **Modal Appears:**
   - Backdrop visible
   - Modal centered on screen
   - Source dropdown shows categories with balance
   - Amount input ready for input

4. **Submit Form:**
   ```
   Network: POST /api/move_money.php
   Status: 200 OK
   Response: {"success": true, ...}
   ```

5. **Success:**
   - Notification appears
   - Categories update
   - Modal closes

## Still Not Working?

### Get Detailed Debug Info

Run this in browser console:

```javascript
// Comprehensive debug report
console.log('=== Move Money Debug Report ===');
console.log('URL:', window.location.href);
console.log('Ledger UUID:', new URLSearchParams(window.location.search).get('ledger'));
console.log('Move buttons found:', document.querySelectorAll('.move-money-btn').length);
console.log('Category rows found:', document.querySelectorAll('.category-row').length);
console.log('Scripts loaded:', document.querySelectorAll('script[src*="move-money"]').length);

const firstBtn = document.querySelector('.move-money-btn');
if (firstBtn) {
    console.log('First button element:', firstBtn);
    console.log('Button disabled:', firstBtn.disabled);
    console.log('Button data:', firstBtn.dataset);
} else {
    console.log('No move buttons found!');
}

// Check if function exists
console.log('openMoveMoneyModal function exists:', typeof window.openMoveMoneyModal === 'function');
```

Copy the output and share it for further diagnosis.

## File Permissions Check

```bash
# All files should be readable
ls -la public/js/move-money-modal.js
# Expected: -rw-r--r--

ls -la public/api/move_money.php
# Expected: -rw-r--r--

ls -la public/budget/dashboard.php
# Expected: -rw-r--r--
```

## Apache/Web Server Check

```bash
# Check Apache error logs
sudo tail -50 /var/log/apache2/error.log

# Check for 404s or permission errors
```

## Database Check

Verify the functions exist:

```sql
-- Connect to database
psql -U pgbudget -d pgbudget

-- Check if functions exist
\df api.move_between_categories
\df utils.move_between_categories

-- Should return function definitions
```

## Last Resort: Re-deploy

If nothing works:

```bash
# Pull latest code
git pull origin main

# Clear browser cache
# Restart Apache
sudo systemctl restart apache2

# Test again
```

## Contact Support

If still not working, provide:
1. Browser console output (all messages)
2. Network tab screenshot (for API calls)
3. Button HTML (inspect element)
4. Any error messages
5. Browser/OS information
