# PGBudget Keyboard Shortcuts

## Phase 6.4: Power User Keyboard Navigation

Complete keyboard shortcut reference for PGBudget. Press **?** (Shift + /) anywhere in the app to see this help.

---

## 🎯 Quick Reference

### Navigation (G + key)
Press **G** then another key to navigate:

| Shortcut | Action | Description |
|----------|--------|-------------|
| **G** then **B** | Go to Budget | Navigate to budget dashboard |
| **G** then **A** | Go to Accounts | Navigate to accounts/home page |
| **G** then **T** | Go to Transactions | Navigate to transaction list |
| **G** then **R** | Go to Reports | Navigate to reports |
| **G** then **H** | Go to Home | Navigate to home page |

---

## ⚡ Actions

Global shortcuts available on most pages:

| Shortcut | Action | Description |
|----------|--------|-------------|
| **T** | New Transaction | Open new transaction form |
| **A** | Assign Money | Go to assign money page |
| **M** | Move Money | Open move money modal |
| **C** | Create Category | Go to create category page |
| **/** | Focus Search | Jump to search box |
| **Ctrl + K** | Focus Search | Alternative search shortcut |
| **?** | Show Help | Display keyboard shortcuts help |

---

## 📊 Budget Screen

Shortcuts when viewing the budget dashboard:

| Shortcut | Action | Description |
|----------|--------|-------------|
| **↑** | Navigate Up | Move to previous category |
| **↓** | Navigate Down | Move to next category |
| **Enter** | Edit Selected | Edit the selected category budget |
| **Tab** | Next Category | Move to next category (natural tab order) |
| **Esc** | Close Modal | Close any open modal or cancel edit |

---

## 📋 Transaction List

Shortcuts when viewing transactions:

| Shortcut | Action | Description |
|----------|--------|-------------|
| **J** | Next Transaction | Move to next transaction (vim-style) |
| **K** | Previous Transaction | Move to previous transaction (vim-style) |
| **E** | Edit Transaction | Edit the selected transaction |
| **D** | Delete Transaction | Delete selected (with confirmation) |
| **X** | Toggle Cleared | Toggle cleared/uncleared status |
| **↑** / **↓** | Navigate | Alternative navigation (arrow keys) |

---

## 🎨 Visual Indicators

### Keyboard Selection
When you navigate with keyboard shortcuts, selected items are highlighted with:
- **Blue outline** - Shows the currently selected item
- **Blue arrow (▶)** - Indicates keyboard focus
- **Light blue background** - Highlights the active row

### Shortcut Feedback
When you use a shortcut, a brief notification appears in the bottom-right showing what action was triggered.

### Help Modal
Press **?** to see the full keyboard shortcuts help modal with all available shortcuts organized by category.

---

## 🔧 How It Works

### Sequence Shortcuts (G + key)
1. Press **G**
2. Within 1 second, press the second key (**B**, **A**, **T**, etc.)
3. Action executes immediately

Example: **G** → **B** = Go to Budget

### Single Key Shortcuts
Simply press the key once (when not typing in a form):
- **T** = New transaction
- **/** = Focus search

### Modifier Shortcuts
Hold modifier key + press letter:
- **Ctrl + K** = Focus search

---

## 💡 Smart Behavior

### Input Detection
Keyboard shortcuts are **automatically disabled** when typing in:
- Text inputs
- Textareas
- Select dropdowns
- Contenteditable elements

**Exception:** **Esc** key always works to cancel/close

### Current Context
Some shortcuts are context-aware:
- Navigation shortcuts use current ledger when available
- List shortcuts only work on list pages
- Budget shortcuts only work on budget pages

### Scroll to View
When navigating with keyboard, selected items automatically scroll into view with smooth scrolling.

---

## 🎓 Tips & Tricks

### Power User Workflow

**Quick Budget Entry:**
1. **G** → **B** (Go to Budget)
2. **↓** **↓** **↓** (Navigate to category)
3. **Enter** (Edit budget)
4. Type amount, **Enter**

**Fast Transaction Entry:**
1. **T** (New transaction)
2. Fill form with **Tab** navigation
3. **Enter** to submit

**Quick Search:**
1. **/** or **Ctrl + K**
2. Start typing
3. **Enter** to search

### Vim-Style Navigation
For vim users, **J** and **K** work like expected:
- **J** = Down/Next
- **K** = Up/Previous

### Esc to Cancel Everything
Press **Esc** anytime to:
- Close modals
- Cancel edits
- Unfocus inputs
- Clear keyboard selection

---

## 🔐 Accessibility

### Screen Reader Support
- All shortcuts work with screen readers
- Focus management follows ARIA best practices
- Visual indicators have proper ARIA labels

### Keyboard-Only Navigation
The entire app is fully navigable with just the keyboard:
1. **Tab** / **Shift + Tab** - Navigate between elements
2. **Enter** / **Space** - Activate buttons
3. **Arrow keys** - Navigate lists
4. **Esc** - Close/cancel

### Focus Visible
Enhanced focus indicators show exactly where keyboard focus is:
- **3px blue outline** for clear visibility
- **2px offset** for better contrast
- Only visible when navigating with keyboard

---

## 🚫 Limitations

### Not Available When:
- Typing in form inputs (except Esc)
- On non-authenticated pages (login/register)
- In browser-controlled inputs (file upload)

### Current Limitations:
- Custom shortcut configuration (coming in future)
- Shortcut conflicts with browser (some may not work)
- No multi-select with keyboard yet

---

## 🛠️ Customization (Future)

Planned features for upcoming releases:
- **Custom shortcut mapping** - Define your own shortcuts
- **Shortcut profiles** - Different sets for different workflows
- **Import/Export** - Share shortcut configurations
- **Conflict detection** - Warn about overlapping shortcuts

---

## 🐛 Troubleshooting

### Shortcuts Not Working?

**Check these:**
1. Are you typing in an input field?
2. Is another modal/overlay open?
3. Did you press keys fast enough? (1 second for sequences)
4. Check browser console for errors

**Common Issues:**
- **Browser shortcuts override** - Some shortcuts like Ctrl+T may be taken by browser
- **Focus lost** - Click on page first if focus is outside the app
- **Modal blocking** - Close any open modals first

### Disable Shortcuts Temporarily
Open browser console and run:
```javascript
window.keyboardShortcuts.disable();
```

To re-enable:
```javascript
window.keyboardShortcuts.enable();
```

---

## 📱 Mobile Considerations

Keyboard shortcuts are **automatically disabled** on touch devices since:
- Virtual keyboards don't support all key combinations
- Touch gestures are better suited for mobile
- Physical keyboards on tablets still work

---

## 🎯 Best Practices

### Learning the Shortcuts
1. **Start with navigation** - G+B, G+T are most useful
2. **Use ? for reference** - Always available
3. **Practice one at a time** - Don't try to learn all at once
4. **Stick to favorites** - Use mouse for less common actions

### Efficiency Tips
- **Chain actions**: G→B (Budget) → ↓↓ (Navigate) → Enter (Edit)
- **Use search**: / is faster than clicking through menus
- **Esc is your friend**: Cancel anything, anywhere

---

## 🚀 Advanced Usage

### Developer Console Integration
Access the shortcuts manager in console:
```javascript
// View all registered shortcuts
window.keyboardShortcuts.shortcuts

// Programmatically trigger a shortcut
window.keyboardShortcuts.focusSearch()

// Navigate programmatically
window.keyboardShortcuts.goToBudget()
```

### Custom Event Listeners
Listen for shortcut events:
```javascript
document.addEventListener('shortcut-triggered', (e) => {
    console.log('Shortcut used:', e.detail);
});
```

---

## 📊 Comparison with YNAB

PGBudget keyboard shortcuts are inspired by YNAB but adapted for our architecture:

| Feature | YNAB | PGBudget | Status |
|---------|------|----------|--------|
| Navigation (G+key) | ✓ | ✓ | ✓ Complete |
| Action shortcuts | ✓ | ✓ | ✓ Complete |
| List navigation | ✓ | ✓ | ✓ Complete |
| Help modal (?) | ✓ | ✓ | ✓ Complete |
| Custom mapping | ✓ | ✗ | Planned |
| Vim bindings (J/K) | ✗ | ✓ | ✓ Bonus! |

---

## 🎓 Keyboard Shortcut Philosophy

**Why keyboard shortcuts matter:**
1. **Speed** - 10x faster than mouse for power users
2. **Flow** - Keep hands on keyboard, stay in the zone
3. **Accessibility** - Essential for users who can't use a mouse
4. **Productivity** - Muscle memory makes budgeting effortless

**Our approach:**
- **Intuitive** - Shortcuts match their actions (G for Go, T for Transaction)
- **Discoverable** - Help always available with ?
- **Safe** - Destructive actions still require confirmation
- **Flexible** - Works alongside mouse/touch, not instead of

---

## 📚 Further Reading

- [WCAG 2.1 Keyboard Guidelines](https://www.w3.org/WAI/WCAG21/Understanding/keyboard.html)
- [Keyboard Navigation Best Practices](https://www.nngroup.com/articles/keyboard-accessibility/)
- [Vim Keyboard Philosophy](https://www.vim.org/docs.php) (inspiration for J/K navigation)

---

## 💬 Feedback

Have suggestions for new shortcuts or improvements?
- Open an issue on GitHub
- Focus on shortcuts you use daily
- Consider conflicts with existing shortcuts

---

**Last Updated:** October 2025
**Version:** 1.0.0
**Phase:** 6.4 - Keyboard Shortcuts
