# web.tanstack-keys Batch 78 — Opus Review

Commit reviewed: 96bdba49
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `96bdba49` — fix(tenant-isolation): wrap web.tanstack-keys batch 78 (users settings)
Scope: 6 callsites in `UsersPage.tsx`.
- .642 read users (filtered), .643 read roles
- .644/.645/.646/.647 invalidate users predicate on create/activate/deactivate/delete

Test: `UsersPage.tenantScope.test.tsx` (new, 236 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: Both reads use `tenantScopedKey([...])`. All 4 invalidates use `scopedNamespacePredicate('users', ...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`. Note: page also refactored `currentUser` selector to `currentUserId` (just the id) to avoid object-identity re-renders.
3. **Enabled gates**: Both reads AND-combine `tenantId !== null && companyId !== null`.
4. **Async invalidate**: All 4 mutation onSuccess handlers `async/await`.

## Non-blocking findings

1. `scopedNamespacePredicate` inlined. Tracked.
2. Refactor of `currentUser → currentUserId` is a minor optimization piggy-backed on this commit. Defensible since the only consumers downstream are `user.id !== currentUser?.id` comparisons.

## Locks applied

6 callsites locked at fix commit `96bdba49`:
web.tanstack-keys.642, .643, .644, .645, .646, .647.
