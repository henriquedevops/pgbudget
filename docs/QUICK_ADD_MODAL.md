# Quick-Add Transaction Modal (Phase 3.4)

## Overview

The Quick-Add Transaction Modal provides a fast, convenient way to add transactions from anywhere in PGBudget without navigating away from the current page.

## Features

### ⚡ Quick Access
- **Keyboard Shortcut**: Press `T` anywhere in the application to instantly open the modal
- **Quick Add Button**: Visible button on the budget dashboard for easy access
- **Modal Overlay**: Form appears as an overlay without page navigation

### 📅 Smart Date Selection
- **Today**: Default option for most transactions
- **Yesterday**: Quick access for transactions you forgot to log
- **Custom Date**: Date picker for older transactions

### 💰 Streamlined Form
- **Type Toggle**: Easy switch between Income and Expense
- **Amount Input**: Supports both comma (,) and period (.) as decimal separators
- **Description**: Quick description field
- **Payee**: Optional payee field with autocomplete
- **Account**: Select from your budget's accounts
- **Category**: Automatically required for expenses, optional for income

### 🔄 Save & Add Another
- **Checkbox option** to keep the modal open after saving
- Perfect for logging multiple transactions in a row
- Maintains your selections for faster data entry

### 🎨 User Experience
- **Responsive Design**: Works on desktop and mobile devices
- **Auto-fill**: Pre-fills account if opened from an account page
- **Validation**: Real-time validation of required fields
- **Success/Error Messages**: Clear feedback on transaction status
- **ESC to Close**: Press ESC to close the modal at any time

## How to Use

### Method 1: Keyboard Shortcut
1. From any page in PGBudget, press the `T` key
2. The Quick-Add modal appears instantly
3. Fill in the transaction details
4. Press Submit or ESC to close

### Method 2: Quick Add Button
1. Navigate to your budget dashboard
2. Click the "⚡ Quick Add" button in the top actions area
3. Fill in the transaction details
4. Click "Add Transaction" to save

### Adding Multiple Transactions
1. Open the Quick-Add modal
2. Check the "Save & Add Another" checkbox
3. Fill in and submit your first transaction
4. The modal stays open and resets for the next transaction
5. Uncheck the box or press ESC when done

## Technical Details

### Files Created
- `/includes/quick-add-modal.php` - Modal HTML and CSS
- `/public/js/quick-add-modal.js` - Modal JavaScript functionality
- `/public/api/quick-add-transaction.php` - API endpoint for saving transactions
- `/public/api/ledger-data.php` - API endpoint for loading accounts and categories

### Integration
The modal is automatically included in the footer for all authenticated users. It's available on every page without additional setup.

### API Endpoints

#### POST `/public/api/quick-add-transaction.php`
Adds a transaction via AJAX.

**Request Body:**
```json
{
  "ledger_uuid": "abc123",
  "type": "inflow|outflow",
  "amount": "50.00",
  "date": "2025-10-12",
  "description": "Grocery shopping",
  "payee": "Whole Foods",
  "account": "account_uuid",
  "category": "category_uuid"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Transaction added successfully!",
  "transaction_uuid": "txn_uuid"
}
```

#### GET `/public/api/ledger-data.php?ledger=uuid`
Returns accounts and categories for a specific ledger.

**Response:**
```json
{
  "success": true,
  "ledger": {
    "uuid": "abc123",
    "name": "My Budget"
  },
  "accounts": [
    {
      "uuid": "acc_uuid",
      "name": "Checking Account",
      "type": "asset",
      "balance": 100000
    }
  ],
  "categories": [
    {
      "uuid": "cat_uuid",
      "name": "Groceries"
    }
  ]
}
```

### JavaScript API

#### Opening the Modal Programmatically
```javascript
// Basic usage
QuickAddModal.open();

// With options
QuickAddModal.open({
  ledger_uuid: 'abc123',    // Pre-select ledger
  account_uuid: 'acc123'     // Pre-select account
});
```

#### Closing the Modal
```javascript
QuickAddModal.close();
```

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `T` | Open Quick-Add Modal |
| `ESC` | Close modal |
| `Enter` | Submit form (when in input field) |
| `↑` / `↓` | Navigate payee suggestions |
| `Enter` | Select highlighted payee suggestion |

## Browser Compatibility

- ✅ Chrome/Edge (latest)
- ✅ Firefox (latest)
- ✅ Safari (latest)
- ✅ Mobile browsers (iOS Safari, Chrome Android)

## Security

- All API endpoints require authentication
- CSRF protection via session validation
- Input sanitization on all fields
- RLS (Row-Level Security) enforced at database level
- XSS prevention via output escaping

## Future Enhancements

Potential improvements for future versions:
- [ ] Remember last-used account/category per user
- [ ] Transaction templates
- [ ] Split transactions from quick-add
- [ ] Recurring transaction creation
- [ ] Attachment upload support
- [ ] Voice input for amount and description
- [ ] Barcode scanner integration (mobile)

## Comparison to Full Transaction Page

| Feature | Quick-Add Modal | Full Page |
|---------|----------------|-----------|
| Speed | ⚡ Fast (no page load) | 🐌 Slower (page navigation) |
| Split Transactions | ❌ No | ✅ Yes |
| Attachments | ❌ No | ✅ Yes (future) |
| Memo Field | ❌ No | ✅ Yes |
| Keyboard Shortcut | ✅ Yes (`T` key) | ❌ No |
| Save & Add Another | ✅ Yes | ❌ No |
| Mobile Friendly | ✅ Yes | ⚠️ Acceptable |

## Troubleshooting

### Modal doesn't open
- **Issue**: Pressing `T` doesn't open the modal
- **Solution**: Make sure you're not focused in an input field. Click outside any form fields first.

### "No budget specified" error
- **Issue**: Modal shows error about missing budget
- **Solution**: Navigate to a budget dashboard first, or pass `ledger_uuid` when opening programmatically.

### Accounts/Categories not loading
- **Issue**: Dropdown menus are empty
- **Solution**: Check that the ledger has accounts and categories created. Check browser console for API errors.

### Transaction not saving
- **Issue**: Submit button shows loading but transaction doesn't save
- **Solution**: Check browser console for errors. Verify all required fields are filled in. Check that category is selected for expenses.

## Support

For issues or questions:
1. Check this documentation
2. Review browser console for errors
3. Check PHP error logs at `/logs/`
4. Report bugs via your issue tracking system

## Credits

Implemented as part of Phase 3.4 of the YNAB Comparison & Enhancement Plan.
