# Residual minors from gate CF (rounds 1–2)

**Raised by:** gate CF code review + re-review, fiscal and frontend halves.
**Status:** OPEN. None can write a wrong fiscal fact; all were explicitly ruled MINOR by
the reviewing gates and deferred out of the cancel-flow lane.

---

## m3 (fiscal) / m6 (FE) — `return_decision` is hand-duplicated on `types/document.ts`

`apps/web/src/types/document.ts` declares a literal object type for `return_decision`
while `packages/shared/types/generated.d.ts` already carries
`App.Modules.Document.Application.DTOs.ReturnDecisionProjection`, generated from the PHP
DTO by `php artisan typescript:transform`.

**Why it was not fixed in-lane:** `types/document.ts` is a wholly hand-written file that
predates the lane — its `Document` interface mirrors `DocumentData` field by field
already. Making one property reference the generated namespace while its ~40 siblings do
not is a half-migration that reads as an inconsistency rather than a convention.

**The work:** retire the hand-written `Document` interface for the generated
`DocumentData` (the same swap plan CF's T8 flagged as preferable for `ReturnNote`), or
migrate the whole file at once. Rule 7 says generated types are the source of truth; this
file is the standing exception, and it should stop being one.

**Risk if left:** the two can drift. A field added to the PHP DTO will not appear here,
and a hand-edit here will not fail any check.

---

## m4 (fiscal) — `checkCancellable()` resolves delivered quantities twice per request

`RefundController::checkCancellable()` calls `RefundService::hasGoodsIssued()` and
`RefundService::deliveredQuantities()`, and each one runs
`DeliveredQuantityResolver::resolve()` from scratch — so the delivery-note traversal, the
per-tuple aggregation and the prior-return netting all execute twice for one HTTP request.
Round 2 widened that traversal further (the mirror direction), so the duplicated cost grew.

**The work:** memoise the resolution per invoice id for the lifetime of the request —
either a small per-instance cache keyed on `$invoice->id` inside
`DeliveredQuantityResolver`, or resolve once in the controller and pass the tuples into
both predicates.

**Risk if left:** performance only, and bounded by the number of delivery notes on one
invoice. Correctness is unaffected: the resolver is a pure read.

---

## m5 (fiscal) — `Product::is_service` is a dead predicate in three services

`$line->product->is_service ?? false` appears in `ReturnNoteService::receiveStockBack()`,
`DeliveryNoteService::issueStock()` and `SalesOrderService`, but `Product` has **no
`is_service` column and no accessor** — so the expression is always `false` and the
service-line branch it guards is unreachable.

The live non-physical test is `product_id === null`, which is what a service line actually
is: `CreateDocumentRequest`'s `lines.*.service_id` carries
`prohibits:lines.*.product_id`. Plan CF keyed its own `requires_return_decision` predicate
on exactly that, so the read model and the restock path agree — but the dead expression
remains and reads as if it were doing something.

**The work:** delete the three `is_service` clauses, or add a real
`is_service` accessor on `Product` if the intent was a genuine flag. Deleting is
preferred: `is_physical` already exists and carries the meaning.

**Risk if left:** a future reader "fixes" the predicate by adding an `is_service` column,
which would silently change which lines restock on every delivery and return note.

---

## m7 (FE) — RESOLVED in round 2

The "draft" state word in `option1.hint` / `option2.scope` is now interpolated from
`sales:returnNotes.status.draft` in both en and fr. Recorded here only so the review trail
is complete.
