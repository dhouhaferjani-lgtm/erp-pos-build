# Verdict

Blockers remain. The 17-commit sweep does fix the literal field-dropping defects from B1/B2/B3 and the targeted tests pass, but it still does not close the voucher-redemption path "once and for all." A `store_voucher` payment can still be accepted and fiscalized with `instrument_type = null` / `instrument_serial = null`, and the POS voucher tender UI is still not a mounted cashier flow that writes a voucher-redemption ledger row. That leaves the v3 chain able to seal a store-voucher tender without the voucher identity, and leaves voucher balances/GL liability untouched after tender use. Do not merge as clean.

## Sweep coverage

| Claim | Status | Evidence |
|---|---|---|
| B1 `bbceb8b8` — POS branches on terminal `fiscal_schema_version`, persists/transmits it, server requires it | Fixed | POS branches v2/v3 at `apps/pos/src/lib/offline/receiptService.ts:218-245`, stamps the row at `apps/pos/src/lib/offline/receiptService.ts:327`, sync emits it at `apps/pos/src/lib/sync/syncService.ts:1230-1278`, API validator requires it at `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:113-117`, DTO hard-fails missing/invalid at `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:121-136`, and server rejects version mismatch at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:158-173`. |
| B2 `b8091610` — snapshot `payment_method_code`; v3 hash binds method/instrument fields | Fixed for hash mapping | Snapshot migration adds/backfills/NOT NULLs `payment_method_code` at `apps/api/database/migrations/2026_05_07_000001_add_payment_method_code_to_pos_receipt_payments.php:37-78`. `V3ReceiptHashComputer` reads `payment_method_code`, `instrument_type`, and `instrument_serial` at `apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php:118-145`. Fixture-08 test covers it at `apps/api/tests/Feature/POS/V3ReceiptHashComputerTest.php:103-150`. New blocker below: the system still permits store-voucher tenders where those instrument fields are null. |
| M1 `3b1c5d7e` — lowercase voucher source values + backend 422 | Fixed | Web union/filter/badge use lowercase at `apps/web/src/features/vouchers/types/voucher.ts:3-9`, `apps/web/src/features/vouchers/pages/VoucherListPage.tsx:19-27`, and `apps/web/src/features/vouchers/components/SourceBadge.tsx:10-16`. Backend returns `INVALID_SOURCE` 422 at `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:72-85`. |
| M2-backend `5d1cee99` — `partner_id` through QR index sync | Fixed | DTO includes and serializes `partner_id` at `apps/api/app/Modules/POS/Application/DTOs/ReceiptQrIndexRowDto.php:27-52`; sync service populates it at `apps/api/app/Modules/POS/Application/Services/ReceiptQrIndexSyncService.php:59-69`; local SQLite upsert writes/updates it at `apps/pos/src/lib/offline/voucherRepository.ts:338-369`. |
| M2-UI `220dc4ea` — remove Find by customer tab/plumbing | Fixed | `ReceiptLocatorScreen` no longer imports/calls partner lookup; tests were reduced accordingly. No `findRecentReceiptsByPartner` caller remains in `apps/pos/src/components/pos/ReceiptLocatorScreen.tsx`. |
| m1 `f12dd595` — persist `override_reason` on transfer | Fixed | Transfer transaction writes `$voucher->override_reason = $reason` at `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:421-427`; test covers it in `VoucherControllerTest` and passed. |
| m2 `01964148` — add `ExpiryExtended` event + ledger row + CHECK migration | Fixed | Enum case exists at `apps/api/app/Modules/Voucher/Domain/Enums/VoucherEvent.php:17-23`; `extendExpiry()` writes the metadata-only row at `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:470-494`; migration only replaces the event CHECK allowlist at `apps/api/database/migrations/2026_05_08_000001_add_expiry_extended_to_voucher_ledger_event_check.php:24-42`, leaving the append-only trigger from the create migration intact. |
| B3 `c6c6b32b` — carry instrument fields through online/offline/sync writers | Partially fixed | When the fields are present, they now survive: online request accepts them at `StoreReceiptPaymentsRequest.php:104-113`, online writer stores them at `ReceiptPaymentService.php:214-230`, offline `payments_json` stores them at `receiptService.ts:268-278`, sync parses them at `syncService.ts:1199-1218`, DTO preserves them at `SyncReceiptPayload.php:102-118`, and sync writer stores them at `ReceiptSyncService.php:429-446`. But the contract still accepts `store_voucher` with no instrument pair; see Blocker 1. |
| R1 `60304219` — real SQLite v28 replay coverage | Fixed | `apps/pos/src/lib/db/__tests__/migrations.v28.test.ts:70-202` uses `SqliteTestAdapter`/`node:sqlite`, replays migrations 1..27 then v28, and covers fresh schema, idempotent duplicate-column run, populated `offline_receipts`, and populated `terminal_state`. |
| R3 `682b7918` — LedgerHistoryTable lowercase event alignment | Fixed | `LedgerEvent` includes backend lowercase values at `apps/web/src/features/vouchers/types/voucher.ts:26-36`; badge map covers all lowercase events including `expiry_extended` at `apps/web/src/features/vouchers/components/LedgerHistoryTable.tsx:21-33`; i18n keys exist in all voucher locale files. |
| R4 `e6bb1050` — GeneralLedgerService docblock refresh | Fixed | Docblock now states wired events and explicitly excludes `ExpiryExtended`, `Transferred`, and `Reversed` at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:869-904`; wired set matches at `:916-921`. |
| Audit-Major `9f7874db` — voucher tenders through AdvancedPaymentsModal into checkout | Partially fixed | `AdvancedPaymentsModal` consumes `paymentStore.voucherTenders`, counts them toward total, and maps them to `AdvancedPaymentLine` with `instrument_type`/`instrument_serial` at `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:95-103`, `:157-178`, and `:279-294`. The store forwards those fields to `createOfflineReceipt` at `apps/pos/src/stores/paymentStore.ts:439-456`. But the POS page still does not mount `VoucherTenderModal`, and checkout does not create redemption ledger rows; see Blocker 2. |
| Audit-Minor1 `5f3ab85b` — `method_code` required on sync wire | Fixed | Validator requires it at `SyncReceiptsRequest.php:104`; DTO throws when missing/empty at `SyncReceiptPayload.php:102-107`; sync writer uses the client snapshot directly at `ReceiptSyncService.php:429-440`. |
| Audit-Minor2 `a7a0b423` — TS path integration coverage | Fixed | `receiptService.test.ts` now runs `createOfflineReceipt` -> persisted row -> production `__test_receiptToPayload` -> canonical builder and asserts hash equality at `apps/pos/src/lib/offline/__tests__/receiptService.test.ts:470-582`; `syncService.test.ts` also asserts voucher wire shape. |
| Audit-Nit `55dced44` — invalid `fiscal_schema_version` throws loudly | Fixed | `receiptToPayload()` throws with receipt number, idempotency key, and bad value at `apps/pos/src/lib/sync/syncService.ts:1230-1251`; tests passed. |
| Followup-Minor1 `a7097900` — onlineCheckout voucher-path test coverage | Fixed | `offlineCheckoutService` forwards payment rows and instrument fields at `apps/pos/src/lib/offline/offlineCheckoutService.ts:90-115`; test asserts the `processReceiptPayments` request body carries the voucher instrument fields at `apps/pos/src/lib/offline/__tests__/offlineCheckoutService.test.ts:224-287`. |
| Followup-Minor2 `e0632e72` — remove silent `bank_account` fallback | Fixed | Voucher repository selection is now active `virtual` only at `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:121-134`; missing virtual repo hard-fails with `voucherRepositoryMissing` at `:260-268`. |

## New findings

### Blocker — Store-voucher payments can still be fiscalized with a null voucher identity

The sweep preserves `instrument_type`/`instrument_serial` when a cooperative caller sends them, but it never enforces the real invariant: a `store_voucher` tender must carry `instrument_type = store_voucher` and the voucher code as `instrument_serial`.

Evidence:
- Online validator makes the instrument pair optional and only enforces both-or-neither at `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php:104-113` and `:141-155`.
- Online writer stores nulls when the pair is omitted: `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:214-230`.
- Sync validator requires `method_code`, but still allows `method_code = store_voucher` with both instrument fields null: `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:104-110` and `:162-176`.
- Sync writer also stores nulls when the pair is omitted: `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:429-446`.
- The POS modal exposes every active method through `activeMethods` and normal payment lines carry no instrument fields: `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:105-117`, `:217-226`, and `:271-277`.

Impact: a cashier or stale client can submit a store-voucher payment method without a serial. The v3 hash then faithfully binds `method_code = store_voucher` and `instrument_serial = null`, which is not the legally meaningful event. That is the same fiscal-integrity failure class as B2/B3, just shifted from "field dropped by code" to "field optional under the voucher method."

What would close it:
- Online: after resolving `PaymentMethod`, reject `code = store_voucher` unless `instrument_type = store_voucher` and `instrument_serial` is non-empty.
- Sync: reject rows where `method_code` lowercases to `store_voucher` unless the same pair is present.
- POS: hide `store_voucher` from ordinary `activeMethods` selection or force it through the voucher tender flow.
- Add negative tests for online and sync store-voucher rows with missing instrument fields.

### Blocker — Voucher tender is recorded as a payment, but no mounted checkout flow debits voucher balance/ledger

The follow-up fixed the shape of `paymentStore.voucherTenders` once that state exists, but the actual redemption flow is still not live end-to-end. The component that calls `addVoucherPayment()` is not mounted by the POS page, and neither the online nor offline checkout path writes a voucher-redemption ledger row.

Evidence:
- `VoucherTenderModal` is only present as its own component/tests; `rg "VoucherTenderModal" apps/pos/src/pages/HomePage.tsx apps/pos/src/components` finds no parent mount outside the component and its tests. `HomePage` renders `TransactionCart`, `CashPaymentScreen`, and `AdvancedPaymentsModal` at `apps/pos/src/pages/HomePage.tsx:913-1003`, but no voucher tender modal.
- `addVoucherPayment()` only mutates in-memory tender state at `apps/pos/src/stores/paymentStore.ts:485-497`; it does not write a local voucher ledger row or reduce local balance.
- `createOfflineReceipt()` persists `payments_json` and `fiscal_schema_version` at `apps/pos/src/lib/offline/receiptService.ts:262-327`, but it does not insert into local `voucher_ledger`.
- The sync pipeline has a push path for pending voucher ledger rows (`apps/pos/src/lib/offline/voucherRepository.ts:379-389`; server handler at `apps/api/app/Modules/POS/Application/Services/VoucherLedgerPushService.php:20-35`), but code search shows no production POS writer creating a pending `Redeemed` row.
- Online checkout uses `ReceiptPaymentService`, which creates a Treasury payment and calls `createPOSPaymentEntry()` at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:172-193`; it does not call `VoucherRedemptionService::redeem()`.

Impact: even when a test preloads `paymentStore.voucherTenders`, checkout only records a receipt payment. The voucher balance/status and liability GL do not move, so the same voucher remains redeemable and accounting is wrong. This is more than a UI test gap: the server-side redemption service exists, and the POS sync service expects redemption ledger rows, but the checkout path does not produce them.

What would close it:
- Mount `VoucherTenderModal` in the payment screen with a real button/scan entry point.
- On apply/confirm, write a local pending `voucher_ledger` redemption row tied to the local receipt identity, update local voucher projection, and push it through `VoucherLedgerPushService`.
- For online checkout, call `VoucherRedemptionService::redeem()` or an equivalent server-side orchestration in the same transaction as the receipt payment.
- Add a real flow test that starts from the mounted POS UI, applies a voucher, completes checkout, and asserts both the receipt payment and voucher ledger/balance change.

## Regression risks

- The positive B3 tests mostly prove "fields survive when present." They do not include negative cases for `store_voucher` without the instrument pair, so the remaining fiscal hole can regress silently.
- `AdvancedPaymentsModal` tests mock `paymentStore.voucherTenders`, and `VoucherTenderModal` tests mock the store. There is still no integration test proving a mounted cashier flow goes `VoucherTenderModal` -> `paymentStore.voucherTenders` -> `AdvancedPaymentsModal` -> checkout.
- `ReceiptPayment` has a defensive model boot fallback that fills `payment_method_code` from `PaymentMethod` if a caller omits it (`apps/api/app/Modules/POS/Domain/ReceiptPayment.php:89-125`). That is acceptable as a write-time snapshot fallback, but tests that omit the explicit snapshot can still mask production callers that are not consciously preserving hash input.
- Pre-v16 stale offline receipts still hit 422 because `method_code` is now required. That was accepted as intentional, and the current sync code documents the behavior at `apps/pos/src/lib/sync/syncService.ts:1173-1187`.

## Hash-stability check

Pass.

- Fixture-01 expected hash remains `4db73253d2456bb80d2577904c4a600f72f218648b7b0350c84311e4f4871003` in both PHP and TS fixture files (`apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/01-cash-only-eur.json:15`, `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/01-cash-only-eur.json:15`).
- Fixture-08 expected hash remains `100f2395c5c1dbd0c46bc57db1acef70075800e9342128dab4a9e30d4847af43` in both PHP and TS fixture files (`apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/08-store-voucher-binding-eur.json:26`, `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/08-store-voucher-binding-eur.json:26`).
- `diff -u` between PHP and TS fixture-01 returned no output.
- `diff -u` between PHP and TS fixture-08 returned no output.
- `git diff --exit-code 2efc007e..feat/refund-flow-fixes -- apps/pos/src/lib/fiscal/hashService.ts apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` returned zero output; v2 path is unchanged.

Targeted verification run:
- `php artisan test tests/Feature/POS/OfflineV3CutoverSyncTest.php tests/Feature/POS/V3ReceiptHashComputerTest.php tests/Feature/POS/StoreReceiptPaymentsInstrumentBindingTest.php tests/Feature/POS/SyncReceiptsRequestTest.php tests/Feature/POS/ReceiptQrIndexSyncServiceTest.php tests/Feature/Voucher/VoucherListSourceFilterTest.php tests/Feature/Voucher/VoucherControllerTest.php tests/Unit/POS/Fiscal/V3/CanonicalPayloadBuilderTest.php tests/Unit/Voucher/Domain/Enums/VoucherEnumsTest.php` — 71 passed, 262 assertions.
- `pnpm --filter @autoerp/pos test -- --run src/lib/fiscal/v3/__tests__/canonicalPayload.test.ts src/lib/offline/__tests__/receiptService.test.ts src/lib/sync/__tests__/syncService.test.ts src/lib/db/__tests__/migrations.v28.test.ts src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx src/stores/__tests__/paymentStore.offlineFirst.test.ts src/lib/offline/__tests__/offlineCheckoutService.test.ts` — 7 files, 100 tests passed.
- `pnpm --filter @autoerp/web test -- --run src/features/vouchers/pages/__tests__/VoucherListPage.test.tsx src/features/vouchers/pages/__tests__/VoucherListPage.filter.test.tsx src/features/vouchers/components/__tests__/LedgerHistoryTable.test.tsx src/features/vouchers/components/__tests__/ProvenanceSection.test.tsx src/features/vouchers/pages/__tests__/VoucherDetailPage.test.tsx` — 5 files, 31 tests passed.

## Decision-conformance check

- B1 hard-fail on cutover: honored. Server rejects schema-version mismatch at `ReceiptSyncService.php:158-173`; no drain machinery was introduced in this sweep.
- B2 snapshot column, not live hash join: honored for hash computation. `payment_method_code` is a stored snapshot and `V3ReceiptHashComputer` reads the snapshot.
- M1 lowercase storage values on the wire: honored. Web, API filtering, badge/i18n, and tests use lowercase values including `gift_card_purchase`.
- M2 drop the tab, still ship `partner_id`: honored. The POS tab is gone and QR index sync/upsert carries `partner_id`.
- m2 add `VoucherEvent::ExpiryExtended`: honored. The controller writes a metadata-only ledger row and the CHECK allowlist is extended.
- Audit-Minor1 `method_code` required on wire: honored. Validator, DTO, and writer no longer have the sync fallback.
- Followup-Minor2 hard-fail missing virtual repo: honored. `bank_account` fallback is gone.

## What's solid

- The low-level v3 canonical hash parity work is real. PHP and TS fixture-08 both bind `instrument_type` and `instrument_serial`, and tampering the serial changes the hash.
- The offline v3 schema-version handshake is now explicit at every layer: local state, offline receipt row, sync payload, request validation, DTO, and server mismatch handling.
- The M1 source rename was fixed broadly rather than papered over with a presentation mapper. Backend-shaped lowercase fixtures now drive the web tests.
- The v28 SQLite migration test is the right kind of test: real SQLite, populated tables, and idempotent replay.
- The `bank_account` voucher repository fallback was removed cleanly; missing `virtual` configuration now fails visibly.
