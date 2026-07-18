# Treasury Phase ④ — Final Whole-Branch Review (2026-07-13)

> **Branch:** `feat/treasury-phase4-expense-depth` (base `e9c2b581d`, tip `a1c3a3ad5`, 36 commits, 115 files, +11,020/−190) · **Merged to dev:** `40d4a9b8a`
> **Pattern:** Phase-③ final-review pattern — Fable orchestrator-only; 1 Sonnet discovery lane + 6 verification lanes (Fable on money path per owner tiering, Opus rest) + fresh in-session verification.
> **Result: ALL LANES APPROVE / SAFE-TO-MERGE. Merged.**
> Codex build: 17 plan tasks / 4 waves, 4 autonomous `claude -p` gates (8 review files incl. multi-lane Gates 2/4), all APPROVE on rc1 (verified earned, not soft — see V6 Part A).

## Pre-checks (session, before lanes)

- 8 gate tags (`phase4-gate-{1..4}` + rc1s) + 8 `VERDICT: APPROVE` review files ✓ · **port byte-diff EMPTY** (`TreasuryMovementService.php`, also vs origin/dev) ✓ · worktree clean, nothing merged/pushed by Codex ✓ · branch based on then-current origin/dev tip ✓.
- ⚠️ Pre-dispatch discovery: origin/dev commit `1be201589` carried the SAME Task 16 outbound-guards change as branch commit `380e4aed9` — routed to V6 for diagnosis (below: identical patch-id, clean auto-dedup).

## Lane verdicts

| Lane | Model | Scope | Verdict | Notable |
|---|---|---|---|---|
| Discovery | Sonnet | full diff map + out-of-scope sweep | clean | zero interlock breaches (`settle()` byte-identical, fiscal perimeter untouched, GL edits confined to `createFromExpense`); every file traced to a task/sanctioned deliverable; 7 honest deviation entries; banned `document_tax_details` widening migration confirmed absent; no debug code / skipped tests / lockfile changes |
| V1 | **Fable** (treasury) | W1 money path + W4 guards | **APPROVE** | remainder-method split verified exact at scale+2 with single boundary `bcround`; all 5 mandatory plan-review fixes in code AND pinned by tests (console-safe create, off-grid 422, scale-parameterized invariants, metadata casts, zero-VAT→null); shared `ExpenseVatSplit` keeps GL 4456 ≡ declared VAT; VAT-less byte-identical regression via full-attribute comparison; reconcile #1–#4 green over authoritative branch; 5 guard points verified; 48 tests executed locally. 3 LOW + 1 INFO |
| V2 | Opus (treasury) | W2 recurring engine | **APPROVE** | origin-anchored cursor (day-31/leap pinned); atomic create+advance; replay advances once, never re-notifies (real notifications-table count); actor/tenant stamping + fallback admin with team-id finally-restore; shared `MAX_LEAD_DAYS`; forecast partition disjoint (no gap/double-count, deletion semantics pinned); 20 tests executed. 4 LOW |
| V3 | Opus (treasury) | W3 analytics + export | **APPROVE** | single-GROUP-BY aggregates, all tenant+company scoped incl. joins; share_percent zero-guard; strings end-to-end (CAST AS TEXT → bcformatStrict); shared `ExpenseIndexQuery` kills filter drift, date_to boundary parity proven; BOM+cursor streaming full-set proven (45 rows); routes above `{id}`. 3 minor (uncapped top_vendors, unconditional index migration — safe, status-vocabulary cosmetic) |
| V4 | Opus (tenancy-authz) | permissions + isolation | **APPROVE** | §8.5 grants EXACT on BE seeder + FE map (hand-map path; generator not landed); deny-paths real HTTP all verbs × 3 roles; two-company isolation fixtures real on CRUD/analytics/export; console team-id = TENANT with finally-restore; recipients company-membership filtered; cache-reset in deploy checklist. 1 minor (no UUID guard on `expense-recurrences/{id}` → PG 500 on malformed id; matches pre-existing module convention) |
| V5 | Opus (frontend-conventions) | FE waves | **APPROVE** | guardrails re-run independently: typecheck clean, design audit **0 new** (753 baseline), tanstack audit 0, vitest 102 passed/3 todo, zombie-check 0; canonical atoms throughout; new-key i18n parity en/fr/ar complete; nested notification keys resolve; blob export with revoke. 3 minor (generated `months: any`; pre-existing ar `pay.*` 12-key gap; ExpenseDetailPage raw money strings vs formatCurrency) |
| V6 | Opus (treasury) | gate honesty + merge interaction | **SAFE-TO-MERGE** | all 4 gates rated SUBSTANTIVE (file:line cites, port-diffs run, §3 hunts enumerated); task15 "review repair" = docs-only ledger rewrite, actual code repair `97cf5a146` was inside Gate 3's reviewed tree; 4 pinned assertions verbatim-or-STRONGER; 0 gaming markers, 106 new tests, 0 removed assertions. **Task 16 duplication: identical patch-id `b6a70381…` (pushed to dev in isolation via rule-21 single-commit pattern) → scratch merge proves clean auto-dedup (exactly 5 guards / 7 tests)**; ONE textual conflict (progress ledger add/add — keep branch's 450-line version); no migration/locale/usePermissions collisions; regenerate types post-merge |

## Fresh verification (session, in worktree)

- Backend: `phpunit tests/Feature/Expense tests/Unit/Expense tests/Feature/Accounting tests/Feature/Treasury` → **1,212 tests / 5,347 assertions, 0 failures** (29 skips = pgsql-gated-on-sqlite documented pattern).
- PHPStan (1G): **0 errors**. (Pint pre-existing baseline debt unchanged — Codex reported the same 18 unrelated files.)
- FE: via V5's independent runs — typecheck clean · design audit 0 new · tenant-key audit 0 · 102/105 vitest.

## Merge (2026-07-13)

- Local dev ff'd to origin/dev `feafa7e8b` → `git merge --no-ff` → single predicted conflict in `docs/handoff/treasury-phase4-progress.md` → resolved keep-branch (full 450-line ledger) → merge commit `40d4a9b8a`.
- Task 16 dedup verified in merged tree: 5 guard strings (4 lifecycle + 1 remittance), 7 test methods — no doubling.
- `php artisan typescript:transform` post-merge: generated.d.ts byte-identical (auto-merge was correct).
- Post-merge sanity: targeted vitest 105 passed (expenses + usePermissions + placement); design/tanstack audits 0 new; typecheck **initially FAILED on a pre-existing dev breakage** — `staleZoneKeys.test.ts` TS4111 ×4 from the location-placement-phase2 merge (verified present at `feafa7e8b`, untouched by Phase ④) — fixed in follow-up commit on dev (bracket access), typecheck now clean.

## Non-blocking follow-ups (registered, not gating)

1. UUID guard on `expense-recurrences/{id}` routes (PG 500 on malformed id; consider module-wide `Route::pattern` — `ExpenseController` shares the gap) (V4).
2. Per-template try/catch inside the generation command's company loop — one throwing template currently skips its same-company siblings for that run (self-heals next day via idempotency) (V2).
3. Off-grid total check only runs when VAT present — hoist above the VAT-null return for full coverage (V1); `$metadata->` vs `$metadata?->` consistency nit (V1); FE `computeVatFromInclusive` truncates the suggested VAT hint (V1, cosmetic).
4. Cap `top_vendors` server-side (or "Other" rollup) before high-vendor-count tenants (V3); CRUD `next_due_date` recompute uses server tz vs command's company tz (V2); template deletion nulls `recurrence_template_id` → materialized draft drops from forecast (V2, document or restrict).
5. FE: generated `ExpenseAnalyticsMatrixData.months` is `Array<any>` — annotate the PHP DTO for a keyed map (V5); `ExpenseDetailPage` money rows → `formatCurrency` (V5); pre-existing ar `pay.*` 12-key gap (V5, predates branch); notification-send failure post-commit marks company FAILURE without re-notify (V2, rare on database channel).
6. `.superpowers/sdd/progress.md` bookkeeping (duplicate Task 3 entry, no Task 15 line) — cosmetic (V6).

## Deploy owes (per `docs/handoff/treasury-phase4-deploy-checklist.md`)

`tenants:migrate` (4 new tenant migrations: expense_metadata VAT columns, recurrence templates ×2, analytics composite index) · perm reseed (`expenses.export`, `expense-recurrences.*`) + `permission:cache-reset` (tenant-blind cache) · scheduler picks up `expenses:generate-recurring` (verify once on staging) · VatDeductible-presence verification query (no CoA reseed) · NO Horizon change (database channel). Stacks on the standing Phase-③/banks/location-hierarchy owes.
