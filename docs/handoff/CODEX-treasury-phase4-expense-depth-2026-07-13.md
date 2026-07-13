# CODEX HANDOVER — Treasury Phase ④: Expense Depth

> **Date:** 2026-07-13 · **Runner:** Codex desktop (CLI-brokered Codex cannot write to `apps/erp.*` worktrees). **AUTONOMOUS GATES (owner re-confirmed 2026-07-13, non-negotiable):** you run every gate review yourself via `claude -p` — no human wait; no gate may be skipped, weakened, or self-attested. The only human gates are dispatch (before you start) and the final whole-branch review + merge (after you finish, owned by the Claude session). Do NOT merge to dev or push to dev yourself under any circumstance.
> **Binding documents (read in this order before writing any code):**
> 1. Spec Rev 2.1: `docs/superpowers/specs/2026-07-13-treasury-phase4-expense-depth-design.md` (§5 posting contract, §6 recurrence semantics, §7 analytics/export, §8/§8.5 guards + permission grants, §11 EC points, §12 deferrals, §13 invariants — note the ⟦Rev 2.1⟧ corrections in §5.3; the plan is normative where they conflict)
> 2. Plan Rev 2 (17 tasks / 4 waves, incl. its reconciliation log): `docs/superpowers/plans/2026-07-13-treasury-phase4-expense-depth.md`
> 3. All three reviews — their **"Verified accurate" sections are ground truth, do NOT re-derive**: spec review `docs/superpowers/specs/reviews/2026-07-13-treasury-phase4-expense-depth-spec-review.md`; plan reviews `…/2026-07-13-treasury-phase4-plan-review-w1-money-path-fable.md` + `…/2026-07-13-treasury-phase4-plan-review-w2-w4-opus.md`

## 0. Mission

Implement plan Rev 2's 17 tasks in wave order: **W1** (Tasks 1–6, expense money path — VAT split posting + `document_tax_details` declaration wiring + supplier link + console-safe `create()`), **W2** (Tasks 7–12, recurring templates — cursor math, generation command, notifications, forecast feeds, CRUD+FE), **W3** (Tasks 13–15, analytics endpoint + streamed CSV export + FE), **W4** (Tasks 16–17, outbound-instrument direction guards + closeout). Four autonomous gates; ALL must APPROVE before the merge request goes to the owner.

## 1. Setup — prerequisites, in order

1. `git fetch origin dev` — base on the CURRENT origin/dev tip. It MUST include the commit carrying this brief (spec Rev 2.1 + plan Rev 2 + the two plan reviews); verify with `git log origin/dev --oneline -5 | grep -i "phase4"` and `test -f docs/superpowers/specs/reviews/2026-07-13-treasury-phase4-plan-review-w1-money-path-fable.md`. The tip also already contains Phases ①–③, ui-gaps (PayExpenseDialog), banks-tug polish, and phase3-followups (`TreasuryAlertRecipients`, the W2 exemplar).
2. `git worktree add ../erp.treasury-phase4 -b feat/treasury-phase4-expense-depth origin/dev`
3. Create `docs/handoff/treasury-phase4-progress.md` (see §6) with a header entry noting the base commit.
4. Backend env: run tests BY PATH only; if the worktree symlinks `vendor`, symlinked vendor runs STALE main-repo code for changed classes — prefer a real `composer install` in the worktree. `php artisan typescript:transform` needs `CACHE_STORE=array` in worktrees.

## 2. Ground rules (non-negotiable — violations block merge)

1. **TDD per plan step** — the plan gives you the failing test first for every task; keep that order. PHPUnit by path (`tests/Feature/Expense`, `tests/Feature/Accounting`, `tests/Feature/Treasury`, `tests/Unit/Expense`), Vitest by path. NEVER run the full PHPUnit or Vitest suite unattended (hung vitest worker pools OOM the machine — if a run hangs: `ps aux | grep 'node (vitest'` and kill the workers).
2. **Inviolate surfaces:** `TreasuryMovementService` public methods byte-untouched; fiscal perimeter (`fiscal_events`, canonical bytes, `pos_receipt_payments`) untouched; `ExpenseService::settle()` amounts logic untouched (it debits/credits `total` — verified; the VAT split changes only the posting DEBIT composition). If a test seems to require editing any of these, the test or your code is wrong — STOP and re-read spec §5.3/§13.
3. **Rule 19:** money = strings end-to-end; `CurrencyScale::bcround` ONLY at the GL-posting boundary; `bcformatStrict` elsewhere; scale ALWAYS `getScale($currency)` with explicit currency — the no-arg `GeneralLedgerService::scale()` helper is BANNED in code this phase touches; percent regexes 2dp (NOT currency-scaled); no literal bcmath scales (`ForbidHardcodedBcmathScale` fires on `bccomp` too — pass the resolved `$scale`).
4. **Rule 20 / console-safety (both plan-review lanes converged on this):** `ExpenseService::create()`/`update()` resolve the Company from explicit `$data['company_id']` — NO `CompanyContext` reads (Task 3). The W2 command passes `company_id` explicitly and MUST NOT bind CompanyContext. Console tests clear the context first.
5. Canonical error envelope for new endpoints; `\DomainException` → 422 for the service-level guards.
6. FE: post-sweep conventions — canonical atoms, `PageHeader`, design tokens only (`audit-design-system.mjs` **0 new** at every gate), `tenantScopedKey` on every `useQuery` key (`audit-tanstack-keys.mjs` green), i18n en+fr+ar (nested notification keys — `types → expense → recurring → generated`), no `any`, no `parseFloat`/`Number()` on money, `<MoneyInput>`/`<QuantityInput decimalPlaces={2}>` per plan.
7. **Deviations:** any departure from plan Rev 2 gets a dated entry in the progress file with justification. The plan's reconciliation log lists sanctioned deviations from spec Rev 2 (widening migration removed; scale+2 intermediates; console-safe create) — these are NOT deviations, they are the plan.
8. Commit per task (conventional commits). Update the progress file after every task: files touched, test counts, verification output, deviations.

## 3. Execution order + AUTONOMOUS AUDIT GATES (run them yourself via `claude -p`)

| Gate | After | Reviewer model + lanes | Scope reviewed |
|---|---|---|---|
| **GATE 1** | W1 (Tasks 1–6) | **Fable-tier treasury** (`--model claude-fable-5` — owner tiering: money path gets Fable directly, not as escalation) | VAT split posting math, tax-detail write, console-safe create, merged-value guards, supplier link, FE VAT block |
| **GATE 2** | W2 (Tasks 7–12) | **Opus** + tenancy-authz lane; Fable escalation ONLY on money-path BLOCKER/HIGH | recurrence schema/cursor/CRUD/permissions, generation command, notifications, forecast feeds, FE |
| **GATE 3** | W3 (Tasks 13–15) | **Opus** + frontend-conventions lane | analytics endpoint, streamed CSV, list/analytics FE, export blob pattern |
| **GATE 4** | W4 (Tasks 16–17) | **Final full-branch multi-lane** (see protocol below) | direction guards + whole-branch coherence, deploy docs, i18n/invalidation sweep |

**Gate protocol (identical mechanics every gate):**

1. Commit everything, run the §4 verification commands, then tag: `git tag phase4-gate-<N>-rc<attempt>`.
2. Run the review from the worktree root. **GATE 1** uses `--model claude-fable-5`; **GATES 2–3** use `--model claude-opus-4-8`:

```bash
claude -p --model <per-gate-model> \
  "ADVERSARIAL GATE REVIEW, Treasury Phase 4, GATE <N> (scope: waves per docs/handoff/CODEX-treasury-phase4-expense-depth-2026-07-13.md §3).
   Review ONLY the diff: git diff <previous-gate-tag-or-origin/dev>..HEAD.
   Binding references: docs/superpowers/specs/2026-07-13-treasury-phase4-expense-depth-design.md (Rev 2.1 — §5.3 posting contract incl. ⟦Rev 2.1⟧ corrections, §6 recurrence semantics, §8.5 grant table) and docs/superpowers/plans/2026-07-13-treasury-phase4-expense-depth.md (Rev 2 — task Interfaces are contracts; the three review files' 'Verified accurate' sections are ground truth).
   Verify against code with file:line citations; hunt: port edits (TreasuryMovementService byte-untouched — run git diff origin/dev..HEAD -- apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php and FAIL the gate if non-empty), unbalanced JE shapes (3 lines must sum to total at currency scale — off-grid inputs must 422 not 500), CompanyContext reads inside ExpenseService::create/update or the generation command (console-safety is a gate-failing regression), no-arg scale()/literal bcmath scales, float on money, deductible-vs-declared divergence (GL 4456 line MUST equal the document_tax_details tax_amount via the shared Shared\Domain\ExpenseVatSplit helper), settle()/linked-cost path edits, missing zero-VAT normalization, cursor math drift (origin-anchored no-overflow, no backfill on resume), idempotency-key/wasRecentlyCreated misuse (replay must not double-advance or re-notify), actor/tenant stamping on generated drafts, Spatie team id set to a COMPANY id anywhere (must be tenant), cross-company leaks in templates scan/analytics/export (two-companies-one-tenant tests present and green), permission grants diverging from spec §8.5 (BE seeder + FE usePermissions map BOTH), route-order (analytics/export above expenses/{id}), tenantScopedKey misuse or missing invalidation prefixes, missing/flat notification i18n keys, interpolation placeholder leakage, hardcoded color classes, spec/plan deviations not recorded in the progress file, test gaps vs the plan's pinned assertions.
   Write the full review to docs/handoff/gate-reviews-phase4/GATE-<N>-rc<attempt>.md with a final verdict line 'VERDICT: APPROVE' or 'VERDICT: CHANGES-REQUIRED' plus numbered findings with severity."
```

3. **Escalation rule (owner's tiering, gates 2–3):** re-run the SAME prompt with `--model claude-fable-5` ONLY when the Opus review (a) returns CHANGES-REQUIRED with any BLOCKER/HIGH finding on a money path (posting shapes, template `amount`/VAT trio flow into drafts, forecast monetary aggregation, export money cells, settle/reconcile interplay), or (b) says it is uncertain about a money-path behavior, or (c) you deviated from the plan on a money path (progress-file deviation entry exists for this wave). Fable 5 is for crucial financial-spine verification only — everything else stays on Opus.
4. **GATE 4 (final, full-branch — replaces the single-lane run):** run FOUR lanes over `git diff origin/dev..HEAD`, each with the step-2 prompt adapted to its lane scope, each writing its own file (`GATE-4-<lane>-rc<attempt>.md`):
   - treasury lane, `--model claude-fable-5` — W1+W4 money surfaces (posting, tax details, guards, reconcile);
   - treasury lane, `--model claude-opus-4-8` — W2+W3 (recurrence engine, analytics/export);
   - tenancy-authz lane, `--model claude-opus-4-8` — permissions/seeder/FE map, company scoping, team ids, cross-company tests;
   - frontend-conventions lane, `--model claude-opus-4-8` — tokens, atoms, i18n (en/fr/ar), tenantScopedKey, invalidations, blob export, RTL matrix.
   ALL FOUR must end `VERDICT: APPROVE`. A money-path BLOCKER/HIGH from any Opus lane escalates to Fable per rule 3.
5. CHANGES-REQUIRED → fix every finding (TDD — regression test first for each real defect), commit, bump `rc<attempt>`, re-run. Repeat until APPROVE. A finding you believe is WRONG: rebut it in the progress file with code evidence and include the rebuttal in the next review prompt — never silently ignore.
6. APPROVE → `git tag phase4-gate-<N>`, log verdict + review file path(s) in the progress file, continue to the next wave.

**The only STOP conditions** (report and halt): a gate fails 3 consecutive rc attempts on the same BLOCKER (design-level contradiction — human decision needed), or a FE-wave interlock precondition fails (below).

**FE-wave interlock precondition:** before Tasks 5, 12 and 15, `git fetch origin dev` and check `git log origin/dev -- apps/web/src/features/expenses apps/web/src/hooks/usePermissions.ts`. If `feat/design-system-unification` (gate 4 in progress) or the replenishment permission-map generator has merged and touched these files, rebase onto origin/dev before the FE task, re-verify the plan's line anchors for that task, and report any conflict rather than resolving silently. If the permission-map generator has landed, REGENERATE the FE permission map instead of hand-editing it (plan Task 9 note). Backend tasks: start immediately, no waiting.

## 4. Verification commands (run at every gate, paste output into the progress file)

```bash
cd apps/api
./vendor/bin/phpunit tests/Feature/Expense            # Gates 1,2,3,4
./vendor/bin/phpunit tests/Feature/Accounting         # Gates 1,2,4 (posting + forecast)
./vendor/bin/phpunit tests/Unit/Expense               # Gates 2,4 (cursor math)
./vendor/bin/phpunit tests/Feature/Treasury           # Gates 1,4 (reconcile + guards)
./vendor/bin/phpstan                                   # level 8, zero NEW errors
./vendor/bin/pint --dirty

cd ../web    # Gates 1 (Task 5), 2 (Task 12), 3, 4
pnpm typecheck && pnpm lint
pnpm vitest run src/features/expenses src/features/notifications src/hooks/usePermissions.test.ts
node tools/audit-design-system.mjs                    # 0 new
node tools/audit-tanstack-keys.mjs
```

Plus per-gate: Gate 1 — the reconcile run over the paid-VAT fixture green (`repository.gl_account_id` set to the purpose-resolved account so the AUTHORITATIVE branch is exercised) + VAT-less regression byte-identical + console-shape create test green; Gate 2 — replay/no-double-notify test + two-companies isolation + `php artisan schedule:list` shows `expenses:generate-recurring`; Gate 3 — leak tests on analytics AND export + full-set CSV (45 rows) + empty-window zero response; Gate 4 — all five guard-point tests + `HorizonQueueCoverageTest` by path + i18n completeness (en/fr/ar) for every new key.

## 5. Interlocks (do NOT touch)

- `TreasuryMovementService` port methods: read-only, byte-identical.
- Fiscal perimeter: never write `fiscal_events`, canonical bytes, or `pos_receipt_payments`.
- `ExpenseService::settle()` amount semantics + `createLinkedCostCapitalizationEntry` (landed-cost/WAC path): untouched.
- `banks` table / `BankPicker` internals → `feat/bank-reference-verification` owns them (using `PartnerPicker` is fine).
- `PayExpenseDialog.tsx` / `AdjustBalanceDialog.tsx`: shipped by ui-gaps + banks-tug polish; only touch if a plan task explicitly requires it (none does).
- Do NOT create a `document_tax_details` widening migration (plan-review F2: columns already 15,3; a new `down()` would fight `2026_03_23_100000_widen_missed_monetary_columns_to_scale_3.php`).
- REALIGNMENT-LOG: not required (all additive internal ERP endpoints).

## 6. Progress tracking

`docs/handoff/treasury-phase4-progress.md` in the worktree — update after every task: files touched, test counts, verification output, deviations-with-justification, contradictions found. Gate review files live in `docs/handoff/gate-reviews-phase4/` and are committed with the branch. This file travels with the branch and is the first thing each gate review reads.

## 7. End state

All 17 tasks committed on `feat/treasury-phase4-expense-depth`; tags `phase4-gate-1..4` present; progress file complete with four APPROVE verdicts (Gate 4 = four lane APPROVEs); `docs/handoff/treasury-phase4-deploy-checklist.md` written (spec §10 verbatim: tenants:migrate; perm reseed + `permission:cache-reset`; scheduler verify; VatDeductible-presence verification query; NO Horizon change) + `docs/handoff/HANDOFF-outbound-instruments-<date>.md` (spec §12 first bullet). Leave the worktree intact, do not merge or push to dev — report done. The Claude session runs the final orchestrated whole-branch review (Phase-③ pattern: `docs/superpowers/audits/2026-07-13-treasury-phase3-final-review.md`) and owns the merge.
