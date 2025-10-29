# Implementation Plan: Friendly Overspending Modal

The goal is to replace the generic "Insufficient funds" error with an interactive modal that guides the user toward a solution, as specified in the usability plan.

**1. Backend: Detect and Signal Overspending**

*   **Identify the Source:** I will first analyze the PostgreSQL functions (likely in the `migrations` directory) to find the exact location where an "insufficient funds" or "overspending" error is triggered during a transaction.
*   **Create a Specific Error Code:** I will modify the database function (e.g., `api.add_transaction`) to catch this specific error. Instead of returning a generic error, it will return a unique, identifiable error code or message (e.g., `P0001` for overspending) along with contextual data like the amount overspent.

**2. Frontend: Intercept the Error and Display the Modal**

*   **Modify Form Submission:** I will update the JavaScript that handles the "Add Transaction" form submission. This script will be modified to check the API response for the new, specific overspending error code.
*   **Create the Modal:** I will create a new, hidden modal dialog in the HTML. This modal will be populated with the details of the overspending error (e.g., "This would overspend your 'Groceries' category by $25.00.").
*   **Display the Modal:** When the specific error is detected, the JavaScript will prevent the default error message from showing and will instead display this new modal to the user.

**3. Frontend: Implement Modal Actions**

The modal will have three buttons, and I will implement the functionality for each:

*   **"Move Money":**
    *   This will trigger another modal or view for moving money between categories.
    *   It will call the existing `api.move_money` function.
    *   Upon a successful move, it will automatically re-submit the original transaction.
*   **"Record Anyway":**
    *   This will re-submit the transaction to the backend, but with a special flag indicating that the user has approved the overspending.
    *   I will modify the `api.add_transaction` function to accept this flag and bypass the overspending check.
*   **"Cancel":**
    *   This will simply close the modal, allowing the user to return to the form and correct the transaction amount.
