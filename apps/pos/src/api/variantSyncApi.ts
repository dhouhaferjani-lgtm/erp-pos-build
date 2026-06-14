/**
 * FV2 — GET /pos/variants client.
 *
 * Returns the tenant's active variant catalog (paginated) plus tombstone
 * IDs (deleted_ids) and the server-issued `as_of` watermark.
 *
 * Uses `apiGetRaw` deliberately: `apiGet` unwraps `response.data` and drops
 * `meta.pagination`, which would silently stop the sync loop after page 1
 * (same reason as stockApi.ts).
 *
 * M1 fix H-1: the caller threads `updated_until` = page-1's `as_of` on
 * every subsequent page so the multi-page delta is read from a single
 * snapshot window.
 */
import { apiGetRaw, type ApiRequestOptions } from '@/lib/api';
import type { ServerVariantRow } from '@/lib/db/repositories/variantRepository';

export interface VariantFeedPage {
  data: { variants: ServerVariantRow[]; deleted_ids: string[]; as_of: string };
  meta: { pagination: { current_page: number; last_page: number; total: number } };
}

export async function fetchVariants(
  // `updated_until` pins the delta read window across all pages of one sync
  // (M1 fix H-1): page 1 omits it and the server mints as_of=now(); pages 2+
  // echo page-1's as_of so every page shares the same upper bound.
  params: { updated_since?: string; updated_until?: string; page?: string },
  opts?: ApiRequestOptions,
): Promise<VariantFeedPage> {
  return apiGetRaw<VariantFeedPage>('/pos/variants', params, opts);
}
