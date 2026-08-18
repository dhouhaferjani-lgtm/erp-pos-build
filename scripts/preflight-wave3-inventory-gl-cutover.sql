\set ON_ERROR_STOP on

-- DPA Wave 3C inventory-GL cutover gate.
--
-- Run this file separately against EVERY deploy-target tenant database before
-- promotion and retain the three result sets with the tenant identifier. Any
-- exception is a hard stop; do not weaken the cutover migration or delete a
-- Posted/hash-chained journal entry to make this pass.

SELECT COUNT(*) AS legacy_invoice_cogs_count
FROM journal_entries
WHERE source_type = 'cogs';

DO $preflight$
BEGIN
    IF EXISTS (SELECT 1 FROM journal_entries WHERE source_type = 'cogs') THEN
        RAISE EXCEPTION 'Wave 3C blocked: legacy source_type=cogs entries require accounting disposition';
    END IF;
END
$preflight$;

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
HAVING COUNT(*) > 1
ORDER BY source_type, source_id;

DO $preflight$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM journal_entries
        WHERE source_type IN (
            'inventory_exit',
            'inventory_entry',
            'inventory_shrinkage',
            'batch_write_off',
            'batch_write_off_reversal'
        )
        GROUP BY source_type, source_id
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Wave 3C blocked: duplicate movement-keyed journal entries require accounting disposition';
    END IF;
END
$preflight$;

SELECT sm.company_id, COUNT(*) AS non_physical_delivery_movement_count
FROM stock_movements AS sm
JOIN document_lines AS dl
  ON dl.document_id = sm.reference_id
 AND dl.product_id = sm.product_id
JOIN products AS p
  ON p.id = dl.product_id
 AND p.tenant_id = sm.tenant_id
 AND p.company_id = sm.company_id
WHERE sm.reason = 'delivery'
  AND sm.reference_type = 'Document'
  AND p.is_physical = FALSE
GROUP BY sm.company_id
ORDER BY sm.company_id;

DO $preflight$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM stock_movements AS sm
        JOIN document_lines AS dl
          ON dl.document_id = sm.reference_id
         AND dl.product_id = sm.product_id
        JOIN products AS p
          ON p.id = dl.product_id
         AND p.tenant_id = sm.tenant_id
         AND p.company_id = sm.company_id
        WHERE sm.reason = 'delivery'
          AND sm.reference_type = 'Document'
          AND p.is_physical = FALSE
    ) THEN
        RAISE EXCEPTION 'Wave 3C blocked: pre-T4 non-physical delivery movements require remediation';
    END IF;
END
$preflight$;

