# Float on the WAC exit path — `DeliveryNoteService` casts quantity to `(float)` into `WeightedAverageCostService`

**Severity:** MEDIUM-HIGH (rule 19, monetary/quantity precision contract).
**Pre-existing** and byte-identical to the lane base, but **NEWLY LOAD-BEARING**:
this is the stock-issuance path the compliance gate now mandates for every
standalone goods invoice.
**Raised by:** 3E code gate — inventory **P2-8**, 2026-08-10.
**Route:** 3A / 3C. **Do not fix in 3E** (gate ruling: pre-existing, ticket only).

---

## The defect

`DeliveryNoteService::issueStock()`:

```php
$movement = $this->wacService->recordSale(
    product: $line->product,
    location: $location,
    quantity: (float) $line->quantity,   // ← rule 19 violation
    ...
);
```

`WeightedAverageCostService::recordSale()` then takes that float and carries it
through the on-hand and valuation arithmetic. `document_lines.quantity` is stored
`decimal(N,4)` precisely so it never becomes a float; the cast throws that away at
the boundary and hands binary-rounded values to the layer that computes inventory
value.

Rule 19, in full: **never let a float touch money or quantity.** Quantities move
as `numeric-string` through `QuantityScale`; the WAC service is the one place in
the exit path where that contract is broken.

## Why it matters more now

Before 3E this ran on operator-chosen delivery confirmations. After T25b/T25c the
guided composite (`create-delivery-and-post`) confirms a delivery note as the ONLY
compliant way to post a standalone goods invoice under `require_delivery_first` —
so every such invoice in every tenant now traverses this cast.

## Fix shape (for 3A/3C, not 3E)

1. Change `WeightedAverageCostService::recordSale()` (and its siblings on the same
   arithmetic) to accept `numeric-string` quantities and use `bc*` throughout,
   scaled by `QuantityScale`.
2. Drop the `(float)` casts at every call site; `DeliveryNoteService` is the one
   on the mandated path, but a sweep is required — the signature change surfaces
   them all.
3. Add the PHPStan guard coverage (`ForbidFloatCastOnDecimalProperty`) once the
   signature no longer invites the cast, so the site cannot regress.

## Cross-references

- `docs/architecture/precision-contract.md` — rule 19, quantity at rest and in
  transit.
- 3E gate: `gate-w3e-code-review-inv.md` P2-8.
- Sibling ticket on the same factory/issuance path:
  `2026-08-10-dn-factory-drops-per-line-location-and-fefo-degrade.md`.
