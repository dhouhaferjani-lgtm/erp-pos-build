# Treasury Phase ③ — Final Whole-Branch Review (2026-07-13)

> **Branch:** `feat/treasury-phase3-cash-visibility` (base `f1d6c1d30`, tip `adef289a5`, 36 commits, 106 files, +6,601/−60) · **Merged to dev:** `05674a586` (pushed origin/dev same day)
> **Pattern:** Phase-② final-review pattern — Fable orchestrator-only; 1 Sonnet discovery lane + 6 Opus verification lanes + fresh in-session verification.
> **Result: ALL LANES APPROVE / SAFE-TO-MERGE. Merged.**
> Codex build: 18 plan tasks / 5 waves, 4 autonomous `claude -p` gates, all APPROVE on rc1 (verified earned, not soft — see V5).

## Pre-checks (session, before lanes)

- 4 gate tags + 4 `VERDICT: APPROVE` reviews present ✓ · mandatory A-1 deviation entry in progress file, honest wording ✓ · **port byte-diff EMPTY** (`TreasuryMovementService.php`) ✓ · worktree clean, nothing merged/pushed by Codex ✓.

## Lane verdicts

| Lane | Model | Scope | Verdict | Notable |
|---|---|---|---|---|
| Discovery | Sonnet | full diff map + out-of-scope sweep | clean | zero interlock violations; all out-of-plan files traced to sanctioned deliverables; found a SECOND deviation entry (D2 all-raw invalidation prefixes — CI-forced, sound rationale) |
| V1 | Opus (treasury) | transfer money path (Wave A) | **APPROVE** | index predicate matches stored enum values; draft factory never posts; §5.2.3d cleanup + out-leg JE-id resolution in place; all pins at full strength; 2 LOW informational (regex-vs-scale truncation is sanctioned rule-19 behavior; reverse gl-TOCTOU fails safe) |
| V2 | Opus (tenancy-authz) | notification center (Wave B) | **APPROVE** | tenant-id team + finally restore + registrar flush verified; deny-direction tested at resolver AND send level; REAL two-tenant-DB harness; A-3 zero-count guard pinned; zero cross-user paths; audit code byte-unchanged |
| V3 | Opus (treasury) | read layer (Wave C) | **APPROVE** | direction filter before count; per-currency totals cloned pre-pagination; mixed-currency test exercises both union legs; company-currency flows guard + occurred_at pinned; precision sweep clean; 2 INFO |
| V4 | Opus (frontend-conventions) | FE (Wave D) | **APPROVE** | guardrails re-run independently (typecheck 0, lint 0 errors, audits 0 new, 102/102 targeted vitest); no baseline absorption/suppression; stable per-open transfer_group_id; 8-family invalidation verified vs tenantScopedKey suffixing; i18n en/fr/ar parity complete; SERVER_AUTHORITATIVE decision recorded and correct |
| V5 | Opus (treasury) | test integrity + gate honesty | **APPROVE** | all 4 gates rated SUBSTANTIVE (Gate 1 caught its own diff-basis trap; Gate 3's LOW fixed in `e1dc510eb`); zero gaming (one justified pgsql-gated skip); all 11 pinned assertions verbatim-or-stronger; progress test counts exact; 13 real E2E screenshots eyeballed (posted JE Dr 512 / Cr 53 250.00 confirmed) |
| V6 | Opus (treasury) | merge interaction vs origin/dev | **SAFE-TO-MERGE** | ONE textual conflict (`usePermissions.ts`, keep-both); KEY semantic check SAFE: ui-gaps' `['treasury-cash-position']` prefix invalidation matches the new windowed key (TanStack v5 exact:false); locale keys disjoint; migration timestamp collision harmless (anonymous classes) |

## Fresh verification (session, in worktree)

- Backend: `phpunit tests/Feature/Treasury tests/Feature/Notification` → **592 tests / 2,389 assertions, 0 failures** (25 skips = pgsql-gated on sqlite, documented pattern).
- PHPStan (1G): **0 errors**. Pint: 20 failing files — ALL outside the branch diff (pre-existing Product/Inventory baseline debt, same set as the Phase-② review).
- FE: typecheck clean · lint rules pass · design audit 753 acknowledged / **0 new** · tenant-key audit 0.

## Merge (2026-07-13)

- Local dev ff'd to origin/dev `4582ccaf0` (two more commits had landed since V6's analysis — stale-chunk reload guard; **zero overlap** with the branch, verified).
- `git merge --no-ff` → single predicted conflict in `usePermissions.ts` → resolved keep-both (`treasury.adjust` + `treasury.transfer`, dev's `expenses.pay` auto-applied) → merge commit `05674a586`.
- Post-merge sanity: typecheck clean, both audits 0 new, targeted vitest 10/10 (usePermissions + useTransferCash + RepositoryListPage).

## Non-blocking follow-ups (registered, not gating)

1. `php artisan typescript:transform` pass — Phase-③ DTOs absent from `packages/shared/types/generated.d.ts` (FE self-defines interfaces; nothing broken) (V6).
2. Optional tidy: `TreasuryAlertRecipients` `whereRaw`→fluent `where` (V2, cosmetic); comment on `CashPositionController` SUM exactness resting on the decimal column (V3).
3. Pre-existing, out of scope: `RepositoryListPage.tsx:244` `bg-white` literal; `RepositoryMovementsTab` raw `<table>`; GL JE display scale-2 vs TND scale-3 drift (`journal_lines` DB decimal(15,2)) — pre-dates this branch (V4/V5).
4. Spec §13 follow-up register unchanged: report-page export; transfer shortcut on RepositoryDetailPage (ui-gaps now merged — unblocked); full notifications page; Reverb real-time; gl_account_id-reassignment guard for repos with same-GL transfer history.

## Deploy owes (per `docs/handoff/treasury-phase3-deploy-checklist.md`)

`tenants:migrate` (notifications table + treasury_transfer posted-scoped unique index) · perm reseed (`treasury.transfer`) + `permission:cache-reset` (tenant-blind cache) · no chart reseed · staging smoke: one drawer→bank transfer + bell delivery. Staging auto-deploys from dev with entrypoint-automated migrate/reseed/cache-reset.
