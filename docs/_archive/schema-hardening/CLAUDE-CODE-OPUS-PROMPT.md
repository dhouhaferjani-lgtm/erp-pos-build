# CLAUDE CODE OPUS - Schema Hardening + GL Posting Implementation

**Project:** AutoERP Fiscal Compliance Foundation  
**Complexity:** High  
**Duration:** ~9 days  
**Model Requirement:** Opus (for architectural validation and implementation)

---

## Mission

Implement database-level fiscal compliance hardening for AutoERP's polymorphic documents table, followed by GL posting service implementation. This work establishes the foundation for NF525 (France), ZATCA (Saudi Arabia), and KassenSichV (Germany) certification.

---

## Context & Background

### What We Have
- ✅ **Research Complete:** Deep dive into NF525, ZATCA, KassenSichV requirements
- ✅ **Validation Complete:** Confirmed Odoo v13 uses polymorphic table + achieved NF525 certification
- ✅ **Design Complete:** Three-layer defense strategy (CHECK constraints + extension tables + triggers)
- ✅ **Smart Payments:** Frontend currently being implemented (must remain functional)

### What You Will Build
1. **Schema Hardening (Phase 1):** Add fiscal_category, status, CHECK constraints, extension tables, immutability triggers
2. **API Updates (Phase 2):** Update resources, validation, error handling
3. **Frontend Integration (Phase 3):** Coordinate TypeScript interfaces, UI components
4. **GL Posting (Phase 4):** Implement DocumentPostingService, FiscalMetadataService, hash chains
5. **Integration Testing (Phase 5):** End-to-end scenarios validating complete flow

---

## Critical Constraints

### NON-NEGOTIABLE Requirements

1. **Non-Breaking Changes Only**
   - All schema changes must be ADDITIVE
   - Existing API contracts must remain functional
   - Smart payments frontend must continue working
   - Baseline tests must pass before and after

2. **Payment System Compatibility**
   - Triggers MUST allow `balance_due` updates on sealed documents
   - Payment allocation service does NOT seal documents (GL posting does)
   - FIFO allocation logic must remain unaffected

3. **Fiscal Compliance Alignment**
   - CHECK constraints enforce mandatory fields for fiscal documents
   - Triggers prevent modification of sealed documents (inalterability)
   - Extension tables keep country-specific data separate (no NULL pollution)
   - Hash chains ensure data integrity

4. **Code Quality Standards**
   - PHPStan level 8 must pass
   - All tests must pass
   - Proper exception handling with user-friendly messages
   - Comprehensive test coverage (unit + integration)

---

## Implementation Approach

### Strategy: Sequential Phases with Validation Gates

```
Phase 0: Compatibility Analysis
   ↓ [Approval Gate]
Phase 1: Schema Hardening
   ↓ [Test Gate: Baseline tests must still pass]
Phase 2: API Contract Updates
   ↓ [Test Gate: API responses backward compatible]
Phase 3: Frontend Integration
   ↓ [Test Gate: Smart payments functional]
Phase 4: GL Posting Implementation
   ↓ [Test Gate: All tests pass, PHPStan clean]
Phase 5: Integration Testing
   ↓ [Final Approval]
Production Ready
```

### Validation Gates

After each phase, you MUST:
1. Run the verification commands provided in the phase document
2. Confirm all tests pass
3. Document any deviations or issues
4. Get approval before proceeding to next phase

---

## Documentation Structure

You have been provided with the follwing files inside /docs/schema-hardening:

1. **schema-hardening-master-plan.md** - Overall strategy, timeline, risk mitigation
2. **PHASE-0-COMPATIBILITY-ANALYSIS.md** - Detailed compatibility assessment process
3. **PHASE-1-SCHEMA-HARDENING.md** - Complete SQL migrations, tests, verification
4. **PHASE-2-3-4-BRIEF.md** - Implementation briefs for remaining phases

### How to Use These Documents

1. **Read ALL documents before starting** - Understand the full scope
2. **Follow phases sequentially** - Do not skip ahead
3. **Execute verification commands** - After each step
4. **Commit at designated points** - Use provided commit messages
5. **Stop at validation gates** - Wait for approval if issues arise

---

## Your Task - Phase by Phase

### Phase 0: Compatibility Analysis (START HERE)

**Duration:** 2 hours  
**Objective:** Confirm schema changes won't break smart payments frontend

**Action Items:**
1. Read `PHASE-0-COMPATIBILITY-ANALYSIS.md` completely
2. Wait for confirmation that smart payments frontend is complete
3. Execute each task in Step 0.1, 0.2, 0.3, 0.4
4. Create compatibility report
5. Run baseline tests and document results
6. Request approval to proceed to Phase 1

**Deliverable:** Compatibility report with sign-off checklist

---

### Phase 1: Schema Hardening

**Duration:** 6 hours  
**Objective:** Add fiscal columns, constraints, extension tables, triggers

**Action Items:**
1. Read `PHASE-1-SCHEMA-HARDENING.md` completely
2. Execute Step 1.1: Add core columns (fiscal_category, status)
   - Run migration
   - Verify columns added
   - Verify backfill correct
   - Commit at designated point
3. Execute Step 1.2: Add CHECK constraints
   - Run migration
   - Run constraint tests
   - Verify constraints enforced
   - Commit
4. Execute Step 1.3: Create extension tables
   - Run migration
   - Verify tables created
   - Verify foreign keys
   - Commit
5. Execute Step 1.4: Add immutability triggers
   - Run migration
   - Run trigger tests
   - Verify triggers enforce rules
   - Commit
6. Execute Step 1.5: Update models and enums
   - Create enums
   - Update Document model
   - Create metadata models
   - Run PHPStan
   - Commit
7. Run Phase 1 Final Verification
8. Re-run baseline tests to confirm non-breaking
9. Request approval to proceed to Phase 2

**Critical:** At Step 1.4, you MUST verify that:
- Sealed documents cannot be modified (immutable fields)
- balance_due CAN be updated on sealed documents (for payments)
- Status can transition from SEALED to VOIDED

**Deliverable:** Hardened schema with all tests passing

---

### Phase 2: API Contract Updates

**Duration:** 3 hours  
**Objective:** Update API resources and validation while maintaining backward compatibility

**Action Items:**
1. Read Phase 2 section in `PHASE-2-3-4-BRIEF.md`
2. Update `DocumentResource` to include new fields
3. Update validation rules with conditional logic
4. Update exception handler for new error codes
5. Test API responses include new fields
6. Verify backward compatibility
7. Request approval to proceed to Phase 3

**Deliverable:** API contracts updated, tests passing

---

### Phase 3: Frontend Integration

**Duration:** 2 hours  
**Objective:** Validate smart payments frontend compatibility

**Action Items:**
1. Read Phase 3 section in `PHASE-2-3-4-BRIEF.md`
2. Coordinate with frontend team on TypeScript updates
3. Provide error code documentation
4. Validate integration works
5. Request approval to proceed to Phase 4

**Deliverable:** Frontend integration validated

---

### Phase 4: GL Posting Implementation

**Duration:** 1 week  
**Objective:** Implement DocumentPostingService with fiscal metadata

**Action Items:**
1. Read Phase 4 section in `PHASE-2-3-4-BRIEF.md`
2. Implement DocumentPostingService
3. Implement FiscalMetadataService
4. Implement HashChainService (if needed)
5. Create exceptions
6. Create events
7. Write comprehensive tests (unit + integration)
8. Verify PHPStan level 8 passes
9. Request approval to proceed to Phase 5

**Key Implementation Points:**
- Post method must be atomic (DB::transaction)
- Must set fiscal_category before saving
- Must seal document (status = SEALED) before GL entries
- Must dispatch FiscalDocumentPosted event
- Must create country-specific metadata based on company country

**Deliverable:** GL posting service complete, tests passing

---

### Phase 5: Integration Testing

**Duration:** 2 hours  
**Objective:** Validate complete flow end-to-end

**Action Items:**
1. Read Phase 5 section in `PHASE-2-3-4-BRIEF.md`
2. Execute Scenario 1: Create Invoice → Post → Pay
3. Execute Scenario 2: Try Modifying Sealed Document
4. Execute Scenario 3: French Company Full Flow
5. Execute Scenario 4: Smart Payment with FIFO
6. Document results
7. Request final approval

**Deliverable:** Integration test report, production readiness confirmation

---

## Red Flags & Escalation

### When to STOP and Ask for Help

1. **Baseline tests fail after Phase 1** → Schema changes broke something
2. **Triggers prevent legitimate operations** → Trigger logic too restrictive
3. **Smart payments frontend breaks** → API contract issue
4. **Performance degradation detected** → Trigger/constraint overhead
5. **Hash chain implementation unclear** → Need architectural guidance

### How to Escalate

Create a detailed report including:
- Which phase you're in
- What command/test failed
- Error messages
- Your analysis of root cause
- Proposed solutions

Then **STOP** and wait for guidance. Do not proceed with uncertain changes.

---

## Success Criteria

### Phase 1 Success
- [ ] All migrations applied successfully
- [ ] Baseline tests still pass (non-breaking)
- [ ] Constraint tests pass
- [ ] Trigger tests pass
- [ ] PHPStan level 8 clean
- [ ] Can update balance_due on sealed documents
- [ ] Cannot modify other fields on sealed documents

### Phase 4 Success
- [ ] Documents sealed correctly
- [ ] GL entries created atomically
- [ ] Hash chains computed and stored
- [ ] Country metadata populated
- [ ] Events dispatched
- [ ] All tests pass
- [ ] PHPStan level 8 clean

### Overall Success
- [ ] All phases complete
- [ ] Zero breaking changes
- [ ] Smart payments frontend functional
- [ ] Production deployment ready
- [ ] Documentation updated

---

## Common Pitfalls to Avoid

### ❌ Don't Do This

1. **Don't modify column types** - Only add columns
2. **Don't remove existing behavior** - Only add behavior
3. **Don't skip verification commands** - Run after every step
4. **Don't implement phases in parallel** - Sequential only
5. **Don't make triggers too restrictive** - Allow balance_due updates
6. **Don't ignore failing tests** - Fix before proceeding
7. **Don't guess at hash chain implementation** - Follow spec exactly

### ✅ Do This

1. **Read all documentation first** - Understand full scope
2. **Run verification after each step** - Catch issues early
3. **Commit at designated points** - Use provided commit messages
4. **Stop at validation gates** - Wait for approval
5. **Document deviations** - Report any changes to plan
6. **Test exhaustively** - Unit + integration + edge cases
7. **Ask questions** - Better to ask than break production

---

## Key Technical Decisions

### Why Polymorphic Table?
- ✅ Proven by Odoo (NF525 certified)
- ✅ Flexible for multiple document types
- ✅ Simplifies querying and relationships
- ❌ Requires strategic hardening (hence this project)

### Why Three-Layer Defense?
1. **CHECK Constraints:** Enforce absolute minimums at DB level
2. **Extension Tables:** Keep country-specific data separate and clean
3. **Triggers:** Prevent tampering with sealed documents

### Why Sequential Phases?
- Reduces risk
- Easier to debug
- Clear validation gates
- Allows rollback per phase

### Why Extension Tables vs. JSON Columns?
- Proper foreign keys
- Better query performance
- Schema validation
- Easier to index

---

## Resources & References

### Research Documents
- Research summary: See transcript `/mnt/transcripts/2025-12-10-10-53-48-fiscal-schema-compliance-research.txt`
- Odoo certified pattern analysis (included in research)
- Regulatory requirements (NF525, ZATCA, KassenSichV)

### Existing Codebase
- Document model: `app/Models/Document.php`
- GL service: `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- Payment service: `app/Modules/Treasury/Application/Services/PaymentAllocationService.php`

### Testing
- Baseline tests: Create in Phase 0
- Constraint tests: Provided in Phase 1
- Trigger tests: Provided in Phase 1
- Integration scenarios: Provided in Phase 5

---

## Your First Steps

1. **Read this entire document** - Understand mission and constraints
2. **Read schema-hardening-master-plan.md** - Understand overall strategy
3. **Read PHASE-0-COMPATIBILITY-ANALYSIS.md** - Your starting point
4. **Confirm smart payments frontend status** - Must be complete before Phase 1
5. **Execute Phase 0** - Create compatibility report
6. **Request approval** - Before proceeding to Phase 1

---

## Questions to Consider Before Starting

1. Do I understand why this work is necessary?
2. Do I understand the non-breaking constraint?
3. Do I understand the three-layer defense strategy?
4. Do I know what to do if baseline tests fail?
5. Do I know when to escalate vs. continue?
6. Have I read ALL the documentation?

If you answer "no" to any question, re-read the relevant sections.

---

## Communication Protocol

### After Each Phase
Provide a brief report:
```
Phase X: [PHASE NAME]
Status: ✅ Complete / ⚠️ Issues / ❌ Blocked
Duration: [actual time]
Tests: [pass/fail count]
Issues: [describe any deviations]
Next: [what you'll do next]
```

### If Blocked
Provide detailed escalation report (see "Red Flags & Escalation" section above)

---

## Final Reminders

1. **This is a foundation project** - Quality over speed
2. **Breaking changes are NOT acceptable** - Test thoroughly
3. **Document everything** - Future developers will thank you
4. **When in doubt, ask** - Better safe than sorry
5. **Trust the process** - Phases designed to catch issues early

---

## Ready to Start?

Your mission: Transform AutoERP's documents table from a soft-validated schema into a bulletproof, certification-ready fiscal compliance foundation.

Your approach: Sequential phases with validation gates.

Your constraint: Zero breaking changes.

Your first action: Read `PHASE-0-COMPATIBILITY-ANALYSIS.md` and confirm smart payments frontend is complete.

**Good luck! 🚀**

---

**Document Version:** 1.0  
**Last Updated:** 2024-12-10  
**Status:** Ready for Opus Review and Implementation
