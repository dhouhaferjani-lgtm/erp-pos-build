I completed a full round-3 pass: read the brief's M1 section and the spec's wave-1 contents, re-verified both prior registers' repairs by running the code, and hunted fresh. One new P1 surfaced.

---

# M1 / Wave 1 — adversarial merge-gate register (round 3)

Range reviewed: `7d85232cc54abd6a6b2135f476205ab434e71a66..e72a1abb5` (16 commits). Repairs under review this round: `6baf6adfc` (Phase 1.1.15) + `82ddc74b0`/`e72a1abb5` (register/YAML records).

Lenses: **frontend-conventions** ✔ applied · **tenancy-authz** ✔ applied · **treasury** ✔ applied (currency + no-aggregate half; this wave has no GL, payment or partial-write path, so that half of the lens does not apply) · **general** ✔ applied.

## Round-2 finding — verification of the repair

| Round-2 finding | Status | Evidence I ran |
|---|---|---|
| **P2-1** — missing `tests/Feature/Compliance/` route-gate coverage for A-1/OP-23, undisclosed in the handback | **CLOSED — CONFIRMED** | `apps/api/tests/Feature/Compliance/ReceiptReportingRouteGateTest.php:1-84` now runs the real `RolesAndPermissionsSeeder`, asserts the seeded **`accountant`** reaches all three `compliance/nf525/*` endpoints **and** both fraud reads, and that a `settings.view`-only role is 403 on all five. `CACHE_STORE=array ./vendor/bin/phpunit tests/Feature/Compliance/ReceiptReportingRouteGateTest.php` → `OK (2 tests, 10 assertions)`. The FE half is now a real mount, not a grep: `apps/web/src/routes/ComplianceRoutePermissions.test.tsx:1-81` renders `AppRoutes` under `MemoryRouter` and asserts each of the three routes mounts on its exact permission and redirects a `settings.view`-only user. `pnpm vitest run src/routes/…` → `19 passed`. |
| P3-4 (NG-5 forward comment) | **CLOSED** | `apps/api/app/Modules/POS/routes.php:144-145`, `apps/web/src/routes/index.tsx:2918`. |
| P3-5 (`filter-options` never run on PG) | **CLOSED** | `ReceiptFilterOptionsTest` added to the pgsql merge-gate filter, `.github/workflows/ci.yml:629`. |
| P3-7 / P3-8 / P3-9 | **CLOSED** | `ReceiptIndexTrainingExclusionTest.php:142-148` (true `min:1` path), `ReceiptAuthorizationTest.php:26-33` (seeded `accountant` role, not an ad-hoc grant), `ReceiptFilterOptionsTest.php:34-42,65` (cross-company terminal excluded). |

No regression from the repair: `ReceiptReturnFlowTest` → `OK (20 tests, 115 assertions)`; the ten new POS classes → `OK (47 tests, 236 assertions)`; `./vendor/bin/phpstan analyse app/Modules/POS` → `[OK] No errors` (269 files).

## P1 — blocks

**1. An archived (soft-deleted) terminal makes `GET /pos/receipts` return HTTP 500 for every window containing its receipts. `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:193`. CONFIRMED — reproduced.**

`->with(['location:id,name', 'terminal:id,code'])` (`:79`) eager-loads through `Receipt::terminal()` (`Receipt.php:309-312`), a plain `belongsTo`. `Terminal` uses `SoftDeletes` (`app/Modules/POS/Domain/Terminal.php:19,75`), so the eager-load applies the soft-delete scope and the relation resolves to **null** for an archived terminal. Line `:193` then does `terminal_code: $receipt->terminal->code` with no guard.

Reproduced against the real stack (probe file written to `/tmp`, nothing in the repo touched): create one SALE receipt, `$this->terminal->delete()` — which is verbatim what `TerminalController::archive()` does (`TerminalController.php:191` `$terminal->delete(); // soft delete`) — then `GET /api/v1/pos/receipts`:

```
STATUS=500
ErrorException: Attempt to read property "code" on null in
  …/app/Modules/POS/Presentation/Controllers/ReceiptController.php:193
#5 …/ReceiptController.php(176): Illuminate\Support\Collection->map(Object(Closure))
```

**This branch introduced it.** The base implementation was null-safe — `git show 7d85232cc:…/ReceiptController.php` renders `'terminal_code' => $receipt->terminal->code ?? ''`. The `??` was dropped when `ReceiptListItemData::$terminal_code` was typed non-nullable `string`; round-1 P1-2's PHPStan item (*"Using nullsafe property access on non-nullable type Location"*) pushed the guards out, and PHPStan cannot see the soft-delete scope, so level 8 stays green on the broken code.

Failure scenario, entirely data-driven and permanent: a tenant replaces a POS terminal and archives the old one — the supported path, since `destroy()` explicitly refuses a hard delete while receipts exist (`TerminalController.php:210-215` `TERMINAL_HAS_HISTORY … Use archive instead`). From that moment `/pos/receipts` 500s for any date window containing that terminal's receipts. It is not recoverable from the UI, and it is *more* likely on this feature than elsewhere, because S-7 deliberately publishes archived terminals as filter options (`ReceiptFilterOptionsController.php:42` uses `Terminal::withTrashed()`, per the spec's frozen contract *"Return all terminals in scope regardless of `is_active`/archived state"*, `SPEC…:474`) — so the UI actively offers the value that detonates the list.

No test covers an archived terminal on the index; `ReceiptReportingTestCase::createReceipt()` always uses a live terminal.

Round 1 checked the adjacent hazard and cleared it on the wrong basis (*"all three are NOT NULL in `create_pos_receipts_table.php`… Safe"`*) — column nullability is not relation resolution under a soft-delete scope. `location` is genuinely safe: `Location` has no `SoftDeletes` (`grep -c SoftDeletes app/Modules/Company/Domain/Location.php` → `0`) and its FK is `cascadeOnDelete`.

To close: resolve the relation with `withTrashed()` on the eager-load (or restore a defensive fallback), and add the red-first case — archive the terminal, assert `200` and the archived terminal's `code` still renders on its historical rows.

## P3 — note (may ship with a ticket)

2. **Playwright flow 1 and the M1 screenshots still have not run** (`apps/web/e2e/pos/receipts-permissions.spec.ts` compiles; the local API was not up at `127.0.0.1:8010`). Honestly disclosed in the handback §Verification and in the YAML `findings:`, exactly as brief §7 requires of an environment-blocked stage. It remains the only live proof of the A-1 nav → `/settings/compliance/export` path as a real accountant. Unchanged from round 2; not closable by the executor.

3. **The exact preflight invocation still never completes as one command** — it stops at repository-wide Pint drift on `tests/Feature/POS/ZReportListTest.php`, genuinely outside the lane and correctly untouched. Every downstream stage passes when run individually; I re-confirmed PHPStan, the new PHPUnit set, scoped ESLint (`0 errors`, 7 pre-existing warnings on untouched lines), the permission-map hash (recomputed `sha256:8546d743…` = the committed header) and the generated-type block. Baseline ticket, not a lane defect.

4. **Three scoped vitest failures persist and are all pre-existing.** `pnpm vitest run src/features/pos src/components/organisms/Sidebar src/routes` → `3 failed | 540 passed`. I verified attribution rather than taking the handback's word: `useAnalytics.tenantScope.test.tsx` and `reportPages.tenantScope.test.tsx` fail on a `{ locScope }` segment their expectations lack, and `POSPage.test.tsx` on a quantity assertion — `git diff --name-only 7d85232cc..HEAD -- apps/web/src/features/pos/hooks apps/web/src/features/pos/pages apps/web/src/lib/locationScopedKey.ts` returns **only** `ReceiptListPage*`, so none of the three subjects or their helpers is touched by this branch.

5. **The legacy `receipt_type` axis still bypasses the code-array bound on the training toggle.** `ReceiptController.php:119` now yields precedence to `invoice_type_codes` (good, and locked by `ReceiptIndexTypeFilterTest.php:47-58`), but `?receipt_type=sale&include_training=true` alone still takes the legacy branch and reaches `:150` with the predicate dropped and no array bounding the set. Back-compat surface only — no screen sends `receipt_type` (FT-16 asserts the payload). Unchanged severity from round 2.

6. **BT-1's `['SALE'] + include_training=true` arm is vacuous.** `ReceiptIndexTrainingExclusionTest::test_include_training_is_a_byte_identical_no_op_for_non_training_code_sets` (`:102-120`) seeds only SALE/REFUND/VOID, so for `codes=['SALE']` there is no TRAINING row that *could* leak — the spec's "the toggle alone widens nothing" claim (`SPEC…:670`) is asserted against a fixture that cannot falsify it. The six mixed non-TRAINING cases r5 added are otherwise covered correctly, including the byte-identical twin assertion.

## Bypasses I tried that FAILED (no defect found)

- **A cross-receipt aggregate / new queue** (Addendum A(c) rule 2, OI-3, rule 20): `git diff -U0 … | grep -inE '^\+.*(SUM\s*\(|\.reduce\(|onQueue\(|dispatch\()'` over `apps/api` + `apps/web` → **zero hits**.
- **Rule 19 sweep over added production lines**: `grep -E 'app\(|parseFloat|Number\(|: any|<any>|toFixed|\(float\)'` over `+` lines in `apps/api/app`, `apps/api/database`, `apps/web/src` → **zero hits**. Money is `CurrencyScale::bcformatStrict((string) $receipt->total, $this->currencyScaleResolver->getScale($receipt->currency))` at the **receipt's** currency with an injected resolver (`ReceiptController.php:177,196`; constructor `:55`). I chased `bcformatStrict` throwing on a scale-3 value under a scale-2 currency — it truncates via `bcadd` and only throws on non-numeric (`CurrencyScale.php:130-145`), so no 500 path there.
- **A company-currency leak on the FE**: `formatCurrency(receipt.total, { currency: receipt.currency })` (`ReceiptListPage.tsx:170`) through `lib/format.ts` — the only implementation that does not default to `'EUR'`; it never parses the string as a float (`format.ts:118-135`). Backend twin asserted with a differing-currency fixture (`ReceiptIndexEnvelopeTest.php:33,61-62,76-77`: company `EUR`, receipt `TND`, emits `TND` / `12.345`).
- **Permission-map and generated-type drift** (hard preflight failure): recomputed the generated map's own hash from its body — matches the committed header byte-for-byte; the map delta is exactly the three accountant keys and nothing else; `packages/shared/types/generated.d.ts` contains the three new DTOs verbatim.
- **A seeder blast radius beyond A-2**: the diff adds exactly `deliveries.view`, `pos.view_receipts`, `pos.view_reports` inside the `accountant` block (`RolesAndPermissionsSeeder.php:796-797`); no other role array is touched; F-1's precondition (keys absent on base) holds.
- **GATE-5 mis-keying**: every POS child matches the frozen §4.2.1 table row-for-row, including `tables → pos.manage_tables` (the r3/N-3 trap) and `vouchers` deliberately left on the `pos` alias; all six identity keys plus the composite `compliance` key are present in `MODULE_PERMISSIONS` (`usePermissions.ts:57-63`), so no sidebar `permission:` on this branch can fail open.
- **CL-7 closed on a false premise**: verified the claim in code before accepting it — `PosAnalyticsService.php:38-40,70,111,139-141,174,203-204,337` all route through `netOfReturns()`/`netOfReturnsQualified()`, `GrandtotalService.php:174,230-237` branches on `ReceiptType::Return` and uses the `-ABS(...)` CASE, and `ReportGenerationService::buildExpectedPerMethod` exists at `:514`. CL-7 is in wave-1 scope (`SPEC…:642,655`), so the ticket edit is not scope creep.
- **A null-FK 500 on `location_name`**: `Location` has no `SoftDeletes` and its FK cascades — the dereference at `:191` cannot go null. (The terminal twin does — P1-1.)
- **`receipt_type` NULL poisoning the SALE-arm predicate** (`where('receipt_type','!=','return')` would silently drop NULL rows): the column is `NOT NULL DEFAULT 'sale'` (`2026_03_09_200000_add_return_fields_to_pos_receipts.php:24`). Safe.
- **Migration safety under auto `tenants:migrate`**: `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS`, no `CONCURRENTLY` — additive and unattended-safe.
- **Route middleware (rule 12)**: `filter-options` is registered before `/pos/receipts/{id}` inside the group carrying `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` (`POS/routes.php:41,144-146`).
- **A shared-component regression from the two seams**: `DataTable.getRowClassName` and `EmptyState.action` are both optional and additive with unchanged default rendering.
- **i18n parity**: scripted flat-key diff of `en/fr` `pos.json` → identical key sets, zero en-only/fr-only; the three new `common.json` keys (`navigation.posReceipts`, `navigation.complianceExport`, `notAvailable`) exist in both, with the spec's frozen labels ("POS Receipts" / "Tickets de caisse").
- **A conditional-hook or scope violation on the new page**: all hooks precede the two early returns; the register lives in a separate component; query keys go through `locationScopedKey` → `tenantScopedKey` (`lib/locationScopedKey.ts:16`).

Nothing else in the wave-1 scope (S-1/S-2/S-3/S-6/S-7/S-9/S-11 index+options/S-13, screen (a), A-1 + nav + OP-23, GATE-3, GATE-5, A-2, CL-1/2/5/6/7, i18n rename, BT-1…BT-5/BT-8…BT-12/BT-16/BT-18, FT-1…FT-3/FT-10/FT-11/FT-14…FT-16) showed a defect on this round. The round-2 P2 is genuinely closed; M1 fails this round on the newly-found P1 alone.

VERDICT: CHANGES-REQUIRED
