# Dispatch plan v4 — first-tenant launch program

**Date:** 2026-07-31. **Supersedes:** `DISPATCH-PLAN-v3-first-tenant-2026-07-31.md` (REJECTED round 3 —
`docs/superpowers/reviews/2026-07-31-codex-v3-dispatch-review.md`).
**Status:** AWAITING ROUND-4 ADVERSARIAL REVIEW. Nothing dispatches until APPROVE.

Round-3 state: F1/F2/F3/F5/F7/F8 confirmed incorporated; Lanes A, B, C(spec), F ruled
manifest-sufficient and are UNCHANGED below except where cited. v4 folds the six required fixes:
correction-chain code made a non-waivable gate (R1); Lane E closure table repaired with named
humans, correct evidence locations, complete no-go criteria (R2); P0 smoke protocol repair added to
the docs lane's manifest (R3); D1 reuses the EXISTING `pos:configure-cash-rounding` command (R4);
target-tenant activation evidence gate added (R5); D2/E overlap resolved as two sequential phases of
one docs/ops lane with explicit ownership transfer (R6).

## Owner rulings folded (2026-07-31 — unchanged from v3)

Lane C = ONE lane spec-first; owner-manual gates LAST; deptrac re-baseline 97 + ticket 36 APPROVED
(lane F); B5 CLOSED (review web-owned); erp-mobile push DONE @ `5a90341`.

Standing gate, now stated precisely (R1): the signed acceptance blank at
`cash-rounding-phase2-deploy-checklist.md:224-229` accepts ONLY the `D/2` refund-payout rounding
discrepancy. It can waive Lane C's E1 refund-ROUNDING layer. It can NEVER waive correction-chain
integrity: gate E-7 below (v3 sale → return → next sale → Z proven) is non-waivable.

---

## Lane A — `SalesReportService::paymentMethodBreakdown` (2 defects + optional hygiene)

UNCHANGED from v3 (`v3` §Lane A — ruled sufficient; fixture discrimination confirmed in round 3 §2d).
Worktree `../erp.report-truth`, branch `fix/pos-cash-report-truth` off `origin/dev`.
- Defect 1: tendered-cash sum; subtract `change_due` exactly once per receipt, cash group only
  (per-receipt pre-aggregation model: `ReportGenerationService.php:504,514,518`).
- Defect 2: `COUNT(*)` → `COUNT(DISTINCT pos_receipts.id)`.
- RED fixture: ONE receipt, TWO cash rows in the same report group + one card row + `change_due > 0`;
  assert (a) change subtracted once, (b) count = 1, (c) card group unchanged. Verify RED first.

**WRITE MANIFEST:** `SalesReportService.php`; ONE test file:
`apps/api/tests/Feature/Accounting/SalesReportServicePaymentBreakdownTest.php` (new; or extend
`SalesReportServiceReturnsTest.php` — implementer picks one and says which). Nothing else.
Review gate: treasury-reviewer (Opus). Merge to LOCAL dev, stop.

## Lane B — signed-payload guards + inert ESLint guard

UNCHANGED from v3 (ruled sufficient). Worktree `../erp.payload-guards`, branch
`fix/pos-payload-guards` off `origin/dev`. B1 new PHP-const parser colocated with the drift test
(`CashRoundingCaps.php` READ-ONLY); B2 real-SQLite 32-column round-trip (3 distinct non-null values,
`sqliteTestAdapter.ts` harness); B3 `appendZSessionCloseAndZReport` signed `tolerance_summary`
assertion in `zReportService.cashRounding.test.ts`; B4 spread `...cartMutatorSelectors` into the
third `no-restricted-syntax` block (~`:332`), `--print-config` before/after,
`cartMutatorGuard.eslint.test.ts` goes green; newly exposed violations are REPORTED, not fixed.

**WRITE MANIFEST:** `apps/pos/eslint.config.js`; test files only under
`apps/pos/src/lib/fiscal/__tests__/`, `apps/pos/src/lib/db/__tests__/`,
`apps/pos/src/lib/offline/__tests__/`; parser helper colocated with the drift test. NO production
`src` besides the eslint config. NO `apps/api`.
Review gate: fiscal-pos-reviewer (Opus). Merge to LOCAL dev, stop.

## Lane C — v3 correction chain (refund + void): SPEC PHASE ONLY

UNCHANGED from v3 (round 3 §2b: brief ruled complete — a passing spec cannot dodge offline policy,
atomicity state machine, VOID, cross-terminal/mixed-v2 originals, mixed-tender destination, or the
Z-summary decision). Worktree `../erp.refund-chain`, branch `feat/v3-refund-chain` off `origin/dev`.
Deliverable: `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md` per v3 §Lane C items
1–6, including the frozen code-phase write manifest.

**WRITE MANIFEST (spec phase):** the spec file only. Zero code.
Review gate: fiscal-pos-reviewer + treasury-reviewer (Opus) + Codex adversarial pass. Code phase has
its own manifest and review; **may not begin while Lane B is unmerged**; its landing is gate E-7.

## Lane D1 — provisioning correctness (manifest corrected per R4)

Worktree `../erp.first-tenant`, branch `feat/first-tenant-provisioning` off `origin/dev`.

**Enablement policy (unchanged in substance, trigger reconciled per R4):** fresh tenant = settings
row with rounding DISABLED + valid CASH tender + terminals v3 from creation. Enablement is the
EXISTING operator command `pos:configure-cash-rounding` (country, denomination, enable, dry-run,
verify options — `ConfigureCashRoundingCommand.php:67-151`; tests
`ConfigureCashRoundingCommandTest.php:80`), executed target-tenant-scoped AFTER the correction gate
clears — where "correction gate" = **Lane C landed OR the signed E1 acceptance** (which waives only
refund-rounding delta; E-7 is never waived).

Tasks:
1. Seeder wiring — UNCHANGED from v3 (four demo seeders; `?Company` no-op trap;
   `DemoPharmacySeeder` rerun path `:341,372`).
2. Provision-at-v3 — UNCHANGED from v3: default-flip tenant migration + explicit
   `fiscal_schema_version = 3` in all THREE `TerminalController` creation paths (`:111`, `:387`,
   `:450`) + creation-path tests. No terminal creation from the Tenant module.
3. **Enable step (R4): REUSE, don't build.** `ConfigureCashRoundingCommand.php` and its test are
   READ-ONLY by default; they may be edited ONLY if the launch-contract test proves a real defect
   (justified in the report). Creating a second/duplicate command is FORBIDDEN.
4. Launch-contract test — two states, pinned end to end (round 3 §2a): state (a) raw DB default is
   3, all three creation paths yield v3, fresh resolver state is coherent-DISABLED
   (`PosPaymentPolicyResolver.php:43,125,169`); state (b) after `pos:configure-cash-rounding`
   scoped to the test tenant: resolver AND the HTTP policy endpoint
   (`PosPaymentPolicyController.php:28`) return enabled + denomination `0.050`. Also REPORT (don't
   flip) the POS-disabled main location intent (`TenantProvisioningService.php:161`).

**WRITE MANIFEST (exact files, R6):**
- `apps/api/database/seeders/DatabaseSeeder.php`, `CoffeeShopSeeder.php`, `ParapharmacySeeder.php`,
  `DemoPharmacySeeder.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php`
- NEW migration: `apps/api/database/migrations/tenant/2026_07_31_000001_default_pos_terminals_fiscal_schema_version_3.php`
  (default flip only; self-guarding by construction — defaults affect only new rows)
- NEW tests: `apps/api/tests/Feature/POS/TerminalCreationFiscalSchemaVersionTest.php`,
  `apps/api/tests/Feature/Identity/TenantLaunchContractTest.php`
- `apps/api/tests/Feature/Identity/TenantProvisioningServiceTest.php` (extend only)
- `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php` ONLY if the
  step-4 report justifies it
- READ-ONLY unless a defect is proven: `apps/api/app/Console/Commands/ConfigureCashRoundingCommand.php`,
  `apps/api/tests/Feature/POS/ConfigureCashRoundingCommandTest.php`
NO reporting services, NO `apps/pos`, NO other migrations, NO new commands.

Review gate: tenancy-authz-reviewer + fiscal-pos-reviewer (both Opus). Merge to LOCAL dev, stop.

## Lane D — docs/ops lane: TWO SEQUENTIAL PHASES, ONE MANIFEST (R3/R6; replaces v3's D2 + Lane E split)

Worktree `../erp.launch-ops`, branch `docs/launch-ops-runbook` off `origin/dev`.

### Phase D2-a — agent-dispatched authoring (this dispatch wave)

1. **Staging runbook** — UNCHANGED from v3: consolidate the 7 open checklists (treasury ③/④/⑤a/⑤b,
   multiloc, `RolesAndPermissionsSeeder` + `permission:cache-reset` [Spatie cache TENANT-BLIND],
   Horizon restart, `DemoPharmacySeeder --force`), Task-8 artisan commands only, mark what staging
   evidence shows done, EXECUTE NOTHING. File: `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md`.
2. **Runbook placeholder burn-down** — fill every repo-derivable placeholder in
   `docs/pos-operations/*.md`; remaining hits must map 1:1 to gate-sheet rows (preflight EXPECTED
   non-zero after D2-a; zero is gate E-5).
3. **P0 smoke protocol repair (R3)** — `docs/qa/2026-05-12-first-tenant-smoke.md`: replace the
   nonexistent `fiscal:verify-chain --vertical=tn` invocations with target-tenant invocations of the
   three REAL verifiers (`fiscal:verify-chains --company=…` [`VerifyFiscalChainsCommand.php:23`],
   `pos:verify-chains --company/--terminal/--type` [`VerifyPosChainCommand.php:35`],
   `fiscal:verify-event-chain --tenant/--terminal` [`VerifyEventChainCommand.php:70`], per
   `docs/runbooks/fiscal-verify-all-chains.md:28`); offline segment ≥10 minutes with 5+ receipts
   (aligns to `2026-05-13-first-tenant-handoff.md:79`); owner field filled (see E table); every
   step's Evidence + sign-off cells required for PASS; remove DRAFT once repaired.
4. **The gate sheet** — materialize the Lane E table below into
   `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`, verbatim columns: gate, named owner,
   evidence, evidence location, pass / no-go criteria, status.

**WRITE MANIFEST (phase D2-a):** `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` (new),
`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md` (new), `docs/pos-operations/*.md`,
`docs/qa/2026-05-12-first-tenant-smoke.md`. Nothing outside `docs/`.
Review gate: one Opus doc-accuracy pass (citations vs code — especially the three verifier
signatures — and vs checklists/preflight output). Merge to LOCAL dev, stop.

### Phase E — execution by named humans (sequenced LAST; ownership transfer per R6)

**On D2-a's merge, write-ownership of the gate sheet, `docs/qa/2026-05-12-first-tenant-smoke.md`,
`docs/qa/2026-05-12-migration-audit-and-rollback.md`, `docs/security/secret-rotation-2026-05-12.md`,
and `docs/pos-operations/*.md` TRANSFERS from the D lane to Phase E** (humans recording evidence; the
orchestrator may commit evidence on their behalf). No agent lane may touch these files after the
transfer. Phase E's write set is exactly those evidence files — this is the Lane E manifest round 3
found missing.

**HARD RULE: tenant #1 CANNOT onboard until every row is closed with evidence. E-7 is
non-waivable.** Named owner for every row: **Houssam (@otospexsolutions, admin@otospex.com)** — the
repo's release owner (`secret-rotation-2026-05-12.md:18`); where a second human is required the
owner names them IN the gate sheet before that row can begin (a blank name blocks the row).

| # | Gate | Evidence → location | Pass / NO-GO |
|---|---|---|---|
| E-1 | Secret rotation | Every credential row in the `secret-rotation-2026-05-12.md` inventory (§ rows at `:57-67` — enumerate rows, don't trust a count) flipped to `revoked`; connection-target decision recorded; Verification Evidence ledger entry per rotation (`:245+`); Final Sign-Off table filled (`:258+`) | PASS only when every inventory row is `revoked` + ledger + sign-off complete. NO risk-acceptance path for Critical/High. |
| E-2 | P0 real-device smoke | The REPAIRED `docs/qa/2026-05-12-first-tenant-smoke.md` executed on the real Windows terminal: every step's Status + Evidence cell filled, all three chain verifiers exit 0, offline ≥10 min / 5+ receipts, printer sleep/reprint/disconnect; role sign-offs at the protocol's sign-off table | PASS = every cell filled and every step green. Any failed step = NO-GO. |
| E-3 | Production migration rehearsal | Staging-clone dry-run + irreversible-operation review + production backup checksum + fiscal-table row counts, recorded in `2026-05-12-migration-audit-and-rollback.md:94` boxes | NO-GO on ANY of: failed dry-run step, unverifiable or mismatched backup checksum, fiscal row-count mismatch, unreviewed irreversible operation, exceeded downtime budget (`:80,:86`) |
| E-4 | TN accountant/legal sign-off | Sign-off appended to `docs/pos-operations/walkthrough-rehearsal.md` per `2026-05-13-first-tenant-handoff.md:100`, or a written dated risk acceptance in the same file | Signed, or explicit risk acceptance |
| E-5 | Runbook preflight ZERO | `preflight-runbooks.sh` exit 0; its full output pasted into the gate sheet row (persisted evidence) | Script green, output persisted |
| E-6 | Walkthrough rehearsal | `walkthrough-rehearsal.md` record complete on the release build | PASS only if EVERY explain-back row = YES, `Approved for go-live docs? = YES`, and every identified gap closed or explicitly accepted (`:15,:35`). "Rehearsal held" is NOT a pass. |
| E-7 | **v3 correction-chain closure (NON-WAIVABLE, R1)** | Lane C spec APPROVED + code phase landed through its review gates; green integration evidence: device v3 sale → online return → next device sale → Z close at schema 3 (test path named in the gate sheet); chosen VOID implementation or compensating control landed | The `D/2` acceptance blank waives ONLY refund-rounding delta. NOTHING waives E-7. |
| E-8 | **Target-tenant activation (R5)** | For the real tenant #1, recorded in the gate sheet before its first live transaction: main-location POS disposition decided + recorded; real terminal created/claimed at schema 3 (verify via `TerminalResource` policy pull); `pos:configure-cash-rounding` executed with explicit `--tenants=<target>` (after E-7 or the authorized rounding exception); device/API policy pull returns enabled + `0.050` (command + HTTP check per `cash-rounding-phase2-deploy-checklist.md:233,236`) | All four items evidenced for the actual tenant, not a test tenant |

## Lane F — deptrac re-baseline (owner-approved)

UNCHANGED from v3. After A + D1 merge to local dev; Haiku; branch `chore/deptrac-rebaseline`.
**WRITE MANIFEST:** `apps/api/deptrac.baseline.json`;
`docs/superpowers/tickets/2026-07-31-deptrac-36-edges.md`. Delta vs 97 = review finding against the
lane that moved it.

---

## Fencing summary (pairwise-disjoint re-audit on exact paths, R6)

| Lane | apps/api | apps/pos | docs |
|---|---|---|---|
| A | `SalesReportService.php`, `tests/Feature/Accounting/SalesReportServicePaymentBreakdownTest.php` (or `SalesReportServiceReturnsTest.php`) | — | — |
| B | — | `eslint.config.js`; tests under `lib/fiscal/__tests__/`, `lib/db/__tests__/`, `lib/offline/__tests__/` | — |
| C (spec) | — | — | `superpowers/specs/2026-07-31-v3-refund-chain-integration.md` |
| D1 | 4 seeders; `TerminalController.php`; migration `2026_07_31_000001_…`; `tests/Feature/POS/TerminalCreationFiscalSchemaVersionTest.php`; `tests/Feature/Identity/TenantLaunchContractTest.php` + `TenantProvisioningServiceTest.php`; (`TenantProvisioningService.php` conditional) | — | — |
| D2-a | — | — | `handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md`; `handoff/OWNER-manual-launch-gates-2026-07-31.md`; `pos-operations/*.md`; `qa/2026-05-12-first-tenant-smoke.md` |
| E (post-transfer) | — | — | the D2-a docs set + `qa/2026-05-12-migration-audit-and-rollback.md` + `security/secret-rotation-2026-05-12.md` (evidence writes only, AFTER D2-a merges) |
| F | `deptrac.baseline.json` | — | `superpowers/tickets/2026-07-31-deptrac-36-edges.md` |

Concurrent lanes (A, B, C-spec, D1, D2-a, later F) are pairwise disjoint on these exact paths. E
overlaps D2-a BY DESIGN as a sequential ownership transfer — never concurrent (E begins only after
D2-a merges). D1's Feature/POS test file is new and untouched by any other lane; A's Accounting test
directory is disjoint from D1's Identity/POS directories. B3's Z test is B's until C's code phase
declares its own manifest.

## Execution order

A, B, C(spec), D1, D2-a dispatch in parallel on round-4 APPROVE. Merges to LOCAL dev per review
gates; F after A + D1; ONE batched ff promotion of A+B+D1+D2-a+F (D1's migration self-guarding; push
auto-runs staging `tenants:migrate`). C's code phase = its own later batch behind its spec gate.
Phase E rows execute LAST (owner ruling), E-7/E-8 immediately before onboarding.

## What round 4 must answer

1. Is the D2-a → E sequential ownership transfer now a real fence (no concurrent writer on any
   shared file), and is E's write set complete for closing every row E-1…E-8?
2. Do the E-row pass/NO-GO criteria now match their repository sources exactly?
3. Is D1's corrected manifest (reuse `pos:configure-cash-rounding`, exact new filenames)
   dispatch-safe?
4. Any remaining blocker for tenant #1 that no lane or gate row covers?
