Systematic ERP Bug Fix & Enhancement Protocol
Objective: Audit the current ERP codebase to resolve critical usability bugs, localization gaps, and logic errors identified during the "Quote-to-Cash" walkthrough. Guiding Principle: Prioritize Context Preservation (users should not lose their place) and Optimistic UI (pre-fill data whenever possible).

Phase 1: Global Localization & Architecture
1.1 Internationalization (i18n) Audit
Problem: The UI is set to "French," but critical elements remain in English. Directives:

Static Strings: Scan all Sidebar items ("Recent Documents", "Revenue"), Page Titles, and Table Headers. Replace hardcoded English strings with translation keys (e.g., t('dashboard.revenue')).

Database Enums: The status tags (e.g., Draft, Active, Inactive) are rendering raw database values.

Action: Create a mapper function that takes the backend enum and returns a translation key (e.g., status_active -> Actif). Do not rely on the backend to return translated strings.

Phase 2: CRM & Sales Module (UX Improvements)
2.1 Context-Aware Customer Creation
Problem: When clicking "Add Customer" from the "Customers" view, the Type dropdown defaults to empty or forces a choice between Customer/Supplier. Directives:

Logic: Check the current route or parent component state.

If route is /sales/customers, default Type to Customer.

If route is /purchases/suppliers, default Type to Supplier.

Validation: Ensure the Type field is effectively hidden or disabled if the context implies the type strictly.

2.2 Product Search "Empty State" (Combobox)
Problem: The product dropdown in the Quote creation screen is empty until the user types. Directives:

Best Practice: Implement an "Empty State" fetch.

Action: When the dropdown opens (on focus), trigger a fetch for the top 10 most recent or most popular products immediately. Do not wait for user input.

UX: Display these with a header like "Recent Products".

2.3 Pricing Automation & Defaults
Problem: Adding a line item results in 0.00 Unit Price and 0.00 Tax. Directives:

Logic: On product selection, immediately trigger a lookup for:

Sales Price: Fetch list_price or calculate based on landed_cost + default_margin.

Tax Rate: Fetch the default tax for the customer's region or the product's tax category.

Action: Pre-fill the Unit Price and Tax % fields. Allow the user to override them, but never default to zero unless the product is actually free.

2.4 In-Flow Product Creation
Problem: User cannot create a new product without leaving the Quote screen. Directives:

Feature: Add a "Create new" button inside the Product Search dropdown footer.

Implementation: Trigger the Product Creation Modal (reuse the existing Add Product form component).

Callback: On successful creation, close the modal, stay on the Quote screen, and automatically select the newly created product in the line item.

Phase 3: Order Management & Logic
3.1 Ghost Error on Success
Problem: Saving a quote triggers a "Cannot read properties of undefined" red toast, yet the quote is successfully created. Directives:

Debugging: Investigate the API response handler in the saveQuote function.

Hypothesis: The frontend is likely trying to access a property (e.g., response.data.id) on a response object that is structure differently than expected (or is returning 204 No Content).

Action: Add optional chaining (?.) to response parsing and ensure the "Success" toast is mutually exclusive with the "Error" toast.

3.2 Sales Order Payment Button
Problem: Confirmed Sales Orders lack a mechanism to record a deposit/down payment before invoicing. Directives:

Feature: Add a "Register Payment" button to the Sales Order view (status: Confirmed).

Flow: This should create a generic payment linked to the Sales Order ID, which is later reconciled against the final Invoice.

3.3 Conversion Blocking (Sales Order -> Delivery)
Problem: Converting a Sales Order to a Delivery Note fails silently or blocks without clear feedback. Directives:

UX: If blocking is intentional (e.g., due to credit limit or stock shortage), replace the generic failure with a specific error message: "Cannot convert: Insufficient stock for item XYZ."

Logic: If the block is accidental, check the backend permission or state transition logic for Order -> Delivery.

Phase 4: Invoicing & Treasury (Critical Context Fixes)
4.1 "Post" Terminology
Problem: The button "Post" on an invoice is ambiguous to the user. Directives:

Copy Update: Rename "Post" to "Confirm Invoice" or "Post to Ledger" (localized) to clearly indicate this action finalizes the financial document.

4.2 Payment Context Loss (High Priority)
Problem: Clicking "Record Payment" redirects to a generic, empty Payment form. The user loses the Invoice ID, Amount, and Partner. Directives:

Architecture: Refactor the "Record Payment" action to open a Modal instead of a full page redirect, OR pass parameters via URL/State.

Pre-filling:

Partner: Inherit from Invoice.

Amount: Inherit amount_residual (remaining balance), not total amount.

Reference: Pre-fill with the Invoice Number (e.g., INV-2025-0001).

Linked Document: Hidden field linking the payment ID to the Invoice ID.

Navigation: On "Save" or "Cancel," the user must be returned to the Invoice they started from, not the generic Payments list.

4.3 Treasury Repository Permissions (403 Error)
Problem: Creating a Bank Account or Cash Register returns a 403 Forbidden. Directives:

Backend: Verify the POST /api/treasury/repositories endpoint permissions. Ensure the current user role (Admin/User) has create access.

Frontend: If the user does not have permission, hide the "Add Repository" button entirely rather than letting them fail.
