# CODEX HANDOVER — Treasury Phase ③ Follow-ups (6 items, 1 gate)

> **Date:** 2026-07-13 · **Runner:** Codex desktop · **AUTONOMOUS GATE:** one `claude -p` gate at the end — no human wait. Do NOT merge or push; leave the worktree and report (the Claude session reviews + owns the merge).
> **Origin:** non-blocking follow-ups from the Phase-③ final review (`docs/superpowers/audits/2026-07-13-treasury-phase3-final-review.md` §follow-ups) + spec §13 register (`docs/superpowers/specs/2026-07-12-treasury-phase3-cash-visibility-design.md`). Phase ③ merged to dev at `05674a586`.

## Setup

`git fetch origin dev` → `git worktree add ../erp.phase3-followups -b chore/treasury-phase3-followups origin/dev`. Progress file: `docs/handoff/treasury-phase3-followups-progress.md`.

## Ground rules

Same as every treasury track: TDD (tests by path only — NEVER full suites; kill hung vitest worker pools), rule 19 (no float on money), rule 18 (design tokens), i18n en+fr+ar for web keys, `tenantScopedKey` on query keys, canonical error envelope, `TreasuryMovementService` port byte-untouched, no scope creep beyond the 6 items. Commit per item.

## Items

**F-1 — `typescript:transform` reconciliation.** Run `CACHE_STORE=array php artisan typescript:transform` (worktree gotcha: symlinked vendor runs stale code — real `composer install` if needed). Commit any `packages/shared/types/generated.d.ts` drift. Phase-③ DTOs (`RepositoryTransferResult`, notification shapes) are NOT annotated for TS export and FE hooks self-define their interfaces — do NOT annotate them as a side quest; if the transform produces no drift, record that as the outcome (decision: FE-local interfaces stay authoritative for these non-domain-entity response shapes).

**F-2 — Transfer shortcut on `RepositoryDetailPage`** (unblocked — `feat/treasury-ui-gaps` is merged). Add a `Transfer cash` button to the PageHeader actions (beside the ui-gaps `Adjust balance` button), gated `hasPermission('treasury.transfer')`, opening the existing `TransferCashModal` with the current repository preselected as source. Requires a new optional `initialFromRepositoryId?: string` prop on `TransferCashModal` (default-select + still user-changeable; keep the stable-uuid-per-open behavior). Vitest: button gating + preselection + modal opens. i18n: reuse `treasury:repositories.transfer.*`.

**F-3 — `gl_account_id` reassignment guard (review L1-8).** Reconcile check #2's same-GL exemption evaluates repos' CURRENT `gl_account_id`; reassigning one repo's account flips historical null-JE same-GL transfer legs to non-exempt → both repos freeze at the next nightly run. Guard: in the repository-update path (`PaymentRepositoryController::update` / the service behind it), when the request changes `gl_account_id` AND the repository has movements with `source_type='transfer'` and `journal_entry_id IS NULL` → reject with `DomainException` 422 explaining why (message names the count of affected legs). PHPUnit: reassignment blocked with legs / allowed without / allowed when only JE-carrying transfer legs exist. This is money-adjacent: if the gate reviewer raises BLOCKER/HIGH here, escalate per the tiering rule below.

**F-4 — `TreasuryAlertRecipients` tidy (V2 cosmetic).** `whereRaw('company_id = ?', ...)`/`whereRaw('status = ?', ...)` → fluent `->where(...)` inside the `whereHas`. Behavior identical; existing tests must stay green unmodified.

**F-5 — SUM exactness comment (V3).** One comment at the flows aggregation in `CashPositionController` (~:124): exactness rests on `repository_movements.amount` being `decimal(15,3)` — do not "align" it to the report service's CAST pattern, and do not change the SQL.

**F-6 — `bg-white` token migration (V4).** `RepositoryListPage.tsx:244` summary card: `bg-white` → the `colors.white`/appropriate token from `@/lib/designTokens` (rule 18 — this file is already being touched by F-2's page sibling; only migrate this one literal, nothing else).

## Gate (after all 6 items)

Commit everything, run: backend `./vendor/bin/phpunit tests/Feature/Treasury` + `./vendor/bin/phpstan` + `./vendor/bin/pint --dirty`; FE `pnpm typecheck && pnpm lint`, `pnpm vitest run src/features/treasury`, `node tools/audit-design-system.mjs` (0 new), `node tools/audit-tanstack-keys.mjs`. Tag `phase3-followups-rc<attempt>`, then:

```bash
claude -p --model claude-opus-4-8 \
  "ADVERSARIAL GATE REVIEW, treasury phase3 follow-ups. Review ONLY git diff origin/dev..HEAD against docs/handoff/CODEX-treasury-phase3-followups-2026-07-13.md (6 items). Verify with file:line citations; hunt: scope creep, F-3 guard correctness vs ReconcileTreasuryCommand::isSameGlAccountTransfer semantics, port edits (must be byte-untouched), broken existing tests, missing i18n parity, design-audit regressions. Write to docs/handoff/gate-reviews-phase3-followups/GATE-rc<attempt>.md ending 'VERDICT: APPROVE' or 'VERDICT: CHANGES-REQUIRED'."
```

Escalation (owner tiering): re-run with `--model claude-fable-5` ONLY on a BLOCKER/HIGH touching F-3 (the reconcile-interplay guard) or any GL/movement semantics. CHANGES-REQUIRED → fix test-first, bump rc, re-run. 3 consecutive failures on the same BLOCKER → STOP and report. APPROVE → leave worktree, report done.
