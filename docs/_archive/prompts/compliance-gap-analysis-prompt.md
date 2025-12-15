# COMPLIANCE GAP ANALYSIS - DOCUMENT POSTING FLOWS

## Context

Jim discovered that `DocumentController@post` is missing critical NF525 compliance logic. While our architecture documents (CLAUDE.md) specify an "Event-first, state-second" pattern with event dispatching and hash chain creation, the actual implementation just updates the status to "Posted" without:
- Dispatching `InvoicePosted` event
- Calling `FiscalHashService`
- Creating hash chain entries
- Proper transaction boundaries

Before deciding whether to fix this now or after the subledger work, we need to understand the scope of the problem.

## Your Task: Conduct a Comprehensive Audit

### Step 1: Find all document posting entry points

```bash
# Search for direct status updates to "Posted"
grep -rn "status.*=.*['\"]Posted['\"]" backend/app --include="*.php"
grep -rn "->post()" backend/app --include="*.php"
grep -rn "setStatus.*Posted" backend/app --include="*.php"

# Find all controllers that handle documents
find backend/app/Http/Controllers -name "*Document*Controller.php" -o -name "*Invoice*Controller.php" -o -name "*Receipt*Controller.php"
```

### Step 2: Audit event dispatching patterns

```bash
# Find where events SHOULD be dispatched
grep -rn "InvoicePosted" backend/app --include="*.php"
grep -rn "DocumentPosted" backend/app --include="*.php"
grep -rn "event(new" backend/app --include="*.php"
grep -rn "Event::dispatch" backend/app --include="*.php"

# Check if events exist but aren't used
find backend/app/Events -name "*Posted*.php" -o -name "*Document*.php"
```

### Step 3: Check hash service integration

```bash
# Find FiscalHashService usage
grep -rn "FiscalHashService" backend/app --include="*.php"
grep -rn "createHash" backend/app --include="*.php"
grep -rn "fiscal_hash" backend/app --include="*.php"

# Check if the service exists but isn't called
ls -la backend/app/Services/*Fiscal* backend/app/Services/*Hash*
```

### Step 4: Identify all posting scenarios

Search for code that might post documents in these modules:

```bash
# Sales/Invoicing
grep -rn "post" backend/app/Modules/Sales --include="*.php" | grep -i "invoice\|document\|receipt"

# Purchases
grep -rn "post" backend/app/Modules/Purchases --include="*.php" | grep -i "invoice\|document"

# Inventory (might auto-post receiving documents)
grep -rn "post" backend/app/Modules/Inventory --include="*.php" | grep -i "document\|receipt"

# Payments (our next planned feature)
grep -rn "post" backend/app/Modules/Payments --include="*.php" | grep -i "payment\|document"

# Any automated processes
grep -rn "post" backend/app/Jobs --include="*.php"
grep -rn "post" backend/app/Console/Commands --include="*.php"
```

### Step 5: Analyze the DocumentController itself

```bash
# Show the full posting method
grep -A 50 "function post" backend/app/Http/Controllers/DocumentController.php
```

### Step 6: Check for existing event handlers

```bash
# See what handlers exist
find backend/app/Listeners -type f -name "*.php"

# Check what they do
grep -rn "FiscalHashService" backend/app/Listeners --include="*.php"
```

## Output Required

Please provide a structured report with:

### A. SCOPE ASSESSMENT
- How many distinct places/methods post documents?
- Is posting centralized (one method) or distributed (multiple implementations)?
- List each posting entry point with file path and line number

### B. COMPLIANCE GAP INVENTORY
For each posting entry point, indicate:
- ✅ Has event dispatching
- ✅ Has hash chain creation  
- ✅ Has transaction wrapper
- ❌ Missing event dispatching
- ❌ Missing hash chain
- ❌ Missing transaction

### C. EVENT/SERVICE STATUS
- Do the required events (`InvoicePosted`, etc.) exist?
- Does `FiscalHashService` exist and is it tested?
- Are there event handlers that exist but aren't triggered?

### D. IMPACT ANALYSIS
- **If we fix DocumentController@post now**: What percentage of posting flows would be fixed?
- **If we wait**: How many new features (subledger, payments) might implement their own broken posting logic?

### E. RECOMMENDATION
Based on the findings:
- **Fix now** if: Posting is centralized and fixing one method fixes 80%+ of flows
- **Fix after subledger** if: Posting is distributed and requires comprehensive refactor across many files
- Provide rationale for your recommendation

### F. SUGGESTED FIX APPROACH (if recommending immediate fix)
- Show the corrected `post()` method with proper event dispatching and hashing
- Estimate complexity (simple, moderate, complex)
- Identify any migration/backfill needs for already-posted documents

---

**Instructions**: Give this prompt to Claude Code and analyze its findings to determine whether to fix the compliance gap immediately or after completing the subledger work.
