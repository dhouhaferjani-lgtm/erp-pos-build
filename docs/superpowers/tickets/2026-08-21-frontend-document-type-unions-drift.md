# Five hand-written `DocumentType` unions have drifted from the backend enum

**Raised by** fiscal-pos gate P3-12 on `feat/r2f4-correcting-documents`, 2026-08-21.
**Status** OPEN. Not fixed in-lane: this is shared front-end surface and the fix
is a typed migration, not a one-liner.

---

## What is there

`packages/shared/types/generated.d.ts` carries the real `DocumentType`,
regenerated from `App\Modules\Document\Domain\Enums\DocumentType` by
`php artisan typescript:transform`. Nothing in `apps/web` or `apps/pos` imports
it. Instead there are five hand-written unions:

| File | Line | Members |
|---|---|---|
| `apps/web/src/features/documents/DocumentListPage.tsx` | 31 | 7 |
| `apps/web/src/lib/entityRoutes.ts` | 8 | (own union, `case` ladders at 78 and 125) |
| `apps/web/src/features/documents/components/RelatedDocumentsPanel.tsx` | 30 | 6 |
| `apps/web/src/hooks/useDraftAutoSave.ts` | 83 | 7 |
| `apps/web/src/features/partners/PartnerDetailPage.tsx` | 67 | 5 |

`DocumentListPage`'s is the de-facto shared one — `DocumentForm.tsx` and
`components/DocumentActions.tsx` import `DocumentType` *from a page component*
to key their `Record<DocumentType, …>` maps.

## The drift

The backend enum has **13** cases. The largest front-end union has **7**. Missing
everywhere: `expense`, `supplier_invoice`, `supplier_credit_note`, `income`,
`purchase_rfq` — and now `correcting_entry`.

Five of those six predate this lane. The drift is years old and silent.

## Why it is invisible

Because the unions are DISCONNECTED, widening the generated type cannot break
them. Adding `correcting_entry` to the backend enum in this lane produced a
regenerated `generated.d.ts` and **zero** front-end type errors — the eight
`Record<DocumentType, …>` maps in `apps/web` are exhaustive over a private
7-member union, not over the real one.

That is the bug. The compiler is being asked to check the wrong contract, so it
reports success on a set of types that no longer describes the system. A
`Record` keyed on the real enum would have failed the build and told us to
decide what a correcting entry's route and title are.

## Consequence today

Any document whose type is outside the local union reaches these components as
an unhandled value: `documentTypeToPath[doc.type]` returns `undefined`, and the
link renders pointing nowhere. Reachable today for expenses, supplier invoices
and income; reachable for correcting entries the moment the related-documents
chain shows them (see `2026-08-21-correcting-entry-chain-visibility-and-quota.md`).

## Fix

1. Export `DocumentType` from `packages/shared` (it is already generated there)
   and re-point all five declaration sites at it, deleting the local unions.
2. Every `Record<DocumentType, …>` then fails to compile until each of the 13
   cases has an answer. That is the point of doing it — the failures ARE the
   inventory of decisions nobody has made.
3. For types with no page (correcting entry, income, purchase_rfq), decide once:
   a non-link label, or an explicit `never`-returning guard. Do not paper over
   it with a fallback that yields a dead link.
4. Add a lint or an architecture test that fails on a locally-declared
   `DocumentType` union, so this cannot silently regrow.

Sequence after the chain-visibility ruling — step 3's answer for
`correcting_entry` depends on whether corrections are shown in the chain at all.
