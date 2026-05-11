# PR #110 — C2 Day 3 (server-side menu_category_id) — Codex review trail

**Branch:** `feat/pos-c2-day3-server-menu-category-id`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 1 round, APPROVE.

## Round 1 — APPROVE

> The changes consistently add menu_category_id to the sync wire format, validation, persistence, and receipt line fillable fields. I did not find a discrete introduced issue that would break existing behavior or the new menu-category preservation path.

No findings. PR ready for merge.

## Why this round was clean (L9 validation)

The four-finding chain on PR #109 was the lesson trigger for L9 ("canonicalize-before-state-machine-input — enumerate ingress sites, not just consumers"). For Day 3 the PR body opened with an explicit downstream audit table enumerating every ingress site for the new `menu_category_id` field on both stacks — DB column, model fillable, DTO docblock, validator, sync ingest, server-side read, wire surfacing. Every ingress was covered before the first push. Result: single-round APPROVE, vs. the 5-6 round multi-fix iterations on PRs #108 + #109 where Codex incrementally surfaced un-enumerated ingress points.

This is the second confirmation of L9 in three sessions (Day 1 cross-tenant audit on PR #107, Day 2 ingress-site discipline on PR #109). The discipline scales beyond canonicalization invariants — for ANY new field/invariant on a multi-stack data shape, the PR body should ship with an ingress enumeration.

## Final shape

- **1 commit** (single push, single round).
- **5 backend files** (migration + DTO + service + model + request validator).
- **2 frontend files** (syncService.ts wire surfacing + compositeUnpack test extension).
- **+3 tests** (composite product_id surfaces id; composite_item_id surfaces id; bare-uuid omits the key).
- Final pos test count: 1196/1196 in 132 files.
- typecheck 0 errors; lint baseline preserved (0 errors / 41 warnings).
- Backend tests not exercised locally (PHPStan/PHPUnit not in this worktree; CI gates these).

## Out of scope (deferred per kickoff)

- In-flight (offline) cart-line migration semantics for terminals upgrading from pre-C2 (kickoff Risk #3 — dump-and-warn recommended).
- E2E `MenuTenantMultiCategoryFixture` seeder (audit-phase smoke).
- `FullReceiptResponse` TS type doesn't yet declare `product_id` / `composite_item_id` / `menu_category_id` per line; the server returns them via `toArray()` but the type would need an additive pass when the server-recall refund flow consumes them.

## Cross-references

- Kickoff doc: `docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md`
- Day 1 review trail: `docs/superpowers/reviews/2026-05-10-pos-c2-day1-codex-rounds.md`
- Day 2 review trail: `docs/superpowers/reviews/2026-05-11-pos-c2-day2-codex-rounds.md` (L9 origin)
- PR #92 in-flight pre-launch work — closes the C2 entry end-to-end.
