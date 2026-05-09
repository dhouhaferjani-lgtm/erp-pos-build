/**
 * T2.1 Step B — three-tier scan resolver.
 *
 * Resolves a barcode/SKU scan code through three tiers in order:
 *   1. **In-memory** (`productStore.products`) — O(n), fast for n ≤ 5000.
 *      Matches `barcode === code || sku === code` (preserved from the
 *      pre-T2.1 inline scan handler at HomePage.tsx).
 *   2. **SQLite** (`getProductByBarcode(db, code)`) — `~10 ms`. The
 *      function name is legacy; it actually does
 *      `barcode = $1 OR sku = $1`.
 *   3. **API** (`fetchProductByBarcode(code, opts)`) — `≤ 5 s` (tunable).
 *      The backend filter accepts `barcode` query param and matches
 *      barcode OR sku server-side.
 *
 * Tier 3 result classification:
 *   - 0 results → `{ kind: 'miss' }`
 *   - exactly 1 → `{ kind: 'hit', product }` + SQLite write-through so the
 *     next scan in the same session is a Tier 2 hit.
 *   - >1 results → `{ kind: 'choose', candidates }` — the caller mounts a
 *     chooser modal (collision UX, matches Toast / Shopify POS pattern).
 *
 * Pre-tier guard: codes shorter than 2 chars or whitespace-only are
 * treated as phantom scanner events and short-circuit to `'miss'`
 * without any tier work.
 *
 * Logging: Tier 3 errors are caught + logged via `serializeErrorForLog`
 * (T0.1 contract) and downgraded to `{ kind: 'miss' }` so the cashier
 * sees the existing "product not found" UX rather than an unhandled
 * rejection.
 */
import type Database from '@tauri-apps/plugin-sql';
import type { POSProduct } from '@/types/product';
import {
  getProductByBarcode,
  upsertProducts,
} from '@/lib/db/repositories/productRepository';
import { fetchProductByBarcode } from '@/api/productApi';
import { serializeErrorForLog } from '@/lib/errorLogging';

/**
 * Tunable Tier 3 (API) timeout. The cashier sees an inline subtle
 * "Looking up…" spinner during this tier; longer than 5 s feels stuck.
 * Tunable here at the top of the file per cashier feedback.
 */
export const SCAN_API_TIMEOUT_MS = 5_000;

const MIN_SCAN_CODE_LENGTH = 2;

export type ResolveScannedCodeResult =
  | { kind: 'hit'; product: POSProduct }
  | { kind: 'choose'; candidates: POSProduct[] }
  | { kind: 'miss' };

export interface ResolveScannedCodeDeps {
  db: Database;
  products: POSProduct[];
  /** Optional caller-provided abort signal — supports concurrent-scan cancellation. */
  signal?: AbortSignal;
}

export async function resolveScannedCode(
  rawCode: string,
  deps: ResolveScannedCodeDeps,
): Promise<ResolveScannedCodeResult> {
  const code = rawCode.trim();
  if (code.length < MIN_SCAN_CODE_LENGTH) {
    return { kind: 'miss' };
  }

  // Tier 1 — in-memory snapshot. Preserves barcode OR sku disjunction
  // from the pre-T2.1 inline handler.
  const inMemoryHit = deps.products.find(
    (p) => p.barcode === code || p.sku === code,
  );
  if (inMemoryHit) {
    return { kind: 'hit', product: inMemoryHit };
  }

  // Tier 2 — SQLite. Function name is legacy ("getProductByBarcode")
  // but the underlying SQL does `barcode = $1 OR sku = $1`.
  try {
    const sqliteHit = await getProductByBarcode(deps.db, code);
    if (sqliteHit) {
      return { kind: 'hit', product: sqliteHit };
    }
  } catch (err) {
    // SQLite read failure → log + fall through to Tier 3. The cashier
    // sees the API tier's outcome; the SQLite bad state is captured
    // for diagnosis.
    console.error(
      '[POS][resolveScannedCode] SQLite tier failed, falling through to API',
      serializeErrorForLog(err),
    );
  }

  // Tier 3 — API. Caller-provided signal threads through to apiGet so
  // a subsequent scan can abort the in-flight call.
  try {
    const apiResults = await fetchProductByBarcode(code, {
      timeoutMs: SCAN_API_TIMEOUT_MS,
      signal: deps.signal,
    });
    if (apiResults.length === 0) {
      return { kind: 'miss' };
    }
    if (apiResults.length === 1) {
      const product = apiResults[0]!;
      // Write-through to SQLite so the next scan of this code is a
      // Tier 2 hit. Best-effort: a write failure here doesn't change
      // the result.
      try {
        await upsertProducts(deps.db, [product]);
      } catch (err) {
        console.error(
          '[POS][resolveScannedCode] SQLite write-through failed (non-fatal)',
          serializeErrorForLog(err),
        );
      }
      return { kind: 'hit', product };
    }
    // Multiple matches — collision UX. Caller resolves with chooser
    // modal; no auto-pick.
    return { kind: 'choose', candidates: apiResults };
  } catch (err) {
    console.error(
      '[POS][resolveScannedCode] API tier failed',
      serializeErrorForLog(err),
    );
    return { kind: 'miss' };
  }
}
