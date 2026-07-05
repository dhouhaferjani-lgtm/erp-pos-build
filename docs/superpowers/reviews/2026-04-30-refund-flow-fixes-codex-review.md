# Verdict

Blocker remains. The sweep fixed several real defects, and the targeted B1/B2/M1/M2/m1/m2 tests pass, but the fiscal-instrument fix is still not production-complete: voucher instrument fields are included in the POS v3 hash input and in the PHP hash mapper, yet both the online payment request path and the offline sync payload drop `instrument_type` / `instrument_serial` before `pos_receipt_payments` is written. That means a voucher-bearing v3 receipt is either sealed without the required instrument identity on the server path, or fails offline sync because the POS hash includes bytes the server cannot reconstruct.

# Sweep coverage

| Finding | Status | Evidence |
|---|---|---|
| B1 | Partially fixed | Server now hard-requires `fiscal_schema_version`: `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:88-92`, and `SyncReceiptPayload::fromArray()` fail-fasts if bypassed at `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:92-107`. POS branches on terminal schema at `apps/pos/src/lib/offline/receiptService.ts:233-253`, persists the version at `apps/pos/src/lib/offline/receiptService.ts:318`, and transmits it at `apps/pos/src/lib/sync/syncService.ts:1183-1213`. But voucher-bearing offline receipts still cannot sync because the same path drops instrument fields from `payments_json` and payload; see Blocker B3 below. |
| B2 | Partially fixed | The snapshot column exists and is NOT NULL/backfilled in `apps/api/database/migrations/2026_05_07_000001_add_payment_method_code_to_pos_receipt_payments.php:37-78`. `V3ReceiptHashComputer` reads `payment_method_code`, `instrument_type`, and `instrument_serial` at `apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php:118-145`. However production writers still do not accept or persist instrument fields; the passing hash test manually creates rows with instrument fields instead of exercising a real payment/sync writer. See Blocker B3. |
| M1 | Fixed | Web source values are lowercase storage strings in `apps/web/src/features/vouchers/types/voucher.ts:3-9`, filter chips emit `refund`, `exchange_surplus`, and `gift_card_purchase` at `apps/web/src/features/vouchers/pages/VoucherListPage.tsx:19-27`, `SourceBadge` keys the same values at `apps/web/src/features/vouchers/components/SourceBadge.tsx:10-16`, and backend returns 422 `INVALID_SOURCE` at `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:72-85`. |
| M2-backend | Fixed | `partner_id` is in `ReceiptQrIndexRowDto` at `apps/api/app/Modules/POS/Application/DTOs/ReceiptQrIndexRowDto.php:27-35` and serialized at `:43-52`; the sync service populates it from `Receipt.partner_id` at `apps/api/app/Modules/POS/Application/Services/ReceiptQrIndexSyncService.php:59-69`; POS local upsert writes it at `apps/pos/src/lib/offline/voucherRepository.ts:344-352` and updates it at `:358-369`. |
| M2-UI | Fixed | The customer tab is gone; `ReceiptLocatorScreen` now imports only `findReceiptByNumber` at `apps/pos/src/components/pos/ReceiptLocatorScreen.tsx:8`, documents the removal at `:78-83`, and searches by receipt number only at `:111-133`. No `findRecentReceiptsByPartner` caller remains in that component. |
| m1 | Fixed | `transfer()` now persists `$voucher->override_reason = $reason` inside the transaction at `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:400-427`; the regression test asserts it at `apps/api/tests/Feature/Voucher/VoucherControllerTest.php:593-619`. |
| m2 | Fixed | `VoucherEvent::ExpiryExtended` exists at `apps/api/app/Modules/Voucher/Domain/Enums/VoucherEvent.php:17-23`; `extendExpiry()` writes a metadata-only ledger row at `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:470-494`; the Postgres event CHECK allowlist is extended without touching the append-only trigger at `apps/api/database/migrations/2026_05_08_000001_add_expiry_extended_to_voucher_ledger_event_check.php:24-42`. |

# New findings

## Blocker - B3: Instrument-bearing payment fields are hashed but not carried through production writers

The B2 tests prove the hash computer can bind instrument fields if a test manually inserts them, but the production paths still strip those fields before writing `pos_receipt_payments`.

Evidence:

- Online payment API does not validate or pass any instrument fields: `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php:89-96`.
- `ReceiptPaymentService::processReceiptPayments()` documents only method, amount, repository, card, reference, and authorization fields at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:53`, then creates `ReceiptPayment` rows without `instrument_type` or `instrument_serial` at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:201-212`.
- The POS offline creator hashes instrument fields into v3 canonical input at `apps/pos/src/lib/offline/receiptService.ts:167-173`, but persists `payments_json` without `methodCode`, `instrumentType`, or `instrumentSerial` at `apps/pos/src/lib/offline/receiptService.ts:262-269`.
- The POS sync payload payment type has no instrument fields at `apps/pos/src/lib/sync/syncService.ts:67-73`, and the parser preserves only method id, repository id, amount, card, and reference at `apps/pos/src/lib/sync/syncService.ts:1166-1172`.
- Server sync validation/DTO also omits instrument fields from `receipts.*.payments.*` at `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:80-85` and `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:84-90`.
- `ReceiptSyncService` creates synced payment rows without `instrument_type` / `instrument_serial` at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:413-424`.

Impact: a v3 offline store-voucher sale will compute a POS-side hash containing the voucher serial, then sync a payload from which that serial has been removed. The server recomputes from rows with null instrument fields and rejects the receipt as a hash mismatch. The online payment path is worse: it can write a store-voucher payment row with no serial at all, so the v3 chain does not bind the actual voucher code. This is exactly the fiscal-integrity gap B2 was supposed to close.

The current tests miss it. `OfflineV3CutoverSyncTest` is cash-only at `apps/api/tests/Feature/POS/OfflineV3CutoverSyncTest.php:168-174`. `receiptService.test.ts` only checks that a voucher-bearing v3 hash is hex and that the row is stamped v3 at `apps/pos/src/lib/offline/__tests__/receiptService.test.ts:414-452`; it never asserts the instrument survives into `payments_json` or the sync payload. `syncService.test.ts` checks only `fiscal_schema_version` on the body at `apps/pos/src/lib/sync/__tests__/syncService.test.ts:270-296`.

# Regression risks

- The B1 SQLite migration is structurally safe (`ALTER TABLE ... DEFAULT 2` on both `terminal_state` and `offline_receipts` at `apps/pos/src/lib/db/migrations.ts:693-706`), but there is no real-SQLite test for replaying migration v28 over both a fresh terminal and a terminal with existing queued receipts. Existing migration integration coverage does not mention `fiscal_schema_version`.
- `OfflineV3CutoverSyncTest` calls itself end-to-end, but it precomputes the offline hash with PHP server code, not the actual TS `createOfflineReceipt` plus `receiptToPayload` path. That allowed B3 to slip through.
- `LedgerHistoryTable` still mostly models PascalCase ledger events (`Issued`, `Redeemed`, `Extended`) while backend `formatLedger()` returns enum values such as `issued` and `expiry_extended` at `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:560-565`. This predates m2 for most events, but m2 only added the `expiry_extended` i18n key. Rendering falls back rather than crashing, so I am treating it as UI drift risk, not a blocker for this sweep.
- `GeneralLedgerService::createVoucherLedgerEntry()` intentionally rejects non-GL administrative events, and `extendExpiry()` bypasses it. That is acceptable for the metadata-only decision, but the stale docblock at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:880-882` still lists Transferred/RoundingAdjustment status inaccurately and should be cleaned before the next accounting pass.

# Hash-stability check

Pass by test run plus code inspection for the explicit fixtures:

- Fixture-01 cash-only sale remains `4db73253d2456bb80d2577904c4a600f72f218648b7b0350c84311e4f4871003` in both PHP and TS fixtures: `apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/01-cash-only-eur.json:15`, `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/01-cash-only-eur.json:15`.
- Fixture-08 cash + store voucher remains `100f2395c5c1dbd0c46bc57db1acef70075800e9342128dab4a9e30d4847af43` in both PHP and TS fixtures: `apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/08-store-voucher-binding-eur.json:26`, `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/08-store-voucher-binding-eur.json:26`.
- PHP builder tests cover fixture-08 at `apps/api/tests/Unit/POS/Fiscal/V3/CanonicalPayloadBuilderTest.php:89-106`; TS parity covers fixture-08 at `apps/pos/src/lib/fiscal/v3/__tests__/canonicalPayload.test.ts:7-19` and asserts canonical bytes + hash at `:29-37`.
- I ran `php artisan test tests/Feature/POS/OfflineV3CutoverSyncTest.php tests/Feature/POS/V3ReceiptHashComputerTest.php`: 6 tests passed.
- I ran `pnpm --filter @autoerp/pos test -- --run src/lib/fiscal/v3/__tests__/canonicalPayload.test.ts src/lib/offline/__tests__/receiptService.test.ts src/lib/sync/__tests__/syncService.test.ts`: 61 tests passed.
- v2 path verification: `apps/pos/src/lib/fiscal/hashService.ts` and `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` are unchanged in `git diff 2efc007e..HEAD`; the POS v2 branch still calls `computeFiscalHash()` with the legacy `{methodCode, amount}` payment shape at `apps/pos/src/lib/offline/receiptService.ts:245-253`.

# Decision-conformance check

- B1 hard-fail on cutover: honored at the server boundary. `ReceiptSyncService` rejects any payload whose declared version differs from the terminal version at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:157-172`; no drain machinery was added.
- B2 snapshot column, not live join: partly honored. `payment_method_code` is a snapshot column and the hash mapper reads it, but production payment writers still do not carry the instrument fields that B2 requires.
- M1 lowercase storage values on the wire: honored. Backend filters use `VoucherSource::tryFrom($sourceValue)` and return 422 on invalid input; web chips/types/i18n use lowercase storage strings including `gift_card_purchase`.
- M2 drop tab and still ship `partner_id`: honored. The tab is removed, while backend DTO/sync and local SQLite upsert all carry `partner_id`.
- m2 add `VoucherEvent::ExpiryExtended`: honored. The implementation chose the enum case plus metadata-only ledger row, not a documented no-ledger exception.

# What's solid

- The `fiscal_schema_version` contract is no longer soft on the server: missing payloads fail validation and DTO bypasses throw, which closes the silent default-to-v2 bug.
- The hash builders themselves are in better shape. PHP and TS fixture-08 parity proves the canonical payload format can bind `instrument_type` and `instrument_serial` byte-for-byte.
- Voucher source drift was cleaned broadly: types, filter chips, badge map, provenance branches, locales, backend 422 behavior, and regression tests all use lowercase storage values.
- M2 was handled pragmatically: deleting the unusable customer tab was the right Phase 1 call, and keeping `partner_id` populated in the local mirror avoids a second backend contract churn when proper phone/email/loyalty search lands.
