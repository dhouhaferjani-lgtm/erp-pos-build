# Gate t5b-gate-5 — Treasury Phase ⑤b Wave 5 treasury review

You are reviewing `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5` on branch `feat/treasury-phase5` at HEAD.

## Reviewer persona

Act as the adversarial **treasury-reviewer**. Verify every claim from code and committed evidence. Cite `file:line` for each finding. Severity is Critical / Important / Minor; any Critical or Important finding means REJECT. You gate only: do not edit, commit, merge, or push.

Treasury truths:

- Matching allocations are metadata and never write money. Only execution actions may create GL/movements, atomically with their allocation/provenance.
- Money is numeric-string plus bcmath at explicit currency scale. No float/`Number()` money paths.
- Completion must count `matched`, `resolved_by_creation`, and `ignored` as resolved, but ignored money is excluded from remaining/unmatched totals and included only through the signed ignored-total acknowledgment.
- Tier 1 confirms an existing movement; Tier 3 executes outbound clear; Tier 4 allocates gross fiscal movement(s) and creates the configured fee movement exactly once.
- Statement completion stamps the end-of-period repository checkpoint. Same-date interactive movement writes must be rejected without partial GL/state mutation.
- Legacy bank-reconciliation routes and UI must be removed/repointed without leaving a money-writing bypass.
- Tenant/company scoping, route-group middleware, and permission gates remain mandatory.

## Authority and scope

Read before judging:

1. `docs/handoff/CODEX-treasury-phase5-2026-07-18.md`
2. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`, especially §§5–6 and §9
3. the three plan reviews in `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-*.md`
4. `docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md`, Tasks 10–13 and Gate 5
5. `CLAUDE.md`, especially rules 1–6, 13, and 19–21

Review the Wave 5 range:

```bash
git diff t5b-gate-4..HEAD -- \
  apps/api/app/Modules/Treasury \
  apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php \
  apps/api/app/Shared/Contracts/Fiscal/PaymentMethodResolver.php \
  apps/api/tests/Feature/Treasury \
  apps/web/src/features/treasury/statements \
  apps/web/src/features/treasury/BankReconciliationPage.tsx \
  apps/web/src/features/treasury/api/reconciliation.ts \
  apps/web/src/features/treasury/hooks/useReconciliation.ts \
  apps/web/src/features/finance/pages/FinanceHubPage.tsx \
  apps/web/src/components/organisms/Sidebar/Sidebar.tsx \
  apps/web/src/routes \
  apps/web/src/locales \
  apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts \
  docs/sessions/treasury-phase5b-e2e \
  docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md
```

Gate focus:

1. Trace list/upload/confirm/reconciliation UI contracts to backend resources and mutations. Check statuses, signed amounts, action params, provenance, ignored totals, and completion gating.
2. Verify `resolved_by_creation` is modeled across API types, filters, translations, badges, completion count, and zero-remaining math without concealing unresolved money.
3. Verify the legacy cutover removes all old backend surfaces (including summary and mutations), deletes stale clients/types/tests, and repoints FinanceHub/sidebar/routes to `/treasury/statements`.
4. Audit the live smoke for false greens: it must use real public HTTP APIs and browser interactions; ingest a valid device fiscal event; create a real outbound instrument; exercise Tiers 1/3/4, create-from-line, ignore+acknowledge, complete, public checkpoint observation, rejected same-date adjustment and clear, and out-of-band reconcile evidence.
5. Check the E2E's decimal helpers and CSV values for exact signed-sum consistency: accepted non-ignored `+15.000 -37.125 +98.500 -2.500 = +73.875`, ignored `-1.250`, statement delta `+72.625`.
6. Confirm tests do not mock the subject under test or weaken required behavior.
7. Verify the Gate 0 pre-cutover follow-up at HEAD: payment-method resolution is scoped by tenant + company + code in the shared port, both fiscal projection consumers pass `event.company_id`, and the repeated-CARD-code regression proves the correct company's method is selected without mutating canonical fiscal payloads.

Fresh evidence (verify independently; backend tests by path only, never full PHPUnit):

- Prior live Playwright smoke: 6/6 passed in 45.0s against the db-per-tenant stack; this repair commit strengthens the assertions, so rerun the smoke before approval.
- Final `treasury:reconcile`: checked 7 repositories; froze 0; 0 portfolio drifts; 0 statement alerts; 0 errors.
- Focused statement Vitest after the repair: 8 files / 32 tests passed, including page-level completion-control coverage. Web typecheck passed. Targeted ESLint: 0 errors. React Doctor changed-scope is 92/100 with one pre-existing `StatementUploadWizard` useReducer warning; no new critical finding was introduced by this repair.
- Fresh combined cutover/matching/completion/checkpoint/tenant-isolation backend paths: 95 tests / 301 assertions. Focused frontend cutover paths: 86 tests passed.
- Fresh full web lint completed with exit 0, 0 errors, 0 new tenant-key/design-system findings, and custom rule tests green (repository-wide acknowledged warnings remain).
- Gate 0 follow-up: resolver RED was 2 failures; focused resolver became 5/5, all projection/routing regressions became 53/53 (206 assertions), and targeted PHPStan/Pint passed.

## Required output

First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`.

Then findings ordered by severity with exact `file:line` evidence, an invariant checklist, and a test-evidence assessment. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one line stating what must be fixed before the ⑤b exit review.
