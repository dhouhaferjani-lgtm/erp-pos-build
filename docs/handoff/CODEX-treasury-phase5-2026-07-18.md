# CODEX DISPATCH BRIEF — Treasury Phase ⑤ (outbound instruments + bank reconciliation), long-running autonomous build

> **Date:** 2026-07-18 · **Controller:** Claude (Fable) session, owner: Houssam
> **Mission:** implement plan ⑤a fully, then plan ⑤b, in long-running sessions, self-verifying with severity-tiered adversarial review gates (Opus default, Fable on critical gates — owner pre-authorized 2026-07-18), Playwright e2e, until CONFIRMED working. The controller then independently verifies and merges. **Use Codex Desktop** (CLI-brokered Codex cannot write to `apps/erp.*` worktrees).

## Authority chain (read in this order before writing any code)

1. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md` — spec **Rev 2**. The law.
2. The three pre-dispatch plan reviews in `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-{codex,treasury,tenancy-authz}-review.md` — every finding is folded into the plans' Rev 2; if plan text seems to contradict a finding, the finding + spec win; stop and flag.
3. The two plans, in order: `docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md` → `…phase5b-bank-statement-reconciliation.md`. Every task = TDD checkbox steps; tick them in the file as you complete them (commit plan-file updates alongside code).
4. The spec-level review `docs/superpowers/reviews/2026-07-18-treasury-phase5-spec-codex-review.md` (context for WHY the invariants exist).
5. Repo law: `apps/erp/CLAUDE.md` (rules 1–21), `docs/conventions/*`, `docs/architecture/precision-contract.md`.

## Workspace & branch contract

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
# The spec/plans live on feat/treasury-phase5-design (worktree ../erp.treasury-phase5, based on local dev e948ccaaf).
# Build ON that worktree, new child branch:
cd ../erp.treasury-phase5 && git switch -c feat/treasury-phase5 && composer install --working-dir=apps/api
```
- ALL work in `../erp.treasury-phase5` on `feat/treasury-phase5`. **NEVER push to `origin/dev`** (push = staging auto-deploy incl. `tenants:migrate`). Never force-push. Push `feat/treasury-phase5` to origin as backup after each gate.
- Tag each passed gate: `t5a-gate-1..4`, `t5b-gate-0..5`.
- Worktree gotchas (memory-verified): symlinked vendor runs stale code — real `composer install`; `php artisan typescript:transform` needs `CACHE_STORE=array`; backend tests BY PATH only; **NEVER the full PHPUnit suite** (crashes the machine).

## Execution order

| Stage | Plan waves | Gate (reviewer lanes) |
|---|---|---|
| 1 | ⑤a Wave 1 (purposes, seeds, backfill, idempotency store) | t5a-gate-1: treasury (Opus) |
| 2 | ⑤a Wave 2 (GL builders, validator, state machine) ∥ ⑤b Waves 0-1 allowed in parallel ONLY in a separate session/sub-branch (routing, schema, parsers) | t5a-gate-2: treasury (Opus) · t5b-gate-0: treasury + fiscal-pos (**Fable** — fiscal projection) · t5b-gate-1: treasury (Opus) |
| 3 | ⑤a Wave 3 (deferred-supplier issue fix, endpoints, reconcile) | t5a-gate-3: treasury (**Fable** — live double-post fix in PaymentController) + tenancy-authz (Opus) |
| 4 | ⑤a Wave 4 (expense instrument mode, FE) → **⑤a exit** | t5a-gate-4: treasury + frontend-conventions (Opus); exit = whole-⑤a treasury pass (Opus) |
| 5 | ⑤b Wave 2 (import flow — serialize AFTER ⑤a Task 7: shared routes.php + seeder) | t5b-gate-2: treasury + tenancy-authz (Opus) |
| 6 | ⑤b Wave 3 (matching engine — requires ⑤a Tasks 3-5; Task 7 after ⑤a Task 9) | t5b-gate-3: treasury (**Fable** — the financial heart) |
| 7 | ⑤b Wave 4 (completion + movement-port checkpoint guard) | t5b-gate-4: treasury (**Fable** — shared port change) |
| 8 | ⑤b Wave 5 (UI, legacy cutover, E2E) → **⑤b exit** | t5b-gate-5: treasury + frontend-conventions (Opus); exit = whole-branch review (**Fable**) |

## Adversarial gates — severity-tiered, automatic

Headless via Claude Code CLI from the worktree; write each gate request to `.gates/gate-<id>-request.md` (reviewer persona copied from `.claude/agents/<reviewer>.md`, branch diff scope `git diff <base>...HEAD -- <paths>`, the plan file, demand APPROVE/REJECT with file:line evidence), then:

```bash
claude -p "$(cat .gates/gate-<id>-request.md)" --model claude-opus-4-8  > .gates/gate-<id>-verdict.md   # default
claude -p "$(cat .gates/gate-<id>-request.md)" --model claude-fable-5   > .gates/gate-<id>-verdict.md   # per matrix above
```

Commit `.gates/` — the audit trail. **Escalate any gate to Fable when:** (a) you believe a BLOCKER is wrong (dispute); (b) two consecutive REJECTs on one gate (deadlock); (c) the matrix marks it Fable. A REJECT is never argued away in-session: fix, or escalate. Never weaken a test to pass a gate.

## Non-negotiable verification (every wave, before its gate)

1. TDD per plan step; by-path test runs; Postgres for aggregate/locking tests (the race tests REQUIRE two real pgsql connections).
2. `./vendor/bin/phpstan` (L8, zero NEW errors on touched paths), `./vendor/bin/pint --test`, `pnpm lint && pnpm typecheck` for FE waves.
3. `CACHE_STORE=array php artisan typescript:transform` after any DTO change.
4. Playwright e2e per plan Gates sections against the local stack (`reference_local_db_per_tenant_demo_launch.md`: API :8010, owner@pharmabio.tn/password) with seeded treasury data; the ⑤b exit runs the full §9 statement flow.
5. End-to-end confirmation (rule 5): a task is done when the critical path is DRIVEN and OBSERVED (a real deferred-supplier cheque issued and cleared; a real statement imported, matched, completed; the checkpoint actually rejecting a backdated write) — never when it merely compiles.

## Hard constraints (violations = stop and flag, do not improvise)

- **Matching is metadata, never money.** No GL/movement writes from allocation code paths; only execution-recorded actions post, atomically with their allocations.
- **Idempotency before GL, replay before transition-validation** (⑤a `instrument_events` action keys + semantic digests; ⑤b `match_executions`); exact replay returns the original result; digest mismatch throws.
- **The deferred-supplier fix (⑤a Task 6) must suppress `createSupplierPaymentJournalEntry` at `PaymentController:975-996`** for `$isDeferredSupplier` — the issue JE REPLACES it. The test asserting exactly ONE JE and zero bank-account lines is the gate's centerpiece.
- **Signed-sum convention** (⑤b Global Constraints) and **movement-row locking in stable ID order** everywhere per-movement totals are read (empty allocation sets lock nothing — lock the movements).
- Checkpoint guard lives in **both `record()` and `transfer()`**, inclusive of the `period_end` date; new `recorded_behind_checkpoint` field — never repurpose `recorded_while_frozen`.
- Routes INSIDE the existing `Presentation/routes.php` group (`EnforceTokenTenantClaim` inherited); `{instrument}`/`{bankStatement}` route-model binding; `ScopedExists::tenant` on every FK input; grants `admin`+`accountant` (`reopen`/`cancel-outbound` tighter per plans).
- Module boundaries: Treasury never writes Expense models — `InstrumentCleared`/`InstrumentCancelled` events + Expense listener.
- Rules 19/20 everywhere: bcmath strings, injected scale resolver with explicit currency, no CompanyContext in projections/queue paths.
- Migration order is load-bearing: ⑤b profiles → statements → lines → allocations → executions; all migrations idempotent + self-guarding.
- **Two merge gates enforced at exit, before ANY dev merge:** (1) expert-comptable confirmation of the `403`/`4035` codes (build on provisional codes is fine; merge is not); (2) the multi-location §3 package landed on dev (⑤b location contract dependency).

## Deliverables (both exits passed)

1. `feat/treasury-phase5` pushed to origin (NOT dev), all gate tags, `.gates/` audit trail.
2. Deploy checklists `docs/handoff/treasury-phase5a-deploy-checklist.md` + `…5b…` (migrations count, chart backfill command, routing config command, perm reseed + `permission:cache-reset` per tenant, ordering; stacking on the owed ③/④ checklists).
3. Both plan files fully checkbox-ticked; `HANDBACK-treasury-phase5.md`: what shipped, deviations (each with its gate approval), known follow-ups, exact controller verify commands.
4. **STOP.** The controller session takes over: independent verification, merge to local dev, batched promotion to origin/dev.

## If blocked

Do not improvise around a hard constraint, a failing gate, or a missing prerequisite (e.g. multi-location §3 not landed when ⑤b exit arrives). Write the blocker into `HANDBACK-treasury-phase5.md` with the exact state, park the branch pushed-to-origin, and stop. The controller resumes.
