# T1-5: Product-detail stock view hides transfer transit and defaults quantity to scale 2

Status: open · severity: medium · owner: Inventory/Product stock read surfaces

Symptom: ship 4 units from a source to a destination with no stock row. Matrix incoming is 4.0000; `/api/v1/products/{id}/stock-levels` returns only the source, `incoming: "0.00"`, `totals.incoming: "0.0000"`. Its field currently means confirmed purchase-order remainder, so it is not a fourth reader of transfer transit.

Seam: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1073-1093` sums confirmed-PO remainder only; `:1091` defaults a quantity to the scale-2 literal `'0.00'`. Fix the visibility semantics and the quantity formatting independently.

Benchmark: parent transfers brief §0 inter-site transit location guarantee; T-1 case 5 requires consistent transfer transit readings. Rule 19 requires quantity-scale strings, with product-unit precision at presentation.

Proposed fix: agree on separate PO and transfer incoming fields (or a clearly documented combined total), reuse the inventory read contract, and include active destinations with incoming but no stock row. Format using the product unit precision rather than a hardcoded monetary-looking zero.

Acceptance: source 6/destination incoming 4 on a 10-unit/4-unit fixture, zero transfer incoming after cancel/complete, PO remainder preserved independently, second-company/second-location isolation, and unit-precision zero formatting. The API and service transcripts in the T-1 evidence distinguish the two meanings until this ticket lands.
