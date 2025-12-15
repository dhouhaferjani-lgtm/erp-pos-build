# Quick Reference Guide - Schema Hardening Implementation

**Last Updated:** 2024-12-10  
**Status:** Ready for Implementation

---

## 📁 Document Structure

```
/home/claude/
├── schema-hardening-master-plan.md           # Overall strategy, timeline, risks
├── PHASE-0-COMPATIBILITY-ANALYSIS.md         # Compatibility assessment (2h)
├── PHASE-1-SCHEMA-HARDENING.md              # Schema changes (6h) - MOST DETAILED
├── PHASE-2-3-4-BRIEF.md                     # API, Frontend, GL Posting briefs
└── CLAUDE-CODE-OPUS-PROMPT.md               # Comprehensive prompt for Opus
```

---

## 🎯 Quick Start for Claude Code Opus

### 1. Read Documents in Order
```bash
1. CLAUDE-CODE-OPUS-PROMPT.md       # Start here - your mission
2. schema-hardening-master-plan.md  # Overall strategy
3. PHASE-0-COMPATIBILITY-ANALYSIS.md # Your first task
4. PHASE-1-SCHEMA-HARDENING.md      # Most detailed implementation
5. PHASE-2-3-4-BRIEF.md             # Remaining phases
```

### 2. Verify Prerequisites
- [ ] Smart payments frontend complete
- [ ] All current tests passing
- [ ] Database backup created
- [ ] Development environment ready

### 3. Start with Phase 0
```bash
cd /path/to/autoerp
cat /home/claude/PHASE-0-COMPATIBILITY-ANALYSIS.md
# Follow instructions step by step
```

---

## 🔑 Key Concepts

### Three-Layer Defense
1. **CHECK Constraints** → Enforce mandatory fields at DB level
2. **Extension Tables** → Country-specific data (no NULL pollution)
3. **Immutability Triggers** → Prevent sealed document modification

### Non-Breaking Changes
- ✅ Add columns (with defaults)
- ✅ Add tables
- ✅ Add constraints (validate existing data first)
- ❌ Remove columns
- ❌ Change column types
- ❌ Break API contracts

### Critical Allowances
- ✅ Update `balance_due` on sealed documents (payments need this)
- ✅ Transition status from SEALED to VOIDED (correction mechanism)
- ❌ Update any other field on sealed documents

---

## 📊 Phase Overview

| Phase | Duration | Status | Key Deliverable |
|-------|----------|--------|-----------------|
| 0: Compatibility | 2h | ⏳ Pending | Compatibility report |
| 1: Schema Hardening | 6h | ⏳ Pending | Hardened documents table |
| 2: API Updates | 3h | ⏳ Pending | Updated API contracts |
| 3: Frontend | 2h | ⏳ Pending | Integration validated |
| 4: GL Posting | 1 week | ⏳ Pending | DocumentPostingService |
| 5: Integration | 2h | ⏳ Pending | E2E tests passing |

---

## 🚨 Critical Checkpoints

### After Phase 0
```bash
✓ Compatibility report created
✓ Baseline tests documented
✓ No breaking changes identified
→ APPROVAL REQUIRED before Phase 1
```

### After Phase 1
```bash
✓ All migrations applied
✓ Baseline tests still pass ← CRITICAL
✓ Constraint tests pass
✓ Trigger tests pass
✓ balance_due updates allowed on sealed docs ← VERIFY THIS
→ APPROVAL REQUIRED before Phase 2
```

### After Phase 4
```bash
✓ DocumentPostingService implemented
✓ Documents sealed correctly
✓ GL entries created
✓ Country metadata populated
✓ All tests pass
✓ PHPStan level 8 clean
→ APPROVAL REQUIRED before Phase 5
```

---

## 🧪 Key Verification Commands

### Check Schema
```bash
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'fiscal_category: ' . (Schema::hasColumn('documents', 'fiscal_category') ? '✓' : '✗') . PHP_EOL;
echo 'status: ' . (Schema::hasColumn('documents', 'status') ? '✓' : '✗') . PHP_EOL;
"
```

### Check Constraints
```bash
php artisan tinker --execute="
\$constraints = DB::select(\"
    SELECT constraint_name
    FROM information_schema.table_constraints
    WHERE table_name = 'documents' AND constraint_type = 'CHECK'
\");
echo 'CHECK constraints: ' . count(\$constraints) . PHP_EOL;
"
```

### Check Triggers
```bash
php artisan tinker --execute="
\$triggers = DB::select(\"
    SELECT trigger_name
    FROM information_schema.triggers
    WHERE event_object_table = 'documents'
\");
echo 'Triggers: ' . count(\$triggers) . PHP_EOL;
"
```

### Run Tests
```bash
# Baseline tests (must pass after Phase 1)
php artisan test tests/Feature/PaymentSystemBaselineTest.php

# Constraint tests
php artisan test tests/Feature/FiscalConstraintTest.php

# Trigger tests
php artisan test tests/Feature/ImmutabilityTriggerTest.php

# All tests
php artisan test --stop-on-failure
```

---

## 💡 Implementation Tips

### When Adding Migrations
1. Name clearly: `YYYY_MM_DD_HHMMSS_descriptive_name`
2. Test up() AND down()
3. Verify data backfill correct
4. Run verification commands after each
5. Commit at designated points

### When Writing Tests
1. Test happy path first
2. Then test constraints/triggers
3. Then test edge cases
4. Each test should be atomic
5. Use descriptive test names

### When Implementing Services
1. Start with interfaces/contracts
2. Then implement concrete classes
3. Write tests BEFORE implementation (TDD)
4. Use DB::transaction for atomicity
5. Dispatch events for auditability

---

## 📝 Commit Message Format

```
feat(accounting): [concise description]

- [bullet point 1]
- [bullet point 2]
- [bullet point 3]

Relates to schema hardening Phase X.Y
```

**Examples:**
```
feat(accounting): add fiscal_category and status columns to documents

- Add fiscal_category enum column (NON_FISCAL, FISCAL_RECEIPT, TAX_INVOICE, CREDIT_NOTE)
- Add status enum column (DRAFT, SEALED, VOIDED)
- Backfill fiscal_category based on document_type
- Add indexes for common queries

Relates to schema hardening Phase 1.1
```

---

## 🆘 Troubleshooting

### Baseline Tests Fail After Phase 1
**Cause:** Schema change broke existing functionality  
**Action:**
1. Identify which test failed
2. Check what changed in that area
3. Verify trigger allows necessary operations
4. Report issue and STOP

### Constraint Violation on Existing Data
**Cause:** Existing data doesn't meet new constraint  
**Action:**
1. Run data audit query
2. Fix data issues first
3. Then apply constraint
4. Document data quality issues

### Trigger Too Restrictive
**Cause:** Trigger blocks legitimate operation  
**Action:**
1. Verify operation should be allowed
2. Adjust trigger logic
3. Re-test thoroughly
4. Document change in commit

---

## 📚 Additional Resources

### Existing Codebase
- Document model: `app/Models/Document.php`
- GL service: `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- Payment service: `app/Modules/Treasury/Application/Services/PaymentAllocationService.php`

### Research
- Full research: `/mnt/transcripts/2025-12-10-10-53-48-fiscal-schema-compliance-research.txt`
- Odoo pattern validation
- Regulatory requirements (NF525, ZATCA, KassenSichV)

### Testing
- PHPUnit docs: https://phpunit.de/
- Laravel testing: https://laravel.com/docs/testing
- Database testing: https://laravel.com/docs/database-testing

---

## ✅ Final Checklist

### Before Starting
- [ ] Read all documentation
- [ ] Understand non-breaking constraint
- [ ] Understand three-layer defense
- [ ] Know when to escalate
- [ ] Development environment ready

### After Each Phase
- [ ] All verification commands executed
- [ ] All tests passing
- [ ] PHPStan clean
- [ ] Changes committed
- [ ] Approval requested

### Before Production
- [ ] All phases complete
- [ ] Zero breaking changes confirmed
- [ ] Smart payments frontend validated
- [ ] Integration tests passing
- [ ] Documentation updated

---

**Remember:** Quality over speed. Breaking changes are not acceptable. When in doubt, ask.

**Good luck! 🚀**
