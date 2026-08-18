# Decide whether return scrap should share the inventory-exit source type

**Severity:** LOW — D-21 consistency follow-up, no current accounting gap.
**Owner:** Inventory + Accounting architecture owner.

`ReturnScrapWriteOffService` keeps the shipped `batch_write_off` source type.
Wave 3C changes only when that entry posts: T16d queues it and posts it at the
owning receipt flush, with the same accounts, amount, source id, narrative, and
idempotency contract. `InventoryGlSourceTypes::ALL`, the partial unique index,
and detector D-a already include this source type, so re-labelling it during the
atomic cutover would add risk without closing a defect.

If uniform reporting later requires `inventory_exit`, treat the change as a
source-schema migration: inventory all consumers and historical rows, preserve
idempotency, update the accepted-source constant and partial index together,
and prove fiscal/hash bytes and reversal behavior remain valid.

