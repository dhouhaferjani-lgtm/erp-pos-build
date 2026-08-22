# NF525 grand totals carry TWO different `net_sales` semantics, and the export shows neither

**Filed:** 2026-08-22 · **Origin:** LEDGER C-6 item 3 (finding F-5), gate finding P3-5
**Status:** OPEN — recorded, not scheduled. No known incorrect number ships today.

## The divergence

Two independent code paths both produce a `period_totals` array consumed by
`Nf525XmlBuilder::addGrandTotals()`, and they define `net_sales` differently.

| Path | Producer | `net_sales` is | Base |
|---|---|---|---|
| Legacy grand-total events | `GrandtotalService::calculatePeriodTotals()` (`apps/api/app/Modules/POS/Domain/Services/GrandtotalService.php:185`) | `gross_sales − tax_amount`, computed at the end of the loop | POST ticket discount, POST cash rounding (it starts from `Receipt::total`) |
| Canonical device Z | `Nf525DataProvider::mapCanonicalZReportGrandTotal()` → the device's `receipt_totals.net_sales` | Σ of each sealed receipt's canonical `subtotal` (`device subtotal − tax_amount`, C-6) | PRE ticket discount, PRE cash rounding |

The two agree exactly on any shift with no ticket-level discount and no cash
rounding, and diverge by (Σ discounts + Σ rounding adjustments) otherwise.

## Why nothing is wrong on the wire today

`Nf525XmlBuilder::addGrandTotals()` (`:335-336`) emits only `VentesBrutes`
(`gross_sales`) and `Taxe` (`tax_amount`) inside `<TotauxPeriode>`. **It never
emits `net_sales` at all**, from either path. So the divergence is currently
invisible in the exported document — which is exactly why it can sit here as a
note rather than a defect.

`<VentesNettes>` *is* exported, but from a different element:
`addZReports()` (`:299`) reads `reportData['net_sales']`, i.e. the per-Z figure,
which after C-6 is unambiguously the canonical-receipt net. That one is settled.

## The trap this note exists to prevent

Anyone who later adds `<VentesNettesPeriode>` (or similar) to `<TotauxPeriode>`
will pick up whichever semantics their test fixture happens to exercise, and the
two paths will silently disagree in production — legacy terminals reporting one
definition, device-authored Zs the other, in the same export, under the same
element name.

**Before adding any net figure to `<TotauxPeriode>`, pick one definition and
converge both producers on it.** The C-6 lane settled the equivalent question
for the per-Z figure in favour of the canonical-receipt net (Σ sealed receipt
`subtotal`), on the grounds that it reconciles EXACTLY against the receipt corpus
an auditor holds; the same reasoning applies here.

## Not in scope for C-6

C-6 fixed only that `<TotauxPeriode>` was exporting `0.00`/`0.00` for every
canonical Z (F-5) — the provider now emits the flat keys the builder reads. It
did not touch `GrandtotalService`, did not change the builder's element set, and
deliberately did not converge the two definitions: that is a semantics decision
with an owner-visible consequence for already-sealed grand-total events, whose
`period_totals` are `json_encode`d into their own fiscal hash
(`GrandtotalService.php:103,:114`) and therefore cannot be recomputed in place.

## References

- `apps/api/app/Modules/POS/Domain/Services/GrandtotalService.php:185`
- `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` — `mapCanonicalZReportGrandTotal()`, `mapGrandTotal()`
- `apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:299`, `:335-336`
- `apps/api/tests/Feature/Compliance/Nf525CanonicalZGrandTotalPeriodTotalsTest.php`
- LEDGER C-6; C-2 M1 ruling `docs/handoff/reviews/z-sale-branch-decomposition/M1-ruling.md`
