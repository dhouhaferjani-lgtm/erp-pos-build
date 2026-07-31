# Dispatch plan v3 — first-tenant launch program

**Date:** 2026-07-31. **Supersedes:** `DISPATCH-PLAN-v2-first-tenant-2026-07-31.md` (REJECTED round 2 —
`docs/superpowers/reviews/2026-07-31-codex-v2-dispatch-review.md`; all 8 findings adjudicated CONFIRMED).
**Status:** AWAITING ROUND-3 ADVERSARIAL REVIEW. Nothing dispatches until APPROVE.

Round-2 folds: D1 manifest expanded to reach the real terminal-creation topology (F1); ONE concrete
enablement policy stated and tested in both states (F2); Lane C spec brief gains the four missing
decision areas (F3); the production migration rehearsal gate joins Lane E (F4); A2's RED fixture is
redesigned to actually discriminate (F5); Lane E is now a closure contract, not an inventory (F6);
D2's preflight acceptance state is pinned (F7); Lane A retitled (F8).

## Owner rulings folded (2026-07-31, this session — completes handover §4)

- **Deptrac:** re-baseline `apps/api/deptrac.baseline.json` to 97 + ticket the 36 excess edges →
  new micro-lane **F** below. APPROVED.
- **B5 (mobile flagged-for-review): CLOSED** — review stays web-owned; mobile stays blind. No work.
- **erp-mobile push: ALREADY DONE** — `main` == `origin/main` == `codex/mobile-counting-hardening`
  @ `5a90341` (owner-verified). STRUCK from D2's owner checklist; handover §1 bullet is stale.
- Lane C = ONE lane spec-first and owner-manual items LAST: re-confirmed (matches §5b addendum).

Standing gate (unchanged): cash rounding stays DISABLED until Lane C lands or the owner signs
`cash-rounding-phase2-deploy-checklist.md:226`.

---

## Lane A — `SalesReportService::paymentMethodBreakdown` (2 defects + optional hygiene)

Worktree `../erp.report-truth`, branch `fix/pos-cash-report-truth` off `origin/dev`.

`apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:160-185`:
1. Sums `pos_receipt_payments.amount` = TENDERED cash; overstates by change given back once v3
   traffic starts. Subtract `change_due` — exactly once per receipt, only from the cash group
   (model: `ReportGenerationService.php:504-532` pre-aggregates per receipt in a subquery).
2. `COUNT(*)` at `:185` counts payment rows, not transactions. Fix to
   `COUNT(DISTINCT pos_receipts.id)` or equivalent.
3. Optional hygiene only (NOT a defect — round-1 refuted the case-variant claim).

**TDD fixture (F5):** the query groups by `payment_type` + method name, so a 1-cash + 1-card fixture
cannot discriminate (each group has one row). RED fixture = ONE receipt with **two cash payment rows
that land in the same cash report group** + one card row + `change_due > 0`. Assert: (a) change is
subtracted once, not once per cash row; (b) transaction count is 1, not 2; (c) the card group gets NO
change subtraction. Verify RED against the unfixed query first and state it in the report.

**WRITE MANIFEST:** `SalesReportService.php`; one test file under `apps/api/tests/Feature/Accounting/`
(new or existing report test). Nothing else.

Review gate: treasury-reviewer (Opus). Merge to LOCAL dev, stop.

## Lane B — signed-payload guards + inert ESLint guard

Worktree `../erp.payload-guards`, branch `fix/pos-payload-guards` off `origin/dev`.
Unchanged from v2 (manifest ruled sufficient in round 2):

- **B1** Pin the denomination cap tables to `CashRoundingCaps::CAPS`
  (`apps/api/app/Shared/Domain/CashRoundingCaps.php:40-44`, READ-ONLY). `readPhpNamedConst` parses
  only `public const` with lowercase string keys; `CAPS` is `private` with int keys → new small
  parser colocated with the drift test, without weakening the existing key-set pin.
- **B2** Real-SQLite round-trip through `insertOfflineReceipt` (29→32 placeholders) asserting three
  DISTINCT non-null rounding values land in the right columns; use the existing
  `sqliteTestAdapter.ts` harness. No mocks on the insert path.
- **B3** Assert the `appendZSessionCloseAndZReport` handoff — `tolerance_summary` now enters SIGNED
  Z bytes with real values. Extend `zReportService.cashRounding.test.ts`.
- **B4** `apps/pos/eslint.config.js`: spread `...cartMutatorSelectors` into the third
  `no-restricted-syntax` block (~`:332`). `--print-config` before/after;
  `cartMutatorGuard.eslint.test.ts` must go green. If un-inerting the guard exposes real cart-mutator
  violations, REPORT them — do not fix in this lane.

**WRITE MANIFEST:** `apps/pos/eslint.config.js`; test files only under
`apps/pos/src/lib/fiscal/__tests__/`, `apps/pos/src/lib/db/__tests__/`,
`apps/pos/src/lib/offline/__tests__/`; a parser helper colocated with the drift test. NO production
`src` code besides the eslint config. NO `apps/api`.

Review gate: fiscal-pos-reviewer (Opus). Merge to LOCAL dev, stop.

## Lane C — v3 correction chain (refund + void): SPEC PHASE ONLY

Worktree `../erp.refund-chain`, branch `feat/v3-refund-chain` off `origin/dev`.

Deliverable: `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md` answering, from code:

1. **Today's failure mode, precisely.** Trace `refundCheckoutStore.ts:357,379` → `/return` →
   `ReceiptReturnService` (legacy draft + legacy hashes, `:702`) → seal via
   `pos_terminals.last_hash/current_sequence` advance (`:469`, `ReceiptFinalizationService.php:90`)
   vs the device's own `fiscal_events` chain head. Name it: duplicate sequence? forked chain?
   quarantine on next device sale? survives? Write the missing integration test's shape:
   device-authored v3 sale → online return → next device sale → Z close at schema 3
   (`ReceiptReturnRefactorTest.php:490` pins v2 — gains a v3 twin, never edited in place).
2. **The integration design.** Who authors the refund fiscal event on a v3 terminal
   (device-authored vs server-authored with chain reconciliation), respecting: `receiptService.ts`
   is the only device SALE_RECEIPT authoring site; refund model = SALE_RECEIPT +
   `invoice_type_code=REFUND` (REFUND_RECEIPT enum vestigial); V1/V2/V3 payload builders are
   byte-immutable.
3. **The four decision areas round 2 proved missing (F3):**
   - **Offline/unsynced refunds:** the flow is explicitly ONLINE-ONLY and rejects unsynced
     originals (`refundSettlementService.ts:4,9`). Decide: does that remain launch policy (document
     + operator guidance), or must device-authored refunds work offline? State the ruling and its
     consequences either way.
   - **Atomicity & failure ordering:** device authors + force-syncs approval events, then posts
     `/return`, then mirrors into local Z accounting (`refundCheckoutStore.ts:331-391`). Define the
     state machine: "approval event authored, settlement rejected" and "settlement committed, local
     event/Z write crashes" — recovery, retry, and reconciliation for each state.
   - **VOID is the same unintegrated correction class:** `ReceiptVoidService.php:71,115` mutates the
     original receipt with no v3 correction event; `SALE_VOID`/`REFUND_RECEIPT` are reserved but
     unimplemented (`FiscalEventPayloadRegistry.ts:65,84`); the `/void` route is deliberately
     retained (`routes.php:137`). The spec MUST cover VOID: either integrate it in the same design
     or rule it out of launch scope with an explicit compensating control.
   - **Mixed-v2 / cross-terminal originals:** canonical REFUND/VOID requires the original fiscal-
     event UUID (`FiscalEventEngine.ts:367`); the projector documents that cross-terminal and legacy
     originals can be unresolved (`PosCoreReceiptProjection.php:605`) while its fail-closed guard
     throws on failed resolution (`:564`). Decide: prohibited, bridged via legacy reference, or a
     new lookup contract — and what the cashier sees in each case.
4. **E1 refund rounding ON TOP** (adopted pattern,
   `docs/superpowers/specs/2026-07-27-refund-rounding-research.md`): independent Swedish rounding of
   the cash payout, no unwinding, partials independent, VAT exact, delta → 6580/7580, own receipt
   line — including destination-specific mixed-tender rounding (only the CASH payout leg rounds) and
   whether refund adjustments enter the Z `cash_rounding_summary` (coordinate with B3's test
   surface — B owns that file until C's code phase).
5. **Constraints:** `hydrateFromReceipt.ts:53` never reads `receipt.total`;
   `ReceiptReturnService.php:1058-1084` caps returns by QUANTITY not amount; the avoir carries no
   rounding line (`buildReceiptData.ts:712-713`).
6. **Freeze the code-phase write manifest** in the spec (exact files, apps/api + apps/pos), stated
   against then-current dev.

**WRITE MANIFEST (spec phase):** the spec file only. Zero code.

Review gate on the spec: fiscal-pos-reviewer + treasury-reviewer (Opus) + Codex adversarial pass.
Then a code-phase plan with its own manifest. **C code phase may not begin while Lane B is unmerged.**

## Lane D1 — provisioning correctness (manifest expanded per F1/F2)

Worktree `../erp.first-tenant`, branch `feat/first-tenant-provisioning` off `origin/dev`.

**The single enablement policy (F2), stated once and tested in BOTH states:**
> A newly provisioned tenant gets: `country_payment_settings` row (rounding switches DISABLED — the
> seeder's contract, `CountryPaymentSettingsSeeder.php:22,57`), a valid CASH tender, and terminals
> that are **v3 from creation**. Cash-rounding enablement is a **deliberate, explicit operator
> step** executed at launch, AFTER the Lane C gate clears — never a provisioning default. The
> launch-contract test proves BOTH states: (a) fresh-provision state is coherent-disabled; (b) after
> executing the documented enable step, the policy resolver / device policy pull returns
> enabled + valid TN denomination (0.050). This composes the two owner rulings: "no mixed fleet,
> v3 from day 1" (terminals) and "rounding OFF until Lane C" (enablement sequencing).

Tasks:
1. Wire `CountryPaymentSettingsSeeder` into `DatabaseSeeder`, `CoffeeShopSeeder`,
   `ParapharmacySeeder`, `DemoPharmacySeeder` (today only `ProductionSeeder:31` +
   `TenantInitializationService`). ⚠️ `project_seeder_optional_company_container_trap`: seeders with
   `?Company $company = null` silently no-op under `tenants:run db:seed`. Note: `DemoPharmacySeeder`
   delegates first-run to `ParapharmacySeeder` but skips it on rerun (`DemoPharmacySeeder.php:341,372`)
   — both must be covered.
2. **Provision-at-v3 (F1) — the real topology:** registration creates NO terminal
   (`TenantProvisioningService.php:137-187`); terminals are born later via `TerminalController`
   creation paths (`:111`, `:387`, `:450`), ALL of which omit `fiscal_schema_version`, and the
   column default is 2 (`2026_05_01_000002_...php:14`). Implement BOTH:
   - a new tenant migration flipping the `pos_terminals.fiscal_schema_version` default to 3
     (self-guarding by construction: defaults affect only new rows; existing staging demo terminals
     keep their value — REQUIRED, since push to origin/dev auto-runs `tenants:migrate`);
   - explicit `fiscal_schema_version` handling on the `TerminalController` creation paths + their
     creation-path tests, so the contract is visible in code, not only in a column default.
   Do NOT create terminals from the Tenant module (cross-module model import is prohibited,
   CLAUDE.md rule 6); the launch contract asserts the real creation path instead.
3. **The enable step:** identify the existing operator path that flips `cash_rounding_enabled` +
   denomination (settings endpoint or artisan). If none exists as a single deliberate step, add a
   small artisan command (`pos:enable-cash-rounding {country} {denomination}` style). It is the
   subject of test-state (b).
4. **Launch-contract test** extending `TenantProvisioningServiceTest.php:72` (or a sibling):
   state (a) — fresh tenant has settings row, valid CASH tender (`is_cash_tender=true ⇒
   code='CASH'`), a terminal created via the real path is v3, `PosPaymentPolicyResolver` returns
   coherent DISABLED; state (b) — after the enable step, resolver + device policy pull return
   enabled + TN denomination. Also REPORT (don't silently flip) the intent of the POS-disabled main
   location at `TenantProvisioningService.php:161`, and document the enable-location step if
   intentional.

**WRITE MANIFEST:** the four seeder files; `TenantProvisioningService.php` ONLY if step 4's report
justifies it; `TenantProvisioningServiceTest.php` and/or a new sibling test under
`apps/api/tests/Feature/Identity/`; `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php`
+ its creation-path tests under `apps/api/tests/Feature/POS/`; ONE new tenant migration
(default flip); the enable artisan command (new file under the POS or Treasury module Console
namespace, per where `country_payment_settings` write-ownership lives) + its test. NO reporting
services, NO `apps/pos`, NO other migrations.

Review gate: tenancy-authz-reviewer + fiscal-pos-reviewer (both Opus — the migration touches the
fiscal terminal table). Merge to LOCAL dev, stop.

## Lane D2 — ops docs (single session, two deliverables, docs only)

Worktree `../erp.launch-ops`, branch `docs/launch-ops-runbook` off `origin/dev`.

1. **One ordered staging runbook** consolidating the 7 open checklists: treasury ③/④/⑤a/⑤b,
   multiloc, `RolesAndPermissionsSeeder` + `permission:cache-reset` (Spatie cache is TENANT-BLIND),
   Horizon restart, `DemoPharmacySeeder --force` rerun. Use the Task-8 artisan commands, never raw
   SQL. Mark what staging evidence shows already done. DO NOT execute anything against staging.
2. **Runbook placeholder burn-down:** fill every placeholder in
   `docs/pos-operations/{install,backup,support,walkthrough-rehearsal}.md` derivable from the repo;
   compile the rest into `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md` (the Lane E gate
   sheet — see Lane E for its required columns), alongside: secret rotation (12 pending rows),
   P0 real-device smoke, TN accountant/legal sign-off, the **production migration rehearsal gate
   (F4)**, and the E1 acceptance decision if Lane C descopes. ~~erp-mobile push~~ — DONE @ `5a90341`,
   do not list.

**Preflight acceptance state (F7), pinned:** `preflight-runbooks.sh` is EXPECTED to remain non-zero
after D2. D2's acceptance = remaining hits reduced to EXACTLY the rows enumerated in the owner gate
sheet (D2's report lists them 1:1 against the preflight output). Preflight-ZERO is a Lane E gate
row, closed only when the owner supplies the values (several exist only after the real-device
rehearsal).

**WRITE MANIFEST:** `docs/handoff/` (two new files), `docs/pos-operations/*.md`. Nothing outside `docs/`.

Review gate: one Opus doc-accuracy pass (citations vs code/checklists/preflight output). Merge to
LOCAL dev, stop.

## Lane E — the onboarding gate (closure contract, F4/F6)

Not dispatched to agents. **HARD RULE: tenant #1 CANNOT onboard until every applicable row below is
closed with evidence.** D2 materializes this table into
`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`; the orchestrator surfaces open rows at every
sync. Each row carries: named human owner, required evidence, evidence location, pass / risk-
acceptance criterion.

| Gate | Owner | Evidence → location | Pass / risk-acceptance |
|---|---|---|---|
| Secret rotation | owner | every row `revoked`; sign-off filled at `secret-rotation-2026-05-12.md:235` | all 17 revoked; NO risk-acceptance path for Critical/High |
| P0 real-device smoke | owner | completed checklist appended to `docs/qa/2026-05-13-first-tenant-handoff.md` (refund, Z, restore, ≥10 min offline w/ 5+ receipts, printer sleep/reprint/disconnect) | all steps pass on the real Windows terminal |
| **Production migration rehearsal (F4 — NEW)** | owner (release-eng hat) | staging-clone migration dry-run + irreversible-operation review + production backup checksum + fiscal-table row counts, recorded in `docs/qa/2026-05-12-migration-audit-and-rollback.md:94` boxes | both ⬜ boxes checked; NO-GO on any unreviewed irreversible migration |
| TN accountant/legal sign-off | owner | sign-off or explicit risk acceptance recorded per `2026-05-13-first-tenant-handoff.md:88` | signed, or a written, dated risk acceptance |
| Runbook preflight ZERO | owner (values) | `preflight-runbooks.sh` exit 0 after owner fills the gate-sheet rows | script green |
| Walkthrough rehearsal | owner + one operator | `walkthrough-rehearsal.md` record filled | rehearsal held on the release build |
| Cash-rounding enable decision | owner | Lane C landed, OR signed acceptance at `cash-rounding-phase2-deploy-checklist.md:226` | one of the two, in writing |

## Lane F — deptrac re-baseline (owner-approved 2026-07-31)

Dispatched AFTER Lanes A + D1 merge to LOCAL dev (so the baseline reflects the batch that faces the
dev→main PR). Model: Haiku. Branch `chore/deptrac-rebaseline` off then-current local dev.

**WRITE MANIFEST:** `apps/api/deptrac.baseline.json` (regenerate);
`docs/superpowers/tickets/2026-07-31-deptrac-36-edges.md` (new ticket). READ-ONLY: `deptrac.yaml`,
`tools/deptrac-ratchet.php`. If the regenerated count ≠ 97, that delta is a REVIEW FINDING against
whichever lane moved it — report, don't absorb.

---

## Fencing summary (disjoint by construction)

| Lane | apps/api | apps/pos | docs |
|---|---|---|---|
| A | `SalesReportService.php` + 1 Accounting test | — | — |
| B | — (reads `CashRoundingCaps.php`) | eslint.config.js + tests only | — |
| C (spec) | — | — | 1 spec file |
| D1 | 4 seeders, provisioning svc/test (conditional), `TerminalController.php` + POS creation tests, 1 migration, enable command + test | — | — |
| D2 | — | — | handoff (2 new) + pos-operations |
| F | `deptrac.baseline.json` | — | 1 ticket |

No file appears in two manifests (A's Accounting tests vs D1's Identity/POS tests are different
directories; D1's POS controller/tests are untouched by any other lane). B3's Z-test file is B's
until C's code phase, which declares its own manifest against then-current dev.

## Execution order

A, B, C(spec), D1, D2 dispatch in parallel on round-3 APPROVE. Merges to LOCAL dev as review gates
pass; F after A+D1 merge; then ONE batched ff promotion of A+B+D1+D2+F (D1's default-flip migration
is self-guarding; push auto-runs staging `tenants:migrate`). C's code phase follows its spec gate as
its own later batch. Lane E rows close in parallel throughout; the LAST gate before onboarding.

## What round 3 must answer

1. Does D1's expanded manifest now actually reach provision-at-v3 (default flip + creation paths +
   enable step), and is the two-state launch-contract test the right closure for F2?
2. Is Lane C's spec brief now complete enough that a passing spec cannot dodge a load-bearing
   decision (offline, atomicity, VOID, cross-terminal, mixed-tender destination, Z summary)?
3. Is the Lane E closure contract executable as written — owners, evidence, no-go criteria?
4. Does the A2 fixture now discriminate all three failure classes?
5. Any remaining blocker for tenant #1 that no lane covers?
