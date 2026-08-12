# Inventory-GL unique-index cutover requires a per-tenant duplicate-count precondition

**Severity:** HIGH (deployment-blocking tenant migration precondition).
**Raised by:** wave3-3c M2 adversarial review round 7, P3-10.
**Disposition:** ship-with-ticket and **hard pre-promotion deploy step** under
`ORCHESTRATOR-RULING-2026-08-18-m2-stop-a.md` ruling 5.

## Risk

`2026_08_11_000100_unique_journal_entries_source_inventory_movement.php` deliberately fails closed
when any tenant has duplicate `(source_type, source_id)` rows for an inventory movement source.
The indexed population includes the already-shipped `batch_write_off` and
`batch_write_off_reversal` writers. One duplicate in one tenant aborts `tenants:migrate` and blocks
promotion before later tenants can complete.

M0’s R-11 query ran only against the local wave database and its sample denominator was zero. Its
result is vacuous and **must not** be cited as deploy evidence.

## Hard pre-promotion step

Before the migration is promoted, run the duplicate-count query against **every deploy-target
tenant database** for every value in `InventoryGlSourceTypes::ALL`. Record the tenant identifier and
integer count. Promotion requires count `0` for every tenant.

The query shape is:

```sql
SELECT source_type, source_id, COUNT(*) AS duplicate_count
FROM journal_entries
WHERE source_type IN (
    'inventory_exit',
    'inventory_entry',
    'inventory_shrinkage',
    'batch_write_off',
    'batch_write_off_reversal'
)
GROUP BY source_type, source_id
HAVING COUNT(*) > 1;
```

## Non-zero remediation

1. Stop promotion; do not bypass or weaken the unique index.
2. Export the duplicate rows with tenant, entry id, status, fiscal hash/chain coordinates, and
   source coordinates.
3. Obtain accounting/fiscal disposition for each duplicate before changing data.
4. Re-run and record a zero count per tenant, then promote.

M3’s T19 deploy notes must link this ticket and retain it as a hard pre-promotion gate.
