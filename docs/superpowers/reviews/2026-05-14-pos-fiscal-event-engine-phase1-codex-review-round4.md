# Adversarial review round 4 — POS Fiscal Event Engine Phase 1 spec

**Reviewer:** Codex (round 4)  
**Review date:** 2026-05-14  
**Spec reviewed:** apps/erp/docs/superpowers/specs/2026-05-14-pos-fiscal-event-engine-phase1.md  
**Verdict:** BLOCK  
**Total findings:** 2 BLOCKER, 3 P1, 3 P2, 0 P3

## Executive summary

- Phase 1 is not buildable as specified because `append()` relies on catching a PostgreSQL unique-constraint violation inside the caller's transaction without a savepoint; PostgreSQL leaves the transaction aborted in that state.
- The reality document's Payment-writer inventory is wrong, and the spec inherits that error: three additional Treasury services create `Payment` rows and would bypass the new `origin` / `fiscal_event_id` contract.
- The optional Bridge increment means Phase 1 can ship with zero production producer, while still claiming closure of the round-3 bridge transaction finding. That is not a valid closure criterion.
- The proposed receipt cross-link is ordered after receipt fiscalization, but existing immutability triggers reject post-fiscalization updates, so the cross-link cannot be persisted as written.
- The canonical-money decision is no longer a blocker: PHP and TS both have currency-scale decimal-string formatting, but the golden-vector test set is underspecified for the edge cases that caused the earlier divergence risk.

## Reality-doc verification

The reality document mostly holds up for the POS receipt chain, canonical V3 code, Spatie/audit-events separation, and POS local database scope. Its load-bearing Payment-writer inventory does not hold up.

- Confirmed: `ReceiptFinalizationService::finalize()` locks the terminal, writes receipt hash/sequence, saves terminal chain state, then registers `DB::afterCommit` before returning (`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:54-103`; reality §1.1 lines 12-16).
- Confirmed: `pos_receipts.receipt_type` exists through the later return migration, with `sale` default and sale/return CHECK logic (`apps/api/database/migrations/2026_03_09_200000_add_return_fields_to_pos_receipts.php:23-48`; reality §6.1 lines 154-155).
- Confirmed/resolved: the effective totals CHECK is `total = subtotal + tax_amount - discount_amount`, not the original base-table formula (`apps/api/database/migrations/2026_03_09_200000_add_return_fields_to_pos_receipts.php:39-42`; reality §6.2 lines 157-158).
- Confirmed: existing receipt immutability blocks UPDATE/DELETE paths only; no TRUNCATE trigger exists on `pos_receipts` (`apps/api/database/migrations/2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php:29-67`; reality §1.3 line 46).
- Reality-doc error: §2.3 says the relevant Payment writers are only `PaymentController::store()`, `PaymentController::storeMultiple()`, and `ReceiptPaymentService` (reality lines 70, 74). Code also creates Treasury `Payment` rows in `MultiPaymentService`, `PaymentRefundService`, and `VendorRefundService`; see BLOCKER finding 2.
- Reality-doc internal drift: §7 says Phase 1 "must add OutboxIngestor + route" and "must use integer minor units" (reality lines 166, 170), while the actual Phase 1 spec deliberately excludes outbox/client producer work and uses existing decimal-string money (`spec §1.1 lines 16-27`, `spec §7.1 line 240`). The spec did not inherit those two conclusions, so they are not findings against Phase 1.

## Round-3 findings closure

- **B2:** ADEQUATE. `FiscalEventV1SignatureProvider` is explicitly separated from receipt V3, with a disjoint canonical object and hash formula (`spec §6 lines 187-227`; reality §1.4 lines 30-37).
- **P1.1:** PARTIAL. Passing an already-locked terminal composes with the existing finalization lock, but Phase 1 can omit Bridge entirely and the specified cross-link ordering is broken (`spec §1.1 lines 16-21`, `spec §10.3 lines 359-384`; `ReceiptFinalizationService.php:76-88`).
- **P1.2:** ADEQUATE. PostgreSQL supports statement-level TRUNCATE triggers and TRUNCATE is a grantable table privilege (`spec §8 lines 270-274`; PostgreSQL CREATE TRIGGER docs lines 30-54 and GRANT docs lines 30-31, 121-127).
- **P1.6:** PARTIAL. The synchronous-only source-of-truth rule is clear, but the duplicate-source idempotency implementation is transactionally invalid (`spec §9 lines 293-306`, `spec §10.2 lines 344-357`).
- **P1.7:** INADEQUATE. The spec repeats the reality doc's incomplete Payment-writer list, so new Payment columns would not be stamped consistently (`spec §11 lines 407-417`; reality §2.3 lines 70, 74).
- **P2.1:** ADEQUATE. The `FiscalEventPayload` interface, per-event DTO, registry, and typegen requirement are enough for Phase 1 and align with the JSONB DTO rule (`spec §5 lines 170-183`).
- **P2.2:** PARTIAL. Decimal-string money is reproducible with the existing PHP/TS helpers, but the golden-vector set is not specified strongly enough to prove PHP/JS parity across Unicode and currency-scale edge cases (`spec §7 lines 235-262`; `CurrencyScale.php:20-103`; `apps/pos/src/lib/decimal.ts:10-48`; `apps/pos/src/lib/currency.ts:10-18`).
- **P3.1:** ADEQUATE. `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` are reserved as distinct event types and the spec explicitly separates received-on-account money from charge-to-account debt (`spec §4 lines 147-164`).

## New findings (round-4)

### [BLOCKER] Idempotency by catching unique violation aborts the caller transaction

**Dimension:** implementation  
**Spec location:** §9.3 lines 303-306; §10.1 lines 313-320; §10.2 step 6 lines 344-357  
**Evidence:** PostgreSQL transaction docs state that `ROLLBACK TO` a savepoint is the only way to regain control after a transaction block is put into aborted state by an error (`https://www.postgresql.org/docs/current/tutorial-transactions.html`, lines 55-71). PostgreSQL `SAVEPOINT` docs define savepoints as the mechanism for rolling back only part of a transaction (`https://www.postgresql.org/docs/current/sql-savepoint.html`, lines 31-48). The spec says `append()` does not start a transaction or savepoint and catches a unique violation as idempotent success (`spec §10.1 lines 317-320`, `spec §10.2 line 353`).  
**Issue:** A unique-constraint violation from `UNIQUE (source_event_class, source_event_id)` cannot be caught and ignored inside the existing Laravel/PostgreSQL transaction. After the failed insert, the surrounding receipt finalization or Payment transaction is aborted; selecting the existing event or saving later state will fail unless the insert was inside a savepoint or the whole transaction is restarted.  
**Impact:** Duplicate Bridge replay, retry, or sync ingestion can poison the caller transaction. Instead of idempotent success, the sale/payment finalization rolls back, and the operator sees a failure on an operation the design says is safe to retry.  
**Suggested fix:** Do not use exception-driven idempotency inside the caller transaction. For source-backed events, first query by `(source_event_class, source_event_id)` before sequence allocation and return the existing event if found. Keep the unique index only as a backstop. If a race can still occur, wrap only the insert in an explicit savepoint / nested transaction and on conflict `ROLLBACK TO SAVEPOINT`, then select the existing event; otherwise let the caller retry the full transaction.

### [BLOCKER] Reality doc and spec miss Treasury Payment writers

**Dimension:** implementation  
**Spec location:** §11 lines 407-417  
**Evidence:** Reality §2.3 lists only `PaymentController::store()`, `PaymentController::storeMultiple()`, and `ReceiptPaymentService` as relevant writers (reality lines 70, 74). The spec repeats that exact three-writer scope (`spec §11 lines 412-415`). Code also creates Treasury `Payment` rows in `MultiPaymentService::payWithMultipleMethods()` (`apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:57-74`), `MultiPaymentService::createDepositPayment()` (`MultiPaymentService.php:120-145`), `MultiPaymentService::createOnAccountPayment()` (`MultiPaymentService.php:263-285`), `PaymentRefundService` full/partial/POS refund paths (`apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:59-80`, `149-165`, `428-445`), and `VendorRefundService::processRefund()` (`apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:91-110`).  
**Issue:** Phase 1's Payment migration/model contract is incomplete. Updating only the three named writers leaves real Treasury `Payment` rows with missing or default `origin` and no clear future `fiscal_event_id` semantics.  
**Impact:** Reports, filters, exports, and future fiscal-event joins will produce mixed-origin data. Refunds and multi-payment/deposit flows are especially dangerous because they are financial movements that auditors will ask about first, yet the Phase 1 stamp is absent.  
**Suggested fix:** Replace the three-writer claim with a complete writer inventory from `rg "Payment::create\\(|new Payment\\(|->payments\\(\\)->create\\("`. Add an origin decision table for every Treasury writer: B2B web payment, POS receipt payment, split/multi-payment, deposit/on-account payment, full/partial refund, POS refund reversal, and vendor refund. Make the migration tests assert that every `Payment::create` call sets `origin` explicitly or routes through a single factory/service that does.

### [P1] Bridge is optional but is used to claim closure of the real-producer risk

**Dimension:** scope  
**Spec location:** §1.1 lines 16-21; §9.2 lines 291-301; §10.3 lines 359-384; §12.4 lines 458-461  
**Evidence:** The spec says Phase 1 Core "needs no real producer" and is valid with synthetic tests (`spec §1.1 lines 20-21`, `spec §9.2 line 301`). Bridge is an increment that may ship in Phase 1 "if chosen" (`spec §10.3 lines 359-364`). Round-3 P1.1 was specifically about composition with `ReceiptFinalizationService`, whose current code has the real transaction, terminal lock, receipt save, terminal save, refresh, and after-commit event (`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:54-103`; reality §1.1 lines 12-16).  
**Issue:** Synthetic-event tests can validate serialization and immutability, but they cannot close the transaction/lock ordering finding that only exists when the engine is called from a real producer. If Bridge is deferred, Phase 1 is a dormant engine with no production path exercising its core integration assumptions.  
**Impact:** The team can mark Phase 1 and round-3 P1.1 closed while the first real producer in Phase 2 discovers lock ordering, model-save, trigger, or after-commit conflicts. That pushes foundation risk into the next phase.  
**Suggested fix:** Either make the Bridge integration mandatory for Phase 1 acceptance, or change the closure table to say P1.1 remains open until the Bridge increment lands. If Core-only ships, require at least one integration test that invokes `FiscalEventEngine::append()` from a transaction shaped like `ReceiptFinalizationService`, using a locked `Terminal` and a model save after append.

### [P1] Bridge receipt cross-link is ordered after receipt fiscalization and cannot be saved

**Dimension:** implementation  
**Spec location:** §10.3 lines 365-383; §11 lines 407-410  
**Evidence:** Existing finalization sets `fiscal_hash`, sets `fiscal_status = Fiscalized`, saves the receipt, then updates and saves the terminal (`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:76-82`). The immutability trigger allows `pending_seal -> fiscalized` exactly once and rejects any other update to a fiscalized receipt (`apps/api/database/migrations/2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php:32-57`; reality §1.3 line 46). The spec appends the Bridge event after receipt finalization and then assigns `$receipt->fiscal_event_id = $bridgeEvent->id` (`spec §10.3 lines 365-380`).  
**Issue:** The pseudocode sets the cross-link after the receipt has already become fiscalized. Persisting that assignment requires another UPDATE on `pos_receipts`, which the trigger rejects. If the code does not save it, the cross-link silently never reaches the database.  
**Impact:** Bridge verification loses the promised receipt-to-fiscal-event link, or finalization fails at runtime when the cross-link save is added. Either outcome breaks the audit trail contract.  
**Suggested fix:** Choose one persistence direction. Prefer adding `fiscal_event_id` to `pos_receipts` before the `pending_seal -> fiscalized` save by preallocating the fiscal event id and including it in the one allowed update, then insert the event with that id in the same transaction. If that ordering is too complex, remove the receipt-side FK and rely on `fiscal_events.source_event_class/source_event_id` plus an indexed read model.

### [P1] Duplicate-source uniqueness is underspecified when source fields are partially null

**Dimension:** consistency  
**Spec location:** §2 lines 99-106; §9.3 lines 303-306; §10.1 lines 321-326  
**Evidence:** The schema defines nullable `source_event_class` and `source_event_id` (`spec §2 lines 83-84`) and a partial unique index only where `source_event_id IS NOT NULL` (`spec §2 lines 105-106`). PostgreSQL unique constraints do not treat NULL values as equal under the ordinary unique-index semantics. The duplicate-source Bridge contract depends on the pair being a single idempotency key (`spec §9.3 lines 303-306`).  
**Issue:** The schema does not enforce "both source fields are null or both are non-null." A row with `source_event_id` set and `source_event_class` null can be inserted more than once because the class column is NULL, defeating the intended idempotency key.  
**Impact:** A malformed producer or migration bug can create duplicate fiscal events for one source id while satisfying the written partial index. That is exactly the duplicate Bridge case the spec claims to make impossible.  
**Suggested fix:** Add a CHECK constraint: `(source_event_class IS NULL AND source_event_id IS NULL) OR (source_event_class IS NOT NULL AND source_event_id IS NOT NULL)`. Change the unique index predicate to `WHERE source_event_class IS NOT NULL AND source_event_id IS NOT NULL`, and make `append()` reject a `sourceEventId` without a class before hashing or sequence allocation.

### [P2] `appendStandalone()` is a production footgun

**Dimension:** implementation  
**Spec location:** §10.1 line 342; §10.2 lines 344-357  
**Evidence:** The main `append()` API requires a pre-locked `Terminal` and deliberately does not lock or open a transaction (`spec §10.1 lines 313-320`). The same service exposes `appendStandalone()` as a self-locking helper "for tests/admin backfills" (`spec §10.1 line 342`). Existing receipt creation/finalization has a specific lock order around Terminal and Receipt writes (`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:54-82`; reality §1.1 lines 12-16).  
**Issue:** The spec says production callers should not use `appendStandalone()`, but does not make that enforceable. A future service can accidentally call it inside a broader transaction and introduce a second, hidden terminal-locking path with different lock ordering.  
**Impact:** Deadlocks or sequence gaps can appear only under production concurrency, and code review has no mechanical way to distinguish a legitimate test helper call from a production shortcut.  
**Suggested fix:** Move the standalone helper out of `FiscalEventEngine` into a test-only fixture or an `AdminFiscalEventAppender` service with a loud method name, separate interface binding, and static-analysis rule forbidding calls from non-test namespaces. Production services should receive only an interface that exposes `append()`.

### [P2] Golden-vector coverage is underspecified for the chosen canonical grammar

**Dimension:** implementation  
**Spec location:** §7 lines 235-262; Appendix B lines 507-518  
**Evidence:** The spec requires PHP and TS golden-vector parity tests but only names two broad cases: `SALE_RECEIPT_BRIDGE` and a "future ACCOUNT_PAYMENT fixture" (`spec §7.3 lines 253-260`). Current PHP encoder sorts keys with `SORT_STRING`, rejects floats, and uses JSON-unescaped Unicode (`apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/CanonicalJsonEncoder.php:51-108`). Current TS encoder rejects non-integer numbers and sorts keys with JS lexical comparison (`apps/pos/src/lib/fiscal/v3/canonicalJson.ts:35-87`). PHP and TS money helpers do exist for decimal-string formatting (`apps/api/app/Shared/Domain/CurrencyScale.php:20-103`; `apps/pos/src/lib/decimal.ts:10-48`; `apps/pos/src/lib/currency.ts:10-18`).  
**Issue:** Decimal-string money is defensible, but the proposed fixture set does not force the risky cases: TND 3-decimal formatting, zero-decimal currency formatting, negative refund-like amount, NFC normalization, U+2028/U+2029 stripping, multibyte strings, non-ASCII object keys, empty arrays, and null optional fields.  
**Impact:** The golden-vector suite can pass while PHP and TS still diverge on an input shape Phase 2 introduces, especially once client-originated events become real.  
**Suggested fix:** Define a minimum vector matrix in Phase 1: one TND 3-decimal amount, one zero-decimal currency, one 2-decimal currency, one negative amount, one payload with empty arrays/null optionals, one multibyte/NFC case, one U+2028/U+2029 producer-normalization case, and one non-ASCII key ordering case. Require both PHP and TS implementations to assert the exact canonical string and final SHA-256 hash.

### [P2] Reserved event types are allowed by the database before their DTOs exist

**Dimension:** consistency  
**Spec location:** §2 lines 111-114; §4 lines 147-164; §5 lines 180-183  
**Evidence:** The `event_type` CHECK includes all Phase 2-5 reserved types immediately (`spec §2 lines 111-114`, `spec §4 lines 150-160`). The DTO registry only has a concrete DTO for implemented event types in Phase 1, and `append()` rejects unregistered types at the application layer (`spec §4 line 162`, `spec §5 lines 180-183`).  
**Issue:** The database accepts event types the Phase 1 application cannot serialize, validate, or project. That is inconsistent with the spec's claim that unsupported types are rejected unless every insert is forced through `FiscalEventEngine`. The migration does not state a DB privilege model that prevents direct app-role inserts.  
**Impact:** A maintenance script, future migration, or accidental raw insert can create fiscally sealed rows that the Phase 1 verifier cannot deserialize through a typed payload. That undermines the "JSONB needs a DTO" rule the spec is trying to enforce.  
**Suggested fix:** In Phase 1, constrain `event_type` to only implemented types, currently `SALE_RECEIPT_BRIDGE`, or add an `implemented_event_types` lookup table enforced by FK. Future phases can add event types in migrations at the same time as DTOs and signature vectors. If the team keeps reserved DB values, revoke direct INSERT on `fiscal_events` from the application role and expose only a SECURITY DEFINER function that calls the typed path.
