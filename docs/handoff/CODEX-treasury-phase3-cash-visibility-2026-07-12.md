# CODEX HANDOVER — Treasury Phase ③: Cash-Visibility Read Layer

> **Date:** 2026-07-12 · **Runner:** Codex desktop (CLI-brokered Codex cannot write to `apps/erp.*` worktrees). **AUTONOMOUS GATES:** you run every gate review yourself via `claude -p` — no human wait; the only human gates are dispatch (before you start) and the final whole-branch review + merge (after you finish, owned by the Claude session). Do NOT merge to dev or push to dev yourself under any circumstance.
> **Binding documents (read in this order before writing any code):**
> 1. Spec Rev 2 (+§15 + amendments A-1..A-3): `docs/superpowers/specs/2026-07-12-treasury-phase3-cash-visibility-design.md`
> 2. Plan Rev 2 (+its reconciliation log): `docs/superpowers/plans/2026-07-12-treasury-phase3-cash-visibility.md`
> 3. Both adversarial reviews (their "Verified accurate" sections are ground truth — do NOT re-derive): `docs/superpowers/specs/reviews/2026-07-12-treasury-phase3-adversarial-review.md`, `docs/superpowers/plans/reviews/2026-07-12-treasury-phase3-plan-adversarial-review.md`

## 0. Mission

Implement plan Rev 2's 18 tasks in wave order: **A** (inter-repo transfer — the money path; first production consumer of `TreasuryMovementService::transfer()`), **B** (notification center + treasury alert delivery), **C** (report direction/totals + cash-position flows), **D** (FE: transfer modal, bell/panel, report page, dashboard widget), **E** (Playwright E2E + deploy docs). Four autonomous gates.

## 1. Setup — prerequisites, in order

1. `git fetch origin dev` — base on the CURRENT origin/dev tip (it already contains treasury Phase ② + the phase2 follow-ups + the Phase-③ spec/plan docs).
2. `git worktree add ../erp.treasury-phase3 -b feat/treasury-phase3-cash-visibility origin/dev`
3. Create `docs/handoff/treasury-phase3-progress.md` (see §6) with a header entry noting the base commit.
4. Backend env: run tests BY PATH only; if the worktree symlinks `vendor`, remember symlinked vendor runs STALE main-repo code for changed classes (memory gotcha) — prefer a real `composer install` in the worktree.

## 2. Ground rules (non-negotiable — violations block merge)

1. **TDD per plan step** — the plan gives you the failing test first for every task; keep that order. PHPUnit by path (`./vendor/bin/phpunit tests/Feature/Treasury` / `tests/Feature/Notification`), Vitest by path. NEVER run the full PHPUnit or Vitest suite unattended (hung vitest worker pools OOM the machine — if a run hangs: `ps aux | grep 'node (vitest'` and kill the workers).
2. **The port is inviolate:** `TreasuryMovementService` public methods byte-untouched. If a test seems to require editing the port, the test is wrong or your code is — STOP and re-read spec §5 + plan-review F9. Same for the fiscal perimeter (`fiscal_events`, canonical bytes, `pos_receipt_payments`).
3. **Rule 19** (canonical decimal strings, `bcformatStrict`, explicit currency to scale resolution) and **rule 20** (console senders: team id = TENANT, registrar flush per tenant, no CompanyContext assumptions) exactly as the spec/plan pin them.
4. Canonical error envelope only for new endpoints (all three port exceptions already extend `DomainException` — verified; no rethrow shims).
5. FE: post-sweep conventions — canonical `Input/Select/Textarea/Button` atoms, `PageHeader`, design tokens only (`audit-design-system.mjs` **0 new** at every gate), `tenantScopedKey` on every `useQuery` key (`audit-tanstack-keys.mjs` green), i18n en+fr+ar for web (backend lang: en+fr only), no `any`, no `parseFloat`/`Number()` on money.
6. **Deviations:** any departure from plan Rev 2 gets a dated entry in the progress file with justification. One deviation entry is ALREADY mandated: spec amendment A-1 (sequential race-shape test substitution) — log it when you implement Task A4's replay tests.
7. Commit per task (conventional commits). Update the progress file after every task: files touched, test counts, verification output, deviations.

## 3. Execution order + AUTONOMOUS AUDIT GATES (run them yourself via `claude -p`)

| Gate | After | Scope reviewed |
|---|---|---|
| **GATE 1** | Wave A (Tasks A1–A5) | transfer money path: index, draft-JE factory, service, endpoint, reconcile pin |
| **GATE 2** | Waves B+C (B1–B4, C1–C2) | notification center + alert senders + report/cash-position additions |
| **GATE 3** | Wave D (D1–D5) | FE: permission map, transfer modal, bell/panel, report page, widget |
| **GATE 4** | Wave E (E1–E2) | E2E evidence + deploy docs + whole-branch coherence |

**Gate protocol (identical every gate):**

1. Commit everything, run the §4 verification commands, then tag: `git tag phase3-gate-<N>-rc<attempt>`.
2. Run the review from the worktree root (**Opus is the standard reviewer**):

```bash
claude -p --model claude-opus-4-8 \
  "ADVERSARIAL GATE REVIEW, Treasury Phase 3, GATE <N> (scope: waves per docs/handoff/CODEX-treasury-phase3-cash-visibility-2026-07-12.md §3).
   Review ONLY the diff: git diff <previous-gate-tag-or-origin/dev>..HEAD.
   Binding references: docs/superpowers/specs/2026-07-12-treasury-phase3-cash-visibility-design.md (Rev 2 — §5 transfer contract, §6.3 recipient resolution, §15 + amendments A-1..A-3) and docs/superpowers/plans/2026-07-12-treasury-phase3-cash-visibility.md (Rev 2 — task Interfaces are contracts; the plan-review 'Verified accurate' sections are ground truth).
   Verify against code with file:line citations; hunt: port edits (TreasuryMovementService must be byte-untouched — run git diff origin/dev..HEAD -- apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php and FAIL the gate if non-empty), orphan draft JEs on any failure path, movements without JEs outside the same-GL exemption, float on money, Spatie team id set to a COMPANY id anywhere (must be tenant), recipient queries missing the active-company-membership filter or the per-tenant registrar flush, maturity notifications sent with zero counts, cross-company notification leaks, currency-mixing in report totals or cash-position flows, tenantScopedKey misuse (queries) or missing raw-prefix invalidations (movements, notifications), missing i18n, permission-gating gaps (backend seeders + FE PERMISSIONS map BOTH), spec/plan deviations not recorded in the progress file, test gaps vs the plan's pinned assertions.
   Write the full review to docs/handoff/gate-reviews-phase3/GATE-<N>-rc<attempt>.md with a final verdict line 'VERDICT: APPROVE' or 'VERDICT: CHANGES-REQUIRED' plus numbered findings with severity."
```

3. **Escalation rule (owner's tiering):** re-run the SAME prompt with `--model claude-fable-5` ONLY when the Opus review (a) returns CHANGES-REQUIRED with any BLOCKER/HIGH finding on a money path (the transfer flow, GL posting shapes, port semantics, reconcile interplay, balance mutation, report/flows monetary aggregation), or (b) says it is uncertain about a money-path behavior, or (c) you deviated from the plan on a money path (progress-file deviation entry exists for this wave — the sanctioned A-1 substitution alone does NOT trigger escalation). Fable 5 is for crucial financial-spine verification only — everything else stays on Opus.
4. CHANGES-REQUIRED → fix every finding (TDD — regression test first for each real defect), commit, bump `rc<attempt>`, re-run step 2. Repeat until `VERDICT: APPROVE`. A finding you believe is WRONG: rebut it in the progress file with code evidence and include the rebuttal in the next review prompt — never silently ignore.
5. APPROVE → `git tag phase3-gate-<N>`, log verdict + review file path in the progress file, continue to the next wave.

**The only STOP conditions** (report and halt): a gate fails 3 consecutive rc attempts on the same BLOCKER (design-level contradiction — human decision needed), or a Wave-D interlock precondition fails (below).

**Wave D interlock precondition:** at Wave D start, `git fetch origin dev` and check `git log origin/dev -- apps/web/src/features/treasury/RepositoryDetailPage.tsx apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx`. Those two files belong to `feat/treasury-ui-gaps` — Phase ③ NEVER edits them regardless (the transfer button lives on `RepositoryListPage`). If `feat/treasury-ui-gaps` has merged and touched `RepositoryListPage.tsx` or `TopBar.tsx` (it should not — verify), rebase onto origin/dev before Wave D and report any conflict rather than resolving silently. Waves A–C are backend-only: start immediately, no waiting.

## 4. Verification commands (run at every gate, paste output into the progress file)

```bash
cd apps/api
./vendor/bin/phpunit tests/Feature/Treasury          # Gates 1,2,4
./vendor/bin/phpunit tests/Feature/Notification      # Gates 2,4 (new module suite)
./vendor/bin/phpstan                                  # level 8, zero NEW errors
./vendor/bin/pint --dirty

cd ../web    # Gates 3 and 4 only
pnpm typecheck && pnpm lint
pnpm vitest run src/features/treasury src/features/notifications src/features/finance src/hooks/usePermissions.test.ts
node tools/audit-design-system.mjs                    # 0 new
node tools/audit-tanstack-keys.mjs
```

Plus per-gate: Gate 1 — the A5 in-test `treasury:reconcile --tenant` run is green and the port-diff check is empty; Gate 2 — the two-company deny-direction recipient test + zero-count maturity test green; Gate 3 — bell badge hidden at count 0 + legacy-FQCN fallback render test green; Gate 4 — the full Task-E1 Playwright A→Z report with screenshots in `docs/sessions/treasury-phase3-e2e/`.

## 5. Interlocks (do NOT touch)

- `apps/web/src/features/treasury/RepositoryDetailPage.tsx` + `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx` → `feat/treasury-ui-gaps`.
- `banks` table / `BankPicker` → `feat/bank-reference-verification`.
- Fiscal perimeter: never write `fiscal_events`, canonical bytes, or `pos_receipt_payments`.
- `TreasuryMovementService` port methods: read-only, byte-identical.
- `getErrorMessage`/`ApiError` in `@/lib/api.ts`: the new endpoints emit the canonical envelope, so no local extractor workarounds are needed — and do NOT "fix" the shared utility as a side quest.
- REALIGNMENT-LOG: not required (all additive internal ERP endpoints — spec §14).

## 6. Progress tracking

`docs/handoff/treasury-phase3-progress.md` in the worktree — update after every task: files touched, test counts, verification output, deviations-with-justification (A-1 entry mandatory at Task A4), contradictions found. Gate review files live in `docs/handoff/gate-reviews-phase3/` and are committed with the branch. This file travels with the branch and is the first thing each gate review reads.

## 7. End state

All 18 tasks committed on `feat/treasury-phase3-cash-visibility`; tags `phase3-gate-1..4` present; progress file complete with the A-1 deviation entry and four APPROVE verdicts; `docs/handoff/treasury-phase3-deploy-checklist.md` written (tenants:migrate, perm reseed + `permission:cache-reset`, no chart reseed, staging smoke). Leave the worktree intact, do not merge or push to dev — report done. The Claude session runs the final orchestrated whole-branch review (Phase-② pattern) and owns the merge.
