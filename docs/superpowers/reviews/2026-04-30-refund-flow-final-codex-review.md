# Verdict

Blockers found. The branch is not clean to treat as spec-conformant Phase 1: the offline v3 cutover path is broken on the POS client, the server-side v3 hash still omits newly-landed payment instrument fields, and the web voucher surfaces drift from the backend contract in ways that will break filtering and provenance rendering. I also found a cross-session gap in the POS customer-history path and two remaining voucher audit-trail omissions that were explicitly deferred but still shipped.

## Findings

### Blocker — POS offline receipts still seal and sync as v2 after the terminal cutover path

The POS offline receipt writer still imports the legacy hash helper and computes hashes with the v2 payload shape only, then syncs receipts without sending `fiscal_schema_version` at all. The API DTO defaults a missing version back to `2`, while `ReceiptSyncService` hard-rejects any payload whose version does not match the terminal’s cutover state. That means a terminal moved to schema v3 cannot successfully sync offline-issued receipts produced by this client path, which violates the Phase 1 offline-first and cutover requirements.

Evidence:
- [apps/pos/src/lib/offline/receiptService.ts](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/pos/src/lib/offline/receiptService.ts:4>)
- [apps/pos/src/lib/offline/receiptService.ts](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/pos/src/lib/offline/receiptService.ts:135>)
- [apps/pos/src/lib/sync/syncService.ts](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/pos/src/lib/sync/syncService.ts:1151>)
- [apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:66>)
- [apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Application/DTOs/SyncReceiptPayload.php:110>)
- [apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:157>)

Proposed change:
- Make offline receipt creation branch on the terminal’s schema version and use the TS v3 canonical payload path for v3 receipts.
- Persist `fiscal_schema_version` with offline receipts and include it in the sync payload.
- Add an end-to-end cutover test: create offline receipt on a v3 terminal, sync it, and assert the server accepts the hash without downgrade/default behavior.

Decision needed before fix:
- Should pre-cutover offline receipts be hard-failed once the terminal flips to v3, or does the team want an explicit drain/migration path for locally queued v2 receipts?

### Blocker — Server-side v3 fiscal hash still ignores payment instrument fields that Phase C introduced

The v3 hash computer still carries the Phase A placeholder mapping for payments: it hardcodes `payment_type = 'pos'`, derives `method_code` from the mutable `payment_type` snapshot, and explicitly sets `instrument_type` and `instrument_serial` to `null`. That is now stale relative to the landed schema and model, which already persist `instrument_type` and `instrument_serial` on `pos_receipt_payments`. As built, the fiscal seal is not binding the voucher/store-instrument identity the spec requires.

Evidence:
- [apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Application/Services/Fiscal/V3/V3ReceiptHashComputer.php:118>)
- [apps/api/app/Modules/POS/Domain/ReceiptPayment.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Domain/ReceiptPayment.php:23>)
- [apps/api/app/Modules/POS/Domain/ReceiptPayment.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Domain/ReceiptPayment.php:69>)

Proposed change:
- Hash `method_code` from the immutable tender identifier that matches the spec contract, not the Phase A fallback.
- Include `instrument_type` and `instrument_serial` in the canonical payment block for v3 receipts.
- Replace the stale comments and refresh the v3 fixtures/tests so they prove parity with instrument-bearing payments.

Decision needed before fix:
- Is `payment_method.code` the intended immutable source for `method_code`, or should the receipt store its own denormalized method-code snapshot first?

### Major — Backend/web voucher source contract drift breaks filters and source-specific rendering

The backend returns lowercase snake-case source values (`refund`, `exchange_surplus`, `gift_card_purchase`, ...), but the web back-office is typed and rendered around PascalCase values (`Refund`, `ExchangeSurplus`, `GiftCard`, ...). The list page emits PascalCase query params, while the backend enum and formatter return lowercase values. This drift will break source filtering and leaves `SourceBadge`/provenance logic on the fallback path for real API data.

Evidence:
- [apps/api/app/Modules/Voucher/Domain/Enums/VoucherSource.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/Voucher/Domain/Enums/VoucherSource.php:18>)
- [apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:489>)
- [apps/web/src/features/vouchers/types/voucher.ts](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/web/src/features/vouchers/types/voucher.ts:3>)
- [apps/web/src/features/vouchers/pages/VoucherListPage.tsx](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/web/src/features/vouchers/pages/VoucherListPage.tsx:19>)
- [apps/web/src/features/vouchers/components/SourceBadge.tsx](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/web/src/features/vouchers/components/SourceBadge.tsx:8>)

Proposed change:
- Pick one canonical API contract and normalize both sides to it.
- If the backend keeps enum storage values in the response, update the web types, filter chips, badges, provenance branches, and tests to the same lowercase contract.
- If the web contract is intentional, map API output to that presentation contract in one serializer and keep storage enums internal.

Decision needed before fix:
- Does the team want transport values to match storage enum values, or to expose a presentation-oriented API enum distinct from persistence?

### Major — POS customer-history lookup is wired to a field the mirror endpoint never sends, and the UI asks for an internal partner UUID

Session H added a local-SQLite “find by customer” path, but the receipt QR index endpoint still does not include `partner_id`, and the POS repository carries a shipped TODO acknowledging that omission. On top of that, the POS UI searches by raw `partner_id` text instead of the spec’s full-phone/full-email/loyalty-card cashier identifiers. In practice this path is either empty for synced data or only usable by operators who somehow know internal partner UUIDs.

Evidence:
- [apps/api/app/Modules/POS/Application/Services/ReceiptQrIndexSyncService.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Application/Services/ReceiptQrIndexSyncService.php:53>)
- [apps/api/app/Modules/POS/Application/DTOs/ReceiptQrIndexRowDto.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/POS/Application/DTOs/ReceiptQrIndexRowDto.php:23>)
- [apps/pos/src/lib/offline/voucherRepository.ts](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/pos/src/lib/offline/voucherRepository.ts:103>)
- [apps/pos/src/lib/offline/voucherRepository.ts](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/pos/src/lib/offline/voucherRepository.ts:200>)
- [apps/pos/src/components/pos/ReceiptLocatorScreen.tsx](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/pos/src/components/pos/ReceiptLocatorScreen.tsx:200>)
- [apps/pos/src/components/pos/ReceiptLocatorScreen.tsx](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/pos/src/components/pos/ReceiptLocatorScreen.tsx:291>)

Proposed change:
- Add `partner_id` to the `/pos/receipts/qr-index` DTO and sync payload immediately.
- Replace the raw partner-id textbox with a cashier-facing identifier flow that matches the spec, or explicitly downgrade the feature behind a product decision until the proper lookup source is available offline.
- Update the POS tests so they do not normalize the UUID-based placeholder workflow as final behavior.

Decision needed before fix:
- For Phase 1, should the offline customer-search key be loyalty-card/phone/email resolution cached locally, or should this tab be removed until the mirror can support the intended identifiers?

### Minor — Voucher transfer still drops `override_reason`, leaving audit/provenance incomplete

The transfer endpoint appends a notes line and writes a `Transferred` ledger row, but it never persists the structured `override_reason` field that the voucher formatter already exposes. This preserves some human-readable history while leaving the machine-readable audit field null.

Evidence:
- [apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:393>)
- [apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:512>)

Proposed change:
- Persist `$voucher->override_reason = $reason` inside the transfer transaction, parallel to the void path.
- Add a controller test that asserts both notes and `override_reason` are written on transfer.

Decision needed before fix:
- None; this looks like an omitted field assignment rather than a product-choice conflict.

### Minor — Voucher expiry extension mutates the voucher without any immutable ledger event

`extendExpiry()` changes `expires_at` and appends a notes entry, but unlike `void()` and `transfer()` it writes no `VoucherLedger` row. That leaves the ledger unable to reconstruct this administrative state change on its own.

Evidence:
- [apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:432>)
- [apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php](</Users/houssamr/Projects/syneriva/apps/erp.refund-flow/apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:462>)

Proposed change:
- Add a dedicated metadata-only ledger event for expiry extension, or explicitly model this as an audited non-ledger action and document that exception.
- Add tests covering the chosen audit contract.

Decision needed before fix:
- Does the team want to extend the `VoucherEvent` enum with an expiry-extension event, or consciously treat expiry edits as outside the immutable ledger?

## Cross-Session Inconsistencies

- Session 1.5 and Session 3 drifted on the receipt QR mirror contract: the POS local schema and UI expect a `partner_id`-backed customer-search path, but the backend QR-index endpoint still ships a reduced row shape without that field.
- Session 2 and Session 4 drifted on voucher source transport values: the back-office UI and tests were written against PascalCase source names while the merged backend still returns storage enum values.
- Phase A and later POS work drifted on the fiscal cutover: the backend now enforces schema-version parity, but the offline POS creation/sync path never finished the v3 handoff.
- Phase C landed `instrument_type` / `instrument_serial`, but the v3 hash computer stayed on the earlier placeholder mapping from the pre-instrument phase.

## What’s Solid

- The receipt lifecycle split itself is in place: `ReceiptCreationService` emits `ReceiptDrafted`, `ReceiptFinalizationService` seals the chain and emits `ReceiptCreated`, and the compliance subscriber handles both paths separately rather than relying on the old single-event semantics.
- The POS scan dispatcher still respects the offline-first boundary and the “do not silently mutate the cart” rule. The task wiring remains centered on `pendingScanResult` followed by explicit `acceptPendingScan`, and the repository helpers used by the dispatcher are local-SQLite only.
- The backend refund exception mapping that Phase H deferred earlier is now present in `bootstrap/app.php`; the POS-side manager-PIN flow is no longer blocked on generic 422 handling.
- The terminal cutover and pending-seal query exclusions appear to have stayed intact in the backend codepaths I sampled; I did not find a regression back to inline sealing or to the old `ReceiptCreated`-at-draft semantics.

## Verification Notes

- I attempted targeted verification with `pnpm --filter @autoerp/pos test -- --run src/lib/scan/__tests__/scanFlow.integration.test.tsx` and `php artisan test tests/Feature/POS/ReceiptSyncServiceV3Test.php`, but both were blocked by sandbox/worktree write restrictions (`.vite-temp`, `.phpunit.result.cache`, and `storage/logs/laravel.log`). I therefore treated test execution as inconclusive in this environment and grounded the findings above in direct code inspection.
