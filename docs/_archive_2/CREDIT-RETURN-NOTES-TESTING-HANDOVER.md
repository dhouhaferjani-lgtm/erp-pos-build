# Credit Notes & Return Notes - Testing Handover Document

**Date**: December 25, 2025
**Author**: Claude Code
**Status**: Ready for Playwright Testing

---

## Executive Summary

This document provides a comprehensive handover for testing the Credit Note and Return Note functionality that has been refactored to match invoice form patterns. All features are now implemented with dual selection modes, matching the DocumentForm layout patterns, and full internationalization support (English + French).

---

## 1. What Was Implemented

### 1.1 Credit Notes Refactor

**File**: `/apps/web/src/features/documents/CreateCreditNotePage.tsx` (579 lines)

#### Features:
- **Dual Mode Selection**: Customer-based OR Invoice-based credit notes
- **Invoice Mode**:
  - Search and select posted invoices
  - View invoice summary with document number, partner, date, total, and balance
  - Line-based crediting with two sub-modes:
    - **Credit All**: Credit entire invoice amount
    - **Credit Partial**: Select specific lines with custom quantities
  - Real-time calculation of credit total
  - Validation against remaining creditable balance
- **Customer Mode**:
  - Select customer from partner search
  - Manual line entry using DocumentLineEditor
  - Create credit note without source invoice
- **Form Layout**: White card sections matching DocumentForm patterns
- **Validation**: Zod schema with comprehensive checks
- **Pessimistic Updates**: Wait for server confirmation before navigation

**Route**: `/sales/credit-notes/create`

**Translation Keys Added**:
- English: `sales:creditNotes.form.*` (14 new keys)
- French: Complete translations added

---

### 1.2 Return Notes Refactor

**File**: `/apps/web/src/features/documents/CreateReturnNotePage.tsx` (742 lines)

#### Features:
- **Dual Source Selection**: Invoice OR Delivery Note
- **Source Selection Modes**:
  - **Delivery Note Mode**: Return goods from confirmed delivery notes
  - **Invoice Mode**: Return and credit invoiced goods (with auto-credit option)
- **Line Selection Modes**:
  - **Return All**: Return all items from source document
  - **Partial Return**: Select specific lines with custom quantities
- **Return Details**:
  - Return reason (required): defective, wrongItem, customerRegret, damagedInTransit, warranty, exchange, other
  - Return condition (optional): unopened, used, damaged, unusable
  - Refund method (optional): originalPayment, storeCredit, exchange, none
  - Notes field
- **Auto-Create Credit Note**: Checkbox for invoice-based returns
- **Form Layout**: Matches invoice/credit note patterns exactly
- **Real-time Total Calculation**: Updates as lines are selected/quantities changed

**Route**: `/sales/return-notes/create`

**Translation Keys Added**:
- English: `sales:returnNotes.*` (7 new keys + 5 form keys)
- French: Complete translations added

---

### 1.3 Generic Components Created

#### DocumentSearchSelect Component
**File**: `/apps/web/src/components/ui/DocumentSearchSelect.tsx` (308 lines)

**Purpose**: Generic, reusable document search with configuration-based customization

**Features**:
- Generic TypeScript with `<T extends BaseDocument>`
- Configuration object for complete customization:
  - Endpoint URL
  - Status filters
  - Additional API filters
  - Search placeholder text
  - Empty state messages
  - Custom render functions
  - Custom display text functions
  - Client-side filtering
- Headless UI patterns
- React Query integration
- Keyboard navigation support
- Clear button
- Loading states

**Used By**:
- InvoiceSearchSelect (refactored to thin wrapper - 82 lines, was 260 lines)
- DeliveryNoteSearchSelect (refactored to thin wrapper - 105 lines, was 272 lines)

**Code Reduction**: 68% reduction in invoice search, 61% in delivery note search

---

#### RelatedDocumentsPanel Component
**File**: `/apps/web/src/features/documents/components/RelatedDocumentsPanel.tsx` (323 lines)

**Purpose**: Visualize document chains and relationships

**Features**:
- Horizontal document chain with arrows (Quote → Order → DN → Invoice → Credit Note)
- Color-coded document types with icons
- Categorized sections:
  - Document Chain (horizontal timeline)
  - Source Documents
  - Derived Documents
  - Credit Notes
  - Return Notes
- Current document highlighting
- Clickable navigation between documents
- Collapsible panel
- Currency formatting
- Date formatting

**Integration**: Can be added to any document detail page

**Translation Keys**: `sales:relatedDocuments.*`

---

## 2. Files Modified/Created

### New Files (4):
1. `/apps/web/src/features/documents/CreateCreditNotePage.tsx` - 579 lines
2. `/apps/web/src/features/documents/CreateReturnNotePage.tsx` - 742 lines
3. `/apps/web/src/components/ui/DocumentSearchSelect.tsx` - 308 lines
4. `/apps/web/src/features/documents/components/RelatedDocumentsPanel.tsx` - 323 lines

### Refactored Files (2):
1. `/apps/web/src/components/ui/InvoiceSearchSelect.tsx` - 260 → 82 lines (68% reduction)
2. `/apps/web/src/components/ui/DeliveryNoteSearchSelect.tsx` - 272 → 105 lines (61% reduction)

### Route Updates (1):
1. `/apps/web/src/routes/index.tsx`:
   - Added lazy import for CreateCreditNotePage
   - Added lazy import for CreateReturnNotePage
   - Added route: `/sales/credit-notes/create`
   - Added route: `/sales/return-notes/create`

### Translation Updates (2):
1. `/apps/web/src/locales/en/sales.json`:
   - Credit notes: 14 new keys in `creditNotes.form` + `createDescription`
   - Return notes: 7 new root keys + 5 form keys
   - Related documents: 3 new keys in `relatedDocuments`
2. `/apps/web/src/locales/fr/sales.json`:
   - Complete French translations for all new keys

### Type Fixes (1):
1. `/apps/web/src/features/documents/ReturnNoteDetailPage.tsx`:
   - Fixed ConfirmDialog props (confirmLabel → confirmText, onCancel → onClose)
   - Removed unused imports

---

## 3. Playwright Test Scenarios

### 3.1 Credit Note - Invoice Mode - Full Credit

**User Story**: Accountant needs to credit entire invoice due to billing error

**Steps**:
1. Navigate to `/sales/credit-notes/create`
2. Verify "From Invoice" mode is selected by default
3. Click invoice search field
4. Type invoice number in search box
5. Verify filtered results show only posted invoices with balances
6. Select an invoice from dropdown
7. Verify invoice summary displays:
   - Document number
   - Partner name
   - Issue date
   - Total amount
   - Remaining balance
8. Verify "Credit All Lines" is selected by default
9. Select reason: "Billing Error"
10. Add notes: "Incorrect pricing applied"
11. Click "Create Credit Note"
12. Verify toast success message
13. Verify navigation to `/sales/credit-notes`
14. Verify credit note appears in list

**Expected Results**:
- Invoice balance is reduced by credit note amount
- Credit note has source_invoice_id populated
- Credit note total = invoice total

---

### 3.2 Credit Note - Invoice Mode - Partial Credit

**User Story**: Customer returns 2 of 5 items from an invoice

**Steps**:
1. Navigate to `/sales/credit-notes/create`
2. Select "From Invoice" mode
3. Search and select posted invoice with multiple lines
4. Click "Credit Partial" button
5. Verify line selection table appears with checkboxes
6. Check 2 of the lines
7. Verify quantity inputs appear for selected lines
8. Change quantity on line 1 from 5 to 2
9. Verify partial credit total updates in real-time
10. Verify total = (line1 qty * price + tax) + (line2 qty * price + tax)
11. Select reason: "Product Return"
12. Click "Create Credit Note"
13. Verify success

**Expected Results**:
- Credit note has only selected lines
- Quantities match user input
- Total calculation is correct
- Invoice balance reduced by partial amount only

**Edge Cases to Test**:
- Try to credit quantity > original quantity (should be capped at max)
- Deselect all lines → verify error message
- Select lines but set quantity to 0 → verify validation

---

### 3.3 Credit Note - Customer Mode

**User Story**: Issue goodwill credit to customer without source invoice

**Steps**:
1. Navigate to `/sales/credit-notes/create`
2. Click "From Customer" mode button
3. Verify mode switches (blue border on customer button)
4. Verify invoice search field disappears
5. Click partner search field
6. Search and select a customer
7. Verify customer is selected
8. Verify DocumentLineEditor appears
9. Click "Add Item" button
10. Search and select a product
11. Enter quantity: 2
12. Verify unit price auto-populates
13. Verify tax rate auto-populates
14. Verify line total calculates correctly
15. Select reason: "Other"
16. Add notes: "Customer satisfaction adjustment"
17. Click "Create Credit Note"
18. Verify success

**Expected Results**:
- Credit note has NO source_invoice_id
- Lines are manually entered
- Customer balance is credited
- Credit note appears in customer's account

**Edge Cases**:
- Try to submit without adding any lines → verify error
- Add line then remove it → verify total updates
- Switch between customer and invoice mode → verify state resets

---

### 3.4 Return Note - Delivery Note Mode - Full Return

**User Story**: Customer returns entire delivery before invoicing

**Steps**:
1. Navigate to `/sales/return-notes/create`
2. Verify "Delivery Note" mode is selected by default
3. Click delivery note search field
4. Search for confirmed delivery note
5. Select delivery note
6. Verify document summary displays
7. Verify "Return All" is selected by default
8. Select return reason: "Damaged in Transit"
9. Select condition: "Damaged"
10. Select refund method: "Store Credit"
11. Add notes: "Package damaged during shipping"
12. Verify "Auto-create credit note" checkbox is NOT visible (delivery note mode)
13. Click "Create Return Note"
14. Verify success

**Expected Results**:
- Return note created with source_delivery_note_id
- All lines from delivery note included
- Stock returned to inventory (backend handles this)
- No credit note created (not invoiced yet)

---

### 3.5 Return Note - Invoice Mode - Partial Return with Auto-Credit

**User Story**: Customer returns 1 defective item from 3-item invoice

**Steps**:
1. Navigate to `/sales/return-notes/create`
2. Click "Invoice" button to switch modes
3. Verify mode switches
4. Search and select posted invoice
5. Verify document summary shows invoice details
6. Click "Partial Return" button
7. Verify line selection table appears
8. Check 1 line (the defective item)
9. Verify quantity defaults to line quantity
10. Adjust quantity if needed
11. Verify return total calculates in footer
12. Select return reason: "Defective"
13. Select condition: "Unusable"
14. Select refund method: "Original Payment Method"
15. Check "Automatically create credit note" checkbox
16. Verify checkbox hint text is visible
17. Add notes: "Product stopped working after 2 days"
18. Click "Create Return Note"
19. Verify success
20. Navigate to created return note
21. Verify linked credit note was created

**Expected Results**:
- Return note created with source_invoice_id
- Only selected line included
- Stock returned
- Credit note automatically created
- Credit note linked to return note
- Invoice balance reduced by credit amount

**Edge Cases**:
- Partial return with no lines selected → verify error
- Switch from "Return All" to "Partial" → verify state resets
- Uncheck auto-credit → verify no credit note created

---

### 3.6 Return Note - Invoice Mode - Return All

**User Story**: Customer returns entire invoice for refund

**Steps**:
1. Navigate to `/sales/return-notes/create`
2. Select "Invoice" mode
3. Search and select posted invoice
4. Verify "Return All" is selected
5. Verify line table is NOT shown (all mode)
6. Select reason: "Customer Regret"
7. Select condition: "Unopened / New"
8. Select refund method: "Original Payment Method"
9. Check "Auto-create credit note"
10. Click "Create Return Note"
11. Verify success

**Expected Results**:
- Return note includes all invoice lines
- Full invoice amount credited
- All stock returned
- Credit note created and linked

---

### 3.7 Document Search Components

**Test**: InvoiceSearchSelect

**Steps**:
1. Open CreateCreditNotePage
2. Click invoice search field
3. Verify dropdown opens with search input
4. Type non-existent invoice number
5. Verify "No invoices found" message
6. Clear search
7. Verify message changes to "No posted invoices available" (if no invoices)
8. Type partial invoice number
9. Verify filtered results appear
10. Verify each result shows:
    - Invoice number
    - Partner name
    - Total amount
    - Balance remaining
11. Click an invoice
12. Verify dropdown closes
13. Verify selected invoice displays in field
14. Click X (clear button)
15. Verify selection clears
16. Click outside dropdown
17. Verify dropdown closes

**Test**: DeliveryNoteSearchSelect (similar flow)

---

### 3.8 Related Documents Panel

**Test**: Document Chain Visualization

**Steps**:
1. Create a complete document flow:
   - Create Quote
   - Convert to Sales Order
   - Convert to Delivery Note
   - Convert to Invoice
   - Create Credit Note from Invoice
   - Create Return Note from Invoice
2. Navigate to invoice detail page
3. Scroll to Related Documents panel
4. Verify panel displays
5. Click collapse/expand button
6. Verify panel toggles
7. Expand panel
8. Verify "Document Chain" section shows:
   - Quote → Order → DN → Invoice (with arrows)
   - Current document (invoice) highlighted with blue border
   - Each document has icon and number
9. Verify "Credit Notes" section shows linked credit note
10. Verify "Return Notes" section shows linked return note
11. Click on Quote in chain
12. Verify navigation to quote detail page
13. Navigate back to invoice
14. Click credit note in Credit Notes section
15. Verify navigation to credit note

**Expected Results**:
- Visual chain is clear and readable
- Current document always highlighted
- All links functional
- Icons match document types
- Colors consistent with app theme

---

### 3.9 Internationalization (i18n)

**Test**: French Translation

**Steps**:
1. Change language to French (if app has language switcher)
2. Navigate to `/sales/credit-notes/create`
3. Verify all text is in French:
   - "Type d'avoir"
   - "À partir d'une facture"
   - "À partir d'un client"
   - "Créer l'avoir"
4. Navigate to `/sales/return-notes/create`
5. Verify all text is in French:
   - "Sélection des lignes"
   - "Tout retourner"
   - "Retour partiel"
   - "Créer le bon de retour"
6. Verify no hardcoded English strings

**Test**: Missing Translation Keys

**Steps**:
1. Open browser console
2. Navigate through credit note and return note pages
3. Verify no i18n warnings about missing keys
4. Test all form validation errors
5. Verify error messages are translated

---

### 3.10 Responsive Design

**Test**: Mobile Layout

**Steps**:
1. Resize browser to mobile width (375px)
2. Navigate to CreateCreditNotePage
3. Verify:
   - Mode selection buttons stack vertically on mobile
   - Forms are scrollable
   - Line selection table is scrollable horizontally
   - Action buttons stack or wrap appropriately
4. Repeat for CreateReturnNotePage
5. Test RelatedDocumentsPanel on mobile
6. Verify horizontal document chain is scrollable

---

### 3.11 Form Validation

**Test**: Required Field Validation

**Steps**:
1. Navigate to CreateCreditNotePage
2. Select "From Invoice" mode
3. Click "Create Credit Note" without selecting invoice
4. Verify inline error message appears
5. Select invoice
6. Click "Create Credit Note" without selecting reason
7. Verify reason error appears
8. Navigate to CreateReturnNotePage
9. Click "Create Return Note" without source document
10. Verify error
11. Select delivery note
12. Click "Partial Return"
13. Don't select any lines
14. Click "Create Return Note"
15. Verify "Please select at least one line" error

**Test**: Quantity Validation

**Steps**:
1. CreateCreditNotePage → Invoice mode → Partial
2. Select a line
3. Try to enter quantity > max quantity
4. Verify input is capped at max
5. Try to enter 0 or negative
6. Verify validation prevents it

---

### 3.12 Loading States

**Test**: Async Operations

**Steps**:
1. CreateCreditNotePage
2. Click invoice search
3. Type search query
4. Verify loading spinner appears while fetching
5. Select invoice
6. Verify loading state while fetching invoice details
7. Fill form
8. Click "Create Credit Note"
9. Verify:
   - Submit button shows "Saving..." text
   - Submit button is disabled
   - Form fields are disabled
   - Cannot click cancel during save
10. After success, verify navigation happens

**Test**: Error Handling

**Steps**:
1. Disconnect network (Dev Tools → Network → Offline)
2. Try to search for invoice
3. Verify error handling (no crash)
4. Try to submit form
5. Verify error message displays
6. Reconnect network
7. Retry → verify success

---

### 3.13 Pessimistic Updates

**Test**: Form Submission Behavior

**Steps**:
1. Fill out credit note form completely
2. Click "Create Credit Note"
3. Verify:
   - Form stays on current page until server confirms
   - No optimistic UI updates
   - Toast appears only after server response
   - Navigation happens only after success
4. Trigger server error (e.g., duplicate credit note)
5. Verify:
   - Error message displays
   - User stays on form
   - Can correct and retry
   - Form data preserved

---

### 3.14 URL Parameters (Pre-selection)

**Test**: Invoice Pre-selection from URL

**Steps**:
1. Navigate to invoice detail page
2. Click "Create Credit Note" button
3. Verify navigation to `/sales/credit-notes/create?invoice_id={id}`
4. Verify invoice is pre-selected in form
5. Verify invoice details load automatically

**Test**: Delivery Note Pre-selection

**Steps**:
1. Navigate to delivery note detail page
2. Click "Create Return Note" button
3. Verify navigation to `/sales/return-notes/create?delivery_note_id={id}`
4. Verify delivery note is pre-selected

---

### 3.15 Edge Cases & Error Scenarios

**Scenario 1**: Over-crediting Prevention
- Select invoice with €100 remaining balance
- Try to create €50 credit note
- Try to create another €60 credit note
- Verify: Second credit note fails or shows €50 max

**Scenario 2**: Concurrent Operations
- Open two browser tabs
- Tab 1: Create credit note from invoice A
- Tab 2: Also create credit note from same invoice A
- Submit both
- Verify: One succeeds, other fails with balance error (or both succeed if balance allows)

**Scenario 3**: Deleted Source Document
- Start creating credit note
- Delete source invoice (via API or another tab)
- Try to submit
- Verify: Error handling, no crash

**Scenario 4**: Permission Checks
- Logout
- Try to navigate to `/sales/credit-notes/create`
- Verify: Redirect to login (not crash)

**Scenario 5**: Invalid URL Parameters
- Navigate to `/sales/credit-notes/create?invoice_id=invalid-uuid`
- Verify: Error handling, no crash
- Form still works for manual selection

---

## 4. Accessibility Testing

### Keyboard Navigation

**Steps**:
1. Navigate to CreateCreditNotePage using only keyboard
2. Tab through mode selection buttons → verify focus visible
3. Tab to invoice search → press Enter → verify dropdown opens
4. Use arrow keys to navigate results
5. Press Enter to select → verify selection works
6. Tab to all form fields
7. Tab to checkboxes in line table
8. Verify all interactive elements accessible via keyboard

### Screen Reader Testing

**Steps**:
1. Enable screen reader (NVDA/JAWS/VoiceOver)
2. Navigate form
3. Verify:
   - Labels are read correctly
   - Error messages announced
   - Required fields indicated
   - Button states announced
   - Loading states announced

### Focus Management

**Steps**:
1. Open invoice search dropdown
2. Verify focus moves to search input
3. Select invoice
4. Verify focus returns to trigger button
5. Submit form with error
6. Verify focus moves to error message

---

## 5. Performance Considerations

### Search Debouncing

**Test**:
1. Open invoice search
2. Type quickly: "INV-001"
3. Monitor network tab
4. Verify: Only 1-2 API calls made (not one per keystroke)

### Data Fetching

**Test**:
1. Select invoice
2. Monitor network tab
3. Verify invoice details fetched only once
4. Navigate away and back
5. Verify React Query cache used (no refetch if within staleTime)

### Bundle Size

**Check**:
- DocumentSearchSelect adds ~8KB to bundle (generic component)
- No duplicate code between InvoiceSearchSelect and DeliveryNoteSearchSelect
- Lazy loading working for CreateCreditNotePage and CreateReturnNotePage

---

## 6. Integration Points

### Backend API Endpoints Expected

**Credit Notes**:
- `GET /api/v1/invoices?status=posted&has_balance=true` - Search posted invoices
- `GET /api/v1/invoices/{id}` - Get invoice details with lines
- `POST /api/v1/credit-notes` - Create credit note
  ```json
  {
    "partner_id": "uuid",
    "source_invoice_id": "uuid", // optional
    "issue_date": "2025-12-25",
    "reason": "return",
    "notes": "string",
    "lines": [  // optional, for partial credits
      {
        "line_id": "uuid",
        "quantity": 2
      }
    ]
  }
  ```

**Return Notes**:
- `GET /api/v1/delivery-notes?status=confirmed` - Search confirmed DNs
- `GET /api/v1/delivery-notes/{id}` - Get DN details with lines
- `POST /api/v1/return-notes` - Create return note
  ```json
  {
    "source_invoice_id": "uuid",  // OR
    "source_delivery_note_id": "uuid",
    "return_reason": "defective",
    "return_condition": "damaged",
    "refund_method": "originalPayment",
    "notes": "string",
    "auto_create_credit_note": true,
    "lines": [  // optional, for partial returns
      {
        "line_id": "uuid",
        "quantity": 2
      }
    ]
  }
  ```

**Related Documents**:
- `GET /api/v1/documents/{id}/related` - Get document relationships
  ```json
  {
    "data": {
      "source_documents": [],
      "derived_documents": [],
      "credit_notes": [],
      "return_notes": [],
      "document_chain": []
    }
  }
  ```

---

## 7. Known Limitations & Future Enhancements

### Current Limitations:
1. **No line-level return reasons**: Can't specify different reasons per line
2. **No attachment support**: Cannot attach images of damaged goods
3. **No approval workflow**: Credit notes created immediately (no draft → approval flow)
4. **No batch operations**: Can't create multiple credit/return notes at once
5. **No RMA number**: Return notes don't generate RMA tracking numbers

### Future Enhancements:
1. Add bulk credit note creation from invoice list
2. Add print templates for return notes
3. Add email notifications when credit/return notes created
4. Add return note approval workflow for high-value returns
5. Integrate with shipping labels for return logistics

---

## 8. Testing Checklist Summary

### Functional Testing
- [ ] Credit Note - Invoice Mode - Full Credit
- [ ] Credit Note - Invoice Mode - Partial Credit
- [ ] Credit Note - Customer Mode
- [ ] Return Note - Delivery Note Mode - Full Return
- [ ] Return Note - Delivery Note Mode - Partial Return
- [ ] Return Note - Invoice Mode - Full Return with Auto-Credit
- [ ] Return Note - Invoice Mode - Partial Return with Auto-Credit
- [ ] Document Search - Invoice Search
- [ ] Document Search - Delivery Note Search
- [ ] Related Documents Panel - Chain Visualization
- [ ] Related Documents Panel - Navigation

### Non-Functional Testing
- [ ] Internationalization - French Translations
- [ ] Internationalization - No Missing Keys
- [ ] Responsive Design - Mobile (375px)
- [ ] Responsive Design - Tablet (768px)
- [ ] Responsive Design - Desktop (1024px+)
- [ ] Form Validation - Required Fields
- [ ] Form Validation - Quantity Limits
- [ ] Loading States - Search
- [ ] Loading States - Form Submission
- [ ] Error Handling - Network Errors
- [ ] Error Handling - Server Errors
- [ ] Pessimistic Updates - Form Behavior
- [ ] URL Parameters - Invoice Pre-selection
- [ ] URL Parameters - Delivery Note Pre-selection

### Edge Cases
- [ ] Over-crediting Prevention
- [ ] Concurrent Operations
- [ ] Deleted Source Documents
- [ ] Permission Checks
- [ ] Invalid URL Parameters

### Accessibility
- [ ] Keyboard Navigation
- [ ] Screen Reader Compatibility
- [ ] Focus Management
- [ ] ARIA Labels

### Performance
- [ ] Search Debouncing
- [ ] React Query Caching
- [ ] Bundle Size Optimization

---

## 9. Test Data Requirements

### Setup Required:
1. **At least 5 posted invoices** with:
   - Different partners
   - Various amounts (€50, €100, €500, €1000, €5000)
   - Multiple line items (3-5 lines each)
   - Some with existing credit notes (partially credited)
   - Some fully paid, some unpaid

2. **At least 5 confirmed delivery notes** with:
   - Different partners
   - Mix of single-item and multi-item
   - Some linked to invoices, some not
   - Various dates

3. **Partners**:
   - At least 10 active customers
   - With different names for search testing

4. **Products**:
   - At least 20 products in catalog
   - With varying prices and tax rates
   - Good searchability (different codes/names)

5. **Complete document chain** (for Related Documents testing):
   - Quote Q-001
   - → Sales Order SO-001 (converted from Q-001)
   - → Delivery Note DN-001 (converted from SO-001)
   - → Invoice INV-001 (converted from DN-001)
   - → Credit Note CN-001 (from INV-001)
   - → Return Note RN-001 (from INV-001)

---

## 10. Regression Testing

**After Playwright tests pass**, verify these existing features still work:

1. **Invoice Creation** - Ensure invoice form still functions
2. **Delivery Note Creation** - Ensure DN form still functions
3. **Document Conversion** - Quote → Order → DN → Invoice flow
4. **Payment Recording** - Ensure payments can still be recorded on invoices
5. **Document Search** - Global document search still works
6. **Partner Management** - Partner CRUD operations
7. **Product Management** - Product CRUD operations

---

## 11. Success Criteria

### ✅ All tests pass if:
1. All functional test scenarios complete without errors
2. All validation works as expected
3. All translations display correctly in English and French
4. All responsive breakpoints render correctly
5. No console errors during normal operation
6. No accessibility violations (WCAG AA compliance)
7. Performance metrics acceptable:
   - Search results return in < 500ms
   - Form submission completes in < 2s
   - Page load time < 3s
8. All edge cases handled gracefully with appropriate error messages
9. All navigation flows work correctly
10. All API integrations function as expected

---

## 12. Contact & Questions

**Implementation Reference**: This document
**Code Review**: See git commits from December 25, 2025
**Architecture**: Follows DocumentForm patterns exactly
**Backend API**: Assumes RESTful endpoints as documented in Section 6

For questions about implementation details, refer to:
- `/apps/web/src/features/documents/CreateCreditNotePage.tsx` (main credit note logic)
- `/apps/web/src/features/documents/CreateReturnNotePage.tsx` (main return note logic)
- `/apps/web/src/components/ui/DocumentSearchSelect.tsx` (generic search component)

---

**End of Handover Document**
**Status**: Ready for Playwright Test Implementation
**Next Step**: Configure Playwright MCP and begin automated test creation
