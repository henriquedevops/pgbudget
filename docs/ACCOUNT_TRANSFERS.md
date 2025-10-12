# Account Transfers Feature

**Phase 3.5 - Account Transfers Simplified**

## Overview

The Account Transfers feature simplifies moving money between accounts in PgBudget. Instead of manually creating double-entry transactions, users can now use a dedicated transfer interface that automatically handles the accounting logic.

## Features

### 1. Simple Transfer Modal

- **Visual Interface**: Shows a clear "From → To" visual representation of the transfer
- **Smart Date Picker**: Quick buttons for "Today" and "Yesterday"
- **Amount Display**: Large, clear display of the transfer amount as you type
- **Optional Memo**: Add notes to describe the transfer
- **Validation**: Prevents invalid transfers (same account, negative amounts, etc.)

### 2. Easy Access

The transfer functionality is available in multiple locations:

- **Budget Dashboard**: "⇄ Transfer" button in the main action bar
- **Account Pages**: "⇄ Transfer Money" button (only for asset/liability accounts)
- **Pre-filled Forms**: When accessed from an account page, the source account is pre-selected

### 3. Automatic Transaction Creation

When you create a transfer, the system automatically:
- Creates a proper double-entry transaction
- Sets descriptive text: "Transfer to: [Account]" and "Transfer from: [Account]"
- Maintains zero-sum accounting (debit = credit)
- Marks the transaction type as "transfer" in metadata

## How to Use

### Creating a Transfer

1. **Open the Transfer Modal**
   - Click the "⇄ Transfer" button on the Budget Dashboard, or
   - Click the "⇄ Transfer Money" button on an account page

2. **Select Accounts**
   - Choose the source account (where money is coming from)
   - Choose the destination account (where money is going to)
   - Visual display updates to show the transfer direction

3. **Enter Amount**
   - Type the transfer amount (supports comma and period as decimal separators)
   - Amount display shows formatted value

4. **Select Date**
   - Use quick buttons ("Today" or "Yesterday"), or
   - Pick a custom date from the date picker

5. **Add Memo (Optional)**
   - Add a note to describe why you're making this transfer
   - Example: "Moving savings to checking for bill payment"

6. **Submit Transfer**
   - Click "Transfer Money" button
   - Success message appears
   - Page reloads to show updated balances

## Database Schema

### Functions

#### `utils.add_account_transfer()`

Core function that creates the transfer transaction.

**Parameters:**
- `p_ledger_uuid` TEXT - The ledger UUID
- `p_from_account_uuid` TEXT - Source account UUID
- `p_to_account_uuid` TEXT - Destination account UUID
- `p_amount` NUMERIC(15,2) - Transfer amount (must be positive)
- `p_date` DATE - Transaction date
- `p_memo` TEXT - Optional memo (default: NULL)

**Returns:** TEXT - UUID of created transaction

**Validations:**
- Amount must be positive
- Accounts must be different
- Both accounts must exist
- Both accounts must belong to the specified ledger
- Both accounts must be asset or liability type

**Transaction Structure:**
```sql
-- Transaction record
INSERT INTO data.transactions (uuid, ledger_uuid, date, description, metadata)
VALUES (uuid, ledger_uuid, date, memo, {type: 'transfer', memo: memo});

-- Debit split (decrease source account)
INSERT INTO data.splits (transaction_uuid, account_uuid, amount, metadata)
VALUES (uuid, from_account_uuid, -amount, {description: 'Transfer to: [Name]'});

-- Credit split (increase destination account)
INSERT INTO data.splits (transaction_uuid, account_uuid, amount, metadata)
VALUES (uuid, to_account_uuid, +amount, {description: 'Transfer from: [Name]'});
```

#### `api.add_account_transfer()`

RLS-aware wrapper function for the API layer.

**Additional Features:**
- Validates user context from session
- Verifies ledger ownership
- Enforces Row-Level Security

## API Endpoints

### POST `/api/account-transfer.php`

Creates a new account transfer.

**Request Body (JSON):**
```json
{
  "ledger_uuid": "eNF2EkfD",
  "from_account_uuid": "abc123",
  "to_account_uuid": "def456",
  "amount": 100.50,
  "date": "2025-10-12",
  "memo": "Optional memo text"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "transaction_uuid": "xyz789",
  "message": "Transfer created successfully"
}
```

**Error Responses:**

400 Bad Request:
```json
{
  "success": false,
  "error": "Amount must be a positive number"
}
```

Possible error messages:
- "Missing required field: [field]"
- "Amount must be a positive number"
- "Invalid date format. Use YYYY-MM-DD"
- "Cannot transfer to the same account"
- "Transfer amount must be positive"
- "Source account not found"
- "Destination account not found"
- "Both accounts must belong to the specified ledger"
- "Source account must be an asset or liability account"
- "Destination account must be an asset or liability account"
- "Access denied: Ledger does not belong to current user"

## Files

### Backend
- **Migration**: `/migrations/20251012150000_add_account_transfer_function.sql`
  - Creates `utils.add_account_transfer()` function
  - Creates `api.add_account_transfer()` wrapper

- **API Endpoint**: `/public/api/account-transfer.php`
  - Handles POST requests for creating transfers
  - Validates input and calls API function

### Frontend
- **Modal UI**: `/includes/transfer-modal.php`
  - HTML structure and CSS styling for transfer modal
  - Visual transfer display components

- **JavaScript**: `/public/js/transfer-modal.js`
  - TransferModal module with public API
  - Form validation and submission
  - Amount parsing (handles comma/period decimal separators)
  - Date helper functions

### Integration Points
- **Footer**: `/includes/footer.php`
  - Includes transfer modal for authenticated users
  - Loads transfer modal JavaScript

- **Budget Dashboard**: `/public/budget/dashboard.php`
  - "⇄ Transfer" button in action bar

- **Account Page**: `/public/transactions/account.php`
  - "⇄ Transfer Money" button (asset/liability accounts only)
  - Pre-fills source account

## JavaScript API

### TransferModal.open(options)

Opens the transfer modal.

**Options:**
```javascript
{
  ledger_uuid: 'eNF2EkfD',           // Required
  from_account_uuid: 'abc123',       // Optional (pre-selects source account)
  to_account_uuid: 'def456'          // Optional (pre-selects destination account)
}
```

**Example Usage:**
```javascript
// Basic usage
TransferModal.open({
  ledger_uuid: 'eNF2EkfD'
});

// With pre-selected source account
TransferModal.open({
  ledger_uuid: 'eNF2EkfD',
  from_account_uuid: 'abc123'
});
```

### TransferModal.close()

Closes the transfer modal.

### TransferModal.setDateToday()

Sets the date field to today's date.

### TransferModal.setDateYesterday()

Sets the date field to yesterday's date.

## User Experience

### Visual Feedback

1. **Transfer Direction**
   - Color-coded boxes: Red for source (From), Green for destination (To)
   - Arrow between accounts shows direction
   - Account names displayed clearly

2. **Amount Display**
   - Large formatted amount shown as you type
   - Helps prevent data entry errors

3. **Loading States**
   - Submit button shows spinner during transfer
   - Button text changes to "Transferring..."
   - Button disabled during submission

4. **Success/Error Messages**
   - Success: Green banner with confirmation
   - Error: Red banner with specific error message
   - Auto-reload after successful transfer

### Keyboard Shortcuts

- **Escape**: Close modal
- **Tab**: Navigate between fields
- **Enter**: Submit form (when focused on input)

## Security

### Authorization
- User must be authenticated
- User must own the ledger
- RLS policies enforce data isolation

### Validation
- Server-side validation of all inputs
- Amount must be positive decimal
- Date must be valid YYYY-MM-DD format
- Accounts must exist and belong to user's ledger
- Accounts must be appropriate type (asset/liability)

### SQL Injection Prevention
- All database queries use prepared statements
- Input sanitization via `sanitizeInput()`

## Limitations

1. **Account Types**: Only asset and liability accounts can be used in transfers
   - Revenue, expense, and equity accounts are excluded
   - This matches accounting best practices

2. **Same Ledger**: Both accounts must belong to the same ledger
   - Cross-ledger transfers are not supported

3. **Amount Precision**: Limited to 2 decimal places (cents)
   - Stored as NUMERIC(15,2) in database

## Future Enhancements

Potential improvements for future versions:

1. **Scheduled Transfers**
   - Recurring transfers (e.g., monthly savings)
   - Integration with recurring transactions feature

2. **Transfer History**
   - Dedicated page showing transfer transactions
   - Filter transactions by type (transfer vs. regular)

3. **Quick Transfer Templates**
   - Save frequently used transfer routes
   - One-click transfers for common scenarios

4. **Split Transfers**
   - Transfer to multiple accounts at once
   - Useful for splitting income or moving funds

## Troubleshooting

### Transfer button doesn't appear
- **Cause**: Account is not asset or liability type
- **Solution**: Transfers only work with asset/liability accounts

### "Account not found" error
- **Cause**: Account UUID is invalid or deleted
- **Solution**: Verify accounts exist and refresh page

### "Access denied" error
- **Cause**: Ledger doesn't belong to current user
- **Solution**: Check you're using the correct ledger

### Modal doesn't open
- **Cause**: JavaScript not loaded or ledger UUID missing
- **Solution**: Check browser console for errors, verify footer includes transfer modal

### Amount not parsing correctly
- **Cause**: Unexpected format or characters
- **Solution**: Use only numbers with optional comma or period for decimal
- **Supported formats**: "100.50", "100,50", "1000.50", "1.000,50"

## Related Documentation

- [YNAB Comparison and Enhancement Plan](YNAB_COMPARISON_AND_ENHANCEMENT_PLAN.md) - Phase 3.5
- [Quick-Add Transaction Modal](QUICK_ADD_MODAL.md) - Phase 3.4
- [Recurring Transactions](RECURRING_TRANSACTIONS.md) - Phase 3.2

## Testing

Manual testing checklist:

- [ ] Open transfer modal from dashboard
- [ ] Open transfer modal from account page
- [ ] Select source and destination accounts
- [ ] Verify visual display updates
- [ ] Enter valid amount
- [ ] Select date using quick buttons
- [ ] Add optional memo
- [ ] Submit valid transfer
- [ ] Verify success message
- [ ] Check transaction appears in both accounts
- [ ] Verify balances updated correctly
- [ ] Test validation errors (same account, negative amount, etc.)
- [ ] Test keyboard shortcut (Escape to close)
- [ ] Test mobile responsive design
- [ ] Test with comma decimal separator (European format)
- [ ] Test with period decimal separator (US format)

## Support

For issues or questions about account transfers:
1. Check this documentation first
2. Review browser console for JavaScript errors
3. Check Apache error logs for PHP errors
4. Report issues via GitHub issues
