# Roadmap Summary

> Prioritized action items for launch and fork

---

## Overall Status

| Area | Score | Status |
|------|-------|--------|
| Backend Business Flow | 85% | Ready with gaps |
| Frontend Features | 72% | Needs i18n fixes |
| Data Quality | 75% | Hardcoded values to fix |
| Translations | 70% | 50+ strings to translate |
| Import Capabilities | 80% | Core features complete |
| Treasury/Payments | 75% | Missing refund UI |
| Audit/Compliance | 85% | Events partially missing |

**Overall: 78%** - Production-ready for core workflows

---

## Critical Blockers (Must Fix Before Launch)

### Week 1 Priority

| # | Task | Module | Effort | Impact |
|---|------|--------|--------|--------|
| 1 | Fix currency hardcoding in 8 components | Frontend | 4h | Multi-country broken |
| 2 | Add missing French translations | i18n | 2h | French users see English |
| 3 | Translate settings page hardcoded text | i18n | 4h | Core UI not localized |
| 4 | Fix margin default inconsistency | Backend | 30min | Wrong calculations |
| 5 | Add document lifecycle events | Audit | 8h | Compliance gap |

### Week 2 Priority

| # | Task | Module | Effort | Impact |
|---|------|--------|--------|--------|
| 6 | Implement PO → Purchase Invoice | Backend | 8h | Purchase flow incomplete |
| 7 | Add COGS posting automation | Backend | 4h | Inventory not reduced |
| 8 | Payment refund UI | Frontend | 8h | Can't process refunds |
| 9 | Role creation UI | Frontend | 8h | Can't manage roles |
| 10 | Bank reconciliation page | Frontend | 12h | Treasury incomplete |

---

## Pre-Fork Requirements

### Must Complete

| # | Task | Effort | Reason |
|---|------|--------|--------|
| 1 | France chart of accounts seeder | 8h | Multi-country support |
| 2 | Payment methods per country | 4h | Country-specific methods |
| 3 | Supplier credit notes | 6h | Returns handling |
| 4 | Multi-currency integration | 16h | Cross-border transactions |
| 5 | Split payment UI | 6h | Common use case |

### Should Complete

| # | Task | Effort | Reason |
|---|------|--------|--------|
| 6 | User profile page | 6h | Self-service |
| 7 | Subscription/billing page | 12h | SaaS model |
| 8 | Document export (PDF) | 8h | Business requirement |
| 9 | Service import capability | 4h | Data migration |
| 10 | Locations import | 4h | Data migration |

---

## Module-Specific Roadmaps

### Backend Flow

```
Phase 1 (Critical):
├── PO → Purchase Invoice conversion
├── COGS posting on invoice
└── Supplier credit notes

Phase 2 (Important):
├── Document conversion events
├── Stock movement source linking
└── Payment tolerance thresholds

Phase 3 (Enhancement):
├── Multi-currency support
├── FIFO/LIFO costing options
└── Purchase returns workflow
```

### Frontend

```
Phase 1 (Critical):
├── Fix currency hardcoding
├── Translate hardcoded strings
├── Payment refund UI
└── Role creation UI

Phase 2 (Important):
├── Bank reconciliation
├── Split payment UI
├── Subscription page
└── User profile page

Phase 3 (Enhancement):
├── Document templates
├── Bulk operations
├── Dashboard customization
└── Advanced reporting
```

### Translations

```
Phase 1 (Critical):
├── Add 3 missing French keys in common.json
├── Refactor SettingsPage.tsx
├── Refactor UsersPage.tsx
└── Refactor AuditLogsPage.tsx

Phase 2 (Important):
├── ReportsPage labels
├── Document costing components
├── Stock action titles
└── Initialize Arabic structure

Phase 3 (Enhancement):
├── Complete Arabic translations
├── RTL support
└── Localized date/number formats
```

### Treasury

```
Phase 1 (Critical):
├── Payment refund UI
├── Split payment UI
└── Bank reconciliation page

Phase 2 (Important):
├── Multi-currency payments
├── Payment method fees
├── Supplier payment workflow

Phase 3 (Enhancement):
├── Instrument action workflows
├── Cash discounts
├── Recurring payments
└── Payment audit trail
```

### Audit/Compliance

```
Phase 1 (Critical):
├── Document lifecycle events
├── Quote→Order→Invoice tracking
├── User action logging
└── Opening balance event

Phase 2 (Compliance):
├── Z-report generation
├── Digital signatures
├── POS receipt chaining

Phase 3 (Operational):
├── API request audit
├── Real-time alerts
├── Audit export
```

---

## Effort Estimates

### Total Hours by Phase

| Phase | Backend | Frontend | i18n | Total |
|-------|---------|----------|------|-------|
| **Week 1** | 12h | 16h | 6h | **34h** |
| **Week 2** | 12h | 28h | 4h | **44h** |
| **Pre-Fork** | 34h | 36h | 8h | **78h** |

### Total: ~156 hours (~4 weeks with 1 developer)

---

## Risk Assessment

### High Risk Items

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Purchase invoice missing | Can't process supplier invoices | Prioritize in Week 2 |
| COGS not posting | Wrong financial reports | Add event listener |
| Currency hardcoding | Wrong prices displayed | Fix in Week 1 |

### Medium Risk Items

| Risk | Impact | Mitigation |
|------|--------|-----------|
| No refund UI | Manual process needed | Add in Week 2 |
| Missing audit events | Compliance gap | Add critical events |
| Single country COA | Limits expansion | Add France seeder |

### Low Risk Items

| Risk | Impact | Mitigation |
|------|--------|-----------|
| No Arabic support | Future market | Initialize structure |
| Limited reports | Manual analysis | Phase 3 enhancement |

---

## Testing Before Launch

### Critical Path Tests

- [ ] Quote → Order → Delivery → Invoice → Payment (full flow)
- [ ] Purchase Order → Goods Receipt → (manual invoice)
- [ ] Credit Note → GL reversal
- [ ] Prepayment on order → Transfer to invoice
- [ ] Fiscal hash chain integrity (100+ documents)
- [ ] Multi-tenant data isolation
- [ ] French language display (all pages)

### Performance Tests

- [ ] 1000+ invoices in hash chain
- [ ] Concurrent stock adjustments
- [ ] Large import (5000 rows)

---

## Launch Checklist

### Pre-Production

- [ ] Remove test users from production seeds
- [ ] Verify currency from company context
- [ ] Fix margin default inconsistency
- [ ] Create separate demo data seeder
- [ ] Clear fake bank account data
- [ ] Run all PHPStan checks (level 8)
- [ ] Run all frontend type checks
- [ ] Complete critical translations

### Production Readiness

- [ ] Database backups configured
- [ ] Redis/queue workers running
- [ ] Error monitoring (Sentry, etc.)
- [ ] SSL certificates
- [ ] Domain DNS configured
- [ ] Email delivery working
- [ ] File storage (S3 or local)

### Post-Launch Monitoring

- [ ] Hash chain verification cron job
- [ ] Anomaly detection alerts
- [ ] Error rate monitoring
- [ ] Performance metrics

---

## Fork Strategy

### Automotive ERP (mecanospex)

**Keep**:
- Vehicle module
- Workshop module
- VIN decoding integration
- Parts catalog features

**Enhance**:
- Automotive-specific reports
- Vehicle history tracking
- Workshop scheduling

**Remove**:
- Generic inventory features not needed

### Generic ERP (boss-erp)

**Keep**:
- Core document/invoice processing
- Accounting/GL engine
- Treasury/payment system
- Compliance/audit framework

**Enhance**:
- Multi-industry templates
- CRM module
- Project management

**Remove**:
- Vehicle module
- Workshop module

### Shared Core (Monorepo)

```
packages/
├── core-accounting/     # GL, journal entries
├── core-documents/      # Invoices, quotes, orders
├── core-treasury/       # Payments, allocation
├── core-compliance/     # Hash chain, audit
└── core-imports/        # Data import framework
```

---

## Success Criteria

### Launch Ready When:

1. Full sales cycle works end-to-end
2. French translations complete
3. Critical blockers resolved
4. Hash chain verified
5. No high-risk issues remaining

### Fork Ready When:

1. All pre-fork requirements complete
2. Multi-country support tested
3. Core packages extracted
4. Documentation complete
5. CI/CD pipelines configured

---

**File**: `docs/live-readiness/08-ROADMAP-SUMMARY.md`
**Generated**: 2025-12-13
