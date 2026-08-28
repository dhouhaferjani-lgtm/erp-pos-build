# AutoERP R-2 deferred document numbering — adversarial gate r1 (fiscal/document-numbering)

**Date:** 2026-08-27  
**Lane:** R-2, “defer document-number allocation to confirm”  
**Reviewed range:** `6cb7641793ab0eaaf027ad41c8eefc25d0fabedd..464917acef77947c5ffe1f98e64575d88626625a` (`54 files`, `1,316 insertions`, `144 deletions`)  
**Inputs:** the supplied diff package, implementer report, `docs/handoff/LEDGER.md` D-T9-1, and `CLAUDE.md` rules 8 and 19  
**Mode:** code read-only; database work was confined to a disposable PostgreSQL database on `127.0.0.1:5433`

## Decision

**CHANGES.** The PostgreSQL migration leg works and most nullable consumers were hardened correctly, but the end state does not meet the allocation contract. A public status-transition seam can renumber a Confirmed, unsealed document. Multiple operator-created Draft paths still consume numbers at birth. Three of the seven standard confirmation paths persist the number in an UPDATE separate from the status flip, and locking does not universally protect the document being confirmed. One immutable fiscal/audit event still silently turns a null number into an empty string.

## Blocking and important findings

### G1 — Critical — `464917ace` permits a Confirmed document to be renumbered

`DocumentStatusService::transition()` treats any caller-provided `document_number` as legal, regardless of whether the row already has a number (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:119-139`). The caller attributes are spread into the update and suppress allocator output (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:152-158`). This bypasses the method's own “NEVER RENUMBERS” claim (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:184-189`).

The database trigger is not a sufficient backstop. It returns immediately unless the **old** row is already `SEALED` (`apps/api/database/migrations/tenant/2025_12_11_054716_add_document_immutability_trigger.php:24-27`); only then does it compare `document_number` (`apps/api/database/migrations/tenant/2025_12_11_054716_add_document_immutability_trigger.php:33-48`). A Confirmed invoice normally remains fiscally `DRAFT` until posting, so its fiscal identity is writable through this service.

I executed both sides against the throwaway PostgreSQL database:

- A legitimate `Confirmed` / fiscal-`DRAFT` invoice numbered `INV-2026-0042`, transitioned to `Posted` with `['document_number' => 'INV-2026-9999']`, committed as `INV-2026-9999`.
- The same attempted change against a `SEALED` row was refused by PostgreSQL with SQLSTATE `23001`.

Therefore the precise answer to contract item 5 is: **Confirmed and unsealed can be renumbered; sealed is protected by the trigger.** This is Critical under the gate's stated rule. The fixture placeholders cited in the final commit do not justify weakening the production invariant. A caller may name a row only when the current number is null (or resupply the identical value); tests that put fabricated `EXP-DRAFT-*` values into drafts should be corrected instead.

### G2 — Important — birth allocation remains on user-authored and user-abandonable Draft paths

The central autosave path is correct: it creates a Draft with `document_number => null` (`apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:249-268`). That fix is not complete across the authored-document surface.

At least these ordinary operator paths still allocate before creating a Draft:

1. **Standalone credit note.** The signed-in UI mounts `DocumentForm` for `/sales/credit-notes/new` (`apps/web/src/routes/index.tsx:774-782`); `DocumentForm` POSTs new documents and then navigates to the created row (`apps/web/src/features/documents/DocumentForm.tsx:390-412`, `apps/web/src/features/documents/DocumentForm.tsx:481-485`). The controller selects standalone mode (`apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:157-168`, `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:190-197`), and the service generates a number before persisting status `Draft` (`apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:1128-1153`, `apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:1181-1199`).
2. **Manual return note.** The UI exposes `/sales/return-notes/create` (`apps/web/src/routes/index.tsx:816-824`) and POSTs `/return-notes` (`apps/web/src/features/documents/CreateReturnNotePage.tsx:281-300`). The controller calls `createDraft()` (`apps/api/app/Modules/Document/Presentation/Controllers/ReturnNoteController.php:154-184`), which generates the number and stores it on a Draft (`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:111-131`, `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:141-159`).
3. **Direct delivery note.** The UI exposes `DocumentForm` at `/inventory/delivery-notes/new` (`apps/web/src/routes/index.tsx:1245-1252`) and maps that type to `/delivery-notes` (`apps/web/src/features/documents/DocumentForm.tsx:97-106`). The controller generates the number (`apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:397-426`) and creates a numbered Draft (`apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:455-470`).
4. **Correcting entry.** This is a user POST, not an automatic conversion (`apps/api/app/Modules/Document/Presentation/Controllers/CorrectingEntryController.php:77-123`). It creates a numbered Draft (`apps/api/app/Modules/Document/Application/Services/CorrectingEntryService.php:56-85`), has a later, separate confirmation action (`apps/api/app/Modules/Document/Presentation/Controllers/CorrectingEntryController.php:126-135`), and may explicitly be deleted while still Draft (`apps/api/app/Modules/Document/Presentation/Controllers/CorrectingEntryController.php:148-158`, `apps/api/app/Modules/Document/Application/Services/CorrectingEntryService.php:240-255`). Its confirm also writes status directly rather than using `DocumentStatusService` (`apps/api/app/Modules/Document/Application/Services/CorrectingEntryService.php:100-120`). This is unambiguously user-abandonable.
5. **Replenishment-created purchase order.** The operator submits the Add-to-PO dialog (`apps/web/src/features/replenishment/components/AddToPoDialog.tsx:67-84`); the application creates a PO and returns its ID without confirming it (`apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:112-158`, `apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:192-193`). `DraftPurchaseOrderService` persists that PO as a numbered Draft (`apps/api/app/Modules/Document/Application/Services/DraftPurchaseOrderService.php:33-67`). The row is linked to its source requests, but an operator can still leave it unconfirmed indefinitely.

This does not require treating every conversion-generated target as in-scope. The direct credit-note, return-note, delivery-note, and correcting-entry routes independently falsify the report's “only system-generated residuals” conclusion. The replenishment PO also shows that “system-generated” does not imply “cannot be abandoned as Draft.” Contract item 3 is not satisfied.

### G3 — Important — three standard confirms do not fold allocation into the status UPDATE

Four standard confirmation paths do satisfy the requested shape:

- Quote: `transition()` under a target-row lock (`apps/api/app/Modules/Document/Presentation/Controllers/QuoteController.php:518-552`).
- Invoice: same (`apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:608-640`).
- Credit note: same (`apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:258-290`).
- Purchase order: the service calls `transition()` (`apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:114-130`), reached from a controller transaction holding a row lock (`apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:701-706`).

The other three call the central service, but through `assignNumberIfMissing()`. That method performs an independent document UPDATE and does not itself require a Draft or perform a transition (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:223-255`):

- Sales order allocates at `apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php:88-97`, then flips status in a later direct update at `apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php:105-111` or `apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php:187-192`.
- Delivery note allocates at `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:146-155`, then flips status and seals at `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:195-205`.
- Return note allocates at `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:632-640`, then flips status and seals at `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:690-699`.

The surrounding transactions make failure rollback the separate number write, but that is weaker than the explicit contract: allocation is observable as a Draft-row mutation before the first transition, and is not folded into the transition UPDATE. For the sealers, generate the number as a local value, hash that value, and include it with the status and seal columns in the final UPDATE. Sales-order reservation notes can likewise consume the local value without first persisting it separately.

### G4 — Important — there is no universal target-row lock preventing same-document double allocation

`DocumentNumberingService` locks the `document_sequences` counter row (`apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:42-67`). That correctly serializes a **sequence**, but it does not serialize two attempts to allocate against the **same document**.

The HTTP controllers for quote, invoice, credit note, purchase order, sales order, and delivery note take `lockForUpdate()` on the target row before the Draft/idempotency check (`apps/api/app/Modules/Document/Presentation/Controllers/QuoteController.php:518-535`, `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:608-625`, `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:258-276`, `apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:701-706`, `apps/api/app/Modules/Document/Presentation/Controllers/SalesOrderController.php:502-507`, `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:558-564`). Those six route paths are protected.

Return-note confirmation is not. Its controller loads without a lock (`apps/api/app/Modules/Document/Presentation/Controllers/ReturnNoteController.php:412-430`). The service opens a transaction and takes product cost advisory locks (`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:503-521`), then checks status on the already-loaded object (`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:540-555`). Its only document `lockForUpdate()` is on a prior fiscal-chain row (`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:594-623`), which locks nothing for a genesis chain and never locks the target row. Public service callers also should not have to depend on a Presentation controller for this invariant.

Consequently the answer to “what lock prevents concurrent double-allocation?” is: the sequence-row lock prevents duplicate sequence values, and six controllers add a target-row lock, but **no lock universally enforces exactly-once allocation for the document itself**. The target row must be locked and refreshed inside every public confirm transaction before checking Draft/number state.

### G5 — Important — Draft sales-order cancellation silently emits an empty document number

`requireDocumentNumber()` correctly makes a null number fail loudly (`apps/api/app/Modules/Document/Domain/Document.php:525-560`), and most fiscal consumers use it. One material exception remains.

The lifecycle permits `Draft -> Cancelled` (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusMachine.php:120-130`). `cancelSalesOrder()` accepts any non-Posted sales order, performs that transition, and schedules its event after commit (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:444-483`). The event builder maps null to `''` (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:767-778`). `SalesOrderCancelled` declares `documentNumber` as a non-null string (`apps/api/app/Modules/Document/Domain/Events/SalesOrderCancelled.php:21-34`), and the compliance subscriber persists that empty value into the audit payload (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:514-529`).

The new cancellation test does not exercise this path: it calls `DocumentStatusService` directly (`apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:308-326`). The existing service cancellation test uses a Confirmed fixture whose helper always manufactures a number (`apps/api/tests/Feature/Document/DocumentCancelConsolidationTest.php:218-260`, `apps/api/tests/Feature/Document/DocumentCancelConsolidationTest.php:308-325`). Thus the gap is reachable and untested.

Because rule 8 makes deployed event shapes immutable (`CLAUDE.md:36-37`), do not change the existing event field in place. The cancellation policy must explicitly distinguish an abandoned, unnumbered Draft (for example, omit this numbered-document event or introduce a versioned event with explicit draft identity) rather than silently storing an empty fiscal identifier.

## PostgreSQL execution record

### Migration hard case — passed

Database: `autoerp_r2_gate_r1_464917ace_test`, created solely for this review. Credentials were read from the worktree's `apps/api/.env`; no configured tenant database was opened or modified.

I first ran the complete migration graph with `APP_ENV=testing` and `DB_CONNECTION=pgsql`; the target tenant migration `2026_08_27_090000_deferred_document_numbering_census` completed in **5.79 ms**. To exercise both PostgreSQL-only DDL halves rather than the already-nullable no-op:

1. In the disposable database only, I removed the target migration's ledger row, restored `documents.document_number NOT NULL`, and inserted one numbered Draft, `QT-2026-0099`.
2. I ran the target by exact path with `php artisan migrate --database=pgsql --path=database/migrations/tenant/2026_08_27_090000_deferred_document_numbering_census.php --force --no-interaction`; it completed in **28.06 ms**.
3. Immediate post-migration checks returned:
   - `information_schema.columns.is_nullable = YES`;
   - numbered Draft count `= 1` and the row still held `QT-2026-0099`;
   - `documents_company_type_number_unique` count `= 0` (no duplicate partial index);
   - unique indexes mentioning `document_number` count `= 1`, namely existing `documents_tenant_id_type_document_number_unique (tenant_id, type, document_number)`.
4. The Laravel log printed both the DDL action and census: `documents.document_number made nullable`, `numbered-draft census: 1`, and `quote: 1 — QT-2026-0099`.

The census implementation is read-only over document rows: it selects Drafts and logs grouped results (`apps/api/database/migrations/tenant/2026_08_27_090000_deferred_document_numbering_census.php:143-190`). The only writes in `up()` are schema DDL (`apps/api/database/migrations/tenant/2026_08_27_090000_deferred_document_numbering_census.php:58-66`, `apps/api/database/migrations/tenant/2026_08_27_090000_deferred_document_numbering_census.php:80-103`, `apps/api/database/migrations/tenant/2026_08_27_090000_deferred_document_numbering_census.php:110-137`). The hard-case row surviving unchanged verifies that the census is non-mutating.

Non-blocking migration observation: the duplicate-index guard treats any `UNIQUE` index definition containing `document_number` as sufficient (`apps/api/database/migrations/tenant/2026_08_27_090000_deferred_document_numbering_census.php:118-129`), rather than verifying exact key columns. It is sufficient for the tested current schema, but a drifted tenant with an unrelated unique index containing that column could make it skip the intended constraint.

### Tests by path on PostgreSQL

All counts below are PHPUnit's exact reported counts; subset runs are explicitly marked and should not be added together.

1. **All eight changed backend Document test files by path — green:** **114 tests, 430 assertions**.
   - `tests/Feature/Document/AutoSaveRouteHardeningTest.php`
   - `tests/Feature/Document/CreateDocumentTest.php`
   - `tests/Feature/Document/DeferredDocumentNumberingTest.php`
   - `tests/Feature/Document/DiscountPolicyDocumentValidationTest.php`
   - `tests/Feature/Document/Types/QuoteControllerTest.php`
   - `tests/Unit/Document/PurchaseOrderServiceTest.php`
   - `tests/Unit/Modules/Document/DraftLineEventV2Test.php`
   - `tests/Unit/Modules/Document/DraftPersistenceServiceTest.php`
2. **Fiscal-critical pair by path — green:** **22 tests, 84 assertions**, with 4 PHPUnit deprecations.
   - `tests/Feature/Compliance/FiscalHardeningE2ETest.php`
   - `tests/Feature/Document/ReturnNoteConfirmSealAndPeriodTest.php`
3. **Deferred-numbering class alone — green subset:** **10 tests, 38 assertions**. This includes the rollback test.
4. **Broader posting/fiscal run — not fully green:** **42 tests, 144 assertions, 1 error**. The isolated error is `DocumentPostingServiceTest::test_revert_clean_purchase_order_to_draft`: PostgreSQL rejects the non-UUID fixture value `confirmed_by = 'user-1'` at `apps/api/tests/Feature/Document/DocumentPostingServiceTest.php:347-353`. Isolated reproduction reports **1 test, 0 assertions, 1 error**. That file is byte-for-byte unchanged across the reviewed range, so this is inherited PostgreSQL fixture debt (and an existing `CLAUDE.md:65-66` rule-17 violation), not evidence introduced by R-2. It still means the broader PG class is not green and should be repaired before relying on it as a PG gate.

The disposable database must not be confused with a tenant clone: tests used only the review database, and it is dropped at the end of this review.

## Allocation and rollback assessment

The rollback test is real, not a mock of the allocator. It begins an actual transaction, calls `DocumentStatusService::transition()`, observes the allocated number inside that transaction, rolls back in `finally`, verifies the Draft number returned to null, and then confirms a second Draft as `QT-<year>-0001` (`apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:232-265`). It passed on PostgreSQL in both the eight-file run and the isolated 10-test run.

The mechanism is sound when called inside an outer transaction: the numbering service's nested transaction locks and increments the sequence row (`apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:42-67`), so Laravel's savepoint remains part of the caller transaction and its counter update rolls back with the confirm. The test proves number reuse after rollback on PostgreSQL. It does **not** repair the separate-update or missing target-lock findings above.

## Nullable-consumer and UI spot checks

### Correctly hardened

- **DN/RN seal input:** both allocate before serialization and call `requireDocumentNumber()` in the hash payload (`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:146-155`, `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:181-191`; `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:632-662`). The split-write problem remains G3, but null cannot silently enter these hashes.
- **Fiscal `post()`:** the posting seal requires the number before hash serialization (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:687-696`) and writes status plus seal columns together (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:707-720`). Posted and fiscal-cancellation events require it (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:732-764`).
- **Confirmed fiscal events:** DN and RN events require the number (`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:254-272`, `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:819-838`). G5 is the one identified cancellation miss.
- **Conversions:** common conversions require both source and target numbers (`apps/api/app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php:245-271`), as do PO-to-goods-receipt (`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/PurchaseOrderToGoodsReceiptConverter.php:145-162`) and purchase-quote-request-to-PO (`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/PurchaseQuoteRequestToPurchaseOrderConverter.php:198-214`).
- **Payments:** paid-event data and allocation candidate output call `requireDocumentNumber()` (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:329-359`, `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:755-793`); the controller does the same before its after-commit paid event (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:1094-1127`).
- **Draft display:** PDF filenames use stable `DRAFT-<short id>` fallback rather than pretending a fiscal number exists (`apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php:119-134`). Credit-note sorting is null-safe and places unnumbered drafts last (`apps/web/src/features/documents/components/CreditNoteList.tsx:65-83`), while the list renders the translation key (`apps/web/src/features/documents/components/CreditNoteList.tsx:203-211`). `draftNumberPlaceholder` exists in Arabic, English, and French (`apps/web/src/locales/ar/sales.json:145`, `apps/web/src/locales/en/sales.json:174`, `apps/web/src/locales/fr/sales.json:174`).

### Rules 8 and 19

No Event class was renamed, removed, or restructured by this lane, so rule 8's shape-immutability requirement is preserved. G5 is instead a bad value supplied to an existing immutable event and must be fixed without mutating that event in place.

I found no new money/quantity float conversion in the reviewed diff. The return-note form preserves quantity and price strings, and the touched backend arithmetic continues through BCMath/scale resolution. The pre-existing `parseFloat(b.total)` in the touched credit-note list (`apps/web/src/features/documents/components/CreditNoteList.tsx:70-79`) violates the literal wording of rule 19 but is unchanged by this range; it is not an R-2 regression.

## N-14 / R-7 inversion and census conclusion

The N-14/R-7 test inversions are legitimate for the autosave contract:

- The first-line autosave now asserts that it authors a row without creating or advancing a sequence (`apps/api/tests/Feature/Document/AutoSaveRouteHardeningTest.php:704-753`).
- The two-save characterization preserves the pre-existing lineless-row behavior while correctly inverting only the number/sequence assertions (`apps/api/tests/Feature/Document/AutoSaveRouteHardeningTest.php:1089-1160`).
- Direct quote creation now expects an unnumbered Draft and no sequence (`apps/api/tests/Feature/Document/CreateDocumentTest.php:354-389`).
- The new regression reproduces nine abandoned one-line drafts and proves that the next confirmed quote gets `0001` (`apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:156-183`).

These tests passed on PostgreSQL. They correctly close the autosave case they cover; they simply do not cover the authored Draft paths in G2. The census is non-mutating, as both its source and the executed hard-case replay show.

## Required changes before re-gate

1. Restore an invariant at `DocumentStatusService::transition()`: a caller may provide a number only if the current number is null or identical. Replace invalid placeholder fixtures instead of preserving a production renumber seam. Add a PG-backed regression for Confirmed/unsealed renumber refusal as well as sealed refusal.
2. Remove birth allocation from every user-authored or user-abandonable Draft path, including at least direct credit note, return note, delivery note, correcting entry, and replenishment PO. Route their first real transition through the allocator and add endpoint/service regressions.
3. Remove `assignNumberIfMissing()` as a separate document write. Generate a local number where pre-status consumers need it, then include it in the same UPDATE as status (and fiscal seal where applicable).
4. Lock and refresh the target document inside each public confirm transaction before checking Draft/number state; do not rely on controller-only locks. Add a real two-connection PostgreSQL confirmation race test.
5. Define explicit semantics for cancellation of an unnumbered Draft sales order without writing `''` to `SalesOrderCancelled`. Preserve rule 8 by versioning rather than mutating an existing event if a new payload shape is needed. Test the actual service/event/subscriber path.
6. Fix the inherited UUID fixture so the broader `DocumentPostingServiceTest` PG class can be used as a green gate.

## Final verdict

**VERDICT: CHANGES**

## r2 scoped re-review

**Date:** 2026-08-27  
**Fix range:** `464917ace..518aff8e1` (single commit)  
**Scope:** G1-G5 only, plus new Critical/Important defects introduced by this fix range  
**Mode:** production code read-only; tests used disposable PostgreSQL databases on `127.0.0.1:5433`, which were dropped after the runs

### G1 — NOT ADDRESSED

The direct r1 exploit is now refused with the typed `DocumentRenumberingException` (`apps/api/app/Modules/Document/Domain/Exceptions/DocumentRenumberingException.php:13-26`). `transition()` compares the caller/in-memory value with `getRawOriginal('document_number')` and throws that type (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:124-138`). The two new regressions cover an explicit replacement and a dirty-model replacement (`apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:430-497`), and the complete class passed on PostgreSQL: **14 tests, 51 assertions**.

That still does not establish the required invariant. The guard trusts the passed model's raw original rather than the database row (`DocumentStatusService.php:124-130`), then performs an unconditional model update (`DocumentStatusService.php:159-165`). A stale model loaded while the row was `Draft`/null therefore still sees `$persistedNumber === null` after another request has confirmed and numbered the database row; it can allocate/stage another number and overwrite the now-non-null database value. The fix range creates an HTTP-reachable instance of exactly this shape: correcting-entry confirm loads without a lock (`apps/api/app/Modules/Document/Presentation/Controllers/CorrectingEntryController.php:126-134`), validates the stale model outside a transaction, and calls `transition()` without refreshing or locking (`apps/api/app/Modules/Document/Application/Services/CorrectingEntryService.php:95-112`). Two concurrent requests can both hold `Draft`/null models, after which the loser overwrites the winner's number.

The typed refusal is therefore real but incomplete. Red-first procedure is also not evidenced: the branch reflog moves directly from `464917ace` to the single combined `518aff8e1` commit, and the range adds test and implementation together with no committed or review artifact recording the failing run. The test scenario clearly contradicts the r1/base behavior, but that is not proof that it was executed red before implementation.

### G2 — ADDRESSED

All five named user-abandonable creators now persist null at birth and reach the central status service on their first transition:

1. Standalone credit note: null at `apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:1172-1182`; confirm calls `DocumentStatusService::transition()` at `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:258-290`.
2. Manual return note: null at `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:131-149`; confirm stages via the central service at `:649-653` and transitions through it at `:689-697`.
3. Direct delivery note: null at `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php:450-465`; confirm stages via the central service at `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:171-175` and transitions through it at `:191-200`.
4. Correcting entry: null at `apps/api/app/Modules/Document/Application/Services/CorrectingEntryService.php:62-75`; first transition calls the central service at `:95-112`.
5. Replenishment PO: null at `apps/api/app/Modules/Document/Application/Services/DraftPurchaseOrderService.php:42-57`; PO confirm calls the central service at `apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:114-130`.

The requested creator execution passed on PostgreSQL: `CreditNoteIntegrationTest::it_generates_sequential_credit_note_numbers_at_confirm` created two null-number drafts, confirmed both, and passed with **1 test, 10 assertions** (`apps/api/tests/Feature/Document/CreditNoteIntegrationTest.php:359-407`). The correcting-entry sequential lifecycle also passed with **2 tests, 17 assertions**, but its newly introduced concurrency/transaction defect is recorded below rather than used to negate the narrow birth/allocator verdict.

### G3 — ADDRESSED

`assignNumberIfMissing()` no longer writes the document; it only calls `setAttribute()` (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:252-264`). The subsequent `transition()` performs the Eloquent save that includes all dirty attributes with the status (`DocumentStatusService.php:159-165`). Sales order stages then transitions at `apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php:88-110` and `:186-190`; delivery note at `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:171-200`; return note at `apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:649-697`. Both sealers stage only after their earlier target-document writes, so no later pre-transition `save()` flushes the staged number separately.

The update-shape regressions inspect Eloquent changes for number+status in one update (`apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:503-591`; `apps/api/tests/Feature/Document/ReturnNoteConfirmSealAndPeriodTest.php:67-91`). They passed in the PostgreSQL runs.

### G4 — NOT ADDRESSED

Return-note confirmation now re-fetches the target with `lockForUpdate()` inside `confirmWithin()` before lifecycle checks (`apps/api/app/Modules/Document/Domain/Services/ReturnNoteService.php:530-556`). Its PostgreSQL query-log test passed (`apps/api/tests/Feature/Document/ReturnNoteConfirmSealAndPeriodTest.php:94-116`; complete class: **10 tests, 32 assertions**).

The r1 required change was universal: lock and refresh the target inside each public confirm transaction and prove the race with two real PostgreSQL connections. The other public confirm services still validate the passed model before opening a transaction and never lock/re-fetch it inside: purchase order (`apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:51-74`), sales order (`apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php:58-77`), and delivery note (`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:75-100`). They are used outside their locking controllers; for example, invoice batch-confirm loads delivery notes without `FOR UPDATE` and passes them directly to the service (`apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:818-839`). The added G4 test merely finds a `FOR UPDATE` query in one RN run; it is not the required two-connection same-document race test. Thus there is still no universal target-row serialization point, and this is also what leaves G1's stale-model bypass reachable.

### G5 — ADDRESSED

Unnumbered sales-order drafts now dispatch a new nullable successor, `SalesOrderCancelledV2`, while numbered cancellations retain `SalesOrderCancelled` (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:768-795`). The subscriber persists null plus the stable draft reference (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:533-553`), with service coverage at `apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:335-365` and subscriber coverage at `apps/api/tests/Feature/Compliance/DomainEventSubscriberTest.php:176-195`. The subscriber test passed on PostgreSQL: **1 test, 3 assertions**.

Rule 8 is preserved. The Event-directory diff contains exactly one added file, `apps/api/app/Modules/Document/Domain/Events/SalesOrderCancelledV2.php:1-32`; no existing Event class is modified. Git resolves `SalesOrderCancelled.php` to the same blob at both endpoints: `202051938735d500b399e1ddb9e873404fc1ae66`.

### New Important introduced by the fix diff

**N1 — correcting-entry first-transition allocation is neither atomic nor serialized.** The fix moves correcting-entry numbering from draft creation to `CorrectingEntryService::confirm()` (`apps/api/app/Modules/Document/Application/Services/CorrectingEntryService.php:56-75`, `:95-112`), but neither the controller nor the service opens an outer transaction or takes a target lock (`apps/api/app/Modules/Document/Presentation/Controllers/CorrectingEntryController.php:126-134`). `DocumentNumberingService` therefore commits its own sequence transaction before the later document update when no outer transaction exists (`apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:42-67`). A failed document update burns a number; concurrent confirms can consume two numbers and let the stale loser overwrite the winner, because G1's guard sees the loser's raw original null. This path did not exist at `464917ace`, where the correcting entry was already numbered at birth inside `create()`'s transaction.

### Verification record

- `git diff --check 464917ace..518aff8e1` — clean.
- PostgreSQL creator flow — **1 passed, 10 assertions**.
- PostgreSQL `DeferredDocumentNumberingTest` — **14 passed, 51 assertions**.
- PostgreSQL `ReturnNoteConfirmSealAndPeriodTest` — **10 passed, 32 assertions**.
- PostgreSQL correcting-entry create/lifecycle subset — **2 passed, 17 assertions**.
- PostgreSQL V2 subscriber test — **1 passed, 3 assertions**.
- All databases were disposable and dropped after execution.

REVERDICT: OPEN
OPEN G1 — stale/null-original models can still overwrite a non-null database number, and RED-first execution is not evidenced.
OPEN G4 — target-row locking is not inside every public confirm service and no real two-connection race regression was added.
OPEN N1 — correcting-entry confirm newly allocates outside an outer transaction/target lock, so failure burns a number and concurrent confirms can renumber.

## r3 scoped re-check

**Date:** 2026-08-28  
**Fix range:** `518aff8e1..576449b5a` (single commit; exactly 3 files)  
**Scope:** prior open G1 and G4, plus new Critical/Important defects introduced by this three-file range  
**Mode:** target worktree read-only; execution used two disposable PostgreSQL databases on `127.0.0.1:5433`, both verified dropped; the retrospective RED replay used a disposable code copy that was removed

### G1 — ADDRESSED

The allocator now makes the database row, rather than the passed model's raw original, the final authority. Its single UPDATE is guarded by the document key, expected `Draft` status, and `document_number IS NULL` (`apps/api/app/Modules/Document/Domain/Services/DocumentStatusService.php:267-271`). It checks affected rows and throws through the transaction boundary on zero so the attempted sequence increment rolls back (`:273-276`). After rollback it reloads the row (`:301-302`), keeps the database winner only when the row is already numbered in the requested target status (`:304-309`), and otherwise refreshes the passed instance and raises `DocumentTransitionException` (`:312-329`). This closes the stale/null-original overwrite seam without trusting the in-memory model.

The stale-model regression really uses two separately loaded instances: the first comes from the fixture and the second from a new query (`apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:508-510`). It confirms the first, proves the second still carries Draft/null in memory, and confirms the stale second (`:511-515`). It then asserts both the returned stale instance and a new database query retain the first number (`:517-522`), the sequence remains at one, and the next document receives `0002` (`:523-534`).

The RED is now reproduced rather than inferred. In a disposable copy containing production code at `518aff8e1` plus the r3 test, the exact stale-model test failed on PostgreSQL at line 517: expected `QT-2026-0001`, actual `QT-2026-0002` (**1 test, 2 assertions, 1 failure**). At `576449b5a`, the same test is green inside the complete class. The pre-existing typed G1 refusal remains pinned for both an explicit replacement and a dirty model (`DeferredDocumentNumberingTest.php:434-500`); both appeared green in the fresh TestDox run.

N1 is consequently closed too: `allocateNumberAndTransition()` wraps generation and the guarded status write in its own transaction (`DocumentStatusService.php:248-282`), so the correcting-entry caller no longer commits sequence allocation separately from its first transition.

### G4 — STILL OPEN

The three-file range does add both requested allocator-level pins. The structural helper requires the emitted UPDATE to end in `WHERE id = ? AND status = ? AND document_number IS NULL` (`apps/api/tests/Feature/Document/DeferredDocumentNumberingTest.php:939-955`), and the DN/RN/SO tests call it. The forked PostgreSQL regression creates two sessions over two stale instances (`:602-657`), proves session B reaches a real PostgreSQL lock wait before A commits (`:690-699`), and asserts that both sessions and the database retain A's number while the sequence remains at one (`:701-737`). It passed.

That race is narrower than the required public-confirmation race: the child directly calls `DocumentStatusService::transition()` (`DeferredDocumentNumberingTest.php:655-657`). The allocator is now a number/status chokepoint, but it is not a serialization point for all confirmation side effects. A live counterexample remains delivery-note confirmation. `DeliveryNoteService` can be called without a target-row lock from invoice batch confirmation (`apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:818-839`); before it stages the number, it releases reservations, issues stock, updates delivered quantities and persists tax data (`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:146-175`). The new staged rollback boundary begins only inside `assignNumberIfMissing()` (`DocumentStatusService.php:409-414`). If that stale request then loses the guarded claim, the same-status reload branch returns success (`DocumentStatusService.php:301-309`), so work performed before the staged savepoint is not rolled back and the public confirm can continue to its event/GL tail. The new test at `DeferredDocumentNumberingTest.php:565-599` proves rollback only for a side effect deliberately written *after* staging; it does not cover this real pre-staging shape.

Thus the first document number is now safe, but G4's original requirement — serialize/refresh the target inside every public confirm transaction and prove the whole confirmation race — is not met. The missing public-service race is material because duplicate stock/fiscal side effects are more than a numbering-display defect.

### New issues in the three-file diff

No separate new Critical/Important issue was found. The confirmation-side-effect race above is the unresolved substance of G4, not a newly introduced defect.

### Verification record

- `git diff --check 518aff8e1..576449b5a` — clean.
- PostgreSQL `DeferredDocumentNumberingTest` at head — **18 tests, 80 assertions**, all green; no skips.
- PostgreSQL `ReturnNoteConfirmSealAndPeriodTest` at head — **10 tests, 33 assertions**, all green; no skips.
- Retrospective PostgreSQL RED at `518aff8e1` with the new stale-model regression — **1 test, 2 assertions, 1 expected failure**, `0001` overwritten by `0002` at test line 517.
- Both disposable databases were dropped and verified absent; the disposable base-code copy was removed.
- Target worktree remained clean at `576449b5a`.

RECHECK: OPEN G4
