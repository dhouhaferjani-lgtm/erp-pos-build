# Quality Verification Checklist

Use this checklist to verify work before considering a phase complete.

---

## Pre-Implementation Checklist

Before starting implementation:

- [ ] Specification document reviewed
- [ ] Similar existing code patterns identified
- [ ] Database schema designed
- [ ] API endpoints defined
- [ ] Test cases outlined
- [ ] Dependencies identified (what must exist first)
- [ ] Product context clear (IziPOS/Otospex/shared)

---

## Code Quality Checklist

After implementation:

### Architecture
- [ ] Follows hexagonal architecture (Domain → Application → Infrastructure → Presentation)
- [ ] Domain models don't depend on framework
- [ ] Repository interfaces in Domain, implementations in Infrastructure
- [ ] DTOs used for layer boundaries
- [ ] Controllers only do request/response handling

### Database
- [ ] Migrations created and run successfully
- [ ] Migrations are reversible (down method works)
- [ ] Foreign keys defined appropriately
- [ ] Indexes added for common queries
- [ ] tenant_id included where needed
- [ ] company_id included where needed

### API
- [ ] Routes registered in module's `Presentation/routes.php`
- [ ] Proper HTTP methods used (GET/POST/PATCH/DELETE)
- [ ] Consistent response format
- [ ] Validation rules defined
- [ ] Authorization checks in place

### Frontend
- [ ] TypeScript types defined
- [ ] React Query hooks created
- [ ] Error handling implemented
- [ ] Loading states shown
- [ ] Form validation present

### Testing
- [ ] Unit tests for domain logic
- [ ] Feature tests for API endpoints
- [ ] Tests pass locally
- [ ] Edge cases covered
- [ ] Both product contexts tested (if shared module)

### Compliance (if applicable)
- [ ] Hash chain maintained for financial transactions
- [ ] Audit trail present
- [ ] Sequential numbering works correctly
- [ ] Pessimistic locking used for critical operations

---

## Code Review Checklist

Items for reviewers:

### Naming
- [ ] Class names follow convention
- [ ] Method names are descriptive
- [ ] Variables are meaningful
- [ ] No abbreviations (except industry-standard)

### Security
- [ ] No SQL injection vulnerabilities
- [ ] Input validation present
- [ ] Authorization enforced
- [ ] Sensitive data not logged

### Performance
- [ ] N+1 queries avoided (eager loading used)
- [ ] Appropriate database indexes
- [ ] No unnecessary loops
- [ ] Pagination for list endpoints

### Maintainability
- [ ] Code is readable without comments
- [ ] Complex logic has explanatory comments
- [ ] No duplicate code
- [ ] Single responsibility principle followed

---

## Multi-AI Verification Process

For critical features, use multiple AI assistants:

### Step 1: Implementation (Claude Code)
- Implement the feature
- Write tests
- Verify locally

### Step 2: Audit (Codex or Gemini)
Prompt for audit:
```
Review this implementation for:
1. Security vulnerabilities
2. Edge cases not handled
3. Performance issues
4. Architecture violations
5. Missing test coverage

[Paste code or describe what was built]
```

### Step 3: Cross-Check (Different AI)
Prompt for cross-check:
```
Given these requirements:
[Paste requirements]

And this implementation summary:
[Describe what was built]

Does the implementation fully meet the requirements?
What gaps exist?
```

---

## Phase Completion Checklist

Before marking a phase complete:

### Functionality
- [ ] All requirements implemented
- [ ] Happy path works end-to-end
- [ ] Error cases handled gracefully
- [ ] Edge cases addressed

### Documentation
- [ ] API endpoints documented
- [ ] New models documented
- [ ] Configuration changes noted
- [ ] README updated if needed

### Testing
- [ ] All tests pass
- [ ] Manual testing completed
- [ ] Integration points verified

### Deployment
- [ ] Migrations ready
- [ ] Seeders updated if needed
- [ ] Environment variables documented
- [ ] No breaking changes (or migration path defined)

---

## Common Issues to Watch For

### "It looks right but doesn't work" (AI Slop)
- Verify code actually runs
- Check that database queries return expected data
- Test API endpoints with real requests
- Don't trust "should work" - verify it works

### Missing Edge Cases
- What if the list is empty?
- What if the user doesn't have permission?
- What if the referenced entity was deleted?
- What if there's a race condition?

### Product Context Confusion
- Did you test in IziPOS context?
- Did you test in Otospex context?
- Are modules loaded correctly based on APP_PRODUCT?

### Hash Chain Breaks
- Is previous_hash correctly retrieved?
- Is hash calculated before insert?
- Does sequence number increment atomically?

---

## Sign-Off Template

```
## Phase Completion Sign-Off

**Phase:** [e.g., Phase 1A - Terminal Management]
**Module:** [e.g., POS]
**Date:** [Date]

### Completed Items
- [x] Item 1
- [x] Item 2
- [x] Item 3

### Known Limitations
- [Any known limitations or future improvements]

### Test Results
- Unit tests: [X] passing, [Y] failing
- Feature tests: [X] passing, [Y] failing
- Manual testing: Completed

### Ready for Next Phase
- [ ] Yes
- [ ] No - blocked by: [reason]

### Notes
[Any additional notes for future development]
```
