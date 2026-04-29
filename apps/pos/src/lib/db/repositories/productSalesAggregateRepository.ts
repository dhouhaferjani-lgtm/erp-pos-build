/**
 * Minimal executor surface satisfied by both `@tauri-apps/plugin-sql`'s
 * `Database` (production) and `SqliteTestAdapter` (tests). Avoids a hard
 * dependency on the Tauri client, which doesn't run in node test envs.
 */
export interface DbExecutor {
  select<T>(sql: string, params?: unknown[]): Promise<T>;
}

export interface AggregateOptions {
  /** Window size in days; rows older than (now - sinceDays) are ignored. */
  sinceDays: number;
}

/**
 * Receipt line shape as written by `apps/pos/src/lib/offline/receiptService.ts`.
 * Either `product_id` or `composite_item_id` is set, never both — depending on
 * `cartItem.product.sellableType`. We aggregate under whichever is present so
 * the sort order matches the IDs used by `POSProduct.id` in the grid.
 */
interface ReceiptLine {
  product_id?: unknown;
  composite_item_id?: unknown;
  quantity?: unknown;
}

/**
 * Aggregate units sold per product from offline_receipts.lines (JSON blob)
 * within a recent date window. Pure read-only — never mutates the receipts.
 *
 * Returns a Map<productOrCompositeId, totalQuantity>. Malformed JSON rows
 * are skipped.
 */
export async function aggregateProductSales(
  db: DbExecutor,
  { sinceDays }: AggregateOptions,
): Promise<Map<string, number>> {
  const rows = await db.select<{ lines: string }[]>(
    `SELECT lines FROM offline_receipts
     WHERE created_at >= datetime('now', $1)`,
    [`-${sinceDays} days`],
  );

  const counts = new Map<string, number>();
  for (const row of rows) {
    let parsed: unknown;
    try {
      parsed = JSON.parse(row.lines);
    } catch {
      continue;
    }
    if (!Array.isArray(parsed)) continue;

    for (const raw of parsed as ReceiptLine[]) {
      const rawId =
        typeof raw.product_id === 'string'
          ? raw.product_id
          : typeof raw.composite_item_id === 'string'
            ? raw.composite_item_id
            : null;
      const qty = raw.quantity;
      if (rawId === null || typeof qty !== 'number' || !Number.isFinite(qty)) {
        continue;
      }
      counts.set(rawId, (counts.get(rawId) ?? 0) + qty);
    }
  }
  return counts;
}
