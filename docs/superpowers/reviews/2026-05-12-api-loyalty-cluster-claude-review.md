# api.loyalty Cluster — Claude Review

Cluster: `api.loyalty`
Cluster aggregate status (inventory): `fixed`
Verdict: **CONDITIONAL APPROVE** (pending non-TanStack closure plan execution for 10 scanner false positives)

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Loyalty member create/update, enroll/opt-out/reactivate, stamp-card requests, member reward + tier resolution. Pre-sweep, several member-route lookups + stamp-card validators were under-scoped.

Inventory callsite total: **13 callsites — 3 fixed, 7 needs_recheck, 3 pending**.

## Implementation summary

Top fix commit: `74ffc022` (10 callsites).

Top files: `CreateStampCardRequest`, `UpdateMemberRequest`, `UpdateStampCardRequest`, `CreateMemberRequest`, `EnrollMemberRequest`.

The open rows split into two scanner-blind-spot classes:

1. **api.loyalty.001, .003** (needs_recheck) — `CreateStampCardRequest` / `UpdateStampCardRequest` use parent-scoped closure validators (`exists:loyalty_rewards,id` scoped via subquery of `loyalty_programs` where tenant_id). Same scanner gap as `api.pos-stabilization.012`. Resolved by closure plan Task 3 Step 2.

2. **api.loyalty.006-.013** (needs_recheck/pending) — `LoyaltyMemberController` uses `LoyaltyMember::where('tenant_id', $tid)->findOrFail($id)` everywhere (verified at lines 87, 124, 150, 178, 197, 217, 236, 265, 312). Scanner's `FindCallVisitor` does not currently recognize the `where->find*` chain as scoped. Resolved by closure plan Task 3 Step 3.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-04-api-loyalty-cluster-opus-round3-review.md`
- `2026-05-04-loyalty-cross-cluster-blind-spots.md` (audit anchor)

## Gates evaluated

1. **Member lifecycle**: enroll/optOut/reactivate paths scoped (api.loyalty.011-.013 code-verified).
2. **Stamp card parent-scope**: `UpdateStampCardRequest.php:60-62` scopes `loyalty_rewards` via the parent `loyalty_programs.tenant_id`. Runtime-correct.
3. **`loyalty_members` table has only tenant_id (no company_id)**: documented at `LoyaltyMemberController.php:83`. Tenant-only scoping is the correct architectural choice for this table.

## Open work (handled by closure plan)

| Row | Pattern | Plan task |
|---|---|---|
| api.loyalty.001, .003 | Parent-scoped closure validator | Task 3 Step 2 (`ExistsRuleVisitor` enhancement) |
| api.loyalty.006-.013 | `Model::where('tenant_id', $tid)->find*` chain | Task 3 Step 3 (`FindCallVisitor` enhancement) |

## Disposition

CONDITIONAL APPROVE. Upgrades to APPROVE once the closure plan executes Task 3 Steps 2 + 3 (or the chosen fallback — attribute allowlist or repository refactor — per Task 5 Step 2).

## Cross-references

- Audit: `docs/superpowers/audits/2026-05-04-loyalty-cross-cluster-blind-spots.md`
- Closure plan: Task 3 Step 2 + Step 3, Task 5 Step 2
- Cluster-level test: `apps/api/tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`
