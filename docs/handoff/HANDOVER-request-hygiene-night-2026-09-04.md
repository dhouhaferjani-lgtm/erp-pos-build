# Request-hygiene Phase A — night run handover (2026-09-04, 00:30 → ~08:30)

Orchestrator: Fable (this session). Implementation lanes: Opus agents in `.worktrees/rh-*`. Gates: Opus reviewer agents (treasury / inventory-costing / frontend-conventions / general) + Codex plan gates r9/r10. Owner asleep from ~00:50; instructions: maximise progress with Opus + Codex, watch swap, park everything but ERP + locaplex, Docker hygiene, audit the teammate's PRs.

## 1. What is on LOCAL `dev` (NOT pushed — `origin/dev` unchanged)

`dev` = `ec9e71cc3` + (placeholder-data lane pending its independent gate). 108 commits ahead of `origin/dev`.

| Lane | Merge | Gates (rounds) | Promotion-owed |
|---|---|---|---|
| T3 payments list | `451f8b62e` | treasury r1 CHANGES → r2 MERGE; FE r1 CHANGES (baseline re-key) → closed | dashboard/page-2 browser check; `payments.spec.ts`, W5c/W8 Playwright |
| T4 audit bounds + document limit | `fbae84cb3` | general r1 CHANGES → r2 MERGE | live campaign `limit=100` + Dashboard `limit=5` |
| T2 stock movements | `7f86dbf0c` | inventory r1 CHANGES → r2 MERGE; FE r1/r2 CHANGES → r3 MERGE | Step 11 browser probe; four W4 Playwright specs |
| lint-debt side lane | `5edb7e810` | r1 CHANGES → r2 MERGE | — (ratchet stays red from pre-existing +6 drift, owner re-baseline decision) |
| T13 transfer/adjustment idempotency | `f85b7c0e9` | inventory r1 CHANGES (CI allowlist) → fixed; FE r1 APPROVE-WITH-FIXES → r2 MERGE | double-click probes with zero-5xx; adjustment page generic error surface |
| T10 query counting + lazy-load guard | `6292cf235` | general MERGE (local only) | **whole-backend CI run before push** (PR→dev or `workflow_dispatch`); **staging log-volume ruling** (require per-process dedupe) |
| dev unit-red repair (test-only) | `d7b087d4a` | — | two `ReceiptReturnServiceTest` batch-restitution cases still red for a substantive reason (see §4) |
| T8 reconnect cooldown | `0bb875180` | FE r1 MERGE (N-1 must-fix) → r2 MERGE | authenticated-layout reconnect probe (no burst on initial connect) |
| T5 product-search debounce | `5e1e54f69` | FE r1 CHANGES (stale-suggestion Enter fork, company leak) → r2 MERGE | wedge scan, 3-chars+Enter <250 ms, company switch, ×4 consumers |
| T7 stock-level dedupe | `9c28b430a` | lane self-gate r2 MERGE + independent MERGE | network-panel confirmatory check |
| T12 payment idempotency keys | `f21510ce6` | treasury r1→r3 MERGE; FE r1 REJECT → r3 MERGE | five browser legs; **P1 pre-production**: RecordPaymentModal form wipe on prefill identity (reconnect refetch forces re-entry); `crypto.randomUUID` needs HTTPS origins |
| T6 pricing debounce | `f57d6b307` | lane self-gate r3 MERGE + independent APPROVE-WITH-FIXES | PO + credit-note browser checks incl. company switch |
| T14 serialized autosave | `ec9e71cc3` | FE r1 CHANGES (FIFO tail) → r2 MERGE | throttled-typing race check, forced-500 repeat |
| placeholder-data audit (follow-up) | pending | lane self-gate MERGE-WITH-FOLLOW-UPS; independent gate in flight | browser company-switch checks on the three surfaces |
| Docs | `451e444b6` rev 10, `a97631051` rev 11, `c872427cb` rev 12, `60f87a5a1` rev 13; Codex gates r9/r10 filed; teammate audit filed | | |

**Not done:** T1 permission cache — owner's Codex Desktop lane (`docs/handoff/HANDOVER-request-hygiene-T1-permission-cache-2026-09-03.md`).

## 2. Decisions taken overnight (owner may overrule)

- Idempotency key = ONE submit intent: unchanged retry keeps the key; first operator edit after a failed attempt rotates it; success rotates; modal open transition rotates. Programmatic RHF writes never rotate.
- Render-phase derived-state page reset (`filterSignature`) is the canonical pattern; `useEffect(() => setPage(1))` is not. Shared-hook extraction is a post-merge follow-up.
- `placeholderData: keepPreviousData` is forbidden on tenant-scoped reads unless placeholder rows are hidden on scope change (Gate C detector in `tools/audit-tanstack-keys.mjs`, pending merge).
- T10 lazy-load guard: merge locally; a per-process (model, relation) dedupe is required before staging.
- Initial WebSocket connect is not a "reconnect" (no sweep, cooldown unarmed).
- Blank query params: `nullable` + blank→null normalisation, half pairs still 422, paging from `validated()`.
- Pre-existing dev reds fixed test-only where mechanical; substantive reds left red and reported.

## 3. Promotion checklist (push = staging auto-deploy; NOT tonight)

1. Owner OKs a non-test-day promotion window.
2. Push `lane/rh-t10-guards` (or `dev`) to a remote branch and run `workflow_dispatch` (or open a PR→dev): whole backend suite must be green except the documented pre-existing reds (`PaymentTest::test_supplier_invoice_payment_clears…`, two `ReceiptReturnServiceTest` batch-restitution cases, plus anything CI shows that reproduces on `origin/dev`).
3. Lazy-load guard dedupe commit (small lane) before pushing.
4. Redis probes per container (web/worker/scheduler/websocket/CLI) + `permission:cache-reset` note (T9 precondition, unchanged).
5. Browser probes: every row's "Promotion-owed" column above, on a live stack (local `autoerp_postgres` needs port 5433 back, or a private port; see `reference_local_pg_port_5433_contention_locaplex`).
6. `lint:ratchet` (+6 drift) and `audit:design-system` (14 ImportWizardPage entries) are red on `dev` independent of these lanes — decide re-baseline vs fix before CI can be green.
7. Confirm staging/prod origins are HTTPS (`crypto.randomUUID`).

## 4. Findings for the next testing wave (outside this programme)

- `FEFOInventoryService::restoreBatchesForReturn()` credits nothing when the original sale has no `stock_movements`/`inventory_batch_movements` rows — fixture gap or behaviour change; two unit tests red (`tests/Unit/POS/ReceiptReturnServiceTest.php`).
- `ProvisionsTenantDatabases.php:71` hardcodes `sqlite_master` → the db-per-tenant test family cannot run on PostgreSQL.
- Campaign `onboarding.campaign.ts` sends `limit=100` at five sites, exactly at the ceiling — truncates silently past 100 documents.
- RecordPaymentModal wipes an in-progress form on any prefill identity change (all three hosts pass inline literals) — P1 before production.
- `DocumentLineEditor` pricing hint is largely dead (`lineColumns` deps / component-typed cells) — pre-existing; fix `LineItemsTable` first, then the dep.
- `useUnsavedChangesGuard` is `beforeunload`-only; in-app navigation can drop an unsent autosave body.

## 5. Teammate (Dhouha) — see `docs/superpowers/reviews/2026-09-04-teammate-pr-audit-dhouha.md`

No pushes since 2026-08-05. Chain #201–#206: 3223 commits behind, 17 conflicting files, 5/7 P1s from the 2026-08-02 review still open (IBAN leak, migration collisions, Button sweep, consumption_mode coercion, drift). #207 (certification regimes): best work of the set, CHANGES (hand-rolled type beside generated DTO, dead Data-typed interfaces, rebase). Three un-PR'd branches already in dev → delete. Recommended: close #200, re-port #201-config / #205-core / #206 as short branches off current dev, hotfix P1-3 independently; the methodology doc was never pushed to her.

## 6. Environment notes

- `autoerp_postgres` is down: `locaplex-postgres` holds 5433 (owner's parallel project). Lanes used private throwaway PG containers (all removed now).
- Worktrees removed after merge; remaining `.worktrees/`: g2-sku, g5-opening, h2-party-kind, k67-import (WIP kept per keep-list), rh-placeholder (pending). `erp.fix-r2d`/`erp.fix-r2l` kept (uncommitted money WIP).
- Swap peaked ~6.4 GB with RAM free never below ~45%; no IDE freeze.
- Process lesson recorded in memory: `git merge` must be issued from the main checkout with an absolute `cd` first — a merge from inside a lane worktree is a silent no-op (bit this session repeatedly; every affected merge was redone and verified via `git rev-list --count origin/dev..dev`).
