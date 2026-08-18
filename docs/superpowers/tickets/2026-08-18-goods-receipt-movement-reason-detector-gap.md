# Record a canonical reason on goods-receipt stock movements

**Severity:** LOW — detector coverage gap.
**Owner:** Procurement + Inventory + Accounting architecture.

`WeightedAverageCostService::recordPurchase()` creates the inbound movement
used by a posted goods-receipt line but currently leaves `reason` NULL. GR-IR is
posted separately as `source_type = goods_receipt`; it is not a movement-keyed
`InventoryGlSourceTypes::ALL` entry. Consequently D-f can prove that the linked
movement exists, but the reason-driven D-a/D-b/D-e checks intentionally do not
classify that inbound row.

Choose and document the accounting ownership before setting
`MovementReason::GoodsReceipt`: either keep GR-IR document-keyed and explicitly
exclude it from movement-keyed coverage, or adopt a movement-keyed inbound GL
contract and migrate its idempotency/source tuple atomically. Add a real posted
goods-receipt test; do not satisfy the detector with a synthetic
`inventory_entry` journal row that production never writes.
