# Treasury Phase ⑤b Gate 2 — statement import flow (tenancy/authz review)

You are the **tenancy-authz-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `e06d2a831` (`t5b-gate-1`)

Review range: `git diff e06d2a831...HEAD`

Read in authority order: spec Rev 2 §§5.3, 7–8; the tenancy-authz plan review (especially findings 2, 4, 6, 7, 8); the other two plan reviews; Wave 2 Task 4; CLAUDE.md rules 1–21; `.claude/agents/tenancy-authz-reviewer.md`; the full diff and relevant middleware/permission conventions.

## Required checks

1. Routes are inside the existing `api/v1` group and inherit `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`, plus the global company context.
2. `bank-statements.view/import/reconcile` are granted to admin+accountant; `bank-statements.reopen` is admin-only; manager receives none and is proven 403 on both read and upload.
3. All request FK inputs use `ScopedExists::tenant`; service/controller guards then enforce active-company ownership and repository/profile binding. Same-tenant cross-company ids fail 422 before storage.
4. Profile CRUD list/show/update/delete is company-scoped and a profile can bind only to an active-company bank repository.
5. Statement list/show/void is tenant+company scoped; foreign-company statements are 404 and malformed UUIDs are 404. The route parameter is named `{bankStatement}` and the controller uses the tenancy review's accepted alternative to implicit binding: explicit `Str::isUuid` guard followed by a tenant+company query (avoids an unscoped model materialization).
6. Encrypted preview tokens cannot cross tenant/company; confirm re-resolves and rechecks every referenced entity rather than trusting token contents alone.
7. No endpoint leaks foreign ids or data through validation/error payloads beyond the authorized active company.
8. Seeder changes require the final deploy checklist to include permission reseed and `permission:cache-reset`; flag any additional deploy obligation now.

## Evidence

- `StatementImportFlowTest.php`: 12 tests / 68 assertions on fresh PostgreSQL, including manager deny, accountant/admin grant matrix, cross-company 422/404, malformed id, tampered token, and no pre-validation file storage.
- SQLite combined import/parser: 21 tests / 117 assertions.
- Pint and PHPStan clean; `git diff --check` clean.

## Required output

After the first-line decision: findings ordered BLOCKER/MAJOR/MINOR with exact evidence; an eight-check pass/fail table; middleware/permission/scoping assessment; explicit decision on the guarded `{bankStatement}` implementation; end with `VERDICT: tenancy ✅/❌ + authz ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one exact fix line if rejected.
