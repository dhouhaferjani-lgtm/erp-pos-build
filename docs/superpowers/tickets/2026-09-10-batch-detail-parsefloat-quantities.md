# Batch quantity display precision follow-up

Status: deferred. Owner: BatchExpiry frontend.
Authority: W-LOT-A-1a inventory gate r1 I-8 and frontend quantity consumer census.

`BatchDetailPage.tsx` stock rendering uses `parseFloat` on quantity strings (on-hand, reserved and available). `BatchListPage.tsx` uses currency decimals for a lot quantity. Current references: `apps/web/src/features/batches/pages/BatchDetailPage.tsx:273`, `:276`, `:279`, `:291`, `:296`, `:301`; `apps/web/src/features/batches/pages/BatchListPage.tsx:23` and `:219`. Both precede A-1a; the new four-decimal string totals do not require changing these displays in this lane.

Replace detail float conversion with decimal-string formatting and use the owning unit's decimal places via `getQuantityDecimals` for list quantities. Keep API and stock mutation behavior unchanged. Cover fractional quantities, large quantities, differing currency/unit precision and nullable unit metadata with rendered-output tests. Resolve the display contract before changing the visible rounding.
