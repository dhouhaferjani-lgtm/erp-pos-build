# Wave D — Multi-batch FEFO demo design

**Status:** Approved by the owner through `docs/handoff/CODEX-replenishment-followups-2026-07-12.md`  
**Branch:** `feat/replenishment-multibatch-fefo-demo` from local `dev` `684e5a198`  
**Scope:** Demo data and live browser evidence only

## Outcome

The Tunisia demo tenant will contain four batch-tracked products whose warehouse stock is represented by three active, sellable batches with staggered expiries. A replenishment transfer for `4.0000` units will consume `3.0000` from the earliest batch and `1.0000` from the second batch. The existing transfer-detail UI must show both allocations in FEFO order.

The batch-allocation rendering prerequisite is already merged into `dev` (`e324330ba`, containing `e77a9436b`). Its focused backend and frontend tests are green before Wave D changes.

## Data contract

`DemoPharmacySeeder` will add a dedicated, idempotent warehouse fixture after its existing default-batch reconciliation:

- Select exactly four non-variant, batch-tracked products at `WH-01`, in stable SKU/ID order, each with at least `9.0000` aggregate stock.
- Exclude products that already have positive warehouse stock in a non-default, non-Wave-D batch. This avoids overwriting organic demo data on a rerun.
- Create or reconcile three product-specific batches named `DEMO-FEFO-<ordinal>-A`, `-B`, and `-C`.
- Set their expiries to 90, 180, and 365 days after the seed date; keep them active, unexpired, and not recalled.
- Allocate warehouse quantities as `3.0000`, `4.0000`, and the remaining aggregate stock respectively.
- Set that product's warehouse `DEFAULT` batch stock to `0.0000`, leaving shop lots untouched.
- Preserve the invariant that warehouse batch quantities sum exactly to `StockLevel.quantity` at decimal scale 4.
- Re-running the seeder updates the same batches and batch-stock rows without duplicates.

If fewer than four safe candidates exist, seeding must fail clearly rather than silently weaken the browser fixture.

## Browser proof

Using the real local db-per-tenant stack and `owner@pharmabio.tn`:

1. Create a replenishment request from a shop for the first fixture product.
2. From the replenishment review queue, create a warehouse transfer for `4.0000` units.
3. Open the resulting transfer detail.
4. Verify two allocations are visible in API order: the `-A` batch for `3.0000`, then the `-B` batch for `1.0000`, with the earlier expiry first.
5. Capture the rendered allocation section at `docs/superpowers/reviews/screenshots/2026-07-19-wave-d/replenishment-fefo-two-batch-transfer.png` and record the durable request/transfer identifiers in the gate report.

## Boundaries

No changes to FEFO allocation services, transfer rendering, fiscal surfaces, permissions, migrations, per-location reorder policy, franchise/intercompany fulfillment, partial settlement, or notifications. Deployment requires only rerunning `DemoPharmacySeeder` for the demo tenant.

