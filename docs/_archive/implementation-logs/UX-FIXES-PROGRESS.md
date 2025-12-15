# UX Fixes Progress Tracker

**Started:** 2025-12-05
**Last Updated:** 2025-12-05 09:25

## Overall Status

| # | Issue | Status | Notes |
|---|-------|--------|-------|
| 1 | Payment Context Loss | ✅ Complete | RecordPaymentModal created, DocumentDetailPage updated |
| 2 | Credit Note Context Loss | ✅ Complete | API call replaces navigation, auto-redirect to new credit note |
| 3 | Search Improvements | ✅ Complete | ILIKE for case-insensitive search (Partner, Product controllers) |
| 4 | Context-Aware Creation | ✅ Complete | Type field hidden when context known, auto-select already working |
| 5 | Translation Completeness | ✅ Complete | DocumentLineEditor + PaymentForm fully translated |

## Status Legend
- ⬜ Not Started
- 🔍 Investigating
- 🔧 In Progress
- ✅ Complete
- ⚠️ Blocked

---

## Detailed Progress

### Issue 1: Payment Context Loss
- [x] Pre-flight check complete
- [x] Root cause identified: Link navigated away to /treasury/payments/new
- [x] PaymentModal exists/created: RecordPaymentModal component
- [x] PaymentForm accepts prefill: prefill prop with partner, amount, reference
- [x] InvoiceDetailPage wired up: Button opens modal, modal receives prefill
- [ ] Manual test passed (awaiting user verification)

### Issue 2: Credit Note Context Loss
- [x] Pre-flight check complete
- [x] Backend conversion method exists: POST /invoices/{id}/create-credit-note
- [x] API endpoint exists: Already in routes.php
- [x] Frontend triggers mutation: Button now calls API instead of navigating
- [x] Line items copied correctly: Backend copies all lines from invoice
- [ ] Manual test passed (awaiting user verification)

### Issue 3: Search Improvements
- [x] Pre-flight check complete
- [ ] Partner combobox implemented (future: requires new component)
- [x] Product search case-insensitive: Changed `like` to `ILIKE` in ProductController
- [x] Partner search case-insensitive: Changed `like` to `ILIKE` in PartnerController
- [ ] Manual test passed (awaiting user verification)

### Issue 4: Context-Aware Creation
- [x] Pre-flight check complete
- [x] Partner type auto-detection working: AddPartnerModal detects context from URL
- [x] Type field hidden when context is clear (sales → customer, purchases → supplier)
- [x] Auto-select after create working: DocumentForm.setValue() on modal onSuccess
- [ ] Manual test passed (awaiting user verification)

### Issue 5: Translation Completeness
- [x] Audit complete: Found hardcoded strings in DocumentLineEditor.tsx and PaymentForm.tsx
- [x] Missing keys identified and added to EN/FR locale files
- [x] DocumentLineEditor.tsx: Added useTranslation, replaced all hardcoded strings
- [x] PaymentForm.tsx: Added useTranslation, replaced all hardcoded strings
- [x] EN sales.json: Added lineItems.* keys (title, empty, actions, loading messages)
- [x] FR sales.json: Added French translations for all new keys
- [x] EN treasury.json: Added payments.form.* keys for form fields/validation
- [x] FR treasury.json: Added French translations for all new keys
- [x] TypeScript check passed
- [ ] Manual test passed (awaiting user verification)

---

## Session Log

### 2025-12-05
- Started UX fixes task
- Application running (backend: 8000, frontend: 5173)
- Database seeded with test data
- Issue 1 completed: RecordPaymentModal created, DocumentDetailPage updated
- Issue 2 completed: Credit note API call replaces navigation
- Issue 3 completed: ILIKE for case-insensitive search
- Issue 4 completed: Type field hidden when context known
- Issue 5 completed: DocumentLineEditor + PaymentForm fully translated
- **All 5 UX issues implemented - awaiting manual verification**
