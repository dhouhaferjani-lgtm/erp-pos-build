# POS cash rounding — Phase 2 (enable + device) checklist

Date: 2026-07-29
Scope: enabling cash rounding / POS tender tolerance for a tenant and rolling
out the POS build that authors SALE_RECEIPT v3.
Spec: `docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md` §7.
Branch: `feat/pos-cash-rounding-device` (device half; server half shipped separately as Plan A).

## Hard preconditions (do not start otherwise)

- [ ] Phase 1 (server) is deployed to this environment and `tenants:migrate` completed for every tenant. This includes migrations `2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php` (adds `cash_rounding_enabled`, `cash_rounding_denomination`, `pos_tolerance_enabled` to `country_payment_settings`) and `2026_07_28_100200_add_cash_rounding_to_pos_receipts.php`.
- [ ] `accounting:backfill-tolerance-purposes` has been run (dry-run reviewed, then applied) for every tenant; every company resolves the `6580` (expense, `PaymentToleranceExpense`) and `7580` (income, `PaymentToleranceIncome`) accounts.
- [ ] `pos:configure-cash-rounding --verify` reports, per tenant: the `country_payment_settings` row exists with the intended values, AND every company has at least one active `is_cash_tender` payment method. A company failing the second assertion loses cash checkout the moment the device build ships.
- [ ] Horizon / queue workers and the API processes have been restarted onto the Phase-1 release.

### Running these commands — read before pasting

Both `pos:configure-cash-rounding` and `accounting:backfill-tolerance-purposes` are
**tenant-DB-scoped and must run through `tenants:run`**. Neither command has a
`--tenant` flag by design (the tenancy runner switches the DB connection per
tenant; a flag would invite half-applied state). `tenants:run` forwards flags
only as repeatable `--option='k=v'` pairs — there is no `--` passthrough — and
boolean flags are passed as `=1`:

```bash
php artisan tenants:run pos:configure-cash-rounding --option='verify=1'
php artisan tenants:run pos:configure-cash-rounding --option='country=TN' --option='denomination=0.0500'
php artisan tenants:run pos:configure-cash-rounding --option='country=TN' --option='enable-rounding=1'
php artisan tenants:run accounting:backfill-tolerance-purposes --option='dry-run=1'
```

**The child exit code is NOT a gate.** `tenants:run` swallows it and always
exits 0. Both commands instead emit one stable summary token per tenant as
their last line, and the checklist must grep for the token — never trust the
shell `$?`:

```bash
php artisan tenants:run accounting:backfill-tolerance-purposes --option='dry-run=1' \
  | tee /tmp/tolerance-backfill-dry.log
# (a) no tenant reported a failure — never `grep -q '… : 0'`, which passes as
#     soon as ANY ONE tenant is clean and lets "FAILURES: 3" through:
! grep -qE 'TOLERANCE-PURPOSE BACKFILL FAILURES: [1-9]' /tmp/tolerance-backfill-dry.log
# (b) every tenant actually reported — token count must equal tenant count,
#     since an ABSENT token means the command aborted early and is a FAILURE:
test "$(grep -c 'TOLERANCE-PURPOSE BACKFILL FAILURES:' /tmp/tolerance-backfill-dry.log)" -eq "$TENANT_COUNT"
```

Apply the same two-part gate to `pos:configure-cash-rounding --verify`, whose
token is `CASH-ROUNDING VERIFY FAILURES: <n>`.

- [ ] Run the backfill dry-run, gate on the token pair above, review the diff, then re-run without `--option='dry-run=1'` and gate again.
- [ ] Run `pos:configure-cash-rounding --option='verify=1'`, gate on `CASH-ROUNDING VERIFY FAILURES:` as above.

## Terminal cutover verification (BEFORE enabling)

There is no packaged bulk-listing command for this — `FiscalSchemaCutoverService`
exposes only a per-terminal cutover (`POST /api/v1/pos/terminals/{terminal}/fiscal-schema-cutover`),
gated on no open shift, no un-Z-reported fiscalized receipts, and an empty
offline-sync queue for that terminal. Confirming the fleet is at v3 is a direct
query against the tenant DB:

- [ ] For the target tenant DB, list every terminal and confirm `fiscal_schema_version = 3`:
  ```sql
  SELECT id, code, fiscal_schema_version FROM pos_terminals WHERE fiscal_schema_version <> 3;
  ```
  An empty result means every terminal has cut over. Terminals still at 2 will NOT round (the device gate is fail-closed) — that is safe on its own, but it means two lanes charge different totals for the same basket once rounding is enabled tenant-wide.
- [ ] Cut over any stragglers via `POST /pos/terminals/{terminal}/fiscal-schema-cutover` (requires no open shift on that terminal, all its fiscalized receipts already Z-reported, and its offline-sync queue empty) and re-run the query above until it returns no rows.

## Enable

- [ ] `php artisan tenants:run pos:configure-cash-rounding --option='country=TN' --option='denomination=0.0500' --option='enable-rounding=1' --option='dry-run=1'` — review the diff (one `[DRY-RUN] Country TN: would UPDATE/INSERT {...}` line per tenant).
- [ ] Re-run the same command without `--option='dry-run=1'`.
- [ ] If POS tender tolerance is also going live for this tenant, repeat with `--option='enable-tolerance=1'` in a **separate** invocation — `--enable-rounding` and `--enable-tolerance` are independent switches on independent columns (`cash_rounding_enabled` and `pos_tolerance_enabled` on `country_payment_settings`), and neither one touches the B2B `payment_tolerance_enabled` column used by invoice write-offs.
- [ ] Confirm `GET /api/v1/pos/payment-policy` returns `cashRoundingEnabled: true` and `cashRoundingDenomination: "0.050"` for the target company — as a STRING at the currency's own scale (TND scale 3), with the trailing zero. A response of `0.05` or a JSON number means a float crept into the DTO; STOP and fix before any device rounds.

## Device rollout

- [ ] Ship the POS build carrying SQLite schema **v64** and v3 authoring. (v63 adds the `payment_policy_cache` table, the three `offline_receipts` cash-rounding/tolerance columns, and `payment_methods.is_cash_tender`; v64 adds the durable per-shift `tolerance_auto_accepts(shift_id PK, accept_count, updated_at)` auto-accept budget — both are required, v64 is the final migration this track ships.)
- [ ] On first launch confirm the device applied through v64:
  ```sql
  SELECT version FROM _migrations ORDER BY version DESC LIMIT 1;
  ```
  must return **64**, not 63 — a device stuck at 63 has the policy cache and receipt columns but not the durable auto-accept budget table, so the §8.1 per-shift cap will not persist across a restart or operator switch.
- [ ] Confirm the device pulled the policy: `SELECT * FROM payment_policy_cache;` shows the tenant's company row with `cash_rounding_denomination = '0.0500'` and `cash_rounding_enabled = 1`.
- [ ] Confirm `SELECT code, is_cash_tender FROM payment_methods;` marks exactly the `CASH` method with `is_cash_tender = 1`.

## Post-enable verification

- [ ] Ring a cash sale totalling 9.997 TND: the cash screen shows 10.000 due and a `Rounding +0.003` line; the printed ticket shows the same rounding line inside the totals block (between Tax and TOTAL).
- [ ] Ring a cash sale totalling 9.973 TND tendered at 9.900: completes with NO manager PIN; the ticket shows both the rounding line and the tolerance write-off line.
- [ ] Attempt a card-only over-tender: refused with the change-eligibility message.
- [ ] Ring a voucher-partial sale: the total is EXACT (no rounding line) — mixed tender with a voucher leg is out of the rounding gate's scope.
- [ ] Close the shift and open the End-of-Day preview. Confirm the two new rows next to the existing `ToleranceDrillDown` tolerance card:
  - **Auto-accepts used** — always rendered (never absent), reading `N / 10` for a shift that spent budget. If the preview is opened with no shift context (`tolerance_auto_accept_count === null`), it reads **`Unknown / 10`**, not `0 / 10` — a `0` here would misreport full headroom on a device whose fail-closed gate is treating that same null shift as fully spent.
  - **Net cash rounding** — this row is **ABSENT entirely** (not a zero row) on a shift where nothing rounded; it only appears when at least one receipt in the shift carries a rounding adjustment, showing the signed net total and the rounded-receipt count, e.g. `-0.020 (2)`.
- [ ] Close the shift: the Z shows a non-zero `tolerance_summary` and (locally, in `report_data` only — `schema_version` stays 2) a `cash_rounding_summary`; the Z pushes without a chain error.
- [ ] Server side: the receipts project with `cash_rounding_adjustment` populated, the GL carries `pos_cash_rounding` (or `pos_cash_rounding_refund` on a refund receipt) and `pos_tolerance_bridge` journal entries (`journal_entries.source_type`), and NO `pos.rounding.policy_mismatch` (`PosCoreReceiptProjection`) or `pos.change.exceeds_cash_legs` (`TreasuryReceiptBridge`) audit events were emitted.

## Kill switches

- [ ] `php artisan tenants:run pos:configure-cash-rounding --option='country=TN' --option='disable-rounding=1'` turns rounding off; `--option='disable-tolerance=1'` (same command, separate flag) turns POS auto-accept off. They are independent — one can be off while the other stays on — and NEITHER affects the B2B `payment_tolerance_enabled` column used by invoice write-offs.
- [ ] A disable reaches each device on its next sync tick. Offline devices keep rounding against the last policy they cached in `payment_policy_cache`; those receipts remain verifiable forever because the denomination is inside the signed v3 bytes, and drift is caught server-side by the `pos.rounding.policy_mismatch` alert.

## Rollback

- [ ] Roll the POS build back to the previous version (pre-v64/v3): the device returns to v2 authoring immediately. The v63/v64 SQLite additions (`payment_policy_cache`, the three `offline_receipts` columns, `payment_methods.is_cash_tender`, `tolerance_auto_accepts`) are all additive and nullable/defaulted, so no data migration or teardown is needed, and every v3 receipt already synced stays valid and verifiable forever.

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
  src/components/pos/EndOfDayPreviewModal.test.tsx
```

The last two additions beyond the original device-track list matter for
different reasons: `voucherCheckout.integration.test.ts` and
`idempotencyRetry.integration.test.ts` are the only suites in this track that
exercise the **real fiscal engine end-to-end** against a real SQLite file
(everything else here unit-tests a module in isolation); a change that breaks
receipt authoring under the real engine but not its unit tests would slip
through without them.
`src/components/pos/EndOfDayPreviewModal.test.tsx` is the Task 10 fix-round
modal suite — it is what actually pins the two new EOD rows (auto-accept
budget, net cash rounding) described above.

Note: `src/__tests__/integration/offlineFirstFlow.test.ts` mocks
`@/lib/fiscal/instance` and therefore does NOT exercise the real fiscal
engine — it is not a substitute for the two integration suites above and is
intentionally excluded from this list.

**Result at HEAD (`a22c00e21`):** 17 files, 220 tests, all passed.

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
