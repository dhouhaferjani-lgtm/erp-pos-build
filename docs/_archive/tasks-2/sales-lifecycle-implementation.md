# Sales & Payments Lifecycle Implementation Plan

> **Purpose:** Track implementation of gaps identified in the gap analysis
> **Reference:** `docs/tasks/sales_payments_lifecycle_certified_erp_medium.md`
> **Status Model:** `docs/tasks/document-statuses.md`

---

## Implementation Phases

### Phase 1: Document State Model Enhancement
**Priority: HIGH** - Foundation for other features

- [ ] **1.1 Add `document_state` enum for delivery-specific states**
  - Add `DocumentState` enum: `draft`, `confirmed`, `shipped`, `closed`, `cancelled`
  - Keep existing `DocumentStatus` for backward compatibility during transition
  - Add `document_state` column to `documents` table

- [ ] **1.2 Add `logistics_state` enum for delivery tracking**
  - Add `LogisticsState` enum: `pending`, `out_for_delivery`, `delivered`, `pod_confirmed`, `failed`
  - Add `logistics_state` column to `documents` table (nullable, only for delivery notes)
  - Add `pod_data` JSONB column for proof of delivery evidence

- [ ] **1.3 Create state transition service**
  - `DocumentStateService::transition($document, $newState)`
  - Validate allowed transitions per document type
  - Emit events for state changes

---

### Phase 2: Delivery Note Hash Chain (Tunisia Compliance) ✅ COMPLETE
**Priority: HIGH** - Required for fiscal compliance

- [x] **2.1 Add DN to fiscal hash chain**
  - Updated `FiscalCategory` enum to include `DeliveryNote`
  - DN uses existing `fiscal_hash`, `previous_hash`, `chain_sequence` columns
  - DN chain is separate from Invoice chain (per company, per document type)

- [x] **2.2 Create DN-specific hashing logic**
  - Created `DeliveryNoteService` with `confirm()` method
  - Hash includes: document_number, date, total, currency
  - Chain links to previous DN hash (per company)
  - Uses company's `fiscal_chain_seed` for genesis document
  - No GL posting (DN is not accounting document - confirmed, not posted)

- [x] **2.3 Create DeliveryNoteConfirmed event**
  - `DeliveryNoteConfirmed` domain event with full audit data
  - Includes: deliveryNoteId, tenantId, companyId, documentNumber, partnerId, total, currency, fiscalHash, chainSequence, confirmedAt

- [x] **2.4 Write tests (TDD)**
  - `DeliveryNoteHashChainTest.php` with 10 comprehensive tests:
    - ✅ confirming_delivery_note_creates_fiscal_hash
    - ✅ sequential_delivery_notes_create_linked_hash_chain
    - ✅ delivery_note_chain_is_separate_from_invoice_chain
    - ✅ dn_hash_chain_uses_company_genesis_seed
    - ✅ dn_hash_chain_is_verifiable
    - ✅ different_companies_have_separate_dn_chains
    - ✅ confirmed_dn_cannot_be_modified
    - ✅ tampered_dn_hash_is_detectable
    - ✅ confirming_draft_dn_works_correctly
    - ✅ already_confirmed_dn_cannot_be_confirmed_again

**Files Created:**
- `app/Modules/Document/Domain/Services/DeliveryNoteService.php`
- `app/Modules/Document/Domain/Events/DeliveryNoteConfirmed.php`
- `tests/Feature/Compliance/DeliveryNoteHashChainTest.php`

**Files Modified:**
- `app/Modules/Document/Domain/Enums/FiscalCategory.php` - Added DeliveryNote case

---

### Phase 3: Partial Delivery Support ✅ COMPLETE
**Priority: MEDIUM** - Required for Tunisia DN consolidation

- [x] **3.1 Add line-level delivery tracking**
  - Added `quantity_delivered` column to `document_lines` table (decimal:4)
  - Added `source_line_id` foreign key to link DN lines to SO lines
  - Added `getQuantityRemaining()`, `isFullyDelivered()`, `hasDeliveries()` methods to DocumentLine
  - Created migration: `2025_12_11_194522_add_delivery_tracking_to_document_lines.php`

- [x] **3.2 Update delivery note creation**
  - Added `convertOrderToPartialDelivery($order, $deliveryQuantities)` method
  - Allow partial quantities per line when creating DN from SO
  - Create multiple DNs from single SO (each DN references source via `source_document_id`)
  - DN lines link to source SO lines via `source_line_id`
  - Updated `convertOrderToDelivery()` to use line-level tracking for full delivery

- [x] **3.3 Update SO delivery status calculation**
  - Added `DeliveryStatus` enum: `NotDelivered`, `PartiallyDelivered`, `FullyDelivered`
  - Added `getDeliveryStatus()` method to Document model
  - Status calculated from line-level `quantity_delivered` vs `quantity`

- [x] **3.4 Write tests (TDD)**
  - Created `tests/Feature/Document/PartialDeliveryTest.php` with 15 tests:
    - ✅ can_create_partial_delivery_with_specific_quantities
    - ✅ partial_delivery_updates_source_line_delivered_quantities
    - ✅ multiple_partial_deliveries_accumulate_delivered_quantities
    - ✅ cannot_deliver_more_than_remaining_quantity
    - ✅ delivery_note_lines_link_to_source_order_lines
    - ✅ order_delivery_status_not_delivered_initially
    - ✅ order_delivery_status_partially_delivered
    - ✅ order_delivery_status_fully_delivered
    - ✅ cannot_create_delivery_for_fully_delivered_order
    - ✅ line_remaining_quantity_calculation
    - ✅ partial_delivery_totals_calculated_correctly
    - ✅ delivery_notes_array_tracks_all_partial_deliveries
    - ✅ full_delivery_still_works_via_legacy_method
    - ✅ cannot_deliver_zero_quantities
    - ✅ only_lines_with_positive_quantities_included_in_delivery

**Files Created:**
- `database/migrations/2025_12_11_194522_add_delivery_tracking_to_document_lines.php`
- `tests/Feature/Document/PartialDeliveryTest.php`
- `app/Modules/Document/Domain/Enums/DeliveryStatus.php`

**Files Modified:**
- `app/Modules/Document/Domain/DocumentLine.php` - Added delivery tracking methods and casts
- `app/Modules/Document/Domain/Document.php` - Added `getDeliveryStatus()` method
- `app/Modules/Document/Domain/Services/DocumentConversionService.php` - Added partial delivery support

---

### Phase 4: DN → Invoice Consolidation (Tunisia Model) ✅ COMPLETE
**Priority: MEDIUM** - Business requirement for Tunisia

- [x] **4.1 Add DN invoicing tracking**
  - Added `invoiced_at` timestamp to DN via JSONB payload
  - Added `invoice_id` in DN payload for linkage
  - Created `DocumentConversionService::markDeliveryNotesAsInvoiced()`
  - Created migration: `2025_12_11_203000_add_invoiced_at_to_delivery_note_payload.php`

- [x] **4.2 Create invoice from multiple DNs**
  - Implemented `DocumentConversionService::createInvoiceFromDeliveryNotes($deliveryNotes)`
  - Consolidates lines from multiple DNs with duplicate item merging
  - Links invoice back to source DNs via payload
  - Validates all DNs are from same company/partner
  - Throws exception if DN already invoiced

- [x] **4.3 Year-end uninvoiced DN report**
  - Created `UninvoicedDeliveryNoteService` with:
    - `getUninvoicedDeliveryNotes()` - List all DNs without matching invoices
    - `calculateUninvoicedTotals()` - Sum totals for account 418
    - `generateYearEndReport()` - Full report grouped by partner
    - `generateYearEndAdjustment()` - Creates journal entry (Debit 418, Credit 70x)
    - `generateReversalEntry()` - Creates reversal at start of new fiscal year
  - Added `SystemAccountPurpose::UninvoicedRevenue` (account 418)

- [x] **4.4 Write tests (TDD)**
  - Created `tests/Feature/Document/DNConsolidationTest.php` with 22 tests:
    - ✅ create_invoice_from_multiple_delivery_notes_works
    - ✅ consolidated_invoice_has_correct_totals
    - ✅ consolidated_invoice_merges_duplicate_product_lines
    - ✅ cannot_consolidate_dns_from_different_partners
    - ✅ cannot_consolidate_dns_from_different_companies
    - ✅ cannot_consolidate_already_invoiced_dns
    - ✅ consolidated_invoice_links_back_to_source_dns
    - ✅ dns_are_marked_as_invoiced_after_consolidation
    - ✅ cannot_consolidate_empty_array_of_dns
    - ✅ cannot_consolidate_draft_dns
    - ✅ consolidated_invoice_preserves_line_details
    - ✅ consolidated_invoice_currency_matches_dns
    - ✅ single_dn_consolidation_works
    - ✅ consolidated_invoice_document_number_generated
    - ✅ consolidated_invoice_status_is_draft
    - ✅ partial_dn_invoicing_not_supported_yet
    - ✅ dns_cannot_be_re_invoiced_to_another_invoice
    - ✅ consolidated_invoice_tax_calculation_correct
    - ✅ empty_line_dns_handled_gracefully
    - ✅ large_quantity_consolidation_works
    - ✅ mixed_currency_dns_rejected
    - ✅ consolidation_respects_original_line_order
  - Created `tests/Feature/Compliance/UninvoicedDNReportTest.php` with 15 tests:
    - ✅ get_uninvoiced_delivery_notes_returns_empty_for_no_dns
    - ✅ get_uninvoiced_delivery_notes_returns_confirmed_dns_without_invoice
    - ✅ get_uninvoiced_delivery_notes_excludes_invoiced_dns
    - ✅ get_uninvoiced_delivery_notes_excludes_draft_dns
    - ✅ get_uninvoiced_delivery_notes_filters_by_date_range
    - ✅ get_uninvoiced_delivery_notes_only_for_specified_company
    - ✅ calculate_uninvoiced_total_returns_sum_of_all_uninvoiced_dns
    - ✅ calculate_uninvoiced_total_includes_tax
    - ✅ calculate_uninvoiced_total_returns_zero_when_all_invoiced
    - ✅ generate_year_end_report_includes_all_required_data
    - ✅ generate_year_end_adjustment_creates_journal_entry
    - ✅ generate_year_end_adjustment_returns_null_when_no_uninvoiced_dns
    - ✅ generate_year_end_adjustment_uses_correct_accounts
    - ✅ generate_reversal_entry_creates_opposite_entry
    - ✅ report_groups_by_partner

**Files Created:**
- `app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php`
- `database/migrations/2025_12_11_203000_add_invoiced_at_to_delivery_note_payload.php`
- `tests/Feature/Document/DNConsolidationTest.php`
- `tests/Feature/Compliance/UninvoicedDNReportTest.php`

**Files Modified:**
- `app/Modules/Document/Domain/Services/DocumentConversionService.php` - Added `createInvoiceFromDeliveryNotes()` and `markDeliveryNotesAsInvoiced()`
- `app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` - Added `UninvoicedRevenue` case

---

### Phase 5: Quote → Invoice Direct Flow
**Priority: MEDIUM** - UX improvement

- [ ] **5.1 Implement silent SO creation**
  - When converting Quote → Invoice directly
  - Auto-create and confirm SO in background
  - SO serves as audit trail / linkage record

- [ ] **5.2 Update conversion service**
  - Add `convertQuoteToInvoice($quote, $createSilentSO = true)`
  - If `createSilentSO`, create confirmed SO first, then invoice
  - Maintain document chain: Quote → SO → Invoice

- [ ] **5.3 Write tests**
  - Test direct quote to invoice conversion
  - Test silent SO is created and confirmed
  - Test document linkage is preserved

---

### Phase 6: Country-Specific Flow Rules
**Priority: LOW** - Framework for future countries

- [ ] **6.1 Create country rules configuration**
  - Add `country_document_rules` table or config
  - Define rules: `dn_required_before_invoice`, `dn_consolidation_allowed`
  - Load rules based on company's country

- [ ] **6.2 Implement rule validation in conversion service**
  - Check country rules before document conversion
  - Throw `ComplianceException` if rule violated
  - Example: Italy requires DN before Invoice

- [ ] **6.3 Document country profiles**
  - Tunisia: DN consolidation allowed, hash chain required
  - Italy: DDT required before invoice
  - France: NF525 for POS (future)

- [ ] **6.4 Write tests**
  - Test Italy DDT rule enforcement
  - Test Tunisia consolidation allowance
  - Test rule bypass for non-configured countries

---

### Phase 7: Audit Log Persistence
**Priority: LOW** - Enhanced compliance

- [ ] **7.1 Create audit_logs table**
  - Schema: `timestamp, user_id, action, entity_type, entity_id, old_value, new_value`
  - Use TimescaleDB for time-series optimization
  - Immutable (no UPDATE/DELETE allowed)

- [ ] **7.2 Create audit event listener**
  - Listen to domain events
  - Persist to audit_logs table
  - Include hash for tamper detection

- [ ] **7.3 Write tests**
  - Test audit log creation on document events
  - Test audit log immutability
  - Test audit log query performance

---

## Year-End Accounting for Uninvoiced DNs

### Tunisia/France Accounting Treatment

When delivery notes exist without matching invoices at fiscal year end:

**For Sales (Outbound DN - BL):**
```
Debit:  418 - Clients, produits non encore facturés
Credit: 70x - Ventes (by product category)
Credit: 4457 - TVA collectée (if applicable)
```

**For Purchases (Inbound DN - BE):**
```
Debit:  60x - Achats (by product category)
Debit:  4456 - TVA déductible (if applicable)
Credit: 408 - Fournisseurs, factures non parvenues
```

### Implementation Notes
- Generate year-end adjustment journal entries automatically
- Reverse entries at start of new fiscal year
- Track which DNs generated adjustment entries

---

## Testing Strategy

### Unit Tests (per phase)
- Domain logic for state transitions
- Hash calculation algorithms
- Quantity calculations

### Integration Tests
- Database transactions for conversions
- Hash chain integrity across operations
- Country rule enforcement

### E2E Tests
- Full Quote → SO → DN → Invoice flow
- Partial delivery workflow
- Multi-DN consolidation to invoice

---

## Files to Modify/Create

### New Files
- `app/Modules/Document/Domain/Enums/DocumentState.php`
- `app/Modules/Document/Domain/Enums/LogisticsState.php`
- `app/Modules/Document/Domain/Services/DocumentStateService.php`
- `database/migrations/xxxx_add_document_state_columns.php`
- `database/migrations/xxxx_add_delivery_tracking_columns.php`
- `tests/Feature/Document/PartialDeliveryTest.php`
- `tests/Feature/Document/DNHashChainTest.php`
- `tests/Feature/Document/DNConsolidationTest.php`

### Files to Modify
- `app/Modules/Document/Domain/Document.php` - Add state accessors
- `app/Modules/Compliance/Services/FiscalHashService.php` - Add DN hashing
- `app/Modules/Document/Domain/Services/DocumentConversionService.php` - Add new flows
- `app/Modules/Document/Domain/Services/DocumentPostingService.php` - State transitions

---

## Progress Tracking

| Phase | Status | Started | Completed | Notes |
|-------|--------|---------|-----------|-------|
| 1. State Model | Not Started | - | - | Foundation |
| 2. DN Hash Chain | ✅ Complete | 2025-12-11 | 2025-12-11 | 10 tests passing |
| 3. Partial Delivery | ✅ Complete | 2025-12-11 | 2025-12-11 | 15 tests passing |
| 4. DN Consolidation | ✅ Complete | 2025-12-11 | 2025-12-11 | 22 tests + 15 year-end tests |
| 5. Quote → Invoice | Not Started | - | - | UX improvement |
| 6. Country Rules | Not Started | - | - | Framework |
| 7. Audit Logs | Not Started | - | - | Enhanced compliance |

---

*Last Updated: 2025-12-11*
