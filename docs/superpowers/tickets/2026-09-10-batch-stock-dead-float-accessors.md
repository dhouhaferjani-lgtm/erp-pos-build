# Remove legacy BatchStock float accessors and mutation helpers

Status: deferred. Owner: BatchExpiry inventory.
Authority: W-LOT-A-1a gate r2 inventory I-4; plan rev 11 §00.

Review `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:44-71`: the float `available_quantity` accessor and `reserve`, `releaseReservation`, `adjustQuantity` helpers are legacy debt. The helpers have no production callers in the reviewed lane census, but an Eloquent accessor can still shadow a decimal cast when code reads the property. Absence of explicit accessor calls does not prove absence of execution.

Follow-up: census property reads and helper calls, remove unused writers, and replace any retained availability path with BCMath decimal strings at QuantityScale::SCALE. Verify fractional/reserved quantities and transfer availability without float conversion. Preserve stock and reservation ownership in the existing services. Do not change schema or introduce another stock write path.

The entity is unchanged by W-LOT-A-1a, including fix round 2. This ticket records debt, not a claim that live quantity reads are all float-free.
