# Treasury Phase ⑤b Gate 0 — payment-method repository routing

You are acting as both the **treasury-reviewer** and **fiscal-pos-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `76e80e664`

Review range: `git diff 76e80e664...HEAD`

This gate is routed directly to Fable by the operating contract because it changes a fiscal projection.

Read in authority order:

1. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md` (Rev 2), especially §6.2 routing.
2. The three plan reviews in `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-{codex,treasury,tenancy-authz}-review.md`, especially the replay-unstable routing finding.
3. Wave 0 / Task 1 in `docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md`.
4. `CLAUDE.md` rules 1–21 and `docs/architecture/precision-contract.md`.
5. `.claude/agents/treasury-reviewer.md` and `.claude/agents/fiscal-pos-reviewer.md`; apply both personas fully.
6. The entire committed diff and relevant pre-existing bridge/idempotency code.

## Required invariants to verify

1. The nullable `payment_methods.default_repository_id` FK is tenant-migration-safe and `nullOnDelete`; the model exposes fillable and relation support.
2. Payment-method create/update accepts and exposes the mapping while rejecting cross-tenant and cross-company repository IDs.
3. For a new tender, an active tenant+company mapped repository with a GL account wins; an absent/inactive/non-GL/foreign mapping falls back to the historical first GL-linked tenant+company repository ordered by stable ID.
4. The fiscal payload remains immutable and carries no repository routing metadata.
5. Replay stability is load-bearing: the existing per-leg `Payment` lookup occurs before current mapping resolution, and a replay uses the stored payment repository for the movement idempotency call. A mapping change must not create a movement in another repository or move history.
6. Projection execution and tests work with `CompanyContext` cleared and do not introduce float money, no-arg currency scale resolution, or a second money writer.
7. `treasury:configure-method-routing {--card-to=} {--dry-run}` is guarded, dry-run safe, idempotent, and resolves the repository independently inside each explicit tenant/company scope. It must not mutate historical payments or movements.
8. Existing unmapped behavior and bridge regression tests remain intact.

## Current committed evidence

- TDD RED: the new path initially failed with missing `default_repository_id` and missing `treasury:configure-method-routing`.
- Focused backend verification after implementation: 46 tests, 163 assertions:
  - `tests/Feature/Treasury/PaymentMethodRepositoryRoutingTest.php`
  - `tests/Feature/Treasury/PaymentMethodTest.php`
  - `tests/Feature/Treasury/PosBridgeSpineTest.php`
  - `tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php`
- Pint `--test` on every touched PHP file: pass.
- PHPStan level 8 on all touched production PHP files: no errors.

## Required output

After the first-line decision:

1. Findings ordered Critical/BLOCKER → Important/MAJOR → Minor, each with exact `file:line` evidence.
2. A table resolving all eight required invariants as pass/fail.
3. Test-quality and replay/idempotency assessment.
4. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and, if rejected, one exact required-fix line.
