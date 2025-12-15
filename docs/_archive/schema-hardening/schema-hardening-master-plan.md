# Schema Hardening + GL Posting - Master Implementation Plan

**Version:** 1.0  
**Date:** 2024-12-10  
**Status:** Ready for Opus Review  
**Estimated Duration:** 9 days  
**Critical Constraint:** Smart payments frontend must remain functional

---

## Executive Summary

This plan implements database-level fiscal compliance hardening for the documents table, followed by GL posting service implementation. The approach is based on deep research into NF525, ZATCA, and KassenSichV certification requirements, validated against Odoo's certified polymorphic table design.

**Key Insight:** Polymorphic tables CAN pass fiscal certification (Odoo v13 NF525 certified), but strategic hardening provides defense-in-depth and auditor confidence.

---

## Project Structure

```
AutoERP Schema Hardening
│
├── Phase 0: Compatibility Analysis (2h)
│   └── Ensure smart payments frontend won't break
│
├── Phase 1: Schema Hardening (6h)
│   ├── Step 1.1: Add core columns (fiscal_category, status)
│   ├── Step 1.2: Add CHECK constraints
│   ├── Step 1.3: Create extension tables (FR, SA, DE)
│   ├── Step 1.4: Add immutability triggers
│   └── Step 1.5: Update models and enums
│
├── Phase 2: API Contract Updates (3h)
│   ├── Step 2.1: Update API resources (additive only)
│   ├── Step 2.2: Update validation rules
│   └── Step 2.3: Update error handling
│
├── Phase 3: Frontend Integration (2h)
│   ├── Step 3.1: TypeScript interfaces
│   ├── Step 3.2: UI components
│   └── Step 3.3: Validation checklist
│
├── Phase 4: GL Posting Implementation (1 week)
│   ├── Step 4.1: DocumentPostingService
│   ├── Step 4.2: FiscalMetadataService
│   └── Step 4.3: Comprehensive tests
│
└── Phase 5: Integration Testing (2h)
    └── End-to-end scenarios
```

---

## Critical Design Decisions

### Decision 1: Polymorphic Table with Strategic Hardening

**Chosen Approach:** Keep polymorphic `documents` table, add defense-in-depth
- ✅ Proven by Odoo (NF525 certified)
- ✅ Flexible for multi-document types
- ✅ Hardened where it matters for compliance
- ❌ Rejected: Separate tables (defeats polymorphic benefits)

### Decision 2: Three-Layer Defense Strategy

**Layer 1: Core CHECK Constraints**
- Enforce mandatory fields for fiscal documents
- Cannot be bypassed by application code

**Layer 2: Country-Specific Extension Tables**
- `fr_fiscal_metadata`, `sa_fiscal_metadata`, `de_fiscal_metadata`
- Clean separation, no NULL pollution in main table

**Layer 3: DB-Level Immutability Triggers**
- Prevent modification of sealed documents
- Align with NF525 "inalterability" principle

### Decision 3: Additive-Only Changes (Non-Breaking)

**All schema changes are additive:**
- New columns: `fiscal_category`, `status`
- New constraints: CHECK, triggers
- New tables: Extension tables
- ❌ No column removals
- ❌ No breaking type changes
- ❌ No removal of existing behavior

---

## Dependency Map

```
┌─────────────────────────────────────────────────────────────┐
│              Smart Payments Frontend (IN PROGRESS)          │
│  Status: Being implemented by another Claude Code instance  │
└────────────────┬────────────────────────────────────────────┘
                 │
                 │ Must not break
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│                   DOCUMENTS TABLE (Core)                     │
│        Phase 1: Add fiscal_category, status, triggers       │
└────────────────┬────────────────────────────────────────────┘
                 │
                 │ Enables
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│                   GL POSTING SERVICE                         │
│        Phase 4: Implement DocumentPostingService            │
└─────────────────────────────────────────────────────────────┘
```

---

## Success Criteria

### Phase 0
- [ ] Compatibility matrix completed
- [ ] Current API contracts documented
- [ ] Frontend dependencies identified
- [ ] Non-breaking strategy confirmed

### Phase 1
- [ ] Migrations executed successfully
- [ ] All existing tests pass
- [ ] New columns visible in documents table
- [ ] CHECK constraints enforced
- [ ] Triggers prevent sealed document modification
- [ ] Extension tables created

### Phase 2
- [ ] API resources include new fields
- [ ] Validation rules updated
- [ ] Error handling covers new exceptions
- [ ] Backward compatibility verified

### Phase 3
- [ ] TypeScript interfaces updated
- [ ] UI components handle new fields
- [ ] Smart payments frontend still functional
- [ ] No breaking changes detected

### Phase 4
- [ ] DocumentPostingService implemented
- [ ] FiscalMetadataService implemented
- [ ] Documents sealed correctly
- [ ] Hash chains computed
- [ ] Country metadata created
- [ ] All tests pass (unit + integration)

### Phase 5
- [ ] End-to-end scenarios pass
- [ ] No regressions detected
- [ ] Performance acceptable
- [ ] Documentation updated

---

## Risk Mitigation

| Risk | Mitigation Strategy |
|------|-------------------|
| **Schema changes break frontend** | Additive-only changes, compatibility phase, extensive testing |
| **CHECK constraints too strict** | Allow balance_due updates on sealed docs, comprehensive edge case tests |
| **Triggers cause performance issues** | Simple trigger logic, benchmarking, indexes on status column |
| **Payment allocation affected** | Triggers explicitly allow balance_due updates, payment service doesn't seal docs |
| **Country metadata creation fails** | Graceful error handling, logging, retry mechanism |
| **Hash chain breaks** | Test vectors, validation before/after each operation |

---

## Verification Strategy

### After Each Phase

```bash
# 1. Run full test suite
php artisan test

# 2. Check PHPStan
./vendor/bin/phpstan analyse app/Modules/Accounting --level=8
./vendor/bin/phpstan analyse app/Modules/Treasury --level=8

# 3. Verify database schema
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'fiscal_category exists: ' . (Schema::hasColumn('documents', 'fiscal_category') ? '✓' : '✗') . PHP_EOL;
echo 'status exists: ' . (Schema::hasColumn('documents', 'status') ? '✓' : '✗') . PHP_EOL;
"

# 4. Test constraint enforcement
php artisan tinker --execute="
try {
    App\Models\Document::create([
        'fiscal_category' => 'TAX_INVOICE',
        'document_number' => null, // Should violate CHECK
    ]);
    echo '✗ CHECK constraint not working' . PHP_EOL;
} catch (\Exception \$e) {
    echo '✓ CHECK constraint enforced' . PHP_EOL;
}
"

# 5. Test trigger enforcement
php artisan tinker --execute="
\$doc = App\Models\Document::where('status', 'SEALED')->first();
if (\$doc) {
    try {
        \$doc->total_amount = '999.99';
        \$doc->save();
        echo '✗ Trigger not working' . PHP_EOL;
    } catch (\Exception \$e) {
        echo '✓ Trigger prevents sealed modification' . PHP_EOL;
    }
}
"
```

---

## Rollback Strategy

Each phase is atomic and can be rolled back independently:

### Phase 1 Rollback
```bash
# Rollback migrations in reverse order
php artisan migrate:rollback --step=5

# Verify rollback
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'fiscal_category exists: ' . (Schema::hasColumn('documents', 'fiscal_category') ? 'YES' : 'NO') . PHP_EOL;
"
```

### Phase 2-3 Rollback
- API changes are additive (no rollback needed)
- Frontend changes are feature flags (toggle off)

### Phase 4 Rollback
```bash
# Remove DocumentPostingService references
# Restore previous posting logic
# Re-run tests
```

---

## Communication Plan

### Stakeholders
- **Frontend Team:** Notify before Phase 1, validate after Phase 3
- **QA Team:** Provide test scenarios for Phase 5
- **DevOps:** Monitor performance after deployment
- **Compliance Team:** Review Phase 1-2 completion

### Checkpoints
1. **Before Phase 1:** Frontend team confirms smart payments complete
2. **After Phase 1:** Review schema changes, verify non-breaking
3. **After Phase 3:** Frontend validates integration
4. **After Phase 4:** Compliance review of GL posting
5. **After Phase 5:** Go/no-go decision for production

---

## Timeline

### Week 1
- **Day 1 (Mon):** Wait for smart payments frontend completion
- **Day 2 (Tue):** Phase 0 - Compatibility analysis
- **Day 3 (Wed):** Phase 1 - Schema hardening
- **Day 4 (Thu):** Phase 2 - API contract updates
- **Day 5 (Fri):** Phase 3 - Frontend integration

### Week 2
- **Day 1-3 (Mon-Wed):** Phase 4 - GL posting implementation
- **Day 4 (Thu):** Phase 4 - Tests and refinement
- **Day 5 (Fri):** Phase 5 - Integration testing

### Week 3
- **Day 1 (Mon):** Buffer for issues, documentation
- **Day 2 (Tue):** Production deployment planning

---

## Post-Implementation

### Monitoring
- Track query performance on documents table
- Monitor trigger execution time
- Alert on CHECK constraint violations
- Log country metadata creation failures

### Documentation Updates
- Update API documentation with new fields
- Update ER diagrams with extension tables
- Create compliance certification guide
- Update developer onboarding docs

### Next Steps
- Implement payment system (uses hardened schema)
- Add Tunisia country adaptation
- Implement Z-report closures (France)
- Implement ZATCA Phase 2 integration (Saudi Arabia)

---

## Appendices

- **Appendix A:** Research Summary (NF525, ZATCA, KassenSichV)
- **Appendix B:** Odoo Certified Pattern Analysis
- **Appendix C:** Full SQL Migration Scripts
- **Appendix D:** Test Scenarios and Expected Outcomes
- **Appendix E:** Error Code Reference Guide

---

**Document Status:** ✅ Ready for Claude Code Opus Review

**Next Action:** Claude Code Opus should review this plan, validate approach, adjust if needed, then proceed to Phase 0.
