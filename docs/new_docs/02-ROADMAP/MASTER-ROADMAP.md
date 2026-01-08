# Master Development Roadmap

**Last Updated:** December 29, 2025

---

## Overview

Three parallel development tracks, designed to allow independent progress:

```
┌─────────────────────────────────────────────────────────────────────┐
│                    PARALLEL DEVELOPMENT TRACKS                       │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  TRACK 1: IziPOS Module          TRACK 2: Tunisia Compliance         │
│  ─────────────────────           ──────────────────────────         │
│  Primary development             Strategic priority                  │
│  POS for retail/F&B              TEJ, withholding tax                │
│                                                                      │
│  Phase 1A: Terminal/Cash ──┐                                         │
│  Phase 1B: Retail POS ─────┼──► Can run in parallel                  │
│  Phase 1C: Batch/Expiry ───┘                                         │
│                                                                      │
│                              Phase 2A: Tax Rules ────┐               │
│                              Phase 2B: Certificates ─┼──► Sequential │
│                              Phase 2C: TEJ Export ───┘               │
│                                                                      │
│  ─────────────────────────────────────────────────────────────────  │
│                                                                      │
│  TRACK 3: Core Refinement (Background)                               │
│  ─────────────────────────────────────                               │
│  Import improvements, test coverage, performance                     │
│  Can happen anytime without blocking other tracks                    │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Track 1: IziPOS Module

**Goal:** Functional point-of-sale system for para-pharmacy and coffee shops  
**Estimated Duration:** 9-13 weeks  
**Dependencies:** Core platform (✅ Ready)

### Phase 1A: Core POS Infrastructure (4-6 weeks)

| Task | Description | Complexity |
|------|-------------|------------|
| Terminal Management | Registration, approval, device binding | High |
| Cash Register | Open/close workflow, drawer management | Medium |
| X-Report | Mid-shift snapshot (no counter reset) | Medium |
| Z-Report | End-of-day closing (counter reset, permanent) | Medium |
| Basic Transaction | Scan → Cart → Payment → Receipt | High |
| Receipt Printing | ESC/POS thermal printer support | Medium |
| Hash Chain | Per-terminal cryptographic chain | High |

**Key Deliverables:**
- `pos_terminals` table with status workflow
- `pos_sessions` table for shift tracking  
- `pos_transactions` with hash chain
- Terminal registration UI
- Cash register open/close flow
- X and Z report generation

### Phase 1B: Retail POS Features (3-4 weeks)

| Task | Description | Complexity |
|------|-------------|------------|
| Barcode Scanning | USB/Bluetooth scanner support | Low |
| Product Lookup | Quick search by name/SKU/barcode | Medium |
| Split Payments | Multiple payment methods per transaction | Medium |
| Discounts | Line-level, transaction-level | Medium |
| Voids | Cancel items with reason codes | Low |
| Draft/Parked Sales | Save incomplete, retrieve later | Medium |
| Sales History | Searchable transaction log | Medium |
| Customer Account | Select customer, view balance | Low |

**Key Deliverables:**
- Full checkout flow UI
- Payment method selection
- Discount application
- Transaction search/filter

### Phase 1C: Batch/Expiry Tracking (2-3 weeks, parallel with 1B)

| Task | Description | Complexity |
|------|-------------|------------|
| Lot/Batch Model | Track by manufacturing batch | Medium |
| Expiry Dates | Mandatory for pharmacy products | Low |
| FEFO Logic | First-Expired-First-Out suggestions | High |
| Expiry Alerts | Warnings at configurable thresholds | Medium |
| Expired Block | Prevent sale of expired items | Low |
| POS Integration | Batch selection at checkout | Medium |

**Key Deliverables:**
- `product_batches` table
- FEFO inventory selection service
- Expiry alert system
- Batch info on receipts

---

## Track 2: Tunisia Compliance

**Goal:** TEJ platform integration for withholding tax  
**Estimated Duration:** 7-10 weeks  
**Dependencies:** Core Treasury module (✅ Ready)

### Phase 2A: Withholding Tax Rules (2 weeks)

| Task | Description | Complexity |
|------|-------------|------------|
| WithholdingTaxRule Model | Country, type, rate configuration | Medium |
| Tunisia Seeder | Pre-configured Tunisian rates | Low |
| Partner Tax Settings | Tax regime per partner | Low |
| Rule Matching | Determine applicable rate | Medium |

**Tunisia Rates to Seed:**
- Professional services (proper accounts): 3%
- Professional services (others): 10%
- Rentals: 10%
- Performance bonuses: 15%
- Interest: 20%
- General goods/services >1,000 TND: 1.5%

### Phase 2B: Withholding Certificates (3-4 weeks)

| Task | Description | Complexity |
|------|-------------|------------|
| Purchase Withholding | Calculate at payment time | Medium |
| Certificate Generation | Create formal certificate document | High |
| Certificate Numbering | Sequential per company per year | Low |
| Treasury Integration | Track withheld amounts separately | Medium |
| Sales Withholding | Toggle when customer withholds | Low |

**Purchase Flow:**
```
Invoice 1,000 TND
  └── Withhold 15% (150 TND)
  └── Pay supplier 850 TND
  └── Issue certificate for 150 TND
  └── Upload to TEJ platform
```

### Phase 2C: TEJ XML Export (2-4 weeks)

| Task | Description | Complexity |
|------|-------------|------------|
| XML Schema | Implement TEJ format | High |
| Export Service | Generate compliant XML | Medium |
| Validation | Ensure data completeness | Medium |
| Batch Export | Multiple certificates in one file | Low |

**Blocker:** Need official TEJ XML schema from Tunisia Ministry of Finance

---

## Track 3: Core Refinement

**Goal:** Ongoing improvements without blocking feature development  
**Duration:** Continuous (background)

### Current Sprint: Import Functionality
- [ ] Product import improvements
- [ ] Partner import improvements
- [ ] Validation error handling
- [ ] Progress feedback

### Upcoming
- [ ] Expand test coverage (target: 80%)
- [ ] Performance baseline establishment
- [ ] UI polish for existing features
- [ ] i18n completion

---

## Timeline Overview

```
Week    1   2   3   4   5   6   7   8   9   10  11  12  13
        ┼───┼───┼───┼───┼───┼───┼───┼───┼───┼───┼───┼───┼

TRACK 1A: Terminal/Cash
        ████████████████████████

TRACK 1B: Retail POS
                            ████████████████

TRACK 1C: Batch/Expiry (parallel)
                            ████████████

TRACK 2A: Tax Rules
        ████████

TRACK 2B: Certificates
                ████████████████

TRACK 2C: TEJ Export
                            ████████████████

TRACK 3: Core (continuous)
        ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
```

---

## Parallel Work Rules

### What CAN Run in Parallel

| Track A | Track B | Why Safe |
|---------|---------|----------|
| 1A (Terminal) | 2A (Tax Rules) | Different modules, no file overlap |
| 1B (Retail POS) | 1C (Batch) | Same module but different concerns |
| 1B (Retail POS) | 2B (Certificates) | Different modules |
| 3 (Core) | Any | Core work is isolated |

### What Should Be Sequential

| First | Then | Why |
|-------|------|-----|
| 1A (Terminal) | 1B (Retail POS) | POS features need terminal infrastructure |
| 2A (Tax Rules) | 2B (Certificates) | Certificates use tax rules |
| 2B (Certificates) | 2C (TEJ Export) | Export needs certificate data |

---

## Decision Points

### Before Starting Phase 1A
- [ ] Confirm terminal numbering strategy (per-terminal sequences ✅)
- [ ] Choose hash algorithm (recommend SHA-256)
- [ ] Define receipt legal requirements for Tunisia

### Before Starting Phase 2C
- [ ] Obtain TEJ XML schema specification
- [ ] Clarify submission requirements
- [ ] Test with Ministry sandbox (if available)

---

## Success Metrics

| Milestone | Target Date | Criteria |
|-----------|-------------|----------|
| First POS Sale | Week 6 | Complete transaction through system |
| Cash Register Close | Week 6 | Z-report generates correctly |
| First Withholding Certificate | Week 8 | Valid certificate PDF generated |
| Batch Tracking Live | Week 10 | FEFO working in POS checkout |
| TEJ Export Ready | Week 13 | Valid XML passes validation |

---

## Risk Register

| Risk | Impact | Mitigation |
|------|--------|------------|
| TEJ schema unavailable | High | Start with best-guess format, adjust when schema obtained |
| Hardware integration issues | Medium | Use simulator for development, test with real hardware early |
| Performance with hash chains | Medium | Benchmark early, optimize if needed |
| Scope creep | High | Strict phase boundaries, defer nice-to-haves |
