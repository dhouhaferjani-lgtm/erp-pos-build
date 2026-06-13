# Verdict

Clean. I re-audited the full B5 range `5b65cd06..bbdbd610` on `feat/refund-flow-fixes` / HEAD `bbdbd610`, including the first broken shape and the five fix commits. The original blocker is closed: the production offline cashier flow no longer relies on a local orphan `voucher_ledger` row, and server sync now writes the canonical redemption after receipt finalization inside the same DB transaction. The hash-parity ordering invariant is real and is implemented correctly. I found no new blocker, major, or minor issue.

## Blocker closure verification

The offline cashier chain now works end-to-end:

1. `HomePage` opens `AdvancedPaymentsModal` for advanced checkout and calls `processAdvancedCheckout` on completion (`apps/pos/src/pages/HomePage.tsx:702`).
2. A `store_voucher` tile tap is caught by the instrument-bearing path. Non-store instruments are gated; `store_voucher` sets `voucherTenderMethodCode = 'store_voucher'` and opens the modal (`apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:240`, `:256`, `:263`, `:268`).
3. `VoucherTenderModal` accepts `methodCode` and calls the payment store's `addVoucherPayment(code, amount)` on successful apply (`apps/pos/src/components/pos/VoucherTenderModal.tsx:81`, `:112`, `:155`, `apps/pos/src/stores/paymentStore.ts:485`).
4. `AdvancedPaymentsModal` converts `paymentStore.voucherTenders` into `AdvancedPaymentLine` rows with `instrument_type: 'store_voucher'` and `instrument_serial: v.code` (`AdvancedPaymentsModal.tsx:381`).
5. `processAdvancedCheckout` preserves those instrument fields into the local-first payment rows (`apps/pos/src/stores/paymentStore.ts:438`, `:451`) and calls `createReceiptLocalFirst`, which calls `createOfflineReceipt` (`paymentStore.ts:198`, `:208`, `:422`).
6. `createOfflineReceipt` computes the v3 hash with `voucher_ledger_entries: []` (`apps/pos/src/lib/offline/receiptService.ts:162`, `:180`), writes `payments_json` with method/instrument fields (`:323`, `:330`), and updates only the local voucher projection. Per Option B it does not write a local `voucher_ledger` row (`:411`, `:441`, `:456`).
7. `runFullSync` pushes offline receipts before voucher-ledger rows and later pulls vouchers and voucher ledger (`apps/pos/src/lib/sync/syncService.ts:1190`, `:1202`, `:1220`, `:1221`).
8. `ReceiptSyncService::syncSingleReceipt` persists `pos_receipt_payments` with `instrument_type` and `instrument_serial` (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:433`, `:461`, `:465`).
9. `finalize()` runs before redemption (`ReceiptSyncService.php:479`, `:484`). At this point there is no `voucher_ledger` row for the receipt, so server v3 canonical input matches the offline hash.
10. Only after hash verification, sync iterates `store_voucher` payments and calls `VoucherRedemptionService::redeem()` with the synced `receipt_id` (`ReceiptSyncService.php:497`, `:526`, `:542`).
11. `VoucherRedemptionService` locks the voucher, validates state, prevents duplicate `(voucher_id, receipt_id, Redeemed)`, writes the canonical `voucher_ledger` row, decrements balance, transitions status, and posts GL (`apps/api/app/Modules/Voucher/Application/Services/VoucherRedemptionService.php:81`, `:86`, `:138`, `:158`).
12. `pullVouchers` and `pullVoucherLedger` then reconcile the local mirror from canonical server state (`syncService.ts:929`, `:965`).

This directly closes the previous failure mode: offline cashier flow -> sync -> canonical voucher debit is no longer silently dropped.

## Per-commit verification

| SHA | Claim | Status | Evidence (file:line) |
|---|---|---|---|
| `d825c9b7` | Mount `VoucherTenderModal` from `AdvancedPaymentsModal` payment-tile tap. | Pass | Tile handler opens the modal for `store_voucher` at `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:268`; modal is mounted with `db`, `remainingDue`, `currency`, `onApplied`, and `methodCode` at `:755`. |
| `abb1323a` | Offline path writes pending local `voucher_ledger` row plus balance/status update. | Superseded by `853720a9` | The initial shape was replaced. HEAD explicitly documents that the offline path no longer writes local ledger rows (`apps/pos/src/lib/offline/receiptService.ts:411`). |
| `156ed65c` | Online path injects `VoucherRedemptionService` and calls `redeem()` in same transaction. | Pass | Constructor injection at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:47`; outer transaction at `:79`; redemption call at `:294`. |
| `002fc681` | Real-SQLite end-to-end voucher checkout integration test. | Pass, updated for Option B | Integration test asserts full offline receipt write, local balance decrement, and zero local ledger rows at `apps/pos/src/lib/offline/__tests__/voucherCheckout.integration.test.ts:147`, `:201`. |
| `1a30b3ec` | Blocker fix: `ReceiptSyncService` invokes `redeem()` after `finalize()` in same DB transaction. | Pass | `DB::transaction` wraps sync at `ReceiptSyncService.php:147`; `finalize()` at `:484`; redemption loop at `:526`; call at `:542`. |
| `ba4749cf` | Per-entry voucher ledger push status inspection. | Pass | Client type guard and status branching at `apps/pos/src/lib/sync/syncService.ts:1080`, `:1118`, `:1145`, `:1150`; tests for failed/duplicate/malformed responses at `apps/pos/src/lib/sync/__tests__/voucherSync.test.ts:271`, `:311`, `:390`. |
| `853720a9` | Option B: remove offline-side `voucher_ledger` row write, preserve local balance decrement. | Pass | `insertPendingVoucherLedgerRow` is gone from the offline writer; local update remains at `receiptService.ts:441`, `:456`; integration test asserts no local row at `voucherCheckout.integration.test.ts:196`. |
| `84ebef8b` | `methodCode` prop and Phase 1 unsupported UX for restaurant/gift-card. | Pass | Prop type at `VoucherTenderModal.tsx:76`, required prop at `:112`, passed at `AdvancedPaymentsModal.tsx:763`; non-store gate at `:263`; tests at `AdvancedPaymentsModal.test.tsx:447`, `:479`; message at `apps/pos/src/locales/en/pos.json:580`. |
| `bbdbd610` | Docblock corrections on voucher ledger push path. | Pass | `VoucherLedgerPushService` now documents that receipt-tied redemptions use `ReceiptSyncService`, client `id` is correlation-only, and null `receipt_id` is rejected (`apps/api/app/Modules/POS/Application/Services/VoucherLedgerPushService.php:29`, `:47`, `:57`). |

## Hash-parity ordering invariant

The implementer's invariant holds. Offline POS hashes v3 receipts with `voucher_ledger_entries: []` (`apps/pos/src/lib/offline/receiptService.ts:180`). Server `V3ReceiptHashComputer` eager-loads `voucherLedgerEntries` from DB during finalization (`apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php:35`, `:45`, `:149`). `ReceiptSyncService` calls `finalize()` and verifies the offline hash at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:484`, then calls `redeem()` only after that at `:526-552`.

If redemption ran before finalize, the just-written ledger row would enter the server canonical input and diverge from the offline hash. The HTTP test locks this down by posting a voucher-bearing v3 sync payload, asserting the server hash equals the offline expected hash, then asserting the canonical ledger row exists after sync (`apps/api/tests/Feature/POS/OfflineV3CutoverSyncTest.php:391`, `:418`, `:427`).

## New findings (if any)

None.

Two non-blocking observations:

Nit: `VoucherTenderModal` accepts `methodCode` but aliases it as `_methodCode` and does not branch on it internally (`apps/pos/src/components/pos/VoucherTenderModal.tsx:166`). This is acceptable because `AdvancedPaymentsModal` gates all non-store values before opening the modal (`AdvancedPaymentsModal.tsx:263`), and Phase 1 only supports store vouchers.

Nit: `ReceiptSyncService` checks `instrument_type !== 'store_voucher'` with a raw string (`ReceiptSyncService.php:527`). The row already passed enum coercion earlier (`:461`), so this is cosmetic.

## Hash-stability check

Pass.

Method:

- `git diff 2efc007e..bbdbd610 -- apps/pos/src/lib/fiscal/hashService.ts apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` returned zero lines.
- Fixture-01 expected hash is present on both TS and PHP sides: `4db73253d2456bb80d2577904c4a600f72f218648b7b0350c84311e4f4871003`.
- Fixture-08 expected hash is present on both TS and PHP sides: `100f2395c5c1dbd0c46bc57db1acef70075800e9342128dab4a9e30d4847af43`.
- Targeted PHP command passed: `php artisan test --filter='OfflineV3CutoverSync|ReceiptSyncServiceVoucherRedemption|ReceiptPaymentServiceVoucherRedemption|store_voucher_method_with_null_instrument_fields_returns_422|test_v3_terminal_rejects_store_voucher_payment_with_null_instrument_fields|test_sync_payload_store_voucher_with_null_instrument_fields_returns_422'` -> 17 passed, 90 assertions.
- Targeted POS command passed: `pnpm --filter @autoerp/pos test -- --run ...` for `voucherSync`, `receiptService`, `voucherCheckout.integration`, `AdvancedPaymentsModal`, and `VoucherTenderModal` -> 5 files passed, 88 tests passed.

## Decision-conformance check

- Spec §6.5: voucher redemption is represented as a `pos_receipt_payments` row with `payment_method.code = store_voucher` and `instrument_serial = <code>`. Honored at the UI mapping (`AdvancedPaymentsModal.tsx:381`) and sync writer (`ReceiptSyncService.php:465`).
- Server-authoritative voucher ledger for offline receipt-tied redemption. Honored: offline writer does not create a local ledger row (`receiptService.ts:411`), and server sync creates the canonical row after finalize (`ReceiptSyncService.php:526`).
- B4 contract: instrument-bearing payment methods must not seal with null instrument fields. Honored by request/writer guards; B4 negative tests passed in the targeted PHP run.
- Phase 1 scope: only store vouchers are live; restaurant vouchers and gift cards are reserved. Honored by parent gate and explicit cashier message (`AdvancedPaymentsModal.tsx:263`, `pos.json:580`).
- Hash-chain stability: v2 path unchanged and fixture-01/fixture-08 hashes preserved. Honored by the zero-line v2 diff and fixture checks.
- Idempotency: same offline `idempotency_key` replay must not double redeem. Honored by the pre-transaction duplicate check (`ReceiptSyncService.php:127`) and tested at `ReceiptSyncServiceVoucherRedemptionTest.php:336`.
- Per-entry voucher-ledger push response must inspect row-level `status`, not just HTTP success. Honored at `syncService.ts:1123`, `:1145`, `:1150` and covered by tests.

## What's solid

- The load-bearing offline sync path now uses a real `VoucherRedemptionService`, not a mock. I searched the POS feature/unit tests for mocking of `VoucherRedemptionService`; the new sync tests resolve the real service from the Laravel container and assert real `voucher_ledger`, voucher balance/status, and GL journal side effects.
- Retry is guarded twice: `ReceiptSyncService` returns `Duplicate` before entering the transaction for an existing `idempotency_key` (`ReceiptSyncService.php:127`), and `VoucherRedemptionService` independently rejects a second redemption for the same `(voucher_id, receipt_id, Redeemed)` tuple (`VoucherRedemptionService.php:138`).
- Concurrent redemptions on the same voucher serialize through `lockForUpdate()` (`VoucherRedemptionService.php:86`).
- The voucher-ledger push client no longer bakes in the silent-drop bug; it fails stale/malformed `{}` responses safely and does not mark per-entry `failed` results as synced (`syncService.ts:1123`, `:1155`; test at `voucherSync.test.ts:311`).
- `pullVouchers` and `pullVoucherLedger` both run after push in `runFullSync`, so the terminal receives canonical balance/status and the server-authored ledger row after sync (`syncService.ts:1220`, `:1221`).
- Online `ReceiptPaymentService` redemption remains covered and passed in the targeted run.
