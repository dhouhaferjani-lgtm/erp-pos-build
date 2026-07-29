# POS cash rounding — Phase 2 (enable + device) checklist

Date: 2026-07-29
Scope: enabling cash rounding / POS tender tolerance for a tenant and rolling
out the POS build that authors SALE_RECEIPT v3.
Spec: `docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md` §7.
Server-half checklist (read first — this document assumes it is done): `docs/handoff/cash-rounding-phase1-deploy-checklist.md`.
Branch: `feat/pos-cash-rounding-device` (device half; server half shipped separately as Plan A).

## Hard preconditions (do not start otherwise)

- [ ] Phase 1 (server) is deployed to this environment and `tenants:migrate` completed for every tenant. This includes migrations `2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php` (adds `cash_rounding_enabled`, `cash_rounding_denomination`, `pos_tolerance_enabled` to `country_payment_settings`) and `2026_07_28_100200_add_cash_rounding_to_pos_receipts.php`.
- [ ] `accounting:backfill-tolerance-purposes` has been run (dry-run reviewed, then applied) for every tenant; every company resolves the `6580` (expense, `PaymentToleranceExpense`) and `7580` (income, `PaymentToleranceIncome`) accounts.
- [ ] Run `pos:configure-cash-rounding --verify` and **read the output, do not rely on the token alone.** The `CASH-ROUNDING VERIFY FAILURES: <n>` token counts only two failure classes: (1) a row with `cash_rounding_enabled = true` but a denomination the resolver would reject, and (2) a company with no active `is_cash_tender` payment method — a company failing (2) loses cash checkout the moment the device build ships. **The token does NOT count a missing `country_payment_settings` row as a failure** — a tenant with zero rows prints a warning (`No country_payment_settings rows exist in this tenant database.`) and still reports `FAILURES: 0` (`ConfigureCashRoundingCommand::verify()`, `apps/api/app/Console/Commands/ConfigureCashRoundingCommand.php:485-489`). Before the Enable step, that is exactly the state you are checking for, so **read the printed `country=… rounding=… denomination=…` line with your own eyes** for the target tenant/country and confirm the row exists at all, not just that the gate passed.
- [ ] Horizon / queue workers and the API processes have been restarted onto the Phase-1 release.

### Running these commands — read before pasting

Both `pos:configure-cash-rounding` and `accounting:backfill-tolerance-purposes` are
**tenant-DB-scoped and must run through `tenants:run`**. Neither command has a
`--tenant` flag of its own by design (the tenancy runner switches the DB
connection per tenant; a flag on the command itself would invite half-applied
state) — but `tenants:run` itself does: `--tenants=<uuid>` (repeatable),
**defaulting to ALL tenants in the environment when omitted**
(`vendor/stancl/tenancy/src/Commands/Run.php:24`: `{--tenants=* : The
tenant(s) to run the command for. Default: all}`). `tenants:run` forwards the
command's own flags only as repeatable `--option='k=v'` pairs — there is no
`--` passthrough — and boolean flags are passed as `=1`:

```bash
php artisan tenants:run pos:configure-cash-rounding --option='verify=1'
php artisan tenants:run pos:configure-cash-rounding --tenants='<tenant-uuid>' --option='country=TN' --option='denomination=0.0500'
php artisan tenants:run pos:configure-cash-rounding --tenants='<tenant-uuid>' --option='country=TN' --option='enable-rounding=1'
php artisan tenants:run accounting:backfill-tolerance-purposes --option='dry-run=1'
```

**`--verify` and the backfill's dry-run/apply are read-mostly and safe to run
fleet-wide** (no `--tenants=`) — that is the intended way to audit every
tenant in one pass. **Any MUTATING invocation — `enable-rounding`,
`disable-rounding`, `enable-tolerance`, `disable-tolerance`, or a
`denomination` write — MUST carry `--tenants=<tenant-uuid>` for the one
tenant being onboarded.** Without it, the command runs for **every tenant in
the environment** (the `Run.php:24` default), which would flip rounding on
for tenants never cut over and for companies your own `--verify` gate may
have already flagged as missing an `is_cash_tender` method — exactly the
"dead cash checkout" failure mode this checklist exists to prevent.

**The child exit code is NOT a gate.** `Run::handle()` calls the child command
and returns without inspecting its result (`Run.php:33-56`), so `tenants:run`
**always exits 0** regardless of what any tenant reported. Both commands
instead emit one stable summary token per tenant as the **last line of that
tenant's block**, preceded by a `Tenant: <uuid>` line
(`Run.php:36`) that both derives the tenant count AND lets you confirm you
scanned the right tenant. Gate on the token, never on `$?`:

```bash
php artisan tenants:run accounting:backfill-tolerance-purposes --option='dry-run=1' \
  | tee /tmp/tolerance-backfill-dry.log

# TENANT_COUNT is derived from the SAME log — tenants:run prints one
# "Tenant: <uuid>" line per tenant before that tenant's output block.
TENANT_COUNT=$(grep -c '^Tenant: ' /tmp/tolerance-backfill-dry.log)
echo "tenants seen: $TENANT_COUNT"
# Before trusting either gate below, confirm TENANT_COUNT is GREATER THAN
# ZERO and matches your tenant inventory. A log with zero "Tenant:" lines
# (e.g. the command name was mistyped, or --tenants matched nothing) makes
# TENANT_COUNT=0, and BOTH halves of the gate below then pass vacuously
# against an empty file — an empty log is not a clean run.

# (a) no tenant reported a failure — never `grep -q '… : 0'`, which passes as
#     soon as ANY ONE tenant is clean and lets "FAILURES: 3" through. NOTE:
#     the `|| echo` here PRINTS, it does not FAIL the shell — read the output,
#     or wire the `!`/`test` expressions into your own `set -e` harness.
! grep -qE 'TOLERANCE-PURPOSE BACKFILL FAILURES: [1-9]' /tmp/tolerance-backfill-dry.log \
  || echo 'GATE FAILED: a tenant reported backfill failures'
# (b) every tenant actually reported — token count must equal tenant count,
#     since an ABSENT token means the command aborted early and is a FAILURE:
test "$(grep -c 'TOLERANCE-PURPOSE BACKFILL FAILURES:' /tmp/tolerance-backfill-dry.log)" -eq "$TENANT_COUNT" \
  || echo 'GATE FAILED: a tenant produced no backfill token'
```

Apply the same two-part gate (plus the `TENANT_COUNT` derivation and the
vacuous-empty-log caveat) to `pos:configure-cash-rounding --verify`, whose
token is `CASH-ROUNDING VERIFY FAILURES: <n>`.

- [ ] Run the backfill dry-run, gate on the token pair above (with the `TENANT_COUNT` check), review the diff, then re-run without `--option='dry-run=1'` and gate again.
- [ ] Run `pos:configure-cash-rounding --option='verify=1'`, gate on `CASH-ROUNDING VERIFY FAILURES:` as above — but see the precondition bullet below for what the token does and does NOT cover.

## What changes WHEN — the BINARY vs the POLICY

Read this before scheduling either the build or the Enable step. They move
different things, and only one of them is gated by anything you configure.

> **Ledger semantics change with the BINARY. Rounding changes with the POLICY.**

The device authors `SALE_RECEIPT event_version = 3` **unconditionally**:
`FiscalEventPayloadRegistry.eventVersionFor('SALE_RECEIPT')` returns `3` with no
policy, terminal or `fiscal_schema_version` check
(`apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:171-173`), and
`receiptService` calls the v3 builder on every sale
(`apps/pos/src/lib/offline/receiptService.ts:357`). Server-side the ONLY
discriminator for the new semantics is the event's own version —
`CashRoundingCutover::applies($event->event_version)`, i.e. `>= 3`
(`apps/api/app/Shared/Domain/CashRoundingCutover.php:43`) — **not**
`pos_terminals.fiscal_schema_version`, and not any `country_payment_settings`
column.

So from the first synced sale of the new build, on every terminal carrying it,
**regardless of policy and regardless of cutover**:

- **Treasury `payments.amount` changes meaning on over-tendered cash sales:
  TENDERED → RETAINED.** `TreasuryReceiptBridge::computeNettedAmounts()` nets
  change off the cash legs above the cutover and hands back the payload amounts
  untouched below it
  (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:874-880`);
  a leg netted fully to zero is then SUPPRESSED — no Treasury payment row is
  written for it at all (`:1142-1145`). A 20.000 note against a 19.500 basket
  used to post 20.000; it now posts 19.500.
- **`pos_receipts` starts carrying `change_due` and `tolerance_writeoff`** (plus
  the two `cash_rounding_*` columns) — `PosCoreReceiptProjection.php:373-378`,
  written only under `$isV3`.
- **Expected-cash and shift-variance figures step down** by the change given
  back, because change stops being counted as cash in the drawer.

This is the intended fix for a pre-existing over-tender GL defect — Treasury was
booking money that went straight back out of the drawer — so the new figures are
the correct ones. Two things it is **not**:

- It is **not** a receipt-level drawer/variance change.
  `pos_receipt_payments.amount` deliberately stays TENDERED
  (`PosCoreReceiptProjection.php:827-834`, the "two-semantics rule"). Treasury's
  `payments.amount` and `pos_receipt_payments.amount` are DIFFERENT numbers by
  design; never reconcile the two columns directly.
- It is **not** rounding. No total moves until the policy is enabled — the
  device gate additionally requires `cash_rounding_enabled`, a usable
  denomination, a cash-only tender and `fiscal_schema_version === 3`
  (`apps/pos/src/lib/payment/checkoutPolicySnapshot.ts:163-169`).

**Operational consequence:** whoever reads Treasury cash figures sees a step
change on the day the **build** ships, not the day rounding is enabled. If you
stage the rollout those are two different dates, possibly weeks apart — brief on
the first, and do not let the second get blamed for the first.

## Terminal cutover verification (BEFORE enabling)

`pos_terminals.fiscal_schema_version >= 3` is a **pre-existing** cutover (the
device-authoritative shift / Z model), not a new rounding flag — but the device's
rounding gate reuses it, so it is a real gate on this rollout too:
`buildCheckoutPolicySnapshot` requires `fiscalSchemaVersion === 3` for BOTH
rounding and tender-tolerance auto-accept
(`apps/pos/src/lib/payment/checkoutPolicySnapshot.ts:168` and `:215`).

**A terminal still at v2 is a reachable, supported state on this build — do not
assume the fleet is already at 3.** The POS keeps its full legacy path:
`openShift` branches on `fiscal_schema_version === 3` and otherwise falls back to
the server-authoritative `POST /pos/shifts/open`
(`apps/pos/src/stores/terminalStore.ts:882` vs `:911-921`). The server's 409s run
the OTHER way round — `ShiftController.php:60` and `SyncController.php:53` reject
the REST shift paths for terminals `>= 3` (`SHIFT_DEVICE_AUTHORITY_REQUIRED`),
because those terminals author locally; nothing rejects a v2 terminal. So a v2
terminal can take the new build, author v3 receipts (with every consequence in
the section above) and never round a single total.

That is exactly what this section is here to catch. There is no packaged
**CLI/artisan** command for it — `FiscalSchemaCutoverService`
exposes only a per-terminal cutover (`POST /api/v1/pos/terminals/{terminal}/fiscal-schema-cutover`),
gated on no open shift, no un-Z-reported fiscalized receipts, and an empty
offline-sync queue for that terminal. There is a **read** path:
`GET /api/v1/pos/terminals` returns every terminal for the authenticated
company and `TerminalResource` includes `fiscal_schema_version` per row
(`apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:127`) —
usable per-company from an authenticated session. For a full-tenant sweep
across every company in one pass without needing a session per company, a
direct query against the tenant DB is faster:

- [ ] For the target tenant DB, list every terminal and confirm `fiscal_schema_version = 3`:
  ```sql
  SELECT id, code, fiscal_schema_version FROM pos_terminals WHERE fiscal_schema_version <> 3;
  ```
  An empty result means every terminal has cut over. Terminals still at 2 will NOT round (the device gate is fail-closed) — that is safe on its own, but it means two lanes charge different totals for the same basket once rounding is enabled tenant-wide.
- [ ] Cut over any stragglers via `POST /pos/terminals/{terminal}/fiscal-schema-cutover` (requires no open shift on that terminal, all its fiscalized receipts already Z-reported, and its offline-sync queue empty) and re-run the query above until it returns no rows.

## Enable

### 🔴 Hard precondition — refunds of rounded receipts

**Enabling rounding makes every refund of a rounded receipt wrong by exactly the
rounding adjustment.** Nothing on this branch fixes it and no code path stops
it, so this is a decision, not a bug report. Take the decision before the Enable
step, not after the first refund.

The mechanism, end to end:

- The device rebuilds the refund cart from the original receipt's stored `lines`
  JSON and never reads the receipt's `total`
  (`apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts:53`). Line amounts are
  pre-rounding — the adjustment lives on the RECEIPT, not on any line — so the
  refund is denominated in the EXACT total while the sale settled at the ROUNDED
  one.
- The server caps a return by **quantity, not by amount**:
  `validateReturnQuantities()` compares the requested quantity against
  `original quantity − already returned`
  (`apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1060-1082`).
  Nothing anywhere compares the money refunded against the money collected.
- The printed avoir carries no rounding line to explain the gap:
  `buildEscPosRefundReceiptData` hardcodes `cash_rounding_adjustment: null` and
  `has_cash_rounding: false` (`apps/pos/src/lib/buildReceiptData.ts:712-713`).

Concretely, TND at `D = 0.050`. It cuts **both ways** — the direction follows how
that receipt rounded:

| | round DOWN | round UP |
|---|---|---|
| Exact basket | 9.973 | 9.977 |
| Collected in cash (rounded) | **9.950** (adj −0.023) | **10.000** (adj +0.023) |
| Full refund paid out (sum of lines) | **9.973** | **9.977** |
| Net effect | customer is **up 0.023**; the refund exceeds the sale it reverses | customer is **down 0.023**; the customer is short-changed on their own return |

Per-refund exposure is bounded by `D/2` (0.025 at `D = 0.050`; 0.02 at EUR
`D = 0.05`) and applies to partial refunds of a rounded receipt too. The
round-UP row is the one that generates complaints at the counter, and the
cashier has nothing on the avoir to point at.

Do **not** run the Enable step until ONE of these is true:

- [ ] The refund-payout rounding track has landed and its own verification is
      green — `docs/superpowers/specs/2026-07-27-refund-rounding-research.md`
      (approved, NOT implemented as of this branch); **or**
- [ ] The owner has explicitly accepted a per-refund discrepancy of up to `D/2`
      in either direction on refunds of rounded receipts, with no ticket line
      explaining it. Record it — who accepted, when: ________________

- [ ] `php artisan tenants:run pos:configure-cash-rounding --tenants='<tenant-uuid>' --option='country=TN' --option='denomination=0.0500' --option='enable-rounding=1' --option='dry-run=1'` — review the diff (`[DRY-RUN] Country TN: would UPDATE/INSERT {...}`). **`--tenants='<tenant-uuid>'` is not optional** — omitting it runs the mutation against every tenant in the environment (`tenants:run`'s own default), not just the one being onboarded.
- [ ] Re-run the same command (same `--tenants=`) without `--option='dry-run=1'`.
- [ ] If POS tender tolerance is also going live for this tenant, repeat with `--option='enable-tolerance=1'` in a **separate** invocation (same `--tenants=`) — `--enable-rounding` and `--enable-tolerance` are independent switches on independent columns (`cash_rounding_enabled` and `pos_tolerance_enabled` on `country_payment_settings`), and neither one touches the B2B `payment_tolerance_enabled` column used by invoice write-offs.
- [ ] Confirm `GET /api/v1/pos/payment-policy` (sanctum + `X-Company-Id`) returns `cashRoundingEnabled: true` and `cashRoundingDenomination: "0.050"` for the target company — as a STRING at the currency's own scale (TND scale 3), with the trailing zero. A response of `0.05` or a JSON number means a float crept into the DTO; STOP and fix before any device rounds. If curling this directly, the fields are nested one level under `data` (`{"data": {"cashRoundingEnabled": ..., ...}}` — `PosPaymentPolicyController::show()`, `apps/api/app/Modules/POS/Presentation/Controllers/PosPaymentPolicyController.php:32`); the device's own client unwraps this automatically.

## Device rollout

- [ ] **Brief the store manager BEFORE the build ships — this step is not optional and does not wait for the cutover call.** Read "What changes WHEN — the BINARY vs the POLICY" above and brief its contents: the moment a terminal is on this build — **cut over or not, rounding enabled or not** — its expected-cash and shift-variance figures move, and Treasury's `payments.amount` changes from tendered to retained on over-tendered cash sales. Brief per terminal as its build ships, not per tenant as rounding is enabled — otherwise the first post-rollout EOD reads as an unexplained regression.
- [ ] Ship the POS build carrying SQLite schema **v64** and v3 authoring. (v63 adds the `payment_policy_cache` table, the three `offline_receipts` cash-rounding/tolerance columns, and `payment_methods.is_cash_tender`; v64 adds the durable per-shift `tolerance_auto_accepts(shift_id PK, accept_count, updated_at)` auto-accept budget — both are required, v64 is the final migration this track ships.)

The three queries below all run against the device's per-company SQLite file, `izipos-<companyId>.db` (`apps/pos/src/lib/db.ts:12`) — it runs in WAL mode, so a copy taken for inspection must also grab the `-wal` and `-shm` sidecar files next to it or the copy may be missing recently-committed rows.

- [ ] On first launch confirm the device applied through v64:
  ```sql
  SELECT version FROM _migrations ORDER BY version DESC LIMIT 1;
  ```
  must return **64**, not 63 — a device stuck at 63 has the policy cache and receipt columns but not the durable auto-accept budget table, so the §8.1 per-shift cap will not persist across a restart or operator switch.
- [ ] Confirm the device pulled the policy: `SELECT * FROM payment_policy_cache;` shows the tenant's company row with `cash_rounding_denomination = '0.050'` and `cash_rounding_enabled = 1`. **Note the scale change from the Enable step:** `--option='denomination=0.0500'` writes at the column's `decimal(15,4)` storage scale, but `PosPaymentPolicyResolver` re-serializes every money field at the **company currency scale** (TND = 3) before it ever reaches the wire (`PosPaymentPolicyDTO` docblock: "a float here re-serializes `0.050` as `0.05`"), and the device stores the API response verbatim (`paymentPolicyStore.ts` writes `response.cashRoundingDenomination` straight through, no rescaling). So `0.0500` in, `'0.050'` cached — a device row of `'0.0500'` would mean the pull never happened and this is stale seed data, not a healthy pull.
- [ ] Confirm `SELECT code, is_cash_tender FROM payment_methods;` marks exactly the `CASH` method with `is_cash_tender = 1`.

## Post-enable verification

- [ ] Ring a cash sale totalling 9.997 TND: the cash screen shows 10.000 due and a `Rounding 0.003` line (**no forced `+` sign** — the line is printed verbatim from `bcformat`, which shows `-` on a round-down and nothing on a round-up; do not treat a bare `0.003` as a missing sign); the printed ticket shows the same rounding line inside the totals block, between Tax and TOTAL (`receipt_template.rs:620-637`) — this rounding line is printed **unconditionally**, unlike the tolerance line below.
- [ ] **Only if `--enable-tolerance` was applied for this tenant** (tolerance is a separate switch from rounding — see Enable step; on a rounding-only tenant this step's shortfall correctly demands a manager PIN instead, which is not a bug): ring a cash sale totalling 9.973 TND tendered at 9.900. Expected: rounded due 9.950, shortfall 0.050 against the tender, which sits at the spec floor and is auto-accepted with NO manager PIN (**provided the shift's tender-tolerance auto-accept budget, 10 per shift, is not already exhausted** — if it is, the same shortfall correctly demands a PIN, which is also not a bug). The ticket shows the rounding line; the tolerance write-off line only prints when `show_payment_details` is on for the receipt template (it lives inside that visibility flag, unlike the rounding line above — `receipt_template.rs:669`, `:702-709`), and it always carries a forced `-` sign (a write-off is always in the customer's favour).
- [ ] Attempt a card-only over-tender: refused with the change-eligibility message.
- [ ] Ring a voucher-partial sale: the total is EXACT (no rounding line) — mixed tender with a voucher leg is out of the rounding gate's scope.
- [ ] Close the shift and open the End-of-Day preview. Confirm the two new rows next to the existing `ToleranceDrillDown` tolerance card:
  - **Auto-accepts used** — always rendered (never absent), reading `N / 10` for a shift that spent budget. If the preview is opened with no shift context (`tolerance_auto_accept_count === null`), it reads **`Unknown / 10`**, not `0 / 10` — a `0` here would misreport full headroom on a device whose fail-closed gate is treating that same null shift as fully spent.
  - **Net cash rounding** — this row is **ABSENT entirely** (not a zero row) on a shift where nothing rounded; it only appears when at least one receipt in the shift carries a rounding adjustment, showing the signed net total and the rounded-receipt count, e.g. `-0.020 (2)`.
- [ ] Close the shift: the Z pushes without a chain error, and (locally, in `report_data` only — `schema_version` stays 2) shows a `cash_rounding_summary` when any receipt in the shift rounded, regardless of how the shift was closed. **`tolerance_summary` is different: it is stamped only when the close supplied cash counts** (`reportData.tolerance_summary = toleranceSummary;` sits inside the `if (opts.cashCounts && opts.cashCounts.length > 0)` block, `apps/pos/src/lib/offline/zReportService.ts:381`, while `cash_rounding_summary` is stamped outside that block, unconditionally, at `:387-388`). A shift with real tolerance write-offs that was closed WITHOUT a cash count legitimately shows no `tolerance_summary` on the Z — that is not a bug, and the EOD preview's own "Auto-accepts used" row (checked above) is the figure to trust for that shift regardless of how it was closed.
- [ ] Server side: the receipts project with `cash_rounding_adjustment` populated, the GL carries `pos_cash_rounding` (or `pos_cash_rounding_refund` on a refund receipt) and `pos_tolerance_bridge` journal entries (`journal_entries.source_type`), and NO `pos.rounding.policy_mismatch` (`PosCoreReceiptProjection`) or `pos.change.exceeds_cash_legs` (`TreasuryReceiptBridge`) audit events were emitted.

## Kill switches

- [ ] `php artisan tenants:run pos:configure-cash-rounding --tenants='<tenant-uuid>' --option='country=TN' --option='disable-rounding=1'` turns rounding off for that tenant; `--option='disable-tolerance=1'` (same command, separate flag) turns POS auto-accept off. They are independent — one can be off while the other stays on — and NEITHER affects the B2B `payment_tolerance_enabled` column used by invoice write-offs. As with Enable, **omitting `--tenants=` disables it fleet-wide**, which may be what you want in a genuine multi-tenant incident but is very rarely what you want for a single tenant's rollback.
- [ ] A disable reaches each device on its next sync tick. Offline devices keep rounding against the last policy they cached in `payment_policy_cache`; those receipts remain verifiable forever because the denomination is inside the signed v3 bytes, and drift is caught server-side by the `pos.rounding.policy_mismatch` alert.

## Rollback

- [ ] **Device build only:** roll the POS build back to the previous version (pre-v64/v3): the device returns to v2 authoring immediately. The v63/v64 SQLite additions (`payment_policy_cache`, the three `offline_receipts` columns, `payment_methods.is_cash_tender`, `tolerance_auto_accepts`) are all additive and nullable/defaulted, so no data migration or teardown is needed on the DEVICE, and every v3 receipt already synced stays valid and verifiable forever.
- [ ] **🔴 A build rollback is NOT a clean revert of the ledger — it opens a MIXED WINDOW in Treasury.** The netting described in "What changes WHEN" is keyed on the EVENT's version, not on the build running now (`CashRoundingCutover::applies($event->event_version)`), and an already-signed v3 receipt keeps its version forever. After a rollback the same Treasury ledger therefore holds both:
  - **v3 receipts** (taken before the rollback — plus any still sitting in a device's offline queue when it happened) whose cash legs are **netted**: `payments.amount` = retained, with fully-netted legs absent from the table entirely; and
  - **v2 receipts** (taken after) whose cash legs are **tendered**: `payments.amount` includes change that went straight back out of the drawer.

  Nothing on the Treasury row says which it is — join back to `fiscal_events.event_version` on the source event to tell them apart, and treat any cash-in-Treasury total spanning the rollback moment as internally inconsistent until you have. Rolling FORWARD again does not repair the v2 rows: they are correct v2 rows, and re-netting them would be wrong.

  **Prefer the policy kill switch (above) to a build rollback.** `disable-rounding` / `disable-tolerance` stop rounding and auto-accept without changing which event version the device authors, so Treasury semantics stay uniform across the incident. Roll the build back only for a defect in the build itself.
- [ ] **🔴 Server-side migration rollback is a ONE-WAY DOOR the instant any tenant has taken a rounded sale — which is exactly the state this document's own Enable step puts a tenant into.** Do **NOT** run `php artisan migrate:rollback` (or the tenant equivalent) against `2026_07_28_100200_add_cash_rounding_to_pos_receipts.php` as a reflex incident response. Its `down()` re-adds the legacy `NOT VALID` totals identity and then **DROPS `pos_receipts.cash_rounding_adjustment`, destroying the values** (confirmed at `apps/api/database/migrations/tenant/2026_07_28_100200_add_cash_rounding_to_pos_receipts.php:243-275`). A rounded receipt is left as bare `total 9.950 / subtotal 9.973` residue satisfying neither identity, and fiscal immutability (`prevent_receipt_modification`) forbids deleting it to clean up. Re-applying `up()` afterwards succeeds but leaves `pos_receipts_totals` permanently `NOT VALID` for the historical rows. **Read Phase-1 §0.2 (`docs/handoff/cash-rounding-phase1-deploy-checklist.md`) in full before touching this migration on a tenant that is past the Enable step.** If it has already been rolled back: recovery is manual — backfill `pos_receipts.cash_rounding_adjustment` from each affected receipt's `canonical_bytes`, then run `ALTER TABLE pos_receipts VALIDATE CONSTRAINT pos_receipts_totals;`.
- [ ] **Open question — not proven either way.** Whether v3 fiscal events already queued on a device at the moment of a device-build rollback (i.e. authored under the new build, not yet synced) push cleanly against the server once that device is back on a v2 build was not verified as part of this task. Do not assume it is safe; treat any device rolled back with a non-empty offline-sync queue as needing a case-by-case check before it resumes syncing.

## Device smoke (test paths)

Run BY PATH only — never run the full suite (`pnpm test` can exhaust laptop memory).

```bash
cd apps/pos && pnpm vitest run \
  src/lib/db/__tests__/migrations.v63.test.ts \
  src/lib/db/repositories/__tests__/paymentRepository.isCashTender.test.ts \
  src/stores/__tests__/paymentPolicyStore.test.ts \
  src/lib/payment/__tests__/cashRounding.test.ts \
  src/lib/payment/__tests__/cartTotals.test.ts \
  src/lib/payment/__tests__/checkoutPolicySnapshot.test.ts \
  src/stores/__tests__/paymentStore.cashRounding.test.ts \
  src/stores/__tests__/paymentStore.changeEligibility.test.ts \
  src/lib/fiscal/payloads/__tests__/SaleReceiptV3Payload.test.ts \
  src/lib/fiscal/payloads/__tests__/SaleReceiptV1V2ByteStability.test.ts \
  src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts \
  src/lib/fiscal/__tests__/zReportHashService.legacyStability.test.ts \
  src/lib/offline/__tests__/receiptService.cashRounding.test.ts \
  src/lib/offline/__tests__/zReportService.cashRounding.test.ts \
  src/lib/offline/__tests__/voucherCheckout.integration.test.ts \
  src/lib/offline/__tests__/idempotencyRetry.integration.test.ts \
  src/components/pos/EndOfDayPreviewModal.test.tsx \
  src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx
```

The last two additions beyond the original device-track list matter for
different reasons: `voucherCheckout.integration.test.ts` and
`idempotencyRetry.integration.test.ts` exercise the real `FiscalEventEngine`
end-to-end against a real SQLite file — real payload building, real chain
sequencing, real writes (everything else in this list unit-tests a module in
isolation). Only the hash bytes are stubbed: both mock
`@/lib/fiscal/hashService`'s `computeFiscalHash` to a fixed string, so a change
that breaks receipt authoring under the real engine but not its unit tests
would still slip through without them — but neither suite is a substitute for
whatever separately pins the hash algorithm itself.
`src/components/pos/EndOfDayPreviewModal.test.tsx` is the Task 10 fix-round
modal suite — it is what actually pins the two new EOD rows (auto-accept
budget, net cash rounding) described above.
`src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx`
pins the split-tender screen against the store's gate in both directions, AND
(final-review Finding 3) that the amount PREFILLED into the numpad is a tender
the cashier can physically hand over — a rounded due on a cash tender, the exact
total on a card/voucher one.

Note: `src/__tests__/integration/offlineFirstFlow.test.ts` mocks
`@/lib/fiscal/instance` and therefore does NOT exercise the real fiscal
engine — it is not a substitute for the two integration suites above and is
intentionally excluded from this list.

**Result at HEAD (final fix wave, 2026-07-29):** 18 files, 284 tests, all passed.

### Known pre-existing failures (NOT regressions from this track)

Two suites outside the device-smoke list above fail at HEAD independent of
this branch — do not mistake either for a regression introduced by cash
rounding:

- `src/lib/sync/__tests__/syncService.test.ts` — 2 of 61 tests fail
  (`pullProducts > processes deleted_ids from the tombstone response` and
  `runFullSync > runs push then pull and returns combined results`). Both
  tests are byte-identical in this branch's base commit (`0763bf8bf`) and
  untouched by any commit in this track; the branch only ADDED tests to this
  file (the `pullPaymentPolicy` describe block), it never edited the two
  failing ones.
- `src/components/pos/__tests__/ReportsMenu.test.tsx` — fails to COLLECT (0
  tests run): `No "initReactI18next" export is defined on the "react-i18next"
  mock` — a mock-hoisting problem in that suite's `vi.mock('react-i18next')`,
  unrelated to cash rounding.

## Quality gates

- [ ] `cd apps/pos && pnpm typecheck` — clean (0 errors) at HEAD.
- [ ] `cd apps/pos && pnpm lint` — clean (0 errors; pre-existing warnings unrelated to this track are expected and not a gate).

---

## Done criteria

- [ ] All 11 tasks committed on `feat/pos-cash-rounding-device`.
- [ ] `cd apps/pos && pnpm typecheck && pnpm lint` clean.
- [ ] The device smoke list above is green.
- [ ] `pnpm vitest run src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` proves the TS v3 key set byte-mirrors the PHP named const at 30 sorted keys.
- [ ] The `zReportHashService.legacyStability` snapshot is UNCHANGED from the value frozen (commit `424a47ef6`, before `normalizeForHash` was touched) — verify with `git diff 424a47ef6 HEAD -- apps/pos/src/lib/fiscal/__tests__/__snapshots__/zReportHashService.legacyStability.test.ts.snap` returning no output.
- [ ] `git diff --stat 0763bf8bf..HEAD -- apps/api` shows no output — no `apps/api` file touched anywhere in this track.
- [ ] Adversarial review dispatched (`fiscal-pos-reviewer` for Tasks 4/6/8/9/10, `frontend-conventions-reviewer` for Tasks 5/6/7) before the branch is merged into local `dev`.
