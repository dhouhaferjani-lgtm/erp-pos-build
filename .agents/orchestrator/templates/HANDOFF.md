# Task Handoff: {TASK-ID}

> This document provides complete context for a worker session to implement this task.
> Read this entire document before starting. Do not skip sections.

---

## Task

**ID:** {TASK-ID}
**Title:** {one-line title}
**Type:** {feature | bugfix | refactor | test | docs}
**Priority:** {urgent | normal | low}
**Estimated hours:** {N}

## Description

{Detailed description of what needs to be done. Be specific. Include the "why" -- what business problem this solves or what technical debt this addresses.}

## Affected Modules

| Module | Path | What changes |
|--------|------|-------------|
| {Module} | `app/Modules/{Module}/` | {what you are changing in this module} |

## Branch

- **Work on:** `feature/{TASK-ID}`
- **Branch from:** `local-dev`
- **Merge target:** `local-dev` (via PR)

## Context Files to Read

Read these files before starting implementation:

1. `~/Projects/syneriva/apps/erp/CLAUDE.md` -- master architecture rules (focus on the 21 operational rules)
2. {module-specific files}
3. {relevant convention docs from docs/conventions/}
4. {relevant Shared/Contracts/ interfaces}
5. {existing tests in the affected area}

## Acceptance Criteria

- [ ] {Criterion 1 -- specific, testable}
- [ ] {Criterion 2}
- [ ] {Criterion 3}
- [ ] All existing tests pass (`composer test`)
- [ ] PHPStan level 8 passes (zero errors)
- [ ] Pint passes (zero style violations)
- [ ] Deptrac passes (zero boundary violations)
- [ ] TypeScript typecheck passes (if frontend changes)
- [ ] ESLint passes (if frontend changes)
- [ ] New code has tests with meaningful coverage
- [ ] No `// TODO` or placeholder code

## Business Rules

{List the specific business rules this task must respect. Reference the PRODUCT-BIBLE section if applicable.}

- {Rule 1}
- {Rule 2}

## Data Model Changes

{If this task involves schema changes:}

```
Table: {table_name}
  - column_name: type (constraints)
  - column_name: type (constraints)

Enum: {EnumName}
  - value_1
  - value_2
```

{If no schema changes: "None -- this task does not modify the database schema."}

## GL Impact

{If this task creates or modifies journal entries:}

| Event | Debit | Credit |
|-------|-------|--------|
| {event} | {account} | {account} |

{If no GL impact: "None -- this task does not affect the general ledger."}

## Reference Implementation

{Point to existing code that follows the same pattern. The worker should use this as a template.}

- Similar feature: `app/Modules/{Module}/Application/Services/{Service}.php`
- Similar test: `tests/Feature/{Module}/{Test}.php`
- Pattern reference: `docs/conventions/{N}-{CONVENTION}.md`

## What to Do When Done

1. Run `./scripts/preflight.sh` -- it must pass completely
2. If you changed PHP DTOs: run `php artisan typescript:transform`
3. Commit all changes to `feature/{TASK-ID}` with message format: `Phase X.Y.Z: {imperative summary}`
4. Create a PR: `feature/{TASK-ID}` -> `local-dev`
5. Update STATE.yaml:
   ```yaml
   status: completed
   pr_number: {N}
   completed_at: "{timestamp}"
   notes: "{brief summary of what was done}"
   ```

## What to Do When Stuck

1. Update STATE.yaml immediately:
   ```yaml
   status: blocked
   blockers:
     - category: {technical | clarification | dependency | environment}
       description: "{what is blocking you}"
       attempted: "{what you already tried}"
       recommendation: "{what you think should happen}"
   ```
2. The orchestrator checks STATE.yaml every 30 minutes and will respond
3. Do NOT wait silently -- write to STATE.yaml so the orchestrator can help
4. If you can make progress on other parts of the task, continue with those while blocked

## Scope Boundaries

**In scope:**
- {exactly what this task covers}

**Out of scope (do NOT do these even if you notice they are needed):**
- {things that are related but should be separate tasks}
- Modifications to files outside the listed modules
- Refactoring of unrelated code
- Adding features beyond the acceptance criteria

If you notice something out of scope that needs attention, add a note to STATE.yaml under `follow_ups` and continue with the in-scope work.
