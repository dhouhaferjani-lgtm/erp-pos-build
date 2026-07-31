
The fix must subtract change exactly once per receipt and only from the cash group. A naïve subtraction after the payment-row join can duplicate change across tender legs. Also, `COUNT(*)` currently counts payment rows, not distinct transactions: [`SalesReportService.php:185`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:185).

### A3 — code citation confirmed, rationale wrong

The raw `UPPER(...)=CASH` predicate exists: [`ReportGenerationService.php:504`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:504). The query already preaggregates and subtracts change: [`ReportGenerationService.php:514`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:514), [`ReportGenerationService.php:532`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:532).

But it does not disagree on case variants: `UPPER('cash')` is `CASH`. Moreover, the write API normalizes codes and enforces `is_cash_tender=true ⇒ code='CASH'`: [`PaymentMethodController.php:332`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:332), [`PaymentMethodController.php:341`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:341). Convergence is still good architecture, but the asserted current disagreement is overstated.

### B1 — cap values confirmed; proposed harness refuted

The three tables currently match:

- PHP: [`CashRoundingCaps.php:40`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Domain/CashRoundingCaps.php:40)
- Signed payload: [`SaleReceiptV3Payload.ts:54`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/payloads/SaleReceiptV3Payload.ts:54)
- Checkout: [`cashRounding.ts:43`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/payment/cashRounding.ts:43)

But `readPhpNamedConst` cannot read this authority. It searches for a `public const` and extracts lowercase scalar string keys: [`FiscalPayloadKeyDrift.test.ts:104`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts:104). `CAPS` is private and associative with integer keys. B1 requires a new/extended parser, not merely one `it()`.

### B4 — confirmed

The first two rule blocks include `cartMutatorSelectors`, while the later overlapping block replaces the rule with only the transaction selector: [`eslint.config.js:287`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/eslint.config.js:287), [`eslint.config.js:304`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/eslint.config.js:304), [`eslint.config.js:315`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/eslint.config.js:315). Local `eslint --print-config` likewise showed only the final selector.

### D staging checkmark — unverifiable

The repository proves only the wiring:

- Production calls the settings seeder: [`ProductionSeeder.php:31`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/ProductionSeeder.php:31).
- `DatabaseSeeder`, CoffeeShop, and Parapharmacy seed countries without settings: [`DatabaseSeeder.php:54`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/DatabaseSeeder.php:54), [`CoffeeShopSeeder.php:110`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/CoffeeShopSeeder.php:110), [`ParapharmacySeeder.php:319`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/ParapharmacySeeder.php:319).

“All five staging tenants have their TN row” is external database state. There is no captured query result or fixture proving it. I cannot confirm or refute it from this repository.

## 3. Independent `tolerance_writeoff` consumer sweep

The plan’s `app/` grep was incomplete, but it did not miss another bad POS-receipt predicate.

Production consumers of `pos_receipts.tolerance_writeoff` are:

- Writers: v3 projection at [`PosCoreReceiptProjection.php:364`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:364) and the retired/legacy payment service at [`ReceiptPaymentService.php:393`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:393).
- Aggregate, breakdown, and drill-down reader: the shared `> 0` query in [`PaymentToleranceQueryService.php:179`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php:179).
- Value reader during voiding: null becomes zero, then participates arithmetically; this is safe: [`ReceiptVoidService.php:285`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:285).
- Implicit API serialization: `ReceiptController::show()` serializes the entire model: [`ReceiptController.php:537`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:537).
- POS/Tauri printing: the API field is typed at [`receipt.ts:83`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/receipt.ts:83), compared numerically against zero at [`buildReceiptData.ts:143`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/buildReceiptData.ts:143), and printed only under the resulting boolean at [`receipt_template.rs:691`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src-tauri/src/printing/receipt_template.rs:691). This is safe for canonical zero.
- Offline printing maps `tolerance_shortfall` into the same output: [`getOfflineReceiptForPrint.ts:116`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts:116).

The Receipt Eloquent scopes contain no tolerance predicate: [`Receipt.php:483`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Domain/Receipt.php:483).

`PaymentAllocationService`, `InvoiceController`, and the web treasury helpers concern the separate `payment_allocations.tolerance_writeoff` domain. Its producer emits either an actual difference or null: [`PaymentAllocationService.php:554`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:554), [`PaymentAllocationService.php:575`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:575). Those null checks must not be rewritten using POS receipt semantics.

Result: the sweep missed API, POS, and Rust consumers, but missed no additional defective POS predicate. Its claimed sole violation is itself not a violation.

## 4. Lane fencing and scope

No concrete duplicate file is provable under the literal tasks. Lane C says “SPEC FIRST, not code,” so it should edit a document, despite the ownership table naming three code files. Under that reading, A/C and B/C cannot collide because C should not touch code at all.

That is not a usable fence; it is an internal contradiction. Before dispatch, each lane needs an explicit write manifest.

Likely future pressure exists around [`zReportService.cashRounding.test.ts:200`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts:200): B3 owns the signed Z handoff, while a complete refund-rounding implementation must decide whether refund adjustments enter the same Z summary. I would not call that a proven collision until C’s design makes that decision.

Scope corrections:

- Lane A is smaller than claimed because A1 has no bug.
- B1 is larger than claimed because the parser cannot read the PHP authority.
- Lane C is drastically under-scoped if it is expected to unblock launch; a specification alone leaves the launch blocker open.
- Lane D combines demo repair, provisioning code, terminal-version policy, and an operational runbook. Split provisioning implementation/testing from release operations.

## 5. Missing blockers for tenant #1

### Critical — the v3 refund fiscal path is not integrated

This is more serious than the rounding delta described in Lane C.

The authoritative v3 chain lives in `fiscal_events`; projected receipt chain columns merely mirror it: [`PosCoreReceiptProjection.php:84`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:84). Device sales author a `SALE_RECEIPT` fiscal event locally: [`receiptService.ts:492`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/receiptService.ts:492).

Refunds do not:

- The device syncs approval events and then posts an ordinary `/return` request: [`refundCheckoutStore.ts:357`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/refundCheckoutStore.ts:357), [`refundCheckoutStore.ts:379`](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/stores/refundCheckoutStore.ts:379).
- The server constructs a legacy-style receipt draft and explicitly computes legacy required hashes: [`ReceiptReturnService.php:702`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:702).
- It seals that receipt by advancing `pos_terminals.last_hash/current_sequence`: [`ReceiptReturnService.php:469`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:469), [`ReceiptFinalizationService.php:90`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:90).
- The response does not return fiscal hash, previous hash, sequence, event ID, rounding adjustment, or denomination: [`ReceiptController.php:312`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:312).
- The principal return-chain test hardcodes the terminal to schema v2: [`ReceiptReturnRefactorTest.php:490`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/POS/ReceiptReturnRefactorTest.php:490).

Therefore the code proves no end-to-end composition between a device-authored v3 sale, an online return, the next device-authored sale, and Z close. I am uncertain whether this manifests as a duplicate sequence, parallel chain, or later reconciliation failure, but the return is demonstrably outside the declared authoritative v3 event path. Lane C must resolve and test that before discussing Swedish refund rounding alone.

### Critical — existing first-tenant release gates are omitted

The repository already declares these P0 gates:

- Provider-side secret rotation remains recorded as pending for Critical/High credentials: [`secret-rotation-2026-05-12.md:53`](/Users/houssamr/Projects/syneriva/apps/erp/docs/security/secret-rotation-2026-05-12.md:53), with blank final sign-off at [`secret-rotation-2026-05-12.md:235`](/Users/houssamr/Projects/syneriva/apps/erp/docs/security/secret-rotation-2026-05-12.md:235). External completion is uncertain, but the repo contains no evidence that the gate closed.
- A real-device smoke pass is explicitly P0 and includes refund, Z, restore, ≥10 minutes offline with 5+ receipts, printer sleep/reprint/disconnect: [`2026-05-13-first-tenant-handoff.md:59`](/Users/houssamr/Projects/syneriva/apps/erp/docs/qa/2026-05-13-first-tenant-handoff.md:59).
- If the tenant is in the Tunisia fiscal scope, accountant/legal sign-off or explicit risk acceptance is P0: [`2026-05-13-first-tenant-handoff.md:88`](/Users/houssamr/Projects/syneriva/apps/erp/docs/qa/2026-05-13-first-tenant-handoff.md:88).

Lane D merely writes a staging chore runbook and explicitly does not execute it. That cannot close these gates.

### Important — the operational runbooks currently fail their own preflight

The preflight exits non-zero for any unresolved marker: [`preflight-runbooks.sh:55`](/Users/houssamr/Projects/syneriva/apps/erp/scripts/preflight-runbooks.sh:55). Current unresolved inputs include:

- Installer version, checksum, signature, and company ID: [`install.md:5`](/Users/houssamr/Projects/syneriva/apps/erp/docs/pos-operations/install.md:5).
- Backup destinations: [`backup.md:92`](/Users/houssamr/Projects/syneriva/apps/erp/docs/pos-operations/backup.md:92).
- Support tool, phone, and logging location: [`support.md:5`](/Users/houssamr/Projects/syneriva/apps/erp/docs/pos-operations/support.md:5).
- The entire rehearsal record: [`walkthrough-rehearsal.md:5`](/Users/houssamr/Projects/syneriva/apps/erp/docs/pos-operations/walkthrough-rehearsal.md:5).

This is exactly what five demo tenants and eight receipts cannot exercise: a signed release artifact on the real Windows terminal, hardware behavior, offline backlog recovery, backup/restore, support escalation, and real fiscal/legal fields.

### Important — provisioning lacks a launch-contract integration test

The PostgreSQL provisioning test verifies owner, company, tax data, and admin role, but not country payment settings, a valid cash tender, terminal v3, enabled rounding policy, or device policy pull: [`TenantProvisioningServiceTest.php:72`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Identity/TenantProvisioningServiceTest.php:72).

It also provisions the main location with POS disabled: [`TenantProvisioningService.php:161`](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:161). That may be intentional onboarding workflow, but the plan does not include the step or assert the final launch state.

Required before dispatch:

1. Delete A1’s fake fix.
2. Define and test an actual provision-at-v3/enabled policy.
3. Expand C into a v3 event-authoritative refund design and implementation, including sale→refund→next sale→Z integration.
4. Add an executed release-readiness lane for artifact, real device, offline, restore, security, and conditional legal sign-off.
5. Freeze concrete per-lane write manifests before parallel work.

**Final verdict: REJECT.**
