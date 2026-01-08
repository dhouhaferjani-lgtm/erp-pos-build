# Track 2: Tunisia Compliance

**Country:** Tunisia  
**Priority:** Strategic  
**Duration:** 7-10 weeks

---

## Track Overview

Implement withholding tax management and TEJ platform integration.

```
Phase 2A: Tax Rules ──► Phase 2B: Certificates ──► Phase 2C: TEJ Export
        (2 weeks)            (3-4 weeks)              (2-4 weeks)
```

---

## Phase 2A: Withholding Tax Rules

**Duration:** 2 weeks  
**Status:** 🔵 Not Started

### Goals
- Withholding tax rule configuration
- Tunisia-specific rates seeded
- Partner tax settings
- Rule matching logic

### Tasks

| # | Task | Complexity | Estimate | Status |
|---|------|------------|----------|--------|
| 1 | withholding_tax_rules migration | Medium | 0.5d | ⬜ |
| 2 | partner_tax_settings migration | Low | 0.5d | ⬜ |
| 3 | WithholdingTaxRule model | Medium | 1d | ⬜ |
| 4 | PartnerTaxSettings model | Low | 0.5d | ⬜ |
| 5 | Tunisia rules seeder | Medium | 1d | ⬜ |
| 6 | WithholdingTaxService (rule matching) | High | 2d | ⬜ |
| 7 | Partner tax settings UI | Medium | 1d | ⬜ |
| 8 | Admin: Rule management UI | Medium | 2d | ⬜ |
| 9 | Tests | Medium | 1d | ⬜ |

### Tunisia Rates to Seed

| Code | Description | Rate | Conditions |
|------|-------------|------|------------|
| TN_PROF_SERVICES_BOOKS_3 | Services (régime réel) | 3% | Corporate with books |
| TN_PROF_SERVICES_OTHER_10 | Services (forfait) | 10% | Individual/forfait |
| TN_RENTAL_10 | Loyers | 10% | All rentals |
| TN_COMMISSIONS_15 | Commissions | 15% | All commissions |
| TN_INTEREST_20 | Intérêts | 20% | All interest |
| TN_GOODS_SERVICES_1_5 | Biens/services | 1.5% | ≥1,000 TND, corporate |
| TN_NON_RESIDENT_15 | Non-résidents | 15% | Non-resident partners |

### Deliverables
- [ ] Withholding rules table with Tunisia data
- [ ] Partner tax regime settings
- [ ] Rule matching service
- [ ] Admin UI for rule management

### Dependencies
- Treasury module ready ✅
- Partner module ready ✅

---

## Phase 2B: Withholding Certificates

**Duration:** 3-4 weeks  
**Status:** 🔵 Not Started  
**Depends On:** Phase 2A

### Goals
- Certificate generation on purchase payments
- Certificate numbering (sequential per year)
- PDF generation
- Sales withholding tracking

### Tasks

| # | Task | Complexity | Estimate | Status |
|---|------|------------|----------|--------|
| 1 | withholding_certificates migration | Medium | 1d | ⬜ |
| 2 | sales_withholding_tracking migration | Low | 0.5d | ⬜ |
| 3 | WithholdingCertificate model | Medium | 1d | ⬜ |
| 4 | CertificateStatus enum | Low | 0.25d | ⬜ |
| 5 | WithholdingCalculation value object | Medium | 0.5d | ⬜ |
| 6 | WithholdingCertificateService | High | 2d | ⬜ |
| 7 | Payment integration (apply withholding) | High | 2d | ⬜ |
| 8 | Certificate PDF generation | Medium | 2d | ⬜ |
| 9 | Certificate API endpoints | Medium | 1d | ⬜ |
| 10 | Sales withholding tracking | Medium | 1d | ⬜ |
| 11 | Frontend: Payment withholding UI | Medium | 2d | ⬜ |
| 12 | Frontend: Certificate list/detail | Medium | 1d | ⬜ |
| 13 | Frontend: Sales withholding toggle | Low | 0.5d | ⬜ |
| 14 | Tests | Medium | 2d | ⬜ |

### Payment Flow

```
Supplier Invoice: 1,000 TND
         │
         ▼
┌─────────────────────────────────┐
│ System checks withholding rules │
│ Partner regime: Individual      │
│ Transaction: Services           │
│ Matched: 10% rate               │
└─────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ Display in payment form:        │
│ ─────────────────────────────── │
│ Invoice Total:    1,000.000 TND │
│ Withholding (10%): -100.000 TND │
│ Net Payment:        900.000 TND │
│ ─────────────────────────────── │
│ □ Override rate  [Edit]         │
└─────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│ On payment confirmation:        │
│ • Record payment: 900 TND       │
│ • Create certificate (draft)    │
│ • Issue certificate             │
│ • PDF available for download    │
└─────────────────────────────────┘
```

### Deliverables
- [ ] Withholding applied at payment time
- [ ] Certificate automatically created
- [ ] Sequential numbering (WHT-2025-0001)
- [ ] Certificate PDF download
- [ ] Sales withholding tracking for receivables
- [ ] Withholding summary report

### Dependencies
- Phase 2A complete
- Treasury payment flow exists ✅

---

## Phase 2C: TEJ Export

**Duration:** 2-4 weeks  
**Status:** 🔵 Not Started  
**Depends On:** Phase 2B  
**Blocker:** TEJ XML schema needed

### Goals
- Generate TEJ-compliant XML export
- Batch export for monthly submission
- Track submission status

### Tasks

| # | Task | Complexity | Estimate | Status |
|---|------|------------|----------|--------|
| 1 | Obtain TEJ XML schema | External | ? | ⬜ |
| 2 | TEJExportService | High | 3d | ⬜ |
| 3 | XML generation | High | 2d | ⬜ |
| 4 | XML validation | Medium | 1d | ⬜ |
| 5 | Export API endpoint | Low | 0.5d | ⬜ |
| 6 | Mark certificates as submitted | Low | 0.5d | ⬜ |
| 7 | Frontend: Export UI | Medium | 1d | ⬜ |
| 8 | Frontend: Submission tracking | Low | 0.5d | ⬜ |
| 9 | Tests | Medium | 1d | ⬜ |

### Expected XML Structure (Placeholder)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<DeclarationRetenue>
    <Declarant>
        <MatriculeFiscal>XXXXXXX</MatriculeFiscal>
        <RaisonSociale>Company Name</RaisonSociale>
    </Declarant>
    <Periode>
        <Mois>12</Mois>
        <Annee>2025</Annee>
    </Periode>
    <ListeRetenues>
        <Retenue>
            <!-- Certificate details -->
        </Retenue>
    </ListeRetenues>
</DeclarationRetenue>
```

### Deliverables
- [ ] TEJ-compliant XML generation
- [ ] Monthly export function
- [ ] Certificates marked as submitted
- [ ] Export history tracking

### Dependencies
- Phase 2B complete
- **BLOCKER:** Official TEJ XML schema from Ministry of Finance

### Action Required
- [ ] Research TEJ platform documentation
- [ ] Contact Ministry of Finance or authorized provider
- [ ] Obtain sample XML files if possible

---

## Success Criteria

### Phase 2A Complete When:
- [ ] Tunisia withholding rules seeded
- [ ] Partner tax regime can be set
- [ ] System correctly identifies applicable rate
- [ ] Rate can be overridden with reason

### Phase 2B Complete When:
- [ ] Withholding calculated at payment time
- [ ] Certificate created automatically
- [ ] Certificate PDF downloads correctly
- [ ] Sequential numbering works
- [ ] Sales withholding can be tracked
- [ ] Summary report shows all withholdings

### Phase 2C Complete When:
- [ ] Valid TEJ XML can be exported
- [ ] Monthly batch export works
- [ ] Certificates marked as submitted
- [ ] Can re-export if needed

---

## Risk Mitigation

| Risk | Impact | Mitigation |
|------|--------|------------|
| TEJ schema not available | High | Start with best-guess format, adjust when schema obtained |
| Incorrect tax rates | High | Make rates configurable, allow admin edits |
| Edge cases in rule matching | Medium | Extensive testing, allow manual override |
| PDF format requirements | Low | Use simple format initially, refine based on feedback |

---

## Research Tasks

Before starting implementation:

- [ ] Find TEJ platform documentation
- [ ] Identify official XML schema
- [ ] Check for API integration (vs file upload)
- [ ] Verify certificate legal requirements
- [ ] Check penalty deadlines for submissions

---

## Specification
See: `03-MODULE-SPECS/TAX-WITHHOLDING-SPEC.md`
