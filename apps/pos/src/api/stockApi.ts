/**
 * Task 9 — GET /pos/stock-levels client.
 *
 * Returns the terminal's own location stock (paginated, PER_PAGE=500
 * server-side) plus the COMPLETE `incoming` snapshot (page 1 only — later
 * pages carry `incoming: []`) and the server-issued `as_of` watermark.
 *
 * Uses `apiGetRaw` deliberately: `apiGet` unwraps `response.data` and
 * drops `meta.pagination`, which would silently stop the sync loop after
 * page 1 (see syncService.pullLocationStock).
 *
 * Quantities are scale-4 decimal STRINGS end-to-end — never parseFloat.
 */
import { apiGetRaw, type ApiRequestOptions } from '@/lib/api';
import type {
  ServerIncomingRow,
  ServerStockRow,
} from '@/lib/db/repositories/locationStockRepository';

export interface LocationStockPage {
  data: {
    stock: ServerStockRow[];
    incoming: ServerIncomingRow[];
    /**
     * Server-issued watermark for the NEXT delta pull's `updated_since`.
     * Persist it verbatim — never substitute device time (clock skew
     * between terminal and server would drop or replay rows).
     */
    as_of: string;
  };
  meta: {
    pagination: {
      current_page: number;
      last_page: number;
      total: number;
    };
  };
}

export async function fetchLocationStock(
  terminalId: string,
  params: { updated_since?: string; page?: string },
  opts?: ApiRequestOptions,
): Promise<LocationStockPage> {
  return apiGetRaw<LocationStockPage>(
    '/pos/stock-levels',
    { terminal_id: terminalId, ...params },
    opts,
  );
}
