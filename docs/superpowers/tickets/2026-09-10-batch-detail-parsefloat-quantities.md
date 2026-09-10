# Batch quantity display precision follow-up

Status: deferred. Owner: BatchExpiry frontend.
Authority: W-LOT-A-1a inventory gate r1 I-8 and gate r2 frontend F-2/F-3; plan rev 11 §00.

`apps/web/src/features/batches/pages/BatchDetailPage.tsx:273`, `:276`, `:279`, `:291`, `:296`, `:301` convert stock quantity strings through `parseFloat`. `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:215`, `:222`, `:229` render on-hand, reserved and available with the deprecated `formatQuantity` from `@/lib/format`. That formatter trims trailing zeros and groups by locale, losing the unit-precision display contract. These production lines remain unchanged in A-1a round 2.

Use decimal-string `formatQuantity` from `@/lib/decimal` with `getQuantityDecimals(batch.product)` for these quantities. Confirm each endpoint eager-loads `product.unitOfMeasure` and exposes truthful `quantity_decimals` metadata in its frontend type before changing formatting. `getQuantityDecimals` silently defaults to 4 when metadata is absent or invalid; the same fallback can hide an unloaded backend unit, so a default-only fixture cannot prove unit precision.

Acceptance: rendered-output tests cover fractional and large quantities, retained trailing zeros, differing currency/unit precision and a product unit whose precision is not 4. Cover genuinely nullable unit metadata separately. Keep stock mutation behavior unchanged.
