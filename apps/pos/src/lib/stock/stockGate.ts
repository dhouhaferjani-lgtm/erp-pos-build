/**
 * Task 11 — the single stock gate for every cart ingress (spec §4.5).
 *
 * Lesson L9 (canonicalize-before-state-machine-input): EVERY path that can
 * add stock-relevant quantity to the cart routes through `gateStockForAdd`
 * — product tile, barcode scan, chooser pick, variant-picker confirm,
 * modifier confirm, smart-prompt add, and the cart-line quantity increase.
 * The UI funnels live in `cartIngress.ts`; this module is the pure decision.
 *
 * Policy source: `terminal.pos_stock_policy` from the terminal payload
 * (Task 2 — company-level `PosStockPolicy`). A missing field defaults to
 * 'block' (fail-safe for retail; Menu tenants are backfilled to 'off').
 *
 *   'off'   → always sellable, availability is NEVER consulted (no DB read).
 *   'warn'  → over-stock adds are allowed but surfaced ({warn:true}).
 *   'block' → over-stock adds are rejected ({ok:false}).
 *
 * Fail-open boundary: when the offline SQLite DB is unavailable (browser /
 * non-Tauri dev) or there is no active company, the gate returns ok with a
 * console.warn — the gate must never brick the browser dev flow. This is the
 * ONLY fail-open in the stock chain; the availability selector itself fails
 * toward enforcement (absent `is_physical` is treated as physical).
 */
import type { POSProduct } from '@/types/product';
import { bccomp } from '@/lib/decimal';
import { getDatabase } from '@/lib/db';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { getEffectiveAvailable, type AvailabilityCartLine } from './availability';

export type StockGateResult =
  | { ok: true; warn: false }
  | { ok: true; warn: true; available: string }
  | { ok: false; available: string };

const PASS: StockGateResult = { ok: true, warn: false };

/**
 * Decide whether `requestedQty` MORE of (product, variant) may enter the cart.
 *
 * @param requestedQty  The quantity being ADDED (usually '1'; the delta for
 *                      quantity edits) — a decimal string, never a float.
 * @param cartLines     The CURRENT cart (`useCartStore.getState().items`);
 *                      read-only — the gate never mutates it.
 */
export async function gateStockForAdd(
  product: POSProduct,
  variantId: string | null,
  requestedQty: string,
  cartLines: ReadonlyArray<AvailabilityCartLine>,
): Promise<StockGateResult> {
  const policy = useTerminalStore.getState().terminal?.pos_stock_policy ?? 'block';
  if (policy === 'off') {
    return PASS;
  }

  const companyId = useAuthStore.getState().companyId;
  if (!companyId) {
    console.warn('[POS][stockGate] no active company — failing open');
    return PASS;
  }

  let available: string | null;
  try {
    const db = await getDatabase(companyId);
    available = await getEffectiveAvailable(db, product, variantId, cartLines);
  } catch (error) {
    // Browser/non-Tauri dev (no SQLite plugin) or a local DB blip — the gate
    // must never block selling on its own infrastructure failure.
    console.warn('[POS][stockGate] offline DB unavailable — failing open', error);
    return PASS;
  }

  // null = not stock-managed (composite/Menu sellables, services) → exempt.
  if (available === null) {
    return PASS;
  }

  if (bccomp(requestedQty, available) <= 0) {
    return PASS;
  }

  if (policy === 'warn') {
    return { ok: true, warn: true, available };
  }
  return { ok: false, available };
}

/**
 * Display formatting for an availability quantity: trim trailing zeros so
 * whole numbers read naturally ('5.0000' → '5', '2.5000' → '2.5'). The POS
 * has no shared formatQuantity helper (checked 2026-06-12) — keep this next
 * to the gate so both toast call sites and Task 12's badge share it.
 */
export function formatAvailableQty(available: string): string {
  if (!available.includes('.')) {
    return available;
  }
  return available.replace(/0+$/, '').replace(/\.$/, '');
}
