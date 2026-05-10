/**
 * C2 — Menu-tenant catalog composite ID helper.
 *
 * Menu-tenant POSProducts encode `(sellable_id, menu_category_id)` as a
 * single underscore-delimited composite string so the same sellable
 * cross-listed across two menu categories surfaces as two distinct rows
 * in the local `products` table (which keys on `id`). For standard-retail
 * tenants the `id` stays a bare sellable UUID and `parseMenuCompositeId`
 * returns `categoryId = null` — both forms round-trip safely through the
 * helper.
 *
 * Why `_` and not `:` — Codex review (PR #107 round 2 P2): the cashier
 * image-cache writes files as `${productId}.${ext}` at
 * `apps/pos/src/lib/images/imageCache.ts:172`. Colon is not a valid
 * filename character on Windows (Tauri targets Win11 desktop builds
 * for IziPOS), so a colon-delimited id would break image caching for
 * Menu products on that OS. Underscore is filename-safe across every
 * platform we ship to AND does not appear in the UUID alphabet
 * (`[0-9a-f-]+`), so the delimiter stays unambiguous.
 *
 * Any input that does not have exactly two parts is treated as a legacy
 * bare id (sellableId = input, categoryId = null) so a malformed cart
 * line does not crash the cashier UI.
 *
 * See `docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md`
 * for the full Shape-2 design.
 */

const DELIMITER = '_';

export function buildMenuCompositeId(sellableId: string, categoryId: string): string {
  return `${sellableId}${DELIMITER}${categoryId}`;
}

export function parseMenuCompositeId(id: string): {
  sellableId: string;
  categoryId: string | null;
} {
  const parts = id.split(DELIMITER);
  if (parts.length !== 2) {
    return { sellableId: id, categoryId: null };
  }
  return { sellableId: parts[0]!, categoryId: parts[1]! };
}
