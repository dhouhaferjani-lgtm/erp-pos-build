# Module Development Prompt Template

Use this template when starting a Claude Code session for module development.

---

## Template

```
## Context

I'm working on [IziPOS/Otospex], a multi-product ERP platform built with Laravel 12 + React.

**Current Task:** [Describe what you're building]

**Module:** [Module name, e.g., POS, Menu, Withholding]

**Phase:** [Phase reference, e.g., Phase 1A - Terminal Management]

---

## Architecture Summary

- Hexagonal architecture: Domain → Application → Infrastructure → Presentation
- Multi-tenancy: Schema-based PostgreSQL with RLS
- Multi-company: Tenant owns multiple Companies
- Controllers in: `Presentation/Controllers/`
- Routes in: `Presentation/routes.php`
- Company model: `App\Modules\Company\Domain\Company`
- Credit notes are `CreditNote` (not CreditMemo)

---

## Key Files to Reference

Before implementing, check these existing patterns:
- [List relevant existing modules to reference]
- [List specific files if known]

---

## Task Details

[Detailed description of what needs to be built]

### Requirements

1. [Requirement 1]
2. [Requirement 2]
3. [Requirement 3]

### Database Tables Needed

[List tables or reference spec document]

### API Endpoints Needed

[List endpoints or reference spec document]

---

## Constraints

- Must work for both IziPOS and Otospex if shared module
- Or: This is [IziPOS/Otospex]-only module
- Must maintain hash chain for [financial/compliance] operations
- Use pessimistic locking for [specify critical operations]

---

## Testing Requirements

- Unit tests for services
- Feature tests for API endpoints
- Test both product contexts if shared module

---

## Specification Document

[Paste relevant section from spec OR attach spec file]
```

---

## Example: Starting POS Terminal Development

```
## Context

I'm working on IziPOS, a multi-product ERP platform built with Laravel 12 + React.

**Current Task:** Implement POS terminal registration and management

**Module:** POS

**Phase:** Phase 1A - Terminal Management

---

## Architecture Summary

- Hexagonal architecture: Domain → Application → Infrastructure → Presentation
- Multi-tenancy: Schema-based PostgreSQL with RLS
- Multi-company: Tenant owns multiple Companies
- Controllers in: `Presentation/Controllers/`
- Routes in: `Presentation/routes.php`
- Company model: `App\Modules\Company\Domain\Company`

---

## Key Files to Reference

Before implementing, check these existing patterns:
- `App\Modules\Inventory\` for module structure example
- `App\Modules\Document\` for status workflow patterns
- Existing migrations for table structure conventions

---

## Task Details

Implement the terminal registration system where:
1. POS apps request registration by sending device fingerprint
2. Admins review and approve/reject terminals
3. Approved terminals get activated with a unique code (POS001, POS002, etc.)
4. Terminals can be suspended or revoked

### Requirements

1. Terminal has states: pending → active → suspended → revoked
2. Terminal code is sequential per company
3. Device fingerprint stored for binding verification
4. Only active terminals can process transactions

### Database Tables

Create `pos_terminals` table:
- uuid, tenant_id, company_id
- code (unique per company, e.g., 'POS001')
- name (display name)
- device_fingerprint
- status (pending/active/suspended/revoked)
- registered_at, registered_by
- last_activity_at

### API Endpoints

POST   /api/pos/terminals              # Request registration
GET    /api/pos/terminals              # List terminals
GET    /api/pos/terminals/{id}         # Get terminal
PATCH  /api/pos/terminals/{id}/approve # Admin: approve
PATCH  /api/pos/terminals/{id}/suspend # Admin: suspend
PATCH  /api/pos/terminals/{id}/revoke  # Admin: revoke

---

## Constraints

- IziPOS-only module (not needed for Otospex)
- No hash chain needed for terminal management (only for transactions)
- Use pessimistic locking when generating terminal codes

---

## Testing Requirements

- test_terminal_registration_creates_pending_terminal()
- test_terminal_approval_activates_terminal()
- test_terminal_code_sequential_per_company()
- test_duplicate_device_fingerprint_rejected()
- test_suspended_terminal_cannot_process_transactions()
```

---

## Example: Continuing Previous Work

```
## Context

I'm continuing work on the POS module for IziPOS.

**Previous Session:** Implemented terminal registration and management
**Current Task:** Implement cash register sessions (open/close workflow)

**Module:** POS

**Phase:** Phase 1A - Cash Register Sessions

---

## What's Already Done

✅ Terminal registration and approval
✅ pos_terminals table and API
✅ TerminalStatus enum
✅ TerminalService and TerminalController

---

## Current Task Details

Implement session management where:
1. Cashier opens session with opening balance declaration
2. Only one session can be open per terminal
3. Session tracks expected vs actual cash
4. Closing session requires cash count
5. Discrepancy (over/short) is calculated and logged

### Database Tables

Create `pos_sessions` table:
- terminal_id, session_number (sequential per terminal)
- opened_by, closed_by (user references)
- opening_balance, expected_balance, counted_balance, discrepancy
- status (open/closed)
- opened_at, closed_at

### API Endpoints

POST   /api/pos/sessions               # Open session
GET    /api/pos/sessions/current       # Get current session
POST   /api/pos/sessions/{id}/close    # Close with cash count
GET    /api/pos/sessions/{id}          # Get session details

---

## Build on Existing Patterns

Use the same patterns from Terminal implementation:
- SessionStatus enum like TerminalStatus
- SessionService like TerminalService
- SessionController like TerminalController
```

---

## Tips for Effective Prompts

1. **Be specific about what exists** - List what's already built to avoid recreation

2. **Reference spec documents** - Point to specific sections for detailed requirements

3. **Clarify scope boundaries** - What's in scope vs out of scope for this session

4. **Mention blockers** - If something depends on external input (e.g., TEJ schema), mention it

5. **State product context** - IziPOS-only, Otospex-only, or shared module

6. **Include test expectations** - What should be tested helps clarify requirements
