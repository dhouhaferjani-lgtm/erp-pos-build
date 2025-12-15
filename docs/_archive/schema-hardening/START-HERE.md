# START HERE: Schema Hardening Handoff Brief

**Date:** December 10, 2025  
**Mission:** Implement fiscal compliance schema hardening and GL posting  
**Status:** Smart Payments COMPLETE ✅

---

## 📋 Quick Context

**What's Done:**
- ✅ Smart payment system complete (allocation, tolerance, GL integration)
- ✅ All payment tests passing (8/8 tests, 46 assertions)
- ✅ Balance updates working correctly
- ✅ GL entries created for payments

**What's Next:**
- ⏳ Schema hardening (3-layer defense for fiscal compliance)
- ⏳ GL posting service (seal documents, create journal entries)

---

## 📚 Documentation Structure

You have 6 comprehensive documents. Read them IN THIS ORDER:

### **1. CLAUDE-CODE-OPUS-PROMPT.md** (START HERE)
**Purpose:** Your complete mission brief  
**Contains:**
- Overall objective and scope
- Constraints and non-negotiables  
- Escalation procedures
- Success criteria

**Action:** Read completely first

---

### **2. CURRENT-STATE-SUMMARY.md** (CONTEXT)
**Purpose:** What's working after smart payments  
**Contains:**
- Working payment allocation system
- Existing GL integration
- Critical test to preserve: `SmartPaymentIntegrationTest.php`
- API contracts to maintain
- Files that must keep working

**Action:** Understand what NOT to break

---

### **3. PHASE-0-COMPATIBILITY-ANALYSIS.md** (START WORK HERE)
**Purpose:** Detailed compatibility assessment process  
**Contains:**
- API contract documentation steps
- Baseline test creation
- Compatibility matrix template
- Sign-off checklist

**Action:** Execute Phase 0 first (2 hours)

---

### **4. PHASE-1-SCHEMA-HARDENING.md** (MOST IMPORTANT)
**Purpose:** Complete implementation with exact SQL  
**Contains:**
- 5 migrations with full SQL code (150+ lines each)
- Test implementations (FiscalConstraintTest, ImmutabilityTriggerTest)
- Model updates (enums, relationships, scopes)
- Verification commands with expected outputs
- Troubleshooting guide

**Action:** Execute Phase 1 (6 hours) - This is your main implementation guide

---

### **5. PHASE-2-3-4-BRIEF.md** (REMAINING PHASES)
**Purpose:** Implementation briefs for API, frontend, GL posting  
**Contains:**
- Phase 2: API contract updates
- Phase 3: Frontend integration
- Phase 4: Document posting service (1 week)
- Phase 5: Integration testing

**Action:** Execute sequentially after Phase 1 validated

---

### **6. QUICK-REFERENCE.md** (KEEP OPEN)
**Purpose:** Fast lookup during work  
**Contains:**
- Common verification commands
- Troubleshooting quick fixes
- Migration rollback commands
- Emergency procedures

**Action:** Keep open for reference throughout

---

### **7. schema-hardening-master-plan.md** (OPTIONAL READING)
**Purpose:** Overall strategy and design decisions  
**Contains:**
- Why polymorphic table approach
- Three-layer defense rationale
- Alternative approaches considered
- Risk mitigation strategy

**Action:** Read if you need strategic context

---

## 🎯 Critical Constraints (NON-NEGOTIABLE)

1. **Smart payments MUST remain functional**
   ```bash
   # This test MUST pass after Phase 1:
   php artisan test tests/Feature/Treasury/SmartPaymentIntegrationTest.php
   ```

2. **All changes ADDITIVE only**
   - No column removals
   - No type changes
   - No breaking API changes

3. **Balance updates MUST work on sealed documents**
   ```bash
   # This MUST succeed after Phase 1:
   $doc->status = 'SEALED';
   $doc->save();
   $doc->balance_due = '50.00';  // Payment allocation
   $doc->save();  // Must work!
   ```

4. **Sequential phases with validation gates**
   - Phase 0 → Compatibility report approved → Phase 1
   - Phase 1 → All tests pass → Phase 2
   - Phase 3 → User review → Phase 4

5. **Escalate when uncertain**
   - Baseline tests fail → STOP
   - Balance updates blocked → STOP
   - Unclear requirements → ASK
   - Breaking changes detected → STOP

---

## 🚀 Execution Sequence

### **Phase 0: Compatibility Analysis** (2h)
**Goal:** Prove non-breaking approach  
**Start:** PHASE-0-COMPATIBILITY-ANALYSIS.md  
**Deliverable:** COMPATIBILITY-REPORT.md  
**Gate:** Compatibility sign-off

---

### **Phase 1: Schema Hardening** (6h)
**Goal:** Add fiscal columns, constraints, triggers  
**Start:** PHASE-1-SCHEMA-HARDENING.md (lines 1-1200)  
**Deliverable:** 5 migrations + tests  
**Gate:** Baseline tests pass + balance updates work

**🚨 CRITICAL VALIDATION:**
```bash
php artisan test tests/Feature/Treasury/SmartPaymentIntegrationTest.php
php artisan test tests/Feature/Accounting/FiscalConstraintTest.php
php artisan test tests/Feature/Accounting/ImmutabilityTriggerTest.php
```
All MUST pass. If ANY fail → STOP immediately.

---

### **Phase 2: API Contracts** (3h)
**Goal:** Update resources/validation (backward compatible)  
**Start:** PHASE-2-3-4-BRIEF.md (lines 1-150)  
**Deliverable:** Updated API resources  
**Gate:** API backward compatible

---

### **Phase 3: Frontend Integration** (2h)
**Goal:** TypeScript interfaces, status badge  
**Start:** PHASE-2-3-4-BRIEF.md (lines 151-250)  
**Deliverable:** Frontend integration  
**Gate:** Frontend functional

**⏸️ USER REVIEW CHECKPOINT HERE**

---

### **Phase 4: GL Posting** (1 week)
**Goal:** Document posting service + hash chains  
**Start:** PHASE-2-3-4-BRIEF.md (lines 251-500)  
**Deliverable:** Complete GL posting system  
**Gate:** All tests pass, PHPStan clean

---

### **Phase 5: Integration Testing** (2h)
**Goal:** End-to-end validation  
**Start:** PHASE-2-3-4-BRIEF.md (lines 501-600)  
**Deliverable:** Production readiness  
**Gate:** Integration tests pass

---

## 📊 Before You Start - Verify Current State

Run these commands to confirm ready state:

```bash
# 1. Smart payments tests pass
php artisan test tests/Feature/Treasury/SmartPaymentIntegrationTest.php
# Expected: 8/8 passing

# 2. Baseline schema dump
php artisan schema:dump > /tmp/schema-before-hardening.sql

# 3. PHPStan clean
vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/
# Expected: Clean or note existing issues

# 4. Git clean
git status
# Expected: Clean state or only expected changes
```

---

## 🎯 Success Criteria

You'll know you're done when:

✅ All 5 migrations applied  
✅ Smart payment tests still passing  
✅ Balance_due updates work on sealed documents  
✅ Fiscal constraint tests passing  
✅ Immutability trigger tests passing  
✅ PHPStan level 8 clean  
✅ Documents can be sealed  
✅ GL entries created on posting  
✅ Hash chains valid  
✅ Country metadata populated  
✅ Integration tests passing  

---

## 📞 When to Escalate

**Immediate Escalation (STOP work):**
- ❌ Baseline tests fail after migration
- ❌ Cannot update balance_due on sealed
- ❌ Breaking changes detected
- ❌ PHPStan errors blocking progress

**Report and Continue:**
- ⚠️ Test failures in new tests (debug first)
- ⚠️ Performance concerns (document)
- ⚠️ Unclear requirements (note assumption, proceed reasonably)

---

## ⏱️ Timeline

```
Day 1:    Phase 0 (2h) + Phase 1 (6h)
Day 2:    Phase 2 (3h) + Phase 3 (2h)
          ─── USER REVIEW ───
Day 3-5:  Phase 4 (GL Posting)
Day 6:    Phase 5 (Integration)
```

---

## ✅ Pre-Flight Checklist

Before starting Phase 0:

- [ ] Read CLAUDE-CODE-OPUS-PROMPT.md completely
- [ ] Read CURRENT-STATE-SUMMARY.md (understand what's working)
- [ ] Understand three-layer defense architecture
- [ ] Know the non-negotiable constraints
- [ ] Know when to escalate vs proceed
- [ ] Smart payments tests are passing
- [ ] Have all 7 documents available

---

## 🚀 Ready to Start?

**Confirm you understand:**
1. ✓ Three-layer defense strategy (CHECK constraints + extension tables + triggers)
2. ✓ Non-breaking constraint (additive changes only)
3. ✓ Sequential phases with validation gates
4. ✓ When to escalate (tests fail, uncertain, breaking changes)
5. ✓ Smart payments must keep working

**Then begin with:**
```
Phase 0: Create COMPATIBILITY-REPORT.md
Reference: PHASE-0-COMPATIBILITY-ANALYSIS.md
```

---

*Simplified handoff brief - December 10, 2025*  
*For full details, read the 6 referenced documents*
