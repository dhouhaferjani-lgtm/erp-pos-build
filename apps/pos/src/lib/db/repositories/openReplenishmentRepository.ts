import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll, queryOne } from '@/lib/db';

export interface ServerOpenReplenishmentRow {
  id: string;
  product_id: string;
  variant_id: string | null;
  status: string;
  requested_qty: string | null;
  request_count: number;
  last_requested_at: string;
}

export interface OpenReplenishmentCacheRow {
  tenant_id: string;
  company_id: string;
  request_id: string;
  product_id: string;
  variant_id: string;
  status: string;
  requested_qty: string | null;
  request_count: number;
  last_requested_at: string;
  fetched_at: string;
}

function assertPresent(field: string, value: string): void {
  if (value.trim() === '') {
    throw new Error(`[replenishment] ${field} is required`);
  }
}

function assertScope(tenantId: string, companyId: string): void {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);
}

export async function replaceOpenRequests(
  db: Database,
  tenantId: string,
  companyId: string,
  rows: ServerOpenReplenishmentRow[],
  fetchedAt: string,
  deleteAbsent = true,
): Promise<void> {
  assertScope(tenantId, companyId);
  assertPresent('fetched_at', fetchedAt);

  for (const row of rows) {
    await execute(
      db,
      `INSERT INTO open_replenishment_cache (
         tenant_id, company_id, request_id, product_id, variant_id, status,
         requested_qty, request_count, last_requested_at, fetched_at
       )
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
       ON CONFLICT(tenant_id, company_id, request_id) DO UPDATE SET
         product_id = excluded.product_id,
         variant_id = excluded.variant_id,
         status = excluded.status,
         requested_qty = excluded.requested_qty,
         request_count = excluded.request_count,
         last_requested_at = excluded.last_requested_at,
         fetched_at = excluded.fetched_at`,
      [
        tenantId,
        companyId,
        row.id,
        row.product_id,
        row.variant_id ?? '',
        row.status,
        row.requested_qty,
        row.request_count,
        row.last_requested_at,
        fetchedAt,
      ],
    );
  }

  if (!deleteAbsent) return;

  if (rows.length === 0) {
    await execute(
      db,
      'DELETE FROM open_replenishment_cache WHERE tenant_id = $1 AND company_id = $2',
      [tenantId, companyId],
    );
    return;
  }

  const placeholders = rows.map((_, index) => `$${index + 3}`).join(', ');
  await execute(
    db,
    `DELETE FROM open_replenishment_cache
      WHERE tenant_id = $1
        AND company_id = $2
        AND request_id NOT IN (${placeholders})`,
    [tenantId, companyId, ...rows.map((row) => row.id)],
  );
}

export async function getOpenRequestForProduct(
  db: Database,
  tenantId: string,
  companyId: string,
  productId: string,
  variantId: string,
): Promise<OpenReplenishmentCacheRow | null> {
  assertScope(tenantId, companyId);
  assertPresent('product_id', productId);

  return queryOne<OpenReplenishmentCacheRow>(
    db,
    `SELECT *
       FROM open_replenishment_cache
      WHERE tenant_id = $1
        AND company_id = $2
        AND product_id = $3
        AND variant_id = $4
        AND status IN ('pending', 'in_progress')
      ORDER BY last_requested_at DESC
      LIMIT 1`,
    [tenantId, companyId, productId, variantId],
  );
}

export async function getAllOpenRequests(
  db: Database,
  tenantId: string,
  companyId: string,
): Promise<OpenReplenishmentCacheRow[]> {
  assertScope(tenantId, companyId);

  return queryAll<OpenReplenishmentCacheRow>(
    db,
    `SELECT *
       FROM open_replenishment_cache
      WHERE tenant_id = $1
        AND company_id = $2
      ORDER BY last_requested_at DESC, request_id`,
    [tenantId, companyId],
  );
}
