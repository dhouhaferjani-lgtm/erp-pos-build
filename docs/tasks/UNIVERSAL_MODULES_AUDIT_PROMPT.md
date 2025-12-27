# Universal Modules Audit Prompt for Claude Code

## Context

We are preparing the Otospex/IziPOS codebase for multi-product architecture. Before building vertical-specific modules (Vehicle, Workshop, Menu, Recipe, POS), we need to ensure the **universal modules** are solid, well-tested, and have stable interfaces.

**Universal modules** (must work for ALL verticals):
- Document (quotes, orders, invoices, credit notes, delivery notes)
- Treasury (payments, payment methods, repositories, allocations)
- Accounting (GL, journal entries, fiscal periods)
- Inventory (stock levels, movements, counting)
- Partner (customers, suppliers)

**The goal**: Verify these modules are ready to be "frozen" as stable foundations that vertical modules can safely depend on.

---

## Your Mission

Perform a comprehensive audit of the universal modules and produce a report with:

1. **Critical Path Test Coverage**: Do we have tests for the essential business flows?
2. **Interface Stability Analysis**: Are the public service methods well-defined and consistent?
3. **Integration Points**: How do modules connect? Are events being used properly?
4. **Gaps & Issues**: What's missing, broken, or inconsistent?
5. **Decision Points**: What architectural decisions need to be made before proceeding?
6. **Task List**: Prioritized list of work items to achieve "stable foundation" status

---

## Step 1: Discover the Codebase Structure

First, understand what exists:

```bash
# Find all universal modules
find app/Modules -type d -maxdepth 1 | grep -E "(Document|Treasury|Accounting|Inventory|Partner)" | sort

# List the structure of each module
for module in Document Treasury Accounting Inventory Partner; do
  echo "=== $module ==="
  find app/Modules/$module -type f -name "*.php" | head -20
done

# Find all tests related to universal modules
find tests -type f -name "*.php" | xargs grep -l -E "(Document|Treasury|Accounting|Inventory|Partner)" | sort

# Count tests per module
for module in Document Treasury Accounting Inventory Partner; do
  echo "$module: $(find tests -name "*${module}*" -o -name "*$(echo $module | tr '[:upper:]' '[:lower:]')*" 2>/dev/null | wc -l) test files"
done
```

---

## Step 2: Critical Path Analysis

For each universal module, verify these critical paths have test coverage:

### Document Module

| Critical Path | What to Check | Test File Pattern |
|---------------|---------------|-------------------|
| Create draft document | `DocumentService::create()` or equivalent | `*Document*Test*`, `*CreateDocument*` |
| Add/update line items | Line item CRUD, calculations | `*DocumentLine*`, `*LineItem*` |
| Post document (finalize) | Status transition, hash chain creation, event dispatch | `*Post*`, `*Finalize*` |
| Cancel document | Reversal logic, GL impact | `*Cancel*`, `*Void*` |
| Convert document | Quote→Order→Invoice flow | `*Convert*`, `*Transform*` |
| Document numbering | Sequence generation, fiscal compliance | `*Number*`, `*Sequence*` |
| Tax calculations | Tax lines, rounding | `*Tax*`, `*Calculation*` |

**Questions to answer:**
- Is `DocumentPostedEvent` dispatched when a document is posted?
- Is the hash chain (`fiscal_hash`, `previous_hash`) being created?
- Are document line totals calculated correctly (qty × price - discount + tax)?

### Treasury Module

| Critical Path | What to Check | Test File Pattern |
|---------------|---------------|-------------------|
| Record payment | `PaymentService::record()` or equivalent | `*Payment*Test*`, `*Record*` |
| Allocate to invoices | Payment allocation logic | `*Allocation*`, `*Apply*` |
| Multi-invoice payment | Split payment across documents | `*Split*`, `*Multi*` |
| Overpayment handling | Credit balance management | `*Overpayment*`, `*Credit*` |
| Payment methods | Cash, card, check, transfer | `*PaymentMethod*` |
| Cash register operations | Open/close, reconciliation | `*Register*`, `*CashDrawer*` |
| Refunds | Payment reversal | `*Refund*`, `*Reverse*` |

**Questions to answer:**
- Does recording a payment automatically create GL entries?
- Is the customer/supplier balance updated correctly?
- Are payment repositories (cash registers, bank accounts) tracking balances?

### Accounting Module

| Critical Path | What to Check | Test File Pattern |
|---------------|---------------|-------------------|
| Create journal entry | `JournalEntryService::create()` | `*JournalEntry*Test*` |
| Post journal entry | Finalization, hash chain | `*Post*`, `*Finalize*` |
| Balance validation | Debits = Credits check | `*Balance*`, `*Validation*` |
| Account balances | Running balance calculation | `*AccountBalance*`, `*Ledger*` |
| Period closing | Fiscal period management | `*Period*`, `*Close*` |
| Trial balance | Report generation | `*TrialBalance*`, `*Report*` |
| Subledger integration | AR/AP from documents/payments | `*Subledger*`, `*Receivable*`, `*Payable*` |

**Questions to answer:**
- Are journal entries created automatically when documents are posted?
- Are journal entries created automatically when payments are recorded?
- Is the hash chain intact for fiscal compliance?

### Inventory Module

| Critical Path | What to Check | Test File Pattern |
|---------------|---------------|-------------------|
| Stock adjustment | `StockMovementService::adjust()` | `*Stock*Test*`, `*Adjustment*` |
| Stock transfer | Between locations | `*Transfer*` |
| Reserve stock | For orders/quotes | `*Reserve*`, `*Allocation*` |
| Consume stock | On invoice posting | `*Consume*`, `*Deduct*` |
| Stock levels query | Current quantity by location | `*StockLevel*`, `*Quantity*` |
| Inventory counting | Blind count flow | `*Counting*`, `*Count*` |
| Negative stock handling | Allow/prevent configuration | `*Negative*` |

**Questions to answer:**
- Is stock automatically deducted when an invoice is posted?
- Is stock automatically returned when a credit note is posted?
- Does stock movement create GL entries (inventory valuation)?

### Partner Module

| Critical Path | What to Check | Test File Pattern |
|---------------|---------------|-------------------|
| Create customer/supplier | Basic CRUD | `*Partner*Test*`, `*Customer*`, `*Supplier*` |
| Balance calculation | Outstanding amount | `*Balance*`, `*Outstanding*` |
| Credit limit | Validation on document creation | `*CreditLimit*`, `*Limit*` |
| Statement generation | Account statement | `*Statement*` |
| Contact management | Multiple contacts per partner | `*Contact*` |

**Questions to answer:**
- Is the partner balance (AR/AP) calculated from posted documents and payments?
- Is credit limit enforced when creating new documents?

---

## Step 3: Integration Flow Tests

These are the **end-to-end flows** that cross multiple modules. Check if tests exist:

### Flow 1: Invoice → Payment → GL (The Money Flow)
```
1. Create invoice (Document)
2. Post invoice (Document) 
   → Creates GL entry (Accounting): Debit AR, Credit Revenue
   → Deducts stock (Inventory)
   → Creates GL entry (Accounting): Debit COGS, Credit Inventory
3. Record payment (Treasury)
   → Allocates to invoice (Treasury)
   → Creates GL entry (Accounting): Debit Cash, Credit AR
4. Verify: Invoice marked paid, customer balance = 0
```

### Flow 2: Credit Note → Stock Return → GL
```
1. Create credit note linked to invoice (Document)
2. Post credit note (Document)
   → Creates reversing GL entry (Accounting)
   → Returns stock (Inventory)
3. Apply credit to customer balance or refund
4. Verify: Stock restored, customer balance adjusted
```

### Flow 3: Quote → Order → Invoice (Document Lifecycle)
```
1. Create quote (Document)
2. Convert to order (Document)
   → Reserves stock (Inventory)
3. Convert to invoice (Document)
   → Confirms stock consumption (Inventory)
4. Verify: Original quote archived, stock properly tracked
```

### Flow 4: Multi-Location Stock Transfer
```
1. Initiate transfer from Location A (Inventory)
2. Stock deducted from A, in-transit created
3. Receive at Location B (Inventory)
4. In-transit cleared, stock added to B
5. Verify: GL entries if using perpetual inventory
```

---

## Step 4: Code Analysis Tasks

Perform these code inspections:

### 4.1 Event Discovery
```bash
# Find all events defined in universal modules
find app/Modules -path "*/Domain/Events/*.php" | xargs grep -l "class.*Event"

# Find all event listeners
grep -r "function handle.*Event" app/Modules --include="*.php"

# Check if events are dispatched in services
grep -r "event(" app/Modules/Document --include="*.php"
grep -r "dispatch(" app/Modules/Document --include="*.php"
```

**Document which events exist and which are missing.**

### 4.2 Service Interface Analysis
```bash
# Find all public service methods in Document module
grep -r "public function" app/Modules/Document/Application/Services --include="*.php"
grep -r "public function" app/Modules/Document/Domain/Services --include="*.php"

# Repeat for other modules
```

**Document the public API of each universal module.**

### 4.3 Cross-Module Dependencies
```bash
# Check if Document module imports from vertical modules (BAD)
grep -r "use App\\Modules\\Vehicle" app/Modules/Document --include="*.php"
grep -r "use App\\Modules\\Workshop" app/Modules/Document --include="*.php"
grep -r "use App\\Modules\\Menu" app/Modules/Document --include="*.php"

# Check proper dependency direction (universal depends on universal only)
for module in Document Treasury Accounting Inventory Partner; do
  echo "=== $module imports ==="
  grep -rh "^use App\\\\Modules\\\\" app/Modules/$module --include="*.php" | sort -u
done
```

**Flag any improper dependencies.**

### 4.4 Database Schema Check
```bash
# Find migrations for each universal module
ls -la database/migrations | grep -i document
ls -la database/migrations | grep -i payment
ls -la database/migrations | grep -i journal
ls -la database/migrations | grep -i stock
ls -la database/migrations | grep -i partner

# Check for nullable foreign keys that might indicate optional vertical relations
grep -r "nullable()" database/migrations | grep -E "(vehicle|workshop|menu)"
```

### 4.5 Hash Chain Compliance
```bash
# Check if fiscal hash fields exist
grep -r "fiscal_hash" database/migrations --include="*.php"
grep -r "previous_hash" database/migrations --include="*.php"
grep -r "chain_sequence" database/migrations --include="*.php"

# Check if hash service exists and is used
find app -name "*Hash*" -o -name "*Fiscal*"
grep -r "FiscalHashService" app/Modules --include="*.php"
```

---

## Step 5: Output Format

Produce a report with these sections:

### SECTION A: Test Coverage Matrix

```markdown
| Module | Critical Path | Has Test? | Test File | Notes |
|--------|--------------|-----------|-----------|-------|
| Document | Create draft | ✅/❌ | path/to/test.php | |
| Document | Post document | ✅/❌ | | Missing hash chain test |
| Treasury | Record payment | ✅/❌ | | |
...
```

### SECTION B: Integration Flow Status

```markdown
| Flow | Status | Missing Pieces |
|------|--------|----------------|
| Invoice → Payment → GL | PARTIAL | No test for GL entry creation |
| Credit Note → Stock Return | NOT TESTED | Entire flow untested |
...
```

### SECTION C: Interface Inventory

For each module, list:
```markdown
## Document Module

### Public Services
- `DocumentService::create(DocumentDTO $dto): Document`
- `DocumentService::post(Document $document): void`
- `DocumentService::cancel(Document $document, string $reason): void`

### Events Emitted
- `DocumentCreatedEvent` - dispatched on create ✅
- `DocumentPostedEvent` - NOT FOUND ❌
- `DocumentCancelledEvent` - NOT FOUND ❌

### Events Listened To
- None (correct for universal module)
```

### SECTION D: Issues Found

```markdown
## Critical Issues (blocks vertical development)
1. **[ISSUE-001]** DocumentPostedEvent not dispatched - verticals can't react to posted invoices
2. **[ISSUE-002]** Payment doesn't create GL entry - accounting broken

## Medium Issues (should fix soon)
3. **[ISSUE-003]** No test for credit note flow
4. **[ISSUE-004]** Stock not deducted on invoice post

## Low Issues (can defer)
5. **[ISSUE-005]** Partner balance calculation not cached
```

### SECTION E: Decisions Required

```markdown
| Decision | Options | Recommendation | Impact |
|----------|---------|----------------|--------|
| Where do vertical-specific document data go? | A) JSONB payload B) Extension tables | A for MVP, migrate to B later | Affects all verticals |
| Should universal modules know about `vehicle_id`? | A) Yes, nullable FK B) No, use payload | B - keeps modules clean | Schema design |
...
```

### SECTION F: Task List

```markdown
## Priority 1: Critical (Must do before verticals)
- [ ] TASK-001: Implement DocumentPostedEvent dispatch
- [ ] TASK-002: Implement Payment → GL entry creation
- [ ] TASK-003: Implement stock deduction on invoice post

## Priority 2: Important (Do within 2 weeks)
- [ ] TASK-004: Write integration test for Invoice → Payment → GL flow
- [ ] TASK-005: Write integration test for Credit Note flow

## Priority 3: Nice to Have (Can do in parallel with verticals)
- [ ] TASK-006: Optimize partner balance query
```

---

## Execution Instructions

1. Start by exploring the codebase structure
2. For each module, check the critical paths systematically
3. Run the bash commands to discover events, services, dependencies
4. Document findings in the output format above
5. Be specific about file paths and line numbers when reporting issues
6. Prioritize ruthlessly - we need to know what BLOCKS vertical development

**Time estimate**: This audit should take 2-4 hours of focused work.

**Output**: Save the report as `UNIVERSAL_MODULES_AUDIT_REPORT.md` in the project root.

---

## Success Criteria

After this audit, we should be able to answer:

1. ✅ Can we freeze the universal module interfaces?
2. ✅ What tests need to be written before we start verticals?
3. ✅ Are there any architectural issues that would force us to modify universal modules later?
4. ✅ Is the event system in place for vertical modules to hook into?
5. ✅ Is fiscal compliance (hash chains) working?

**If the answer to any of these is "no", the task list should include specific remediation steps.**
