# Verdict

Minor issues, no B4 blocker. The B4 fiscal hole is closed on HEAD `0925d288`: online payments, sync payloads, and both writer layers now reject `store_voucher` / `restaurant_voucher` / `gift_card` rows with a missing or empty instrument pair, and the POS modal no longer routes those payment-method tiles into the free-form line flow. I found two non-blocking problems: the public `InstrumentRequiredException` message exposes transport field names despite the review checklist saying it must not, and the online `PaymentMethod` resolver still relies on unscoped `exists` / `find()` behavior while falsely documenting a global tenant scope that the model does not have. Neither reopens the null-instrument fiscalization bug.

## Per-commit verification

| SHA | Claim | Status | Evidence (file:line) |
|---|---|---|---|
| `fc6c93ad` | Add `PaymentInstrumentKind::requiresInstrumentForMethodCode()` that iterates enum cases. | Fixed | The helper normalizes with `strtolower(trim(...))`, skips empty / `None`, then iterates `self::cases()` at `apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php:63-81`. `PaymentInstrumentKindTest` covers all cases, normalization, `none`, empty, and unknown codes at `apps/api/tests/Unit/POS/Domain/Enums/PaymentInstrumentKindTest.php:22-93`. |
| `7879e489` | Server enforcement on online + sync wires; writer-layer `InstrumentRequiredException`; 422 mapping. | Fixed with minor exposure issue | Online validator resolves the method and requires both fields for instrument-bearing codes at `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php:173-218`. Sync validator applies the same predicate to wire `method_code` at `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:184-217`. Online writer guard runs before Treasury / GL / ReceiptPayment writes at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:173-197`; sync writer guard runs before `ReceiptPayment::create()` and `finalize()` at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:433-462`. `DomainException` maps to JSON 422 at `apps/api/bootstrap/app.php:199-208`. New minor: exception text leaks field names at `apps/api/app/Modules/POS/Domain/Exceptions/InstrumentRequiredException.php:28-35`. |
| `426e70c7` | POS UI routes instrument-bearing tiles away from free-form `PaymentLineItem`; cashier message + visual differentiation. | Fixed | TS helper mirrors normalization at `apps/pos/src/lib/payment/paymentMethodKind.ts:21-44`. `handleSelectMethod()` detects instrument-bearing methods, clears free-form state, sets `advancedPayments.voucherTenderFlowRequired`, and returns before setting `selectedMethodId` at `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:196-224`. Ticket icon + Voucher badge render at `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:382-414`. Tests assert no Add Payment button for store voucher and cash still works at `apps/pos/src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx:159-210`. |
| `0925d288` | TS/PHP parity test for instrument-bearing method list. | Fixed | The parity test reads the PHP enum via `node:fs`, extracts `case <Name> = '<value>';` values, filters `none`, and compares sorted TS values at `apps/pos/src/lib/payment/__tests__/paymentMethodKind.parity.test.ts:20-46`. The regex is adequate for the current enum format and handles normal whitespace/newlines around tokens because it uses `\s`; it would not parse double-quoted enum values, but the PHP file uses single-quoted values at `apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php:39-42`. |

## New findings

### Minor — `InstrumentRequiredException` exposes internal field names in the public 422 message

The checklist explicitly said the exception message must not leak FK IDs, internal field names, or tenant IDs. The constructor does not leak IDs or tenant data, but it does return `instrument_type` and `instrument_serial` verbatim: `Payment method "%s" requires both instrument_type and instrument_serial...` at `apps/api/app/Modules/POS/Domain/Exceptions/InstrumentRequiredException.php:30-35`. Because generic `DomainException` responses render `$e->getMessage()` into the JSON body at `apps/api/bootstrap/app.php:199-208`, programmatic writer failures can expose those transport names to clients.

This is not a fiscal blocker: normal FormRequest validation already returns field-keyed errors, and the server still rejects the malformed tender. But the domain exception should use cashier/integrator-safe wording such as "voucher identity" / "instrument identity" rather than raw field names.

## Regression risks

- The online `PaymentMethod` resolver is not tenant/company scoped. `StoreReceiptPaymentsRequest` uses unscoped `exists:payment_methods,id` at `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php:93`, then unscoped `PaymentMethod::query()->find($paymentMethodId)` at `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php:192-195`. The comment claiming a global tenant scope exists at `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php:140-144` is false: `PaymentMethod` only defines manual `scopeForTenant()` and `scopeForCompany()` at `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php:167-213`. This is inherited from the existing payment flow and B4 mostly rejects more shapes than before, so I am not treating it as a B4 blocker, but the comment should be corrected and the payment-method/repository validation should be scoped in a tenant-isolation sweep.
- The B4 cashier message is a documented dead-end until B5. The UI tells the cashier to use the voucher button or scan code at `apps/pos/src/locales/en/pos.json:579` and `apps/pos/src/locales/fr/pos.json:579`; B5 is still responsible for mounting the actual `VoucherTenderModal`. Per the review scope, I did not penalize B4 for that missing mount.
- Arabic locale was requested in the checklist, but POS currently registers only `en` and `fr` resources at `apps/pos/src/lib/i18n.ts:4-23`, and `rg --files apps/pos/src/locales` shows only those two locale trees. The new key exists in every configured POS locale.

## Hash-stability check

Pass.

- `git diff --exit-code e0632e72..0925d288 --` the PHP/TS fixture-01 and fixture-08 JSON files returned zero output, so B4 did not change those bytes.
- `cmp -s` confirms PHP and TS fixture-01 are byte-identical; `cmp -s` confirms PHP and TS fixture-08 are byte-identical.
- Fixture-01 expected hash remains `4db73253d2456bb80d2577904c4a600f72f218648b7b0350c84311e4f4871003`.
- Fixture-08 expected hash remains `100f2395c5c1dbd0c46bc57db1acef70075800e9342128dab4a9e30d4847af43`.
- `git diff --exit-code 2efc007e..0925d288 -- apps/pos/src/lib/fiscal/hashService.ts apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` returned zero output; v2 hash path is bit-for-bit untouched.

Targeted tests run:

- `php artisan test tests/Unit/POS/Domain/Enums/PaymentInstrumentKindTest.php tests/Feature/POS/StoreReceiptPaymentsInstrumentBindingTest.php tests/Feature/POS/SyncReceiptsRequestTest.php tests/Feature/POS/ReceiptPaymentServiceInstrumentGuardTest.php tests/Feature/POS/ReceiptSyncServiceInstrumentGuardTest.php tests/Feature/POS/OfflineV3CutoverSyncTest.php tests/Feature/POS/V3ReceiptHashComputerTest.php` — 42 passed, 113 assertions.
- `pnpm --filter @autoerp/pos test -- --run src/lib/payment/__tests__/paymentMethodKind.test.ts src/lib/payment/__tests__/paymentMethodKind.parity.test.ts src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx src/lib/fiscal/v3/__tests__/canonicalPayload.test.ts` — 4 files, 32 tests passed.

## Decision-conformance check

- Server value-conditional rule: honored. Online uses resolved `PaymentMethod.code`; sync uses lowercased/trimmed `method_code` via the shared predicate. Both require non-empty `instrument_type` and `instrument_serial` for `store_voucher`, `restaurant_voucher`, and `gift_card`.
- POS UI decision (b): honored. Instrument-bearing tiles stay visible, but tapping them does not enter the free-form payment-line flow; it surfaces a cashier message and leaves `selectedMethodId` null.
- No tight coupling: mostly honored. PHP enforcement derives from enum cases, and TS drift is now caught by the parity test. The TS runtime list is still manually duplicated, but the CI guard closes the silent-drift gap.
- B5 boundary: respected. The review did not penalize the absence of the actual voucher tender modal mount; B4 only had to prevent free-form fallback and reject malformed server inputs.

## What's solid

- I could not find a path where `method_code = store_voucher` with null/empty instrument fields reaches fiscalization. The online request, sync request, online writer, and sync writer all reject it.
- Defense-in-depth ordering is right. Online rejection occurs before Treasury Payment creation, GL posting, and `ReceiptPayment::create()`; sync rejection occurs before payment row creation and finalization, and the transaction rollback test asserts no receipt row survives.
- Case normalization is consistent. PHP and TS both lowercase and trim; sync uppercase coverage exists, and the PHP unit tests cover whitespace.
- The modal tests exercise the production component, not a mocked API surface. They assert the banned free-form path is closed while cash still works.
- The parity test closes the audit’s PHP/TS drift gap without adding a build-time generator.
