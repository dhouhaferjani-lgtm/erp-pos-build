# Track 1: IziPOS POS Module Development

**Product:** IziPOS  
**Priority:** Primary  
**Duration:** 9-13 weeks

---

## Track Overview

Build the point-of-sale functionality for retail and F&B businesses.

```
Phase 1A: Core POS Infrastructure ──► Phase 1B: Retail POS Features
           (4-6 weeks)                        (3-4 weeks)
                                                   │
                                    Phase 1C: Batch/Expiry (parallel)
                                              (2-3 weeks)
```

---

## Phase 1A: Core POS Infrastructure

**Duration:** 4-6 weeks  
**Status:** 🔵 Not Started

### Goals
- Terminal registration and management
- Cash register sessions (shifts)
- Basic transaction flow
- X and Z reports
- Receipt printing foundation
- Hash chain implementation

### Tasks

| # | Task | Complexity | Estimate | Status |
|---|------|------------|----------|--------|
| 1 | Database migrations | Medium | 2d | ⬜ |
| 2 | TerminalStatus, SessionStatus enums | Low | 0.5d | ⬜ |
| 3 | Terminal domain model | Medium | 1d | ⬜ |
| 4 | Terminal repository | Medium | 1d | ⬜ |
| 5 | TerminalService | Medium | 2d | ⬜ |
| 6 | TerminalController + routes | Medium | 1d | ⬜ |
| 7 | Session domain model | Medium | 1d | ⬜ |
| 8 | SessionService (open/close) | High | 2d | ⬜ |
| 9 | SessionController + routes | Medium | 1d | ⬜ |
| 10 | HashChainService | High | 2d | ⬜ |
| 11 | Transaction model with hash | High | 2d | ⬜ |
| 12 | TransactionService | High | 3d | ⬜ |
| 13 | ReportService (X/Z) | Medium | 2d | ⬜ |
| 14 | Receipt generation | Medium | 2d | ⬜ |
| 15 | Frontend: Terminal management | Medium | 2d | ⬜ |
| 16 | Frontend: Session open/close | Medium | 2d | ⬜ |
| 17 | Frontend: Basic checkout | High | 3d | ⬜ |
| 18 | Tests | Medium | 2d | ⬜ |

### Deliverables
- [ ] `pos_terminals` table with full workflow
- [ ] `pos_sessions` table with open/close
- [ ] `pos_transactions` table with hash chain
- [ ] `pos_reports` table for X/Z
- [ ] Working terminal registration flow
- [ ] Working cash register open/close
- [ ] Basic transaction processing
- [ ] X and Z report generation

### Dependencies
- Core platform ready ✅
- No external dependencies

### Specification
See: `03-MODULE-SPECS/POS-MODULE-SPEC.md`

---

## Phase 1B: Retail POS Features

**Duration:** 3-4 weeks  
**Status:** 🔵 Not Started  
**Depends On:** Phase 1A

### Goals
- Complete checkout experience
- Payment handling
- Discounts and voids
- Transaction history
- Customer integration

### Tasks

| # | Task | Complexity | Estimate | Status |
|---|------|------------|----------|--------|
| 1 | Barcode scanning integration | Low | 1d | ⬜ |
| 2 | Product quick search | Medium | 1d | ⬜ |
| 3 | Cart management improvements | Medium | 2d | ⬜ |
| 4 | Split payment handling | Medium | 2d | ⬜ |
| 5 | Line-level discounts | Medium | 1d | ⬜ |
| 6 | Transaction-level discounts | Medium | 1d | ⬜ |
| 7 | Void transaction flow | Medium | 1d | ⬜ |
| 8 | Draft/parked sales | Medium | 2d | ⬜ |
| 9 | Sales history search | Medium | 2d | ⬜ |
| 10 | Customer selection | Low | 1d | ⬜ |
| 11 | Customer account view | Medium | 1d | ⬜ |
| 12 | Receipt customization | Low | 1d | ⬜ |
| 13 | Tests | Medium | 2d | ⬜ |

### Deliverables
- [ ] Full checkout UI
- [ ] Multiple payment methods in single transaction
- [ ] Discount application (percentage, fixed)
- [ ] Transaction void with reason
- [ ] Save and retrieve parked sales
- [ ] Searchable transaction history
- [ ] Customer lookup and selection

### Dependencies
- Phase 1A complete

---

## Phase 1C: Batch & Expiry Tracking

**Duration:** 2-3 weeks  
**Status:** 🔵 Not Started  
**Can Start:** After Phase 1A (parallel with 1B)

### Goals
- Lot/batch tracking for pharmacy products
- FEFO (First-Expired-First-Out) logic
- Expiry alerts and blocks
- POS integration for batch selection

### Tasks

| # | Task | Complexity | Estimate | Status |
|---|------|------------|----------|--------|
| 1 | product_batches migration | Medium | 1d | ⬜ |
| 2 | inventory_batch_stock migration | Medium | 1d | ⬜ |
| 3 | Batch domain model | Medium | 1d | ⬜ |
| 4 | ExpiryStatus enum | Low | 0.5d | ⬜ |
| 5 | FEFOInventoryService | High | 2d | ⬜ |
| 6 | Batch CRUD service | Medium | 1d | ⬜ |
| 7 | Batch API endpoints | Medium | 1d | ⬜ |
| 8 | Expiry alert service | Medium | 1d | ⬜ |
| 9 | Daily expiry check job | Medium | 1d | ⬜ |
| 10 | POS batch selection UI | Medium | 2d | ⬜ |
| 11 | Batch info on receipt | Low | 0.5d | ⬜ |
| 12 | Expiry report | Medium | 1d | ⬜ |
| 13 | Tests | Medium | 2d | ⬜ |

### Deliverables
- [ ] Batch creation and management
- [ ] FEFO suggestions at checkout
- [ ] Expiry warnings for approaching dates
- [ ] Block sale of expired products
- [ ] Batch number and expiry on receipts
- [ ] Expiring products report

### Dependencies
- Phase 1A complete (for POS integration)
- Inventory module ready ✅

### Specification
See: `03-MODULE-SPECS/BATCH-EXPIRY-SPEC.md`

---

## Success Criteria

### Phase 1A Complete When:
- [ ] Can register a new terminal from POS app
- [ ] Admin can approve/reject terminals
- [ ] Cashier can open shift with opening balance
- [ ] Can process a basic sale transaction
- [ ] Transaction hash chain is maintained
- [ ] Can generate X-report mid-shift
- [ ] Can close shift with Z-report
- [ ] Receipt prints correctly

### Phase 1B Complete When:
- [ ] Can scan barcode to add product
- [ ] Can search products by name
- [ ] Can split payment across methods
- [ ] Can apply discounts
- [ ] Can void a transaction
- [ ] Can save and retrieve draft sales
- [ ] Can search transaction history
- [ ] Can select customer for transaction

### Phase 1C Complete When:
- [ ] Can create products with batch tracking
- [ ] FEFO suggests earliest expiry first
- [ ] Warning shown for near-expiry products
- [ ] Cannot sell expired products
- [ ] Batch/expiry shown on receipt
- [ ] Expiry report shows at-risk inventory

---

## Risk Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| Hash chain complexity | High | Implement and test early in 1A |
| Hardware integration | Medium | Use simulators, test with real hardware weekly |
| Performance with many transactions | Medium | Load test after 1A |
| Offline mode complexity | High | Defer to Phase 3 (Desktop POS) |

---

## Notes

- Focus on web POS first (Tauri desktop POS is Phase 3)
- Receipt printing can use browser print initially
- Keep mobile-responsive for tablet POS use
