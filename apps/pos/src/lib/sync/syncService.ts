import type Database from '@tauri-apps/plugin-sql';
import { apiGet, apiPost, apiPostRaw, ApiRequestError } from '@/lib/api';
import { parseMenuCompositeId } from '@/lib/menu/compositeId';
import {
  upsertProducts,
  deleteProducts,
  reconcileMenuProducts,
} from '@/lib/db/repositories/productRepository';
import {
  deleteForProducts as deleteLocationStockForProducts,
  upsertStockRows,
  replaceAllStock,
  replaceIncoming,
  type ServerIncomingRow,
  type ServerStockRow,
} from '@/lib/db/repositories/locationStockRepository';
import { deleteDistributionForProducts } from '@/lib/db/repositories/crossLocationStockRepository';
import { fetchLocationStock, type LocationStockPage } from '@/api/stockApi';
import { flattenMenuToProducts } from '@/api/productApi';
import {
  upsertPaymentMethods,
  upsertPaymentRepositories,
} from '@/lib/db/repositories/paymentRepository';
import { upsertOperators, pruneOperatorsExcept } from '@/lib/db/repositories/operatorPinRepository';
import Big from 'big.js';
import {
  upsertTerminalState,
  setShiftNumberSeed,
  upsertZChainState,
  getZChainState,
  type TerminalHashState,
} from '@/lib/db/repositories/terminalStateRepository';
import {
  getPendingPinUpdates,
  markPinUpdateSynced,
  markPinUpdateFailed,
} from '@/lib/db/repositories/queuedPinUpdateRepository';
import {
  getPendingAuditEvents,
  markAuditEventsSyncing,
  markAuditEventSynced,
  markAuditEventFailed,
  type QueuedAuditEvent,
} from '@/lib/db/repositories/queuedAuditEventRepository';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { upsertFloors, upsertTables } from '@/lib/db/repositories/tableRepository';
import {
  upsertMenuCategories,
  upsertMenuCategoryItems,
  deleteMenuCategories,
  deleteMenuCategoryItems,
} from '@/lib/db/repositories/menuRepository';
import type { FloorData } from '@/api/tableApi';
import type { ModifierGroup } from '@/types/modifier';
import { computeGenesisHash } from '@/lib/fiscal/hashService';
import {
  updateReceiptStatus,
  cleanupSyncedReceipts,
  cleanupStuckReceipts,
} from '@/lib/db/repositories/offlineReceiptRepository';
import {
  fiscalEventToWireEnvelope,
  getPendingFiscalEventsForSync,
  recoverStrandedSyncingFiscalEvents,
  updateFiscalEventSyncStatus,
  type FiscalEventSyncBatchResponse,
} from '@/lib/db/repositories/fiscalEventRepository';
import {
  getUnsyncedZReports,
  markZReportSynced,
} from '@/lib/db/repositories/zReportRepository';
import {
  getPendingCashDrawerOps,
  updateCashDrawerOpStatus,
  cleanupSyncedCashDrawerOps,
} from '@/lib/db/repositories/cashDrawerRepository';
import { logSyncOperation, getSyncMetadata, setSyncMetadata, cleanupOldSyncLogs } from '@/lib/db/repositories/syncLogRepository';
import { coerceSyncError } from '@/lib/sync/coerceSyncError';
import { reconcileOpenShift, applyShiftReconcileVerdict } from '@/lib/sync/shiftReconcile';
import { withWriteTransaction } from '@/lib/db/writeGate';
import {
  upsertVouchers,
  upsertVoucherLedgerEntries,
  upsertReceiptQrIndexEntries,
  getPendingVoucherLedgerEntries,
  markVoucherLedgerEntrySynced,
  markVoucherLedgerEntryFailed,
  type LocalVoucher,
  type LocalVoucherLedgerEntry,
  type LocalReceiptQrIndexEntry,
} from '@/lib/offline/voucherRepository';
import type { LocalZReport } from '@/lib/offline/types';
import type { POSProduct } from '@/types/product';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';

interface OperatorPinData {
  id: string;
  name: string;
  email: string;
  pin_hash: string;
  roles: string[];
  permissions: string[];
  can_discount: boolean;
  max_discount_percent: number | null;
}

interface TerminalStateResponse {
  id: string;
  code: string;
  location: { code: string | null } | null;
  genesis_seed: string;
  last_hash: string | null;
  hash_sequence: number;
  /**
   * Server-side fiscal_schema_version. Optional in the wire shape so a stale
   * server (pre-B1) does not 500 the client; if absent we fall back to 2 —
   * the legacy default. The receipt-creation path always reads the stored
   * column, so a stale pull just keeps the local terminal on v2 until the
   * server roll-out adds the field.
   */
  fiscal_schema_version?: number;
  /**
   * Offline-first shifts Phase 6.1: the server's current
   * `MAX(pos_shifts.shift_number)` for this terminal. Cached into
   * `terminal_state.shift_number_seed` so a freshly-installed device continues
   * numbering monotonically instead of restarting at 1. Optional in the wire
   * shape so a stale server (pre-6.1) does not break the pull; absent → seed 0.
   */
  max_shift_number?: number;
}

export interface SyncResult {
  receiptsPushed: number;
  receiptsFailed: number;
  zReportsPushed: number;
  zReportsFailed: number;
  cashDrawerOpsPushed: number;
  pinUpdatesPushed: number;
  voucherLedgerPushed: number;
  voucherLedgerFailed: number;
  productsPulled: number;
  paymentConfigPulled: boolean;
  operatorsPulled: number;
  terminalStatePulled: boolean;
  tablesPulled: boolean;
  activeMenuPulled: boolean;
  vouchersPulled: number;
  voucherLedgerPulled: number;
  receiptQrIndexPulled: number;
  chainBreak: boolean;
  errors: string[];
  /**
   * T1.3 Step 4.3: tristate sync-indicator signal. True when the tick
   * completed but with at least one observable failure that the cashier
   * needs to know about. Drives the amber dot in `SyncButton`.
   *
   * Heuristic (canonical Phase-4 spec, line 264 of
   * pos-offline-first-hardening.md):
   *   - receiptsFailed > 0  (sales didn't sync)
   *   - zReportsFailed > 0  (Z-reports didn't sync)
   *   - !paymentConfigPulled (gate trips downstream)
   *   - errors.length > 0   (anything else surfaced a string error)
   *
   * Explicitly does NOT include `productsPulled === 0` — a tenant
   * with no products yet is a valid empty state, not degraded
   * (round-1 false-positive guard from the canonical spec).
   */
  degraded: boolean;
}

/**
 * T1.3 Step 4.3: compute the `degraded` tristate signal for SyncResult.
 *
 * Returns true when the just-completed tick had at least one observable
 * failure that the cashier needs to know about — drives the amber dot
 * in `SyncButton`.
 *
 * Heuristic (canonical Phase-4 spec, line 264 of
 * pos-offline-first-hardening.md):
 *   - receiptsFailed > 0     (sales didn't sync)
 *   - zReportsFailed > 0     (Z-reports didn't sync)
 *   - !paymentConfigPulled   (gate trips downstream)
 *   - errors.length > 0      (anything else surfaced a string error)
 *
 * Explicitly excludes `productsPulled === 0` — a tenant with no
 * products yet is a valid empty state, not degraded.
 *
 * Exported for unit testing; production callers compose it inline at
 * the bottom of `runFullSync`.
 */
export function computeDegraded(input: {
  receiptsFailed: number;
  zReportsFailed: number;
  paymentConfigPulled: boolean;
  errors: string[];
}): boolean {
  return (
    input.receiptsFailed > 0 ||
    input.zReportsFailed > 0 ||
    !input.paymentConfigPulled ||
    input.errors.length > 0
  );
}

/**
 * Detect if an error indicates a hash chain break (server-side hash mismatch).
 * These errors are unrecoverable without operator intervention.
 */
function isChainBreakError(error: unknown): boolean {
  const msg = (
    error instanceof Error ? error.message :
    typeof error === 'string' ? error :
    ''
  ).toLowerCase();
  return msg.includes('hash chain') || msg.includes('hash mismatch') || msg.includes('chain break');
}

/**
 * H4: the server retires the legacy Z-report sync endpoint for cutover
 * (v3 device-authority) terminals — it returns 409 with code
 * `Z_SESSION_DEVICE_AUTHORITY_REQUIRED` because the device-authored canonical
 * Z_REPORT fiscal event is the authority and syncs via the fiscal-event path.
 * Without recognising this, the device retries the legacy push forever.
 */
function isLegacyZSyncRetiredError(error: unknown): boolean {
  return error instanceof ApiRequestError && error.code === 'Z_SESSION_DEVICE_AUTHORITY_REQUIRED';
}

/**
 * Push device-authored fiscal events to the server.
 *
 * Kept under the legacy function name because the scheduler and UI badge
 * still model "receipt push" as the operator-visible workflow. The transport
 * is no longer `/pos/receipts/sync`; it posts immutable fiscal-event envelopes
 * to `/pos/sync/fiscal-events`.
 */
export async function pushOfflineReceipts(db: Database): Promise<{
  pushed: number;
  failed: number;
  errors: string[];
  chainBreak: boolean;
}> {
  await recoverStrandedSyncingFiscalEvents(db);

  const pending = await getPendingFiscalEventsForSync(db);
  let pushed = 0;
  let failed = 0;
  let chainBreak = false;
  const errors: string[] = [];

  for (const event of pending) {
    try {
      await updateFiscalEventSyncStatus(db, event.id, 'syncing');

      // RAW post — the controller returns a TOP-LEVEL { results } with no
      // { data, meta } envelope; apiPost's unwrap yields undefined and the
      // historical `response.results` crash (receipts sealed but never
      // synced). Error responses keep the { error } shape ApiRequestError
      // parses, so only the success path needs the raw body.
      const response = await apiPostRaw<FiscalEventSyncBatchResponse>(
        '/pos/sync/fiscal-events',
        { envelopes: [fiscalEventToWireEnvelope(event)] },
        { timeoutMs: 30_000 },
      );

      if (response.results.length === 0) {
        throw new Error(`Sync response missing result for fiscal event ${event.id}`);
      }
      if (response.results.length !== 1) {
        throw new Error(`Sync response expected one result for fiscal event ${event.id}, got ${response.results.length}`);
      }

      const resultItem = response.results[0];
      if (!resultItem) {
        throw new Error(`Sync response missing result for fiscal event ${event.id}`);
      }
      if (resultItem.fiscal_event_id !== event.id) {
        if (resultItem.fiscal_event_id !== null) {
          throw new Error(`Sync response missing result for fiscal event ${event.id}`);
        }
        if (!resultItem.sequence_conflict && resultItem.exception_class === null) {
          throw new Error(`Sync response returned null fiscal_event_id without rejection for fiscal event ${event.id}`);
        }
      }

      if (
        resultItem.fiscal_event_id === event.id
        && !resultItem.sequence_conflict
        && resultItem.exception_class === null
      ) {
        await updateFiscalEventSyncStatus(db, event.id, 'synced');
        if (event.source_event_class === 'offline_receipts' && event.source_event_id !== null) {
          await updateReceiptStatus(db, event.source_event_id, 'synced');
        }
        await logSyncOperation(
          db,
          'push',
          'fiscal_event',
          event.id,
          'success',
          resultItem.stored ? 'stored' : 'idempotent',
        );
        pushed++;
      } else if (resultItem.sequence_conflict || isChainBreakError(resultItem.exception_class)) {
        const reason = resultItem.exception_class ?? 'sequence_conflict';
        await updateFiscalEventSyncStatus(db, event.id, 'failed', reason);
        await logSyncOperation(db, 'push', 'fiscal_event', event.id, 'error', reason);
        chainBreak = true;
        errors.push(`CHAIN_BREAK at fiscal event ${event.id}`);
        failed++;
        break;
      } else {
        const reason = resultItem.exception_class ?? 'failed';
        await updateFiscalEventSyncStatus(db, event.id, 'failed', reason);
        await logSyncOperation(db, 'push', 'fiscal_event', event.id, 'error', reason);
        errors.push(`Fiscal event ${event.id}: ${reason}`);
        failed++;
      }
    } catch (error) {
      if (error instanceof FetchTimeoutError) {
        console.warn('[POS][sync][pushOfflineReceipts] fiscal-event push timed out — leaving pending for next tick', {
          ...serializeErrorForLog(error),
          url: error.url,
          timeoutMs: error.timeoutMs,
          method: error.method,
          fiscalEventId: event.id,
          sequenceNumber: event.sequence_number,
        });
        await updateFiscalEventSyncStatus(db, event.id, 'pending');
        await logSyncOperation(db, 'push', 'fiscal_event', event.id, 'success', 'timeout — pending for retry');
        continue;
      }

      const message = coerceSyncError(error);
      console.error('[POS][sync][pushOfflineReceipts] fiscal-event push threw', {
        ...serializeErrorForLog(error),
        fiscalEventId: event.id,
        sequenceNumber: event.sequence_number,
      });
      await updateFiscalEventSyncStatus(db, event.id, 'failed', message);
      await logSyncOperation(db, 'push', 'fiscal_event', event.id, 'error', message);
      errors.push(`Fiscal event ${event.id}: ${message}`);
      failed++;

      if (isChainBreakError(error)) {
        chainBreak = true;
        errors.push('CHAIN_BREAK: Sync halted. Operator must resolve hash chain conflict.');
        break;
      }
    }
  }

  return { pushed, failed, errors, chainBreak };
}

/**
 * Push unsynced Z-reports to server.
 * Processes sequentially to maintain Z-report chain order.
 */
export async function pushZReports(db: Database): Promise<{
  pushed: number;
  failed: number;
  errors: string[];
}> {
  const unsynced = await getUnsyncedZReports(db);
  let pushed = 0;
  let failed = 0;
  const errors: string[] = [];

  for (const zReport of unsynced) {
    try {
      await apiPost('/pos/reports/z/sync', zReportToSyncPayload(zReport));
      await markZReportSynced(db, zReport.id, new Date().toISOString());
      await logSyncOperation(db, 'push', 'z_report', zReport.id, 'success');
      pushed++;
    } catch (error) {
      // H4: cutover (v3) terminal — the legacy Z mirror is retired server-side.
      // Mark it synced so it is never retried; the canonical Z_REPORT fiscal
      // event already carries authority and syncs via the fiscal-event path.
      if (isLegacyZSyncRetiredError(error)) {
        await markZReportSynced(db, zReport.id, new Date().toISOString());
        await logSyncOperation(
          db,
          'push',
          'z_report',
          zReport.id,
          'success',
          'legacy Z sync retired (cutover) — superseded by device-authored Z fiscal event',
        );
        continue;
      }

      const message = coerceSyncError(error);
      await logSyncOperation(db, 'push', 'z_report', zReport.id, 'error', message);
      errors.push(`Z-Report ${zReport.formatted_z_number}: ${message}`);
      failed++;

      // On chain-break, halt
      if (isChainBreakError(error)) {
        errors.push('Z-REPORT CHAIN_BREAK: Sync halted.');
        break;
      }
    }
  }

  return { pushed, failed, errors };
}

/**
 * Push offline cash drawer operations to server.
 * No chain ordering required — these are independent operations.
 */
export async function pushCashDrawerOps(db: Database): Promise<{
  pushed: number;
  errors: string[];
}> {
  const pending = await getPendingCashDrawerOps(db);
  let pushed = 0;
  const errors: string[] = [];

  for (const op of pending) {
    try {
      await updateCashDrawerOpStatus(db, op.id, 'syncing');
      await apiPost(`/pos/cash-drawer/${op.type}`, {
        idempotency_key: op.idempotency_key,
        amount: op.amount,
        reason: op.reason,
        terminal_id: op.terminal_id,
        shift_id: op.shift_id,
        operator_id: op.operator_id,
        approval_id: op.approval_id,
        approval_fiscal_event_id: op.approval_fiscal_event_id,
        approval_scope: op.approval_scope,
        approval_supervisor_user_id: op.approval_supervisor_user_id,
        approval_target_hash: op.approval_target_hash,
        created_at: op.created_at,
      });
      await updateCashDrawerOpStatus(db, op.id, 'synced');
      await logSyncOperation(db, 'push', 'cash_drawer_op', op.id, 'success');
      pushed++;
    } catch (error) {
      const message = coerceSyncError(error);
      await updateCashDrawerOpStatus(db, op.id, 'failed', message);
      await logSyncOperation(db, 'push', 'cash_drawer_op', op.id, 'error', message);
      errors.push(`Cash drawer ${op.type} ${op.id}: ${message}`);
    }
  }

  return { pushed, errors };
}

export function zReportToSyncPayload(report: LocalZReport): Record<string, unknown> {
  return {
    id: report.id,
    terminal_id: report.terminal_id,
    shift_id: report.shift_id,
    z_number: report.z_number,
    formatted_z_number: report.formatted_z_number,
    generated_at: report.generated_at,
    fiscal_hash: report.fiscal_hash,
    previous_hash: report.previous_hash,
    hash_sequence: report.hash_sequence,
    report_data: report.report_data,
    opening_cash: report.opening_cash,
    expected_cash: report.expected_cash,
    receipt_snapshots: report.receipt_snapshots,
    grand_totals: report.grand_totals,
    cash_counts: report.cash_counts ?? [],
    shift_fields: report.shift_fields ?? null,
    manager_user_id: report.manager_user_id ?? null,
    tolerance_summary: report.tolerance_summary ?? {
      totalAmount: '0.000',
      currencyCode: report.currency_code ?? 'EUR',
      writeoffCount: 0,
    },
  };
}

/**
 * Pull products delta from server with pagination.
 * Uses per_page=500 and loops until a page returns fewer than 500 items.
 * Consumes the `deleted_ids` tombstone list returned by the server when
 * `updated_since` is present, removing locally-cached rows for soft-deleted
 * server-side products.
 */
/**
 * T2.1 Step A — typed error class for `pullProductsCore` rejections.
 *
 * Distinguishes the three classes of failure the foreground caller
 * (`pullProductsForeground` → `productStore.fetchProducts`) needs to
 * surface to the cashier-facing error banner: HTTP 5xx (server is down /
 * overloaded), network (Tauri-side fetch failure / no DNS / no route),
 * and parse (server returned non-JSON or malformed JSON).
 *
 * Timeout is propagated as the existing `FetchTimeoutError` (T0.3) — not
 * wrapped into `PullProductsError` — so callers that already discriminate
 * timeout vs other errors (e.g. the receipt-sync POST path) continue to
 * see the same shape.
 */
export class PullProductsError extends Error {
  constructor(
    public readonly kind: 'http_5xx' | 'network' | 'parse',
    public readonly cause: unknown,
    public readonly status?: number,
  ) {
    const causeMsg = cause instanceof Error ? cause.message : String(cause);
    super(`pullProducts ${kind}${status !== undefined ? ` (${String(status)})` : ''}: ${causeMsg}`);
    this.name = 'PullProductsError';
  }
}

/**
 * T2.1 Step A — lower-level pagination loop that THROWS typed errors.
 *
 * Extracted from the legacy `pullProducts(db)` so the foreground caller
 * can distinguish timeout / 5xx / network / parse failures (where the
 * scheduler-side caller still wants the swallow-and-retry-next-tick
 * semantics — see `pullProducts` below for the bit-equivalent wrapper).
 *
 * Idempotency contract:
 *   - Page upserts happen mid-loop; partial commits survive a mid-pull
 *     throw.
 *   - The `products_last_sync` cursor is written ONLY after the full
 *     loop completes (preserves the legacy per-pull cursor semantics).
 *     A mid-pull throw therefore does not advance the cursor — the next
 *     call re-fetches from the same `updated_since` watermark, and the
 *     idempotent SQLite upserts dedup the already-committed pages.
 *   - Tombstoning (`deleted_ids` accumulation + final `deleteProducts`)
 *     also runs only on full-loop completion. A mid-pull throw leaves
 *     stale rows in SQLite until the next successful pull — acceptable
 *     for a transient retry; the alternative (delete-on-each-page)
 *     would risk losing tombstones if the server's `deleted_ids` list
 *     is split across pages.
 */
export async function pullProductsCore(
  db: Database,
  opts: { signal?: AbortSignal; timeoutMs?: number } = {},
): Promise<{ count: number }> {
  const lastSync = await getSyncMetadata(db, 'products_last_sync');
  const params: Record<string, string> = { per_page: '500' };
  if (lastSync) {
    params['updated_since'] = lastSync;
  }

  let totalPulled = 0;
  let page = 1;
  let hasMore = true;
  const deletedIdsAccumulator: string[] = [];

  while (hasMore) {
    let result: POSProduct[] | { data: POSProduct[]; deleted_ids?: string[] };
    try {
      result = await apiGet<
        POSProduct[] | { data: POSProduct[]; deleted_ids?: string[] }
      >('/products', { ...params, page: String(page) }, opts);
    } catch (err) {
      // FetchTimeoutError propagates verbatim — callers already
      // discriminate it via `instanceof` (T0.3 contract).
      if (err instanceof FetchTimeoutError) throw err;
      // ApiRequestError carries an HTTP status — classify 5xx vs other.
      if (err instanceof ApiRequestError) {
        if (err.status >= 500 && err.status < 600) {
          throw new PullProductsError('http_5xx', err, err.status);
        }
        // 4xx on the catalog endpoint is a programming error, not a
        // transient retryable — propagate as a network-class typed
        // error so the cashier-facing banner reads consistently.
        throw new PullProductsError('network', err, err.status);
      }
      // SyntaxError = response body wasn't JSON (server returned HTML
      // or malformed JSON). Parse class.
      if (err instanceof SyntaxError) {
        throw new PullProductsError('parse', err);
      }
      // Default = network-class (TypeError from fetch, undici-style
      // ECONNRESET, etc.).
      throw new PullProductsError('network', err);
    }

    const products = Array.isArray(result) ? result : result.data;
    const deletedIds = Array.isArray(result) ? [] : (result.deleted_ids ?? []);

    if (products.length > 0) {
      await upsertProducts(db, products);
      totalPulled += products.length;
    }

    if (deletedIds.length > 0) {
      deletedIdsAccumulator.push(...deletedIds);
    }

    hasMore = products.length === 500;
    page++;
  }

  if (deletedIdsAccumulator.length > 0) {
    await deleteProducts(db, deletedIdsAccumulator);
    // Task 8 — tombstone cascade: remove cached location_stock rows for
    // products the server has deleted so stale stock data is never surfaced.
    await deleteLocationStockForProducts(db, deletedIdsAccumulator);
    // Task F5 — tombstone cascade: evict cross-location distribution cache for
    // deleted products so the drawer never shows stale data.
    await deleteDistributionForProducts(db, deletedIdsAccumulator);
  }

  if (totalPulled > 0 || deletedIdsAccumulator.length > 0) {
    await setSyncMetadata(db, 'products_last_sync', new Date().toISOString());
    await logSyncOperation(
      db,
      'pull',
      'products',
      null,
      'success',
      `${totalPulled} upserted, ${deletedIdsAccumulator.length} tombstoned`,
    );
  }

  return { count: totalPulled };
}

/**
 * T2.1 Step A — scheduler-side thin wrapper around `pullProductsCore`.
 *
 * Preserves the bit-equivalent legacy contract: swallows ALL errors
 * (typed and otherwise), logs to `sync_log`, returns `0`. The
 * scheduler retries on its next tick; the `isSyncing` guard +
 * idempotent SQLite upserts cover correctness.
 *
 * DO NOT change this wrapper's observable behavior without also
 * updating the scheduler-side test surface — it's load-bearing for
 * the every-60s background pull.
 *
 * C2 Day 1 — Codex round 1 P1 + round 2 P2 closure: skip the flat
 * product pull for Menu tenants. Their canonical catalog source is
 * `/active-menu` (pulled separately by `pullActiveMenu`); the
 * productStore's Menu fetch path writes composite-id rows. A
 * concurrent `pullProductsCore` would write bare-id rows for the same
 * sellables, polluting the local SQLite cache and surfacing as
 * duplicates in the POS grid.
 *
 * Round 2 P2 — when `companyConfig` is unknown (pre-fetch boot or a
 * failed config refresh), we DO NOT fall through to /products. Treating
 * null-config as non-Menu would pollute Menu-tenant SQLite during the
 * boot window. Instead, we attempt to fetch the config inline; if that
 * fails (network down), we skip this tick — `runFullSync` reruns every
 * 60s, so deferring is bounded. Standard-retail tenants get their
 * catalog on the next tick once config is loaded.
 *
 * The dynamic import of `productStore` is intentional — `productStore`
 * already imports `pullProductsForeground` from this module, so a
 * static import would create a circular dependency.
 */
type CatalogTenantGate =
  | { decision: 'menu' | 'standard' }
  | { decision: 'defer'; cause: 'config_fetch_failed' | 'module_import_failed' };

/**
 * Task 9 — shared Menu-tenant routing gate, extracted verbatim from
 * `pullProducts` so `pullLocationStock` applies the IDENTICAL decision
 * (Menu tenants source their catalog from /active-menu and must not pull
 * flat /products NOR /pos/stock-levels; an unknown config defers the tick
 * rather than guessing — see the pullProducts docblock for the C2 Day 1 /
 * Codex r2 P2 history).
 *
 * The dynamic import of `productStore` is intentional — `productStore`
 * already imports from this module, so a static import would create a
 * circular dependency.
 */
async function resolveCatalogTenantGate(): Promise<CatalogTenantGate> {
  try {
    const { useProductStore, hasModule } = await import('@/stores/productStore');
    let config = useProductStore.getState().companyConfig;

    if (config === null) {
      // Pre-fetch boot — try to load config so we can route correctly.
      try {
        const { fetchCompanyConfig } = await import('@/api/productApi');
        config = await fetchCompanyConfig();
        useProductStore.setState({ companyConfig: config });
      } catch {
        // Config fetch failed (network down, server 5xx, etc.). Defer
        // this tick rather than guess: a subsequent runFullSync will
        // retry. Skipping is safer than risking bare-row pollution
        // for a yet-unknown Menu tenant.
        return { decision: 'defer', cause: 'config_fetch_failed' };
      }
    }

    return { decision: hasModule(config, 'Menu') ? 'menu' : 'standard' };
  } catch {
    // Defensive: dynamic-import failure (test harness boot, module
    // resolution edge case). Without a known config we cannot make a
    // routing decision; defer to the next tick rather than risk
    // pollution.
    return { decision: 'defer', cause: 'module_import_failed' };
  }
}

export async function pullProducts(db: Database): Promise<number> {
  const gate = await resolveCatalogTenantGate();

  if (gate.decision === 'defer') {
    if (gate.cause === 'config_fetch_failed') {
      try {
        await logSyncOperation(
          db,
          'pull',
          'products',
          null,
          'error',
          'companyConfig unknown — deferring /products until next tick',
        );
      } catch { /* logging must never throw out of the scheduler wrapper */ }
    }
    return 0;
  }

  if (gate.decision === 'menu') {
    return 0;
  }

  try {
    const result = await pullProductsCore(db);
    return result.count;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'products', null, 'error', message);
    return 0;
  }
}

/**
 * T2.1 Step A — foreground full-catalog pull for `productStore.fetchProducts`.
 *
 * Differs from the scheduler-side `pullProducts(db)` wrapper in three ways:
 *   1. Calls `pullProductsCore` DIRECTLY so typed errors (FetchTimeoutError,
 *      PullProductsError) propagate to the cashier-facing UI rather than
 *      being silently swallowed.
 *   2. Wraps each page request with the foreground 30s per-page timeout
 *      (caller-overridable via `opts.timeoutMs`).
 *   3. Deduplicates concurrent foreground calls — if a cashier rapid-taps
 *      "refresh" while a pull is in flight, the second call awaits the
 *      same in-flight promise rather than firing a parallel pull.
 *
 * Acceptance contract for the foreground/scheduler race (Codex round-2 R1):
 * `useSyncStore.isSyncing` is set by `SyncScheduler.startSync` (a method
 * on the scheduler instance), NOT by this free-function wrapper. Therefore
 * a benign rare double-pull is possible (foreground + scheduler-tick
 * concurrent). SQLite per-statement atomicity + idempotent upserts cover
 * correctness; in-memory snapshot is last-write-wins. Future architectural
 * session can add a free-function-level mutex if the cost becomes
 * user-visible (rare on Slow-3G + 10K SKUs).
 */
let foregroundPullInFlight: Promise<{ ok: true; count: number }> | null = null;

export async function pullProductsForeground(
  db: Database,
  opts: { timeoutMs?: number; signal?: AbortSignal } = {},
): Promise<{ ok: true; count: number }> {
  if (foregroundPullInFlight) return foregroundPullInFlight;

  const promise = (async () => {
    try {
      const result = await pullProductsCore(db, {
        timeoutMs: opts.timeoutMs ?? 30_000,
        signal: opts.signal,
      });
      return { ok: true as const, count: result.count };
    } catch (err) {
      console.error(
        '[POS][syncService][pullProductsForeground] pull failed',
        serializeErrorForLog(err),
      );
      // Best-effort log to sync_log_repository for parity with the
      // scheduler-side `pullProducts` swallow path; never let the log
      // failure mask the original error.
      await logSyncOperation(
        db,
        'pull',
        'products',
        null,
        'error',
        coerceSyncError(err),
      ).catch(() => undefined);
      throw err;
    }
  })();

  foregroundPullInFlight = promise;
  // Use .then(onFulfilled, onRejected) with the same callback so the
  // cleanup chain doesn't produce its own unhandled rejection when
  // `promise` rejects. The original `promise` is what we return to
  // callers — they catch the rejection there.
  const cleanup = (): void => {
    if (foregroundPullInFlight === promise) {
      foregroundPullInFlight = null;
    }
  };
  promise.then(cleanup, cleanup);
  return promise;
}

/**
 * Task 9 — sync_metadata keys for the location-stock pull.
 *
 * `location_stock_as_of` stores the SERVER-ISSUED `as_of` watermark from
 * the last successful pull — sent verbatim as `updated_since` on the next
 * delta pull. NEVER device time (clock skew would drop or replay rows).
 */
const STOCK_CURSOR_KEY = 'location_stock_as_of';
const STOCK_LAST_SYNC_KEY = 'stock_last_sync';

/**
 * Typed error for `pullLocationStock` — same taxonomy as
 * `PullProductsError` (which hardcodes its name + `pullProducts` message
 * prefix, so it is not reusable here without lying in logs/instanceof
 * checks).
 */
export class PullLocationStockError extends Error {
  constructor(
    public readonly kind: 'http_5xx' | 'network' | 'parse',
    public readonly cause: unknown,
    public readonly status?: number,
  ) {
    const causeMsg = cause instanceof Error ? cause.message : String(cause);
    super(`pullLocationStock ${kind}${status !== undefined ? ` (${String(status)})` : ''}: ${causeMsg}`);
    this.name = 'PullLocationStockError';
  }
}

/**
 * Task 9 — pull the terminal's own location stock from
 * GET /pos/stock-levels into the local `location_stock` table.
 *
 * Modes:
 *   - 'full'  → replaceAllStock (deletes local rows absent server-side).
 *     Used at terminal claim/boot and shift open (re-baseline).
 *   - 'delta' → upsertStockRows with `updated_since=<stored cursor>`.
 *     Used on the 60s sync tick. A MISSING cursor (first pull after the
 *     v50 migration) silently degrades to a full pull.
 *
 * Atomicity contract (differs from pullProductsCore's per-page commits):
 *   ALL pages are accumulated in memory before ANY DB write. Stock pages
 *   are point-in-time consistent only as a set — a partially-applied
 *   multi-page pull could pair page-1 quantities with a cursor that was
 *   never written, or worse, advance the cursor past unfetched rows. A
 *   mid-loop failure therefore writes NOTHING and the next tick retries
 *   from the same cursor. (~10 pages × 500 rows of small objects — memory
 *   is a non-issue.)
 *
 * `incoming` arrives COMPLETE on page 1 ([] on later pages) and is
 * replaced wholesale on EVERY pull. The cursor is page 1's `as_of`.
 *
 * Gating: Menu tenants skip (identical decision to pullProducts — see
 * resolveCatalogTenantGate); no claimed terminal skips. Both return
 * {count: 0} without an API call.
 *
 * `opts.terminalId` exists because several boot/claim call sites run
 * BEFORE `useTerminalStore.set({ terminal })` publishes the terminal
 * (claimTerminal, initialize paths 2/3) — reading the store there would
 * silently no-op. When omitted, falls back to the claimed terminal in
 * the store.
 *
 * Errors are TYPED and THROWN (FetchTimeoutError verbatim, otherwise
 * PullLocationStockError) — callers in the sync cycle / boot flows wrap
 * with swallow-and-log. A stock-pull failure must NEVER block selling or
 * the rest of the sync cycle.
 */
export async function pullLocationStock(
  db: Database,
  mode: 'full' | 'delta',
  opts: { signal?: AbortSignal; timeoutMs?: number; terminalId?: string } = {},
): Promise<{ count: number }> {
  const gate = await resolveCatalogTenantGate();
  if (gate.decision !== 'standard') {
    // No defer breadcrumb here: pullProducts logs the single
    // config-unknown defer for the cycle — a second row per tick would
    // just duplicate it. Menu tenants are a silent permanent skip.
    return { count: 0 };
  }

  let terminalId = opts.terminalId ?? null;
  if (terminalId === null) {
    try {
      // Dynamic import — terminalStore statically imports pullLocationStock
      // from this module (shift-open hook), so a static import back would
      // be circular.
      const { useTerminalStore } = await import('@/stores/terminalStore');
      terminalId = useTerminalStore.getState().terminal?.id ?? null;
    } catch {
      terminalId = null;
    }
  }
  if (terminalId === null) {
    return { count: 0 };
  }

  const cursor = mode === 'delta' ? await getSyncMetadata(db, STOCK_CURSOR_KEY) : null;
  // Delta without a persisted cursor = first pull after migration → FULL.
  const effectiveMode: 'full' | 'delta' = cursor !== null ? 'delta' : 'full';

  const allStock: ServerStockRow[] = [];
  let incoming: ServerIncomingRow[] = [];
  let asOf: string | null = null;

  let page = 1;
  let lastPage = 1;
  do {
    const params: { updated_since?: string; page?: string } = { page: String(page) };
    if (cursor !== null) {
      params.updated_since = cursor;
    }

    let result: LocationStockPage;
    try {
      result = await fetchLocationStock(terminalId, params, {
        signal: opts.signal,
        timeoutMs: opts.timeoutMs,
      });
    } catch (err) {
      // Mirror pullProductsCore's classification exactly.
      // FetchTimeoutError propagates verbatim — callers discriminate it
      // via `instanceof` (T0.3 contract).
      if (err instanceof FetchTimeoutError) throw err;
      if (err instanceof ApiRequestError) {
        if (err.status >= 500 && err.status < 600) {
          throw new PullLocationStockError('http_5xx', err, err.status);
        }
        throw new PullLocationStockError('network', err, err.status);
      }
      if (err instanceof SyntaxError) {
        throw new PullLocationStockError('parse', err);
      }
      throw new PullLocationStockError('network', err);
    }

    allStock.push(...result.data.stock);
    if (page === 1) {
      // `incoming` is complete on page 1; `as_of` is the cursor for the
      // NEXT pull — take page 1's value so rows updated between page
      // fetches are re-sent next delta rather than skipped.
      incoming = result.data.incoming;
      asOf = result.data.as_of;
    }
    // FU-8: page size is server-owned (PosStockLevelController::PER_PAGE = 500).
    // We never hardcode it — looping on `last_page` self-adapts to whatever the
    // server returns, so a server page-size change needs no client change.
    lastPage = result.meta.pagination.last_page;
    page++;
  } while (page <= lastPage);

  // Every page fetched — NOW write (see atomicity contract above).
  if (effectiveMode === 'full') {
    await replaceAllStock(db, allStock);
  } else {
    await upsertStockRows(db, allStock);
  }
  // `replaceIncoming` zeroes ALL incoming columns then re-upserts — atomic on
  // the single writer so concurrent stock reads never observe the zeroed
  // window. NOTE: pass `tx` (the raw writer), never the gated wrapper — a
  // gated execute inside a gate job would deadlock the queue.
  await withWriteTransaction('sync', (tx) =>
    replaceIncoming(tx as unknown as Database, incoming),
  );

  if (asOf !== null) {
    await setSyncMetadata(db, STOCK_CURSOR_KEY, asOf);
  }
  await setSyncMetadata(db, STOCK_LAST_SYNC_KEY, new Date().toISOString());
  await logSyncOperation(
    db,
    'pull',
    'location_stock',
    null,
    'success',
    `${String(allStock.length)} rows (${effectiveMode})`,
  );

  return { count: allStock.length };
}

/**
 * Pull payment configuration (methods and repositories).
 */
export async function pullPaymentConfig(db: Database): Promise<boolean> {
  try {
    // T0.5 (2026-05-08): aligned to the canonical endpoint pair. The
    // pre-T0.5 path called `/treasury/payment-methods` + `/treasury/payment-
    // repositories`, which DO NOT EXIST in the backend (Treasury routes
    // register under `Route::prefix('api/v1')` with no `treasury/` prefix
    // — see apps/api/.../Treasury/Presentation/routes.php:25-66). The 404s
    // were silently absorbed by the outer try/catch below, leaving payment-
    // config sync as a no-op since the bug shipped.
    const [methods, repositories] = await Promise.all([
      apiGet<PaymentMethod[]>('/payment-methods'),
      apiGet<PaymentRepository[]>('/payment-repositories'),
    ]);

    await upsertPaymentMethods(db, methods);
    await upsertPaymentRepositories(db, repositories);
    await setSyncMetadata(db, 'payment_config_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'payment_config', null, 'success');
    return true;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'payment_config', null, 'error', message);
    return false;
  }
}

/**
 * Pull operator PIN hashes for offline verification.
 */
export async function pullOperatorPins(db: Database, terminalId: string): Promise<number> {
  try {
    // terminal_id is REQUIRED: the server scopes each mirrored operator's
    // approval to the requesting terminal (terminal_ids = [terminal_id]).
    // Omitting it returns terminal_ids: [], and verifyOfflineApprovalPin then
    // rejects every operator (scope_mismatch) — breaking ALL offline approvals
    // (cash-drawer, discount, and the B7 EOD manager-PIN close).
    const operators = await apiGet<OperatorPinData[]>(
      `/pos/auth/pin-data?terminal_id=${encodeURIComponent(terminalId)}`,
    );
    await upsertOperators(db, operators);
    // FU-1 — make this confirmed-full pull authoritative: delete any cached
    // operator NOT in the response so a suspended-then-omitted manager can no
    // longer approve offline overrides against a stale local PIN. Gate on a
    // NON-EMPTY response only: a real terminal always returns at least the
    // operator driving the sync, so an empty result is treated as
    // non-authoritative and never prunes (which would otherwise wipe
    // legitimately-offline operators). A failed pull throws before reaching
    // here, so it never prunes either.
    if (operators.length > 0) {
      await pruneOperatorsExcept(db, operators.map((op) => op.id));
    }
    await setSyncMetadata(db, 'operators_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'operators', null, 'success', `${operators.length} operators`);
    return operators.length;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'operators', null, 'error', message);
    return 0;
  }
}

/**
 * Pull terminal state (hash chain state from server).
 *
 * Client-owned invariant: once the local terminal has advanced the chain past
 * the server's known `hash_sequence` (e.g., we pushed receipts offline that
 * haven't synced yet, or a relaunch), the client's value is authoritative.
 * A `FiscalRegressionError` from the repo guard is therefore EXPECTED behavior
 * and must be logged but NOT propagated to the scheduler.
 */
export async function pullTerminalState(
  db: Database,
  terminalId: string,
): Promise<boolean> {
  try {
    const state = await apiGet<TerminalStateResponse>(`/pos/terminals/${terminalId}`);
    if (!state.genesis_seed) {
      return false;
    }

    // For a new terminal with no receipts, last_hash is null on the server.
    // The genesis hash (SHA-256 of "GENESIS|<seed>") is the chain's starting point.
    const initialHash = state.last_hash ?? await computeGenesisHash(state.genesis_seed);

    // Codex review B1: project the server's fiscal_schema_version so the
    // receipt creator can branch on it. If the server is stale (pre-B1
    // serializer) and omits the field, default to 2 — the legacy schema.
    // Coerce loudly: an unexpected integer (e.g. 4) should not silently
    // route to v2 or v3.
    const rawVersion = state.fiscal_schema_version ?? 2;
    if (rawVersion !== 2 && rawVersion !== 3) {
      throw new Error(
        `[fiscal] pullTerminalState received unsupported fiscal_schema_version=${rawVersion} for terminal ${state.id}`,
      );
    }
    const fiscalSchemaVersion: 2 | 3 = rawVersion;

    // Offline-first shifts Phase 6.1: cache the server's per-terminal
    // MAX(shift_number) so the device's `nextShiftNumber` continues numbering
    // monotonically after a fresh install / DB reset. Carried INSIDE the
    // terminal_state upsert so the row and its seed are written in one atomic
    // statement — a crash between two separate writes can never leave a fresh
    // row with seed 0 (Codex r1 HIGH). The upsert is monotone (MAX), so a stale
    // server read can never rewind it. Absent on a pre-6.1 server → 0 (legacy
    // local-only behaviour).
    const shiftNumberSeed = state.max_shift_number ?? 0;

    const hashState: TerminalHashState = {
      terminal_id: state.id,
      terminal_code: state.code,
      location_code: (state.location?.code ?? 'MAIN').toUpperCase(),
      genesis_seed: state.genesis_seed,
      last_hash: initialHash,
      hash_sequence: state.hash_sequence,
      manager_pin_throttle_until: null,
      manager_pin_failed_attempts: 0,
      fiscal_schema_version: fiscalSchemaVersion,
      shift_number_seed: shiftNumberSeed,
    };

    try {
      await upsertTerminalState(db, hashState);
      await logSyncOperation(db, 'pull', 'terminal_state', terminalId, 'success');
      return true;
    } catch (error) {
      const { FiscalRegressionError } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );
      if (error instanceof FiscalRegressionError) {
        console.warn('[fiscal] pullTerminalState preserved local state', {
          terminal_id: terminalId,
          local_sequence: error.before,
          server_sequence: error.after,
        });
        // The local fiscal head is ahead of the server, so upsertTerminalState
        // rejected the whole write (seed included). The row already exists —
        // refresh the monotone shift-number seed on its own.
        await setShiftNumberSeed(db, terminalId, shiftNumberSeed);
        await logSyncOperation(
          db,
          'pull',
          'terminal_state',
          terminalId,
          'success',
          `preserved local state (local=${error.before}, server=${error.after})`,
        );
        return true;
      }
      throw error;
    }
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'terminal_state', terminalId, 'error', message);
    return false;
  }
}

interface ZChainStateResponse {
  z_last_hash: string;
  z_hash_sequence: number;
  z_number: number;
  // Laravel serializes decimal(15,3) columns as JSON strings, so these come
  // over the wire as decimal strings — mirroring the SQLite TEXT storage.
  grand_totals: {
    cumulative_sales: string;
    cumulative_tax: string;
    cumulative_refunds: string;
    perpetual_grand_total: string;
    receipt_count_lifetime: number;
  } | null;
}

/**
 * Pull Z-chain state from server (for recovery after local DB loss).
 * Updates only Z-chain columns in terminal_state without affecting receipt chain.
 *
 * Defensive invariant: the cumulative_* counters are append-only over the
 * lifetime of a terminal. A server response that would zero or rewind them
 * is either a stale snapshot (online-only tenant whose grand_totals were
 * never populated server-side, pre-fix) or a genuine data corruption. In
 * neither case should the local, post-activation-accurate state be overwritten.
 *
 * Rules:
 *   - Null or all-zero server grand_totals AND local has non-zero counters:
 *     preserve local, log, skip upsert.
 *   - Any counter moves backwards vs. local: preserve local, log as regression,
 *     skip upsert (mirrors upsertTerminalState's FiscalRegressionError pattern).
 *   - All counters move forward (or local is zero): apply server values.
 */
export async function pullZChainState(
  db: Database,
  terminalId: string,
): Promise<boolean> {
  try {
    const state = await apiGet<ZChainStateResponse>(`/pos/terminals/${terminalId}/z-chain-state`);
    const local = await getZChainState(db, terminalId);
    const decision = decideZChainUpsert(local, state);

    if (decision.action === 'skip') {
      console.warn('[fiscal] pullZChainState preserved local state', {
        terminal_id: terminalId,
        reason: decision.reason,
        local_cumulative_sales: local?.cumulative_sales ?? '0.000',
        server_grand_totals: state.grand_totals,
      });
      await logSyncOperation(
        db,
        'pull',
        'z_chain_state',
        terminalId,
        'success',
        `preserved local state (${decision.reason})`,
      );
      return true;
    }

    await upsertZChainState(db, terminalId, state);
    await logSyncOperation(db, 'pull', 'z_chain_state', terminalId, 'success');
    return true;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'z_chain_state', terminalId, 'error', message);
    return false;
  }
}

type ZChainDecision =
  | { action: 'apply' }
  | { action: 'skip'; reason: 'server_null_or_zero' | 'server_regresses_local' };

function decideZChainUpsert(
  local: {
    cumulative_sales: string;
    cumulative_tax: string;
    cumulative_refunds: string;
    perpetual_grand_total: string;
    receipt_count_lifetime: number;
  } | null,
  server: ZChainStateResponse,
): ZChainDecision {
  const serverTotals = server.grand_totals;

  // `localSum` mixes monetary values (decimal strings) with `receipt_count_lifetime`
  // (an integer). Dimensionally odd, but only used as a `.gt(0)` "has any local data"
  // gate — so a terminal with voided-only receipts (zero revenue, non-zero count) is
  // correctly protected against null-clobber. Don't "fix" by dropping the count.
  const localSum = local
    ? Big(local.cumulative_sales || '0')
        .plus(local.cumulative_tax || '0')
        .plus(local.cumulative_refunds || '0')
        .plus(local.perpetual_grand_total || '0')
        .plus(local.receipt_count_lifetime)
    : Big(0);
  const localHasData = localSum.gt(0);

  if (serverTotals === null) {
    return localHasData
      ? { action: 'skip', reason: 'server_null_or_zero' }
      : { action: 'apply' };
  }

  const serverSum = Big(serverTotals.cumulative_sales || '0')
    .plus(serverTotals.cumulative_tax || '0')
    .plus(serverTotals.cumulative_refunds || '0')
    .plus(serverTotals.perpetual_grand_total || '0')
    .plus(serverTotals.receipt_count_lifetime);

  if (serverSum.eq(0) && localHasData) {
    return { action: 'skip', reason: 'server_null_or_zero' };
  }

  if (local !== null && localHasData) {
    const regresses =
      Big(serverTotals.cumulative_sales || '0').lt(local.cumulative_sales || '0') ||
      Big(serverTotals.cumulative_tax || '0').lt(local.cumulative_tax || '0') ||
      Big(serverTotals.cumulative_refunds || '0').lt(local.cumulative_refunds || '0') ||
      Big(serverTotals.perpetual_grand_total || '0').lt(local.perpetual_grand_total || '0') ||
      serverTotals.receipt_count_lifetime < local.receipt_count_lifetime;
    if (regresses) {
      return { action: 'skip', reason: 'server_regresses_local' };
    }
  }

  return { action: 'apply' };
}

/**
 * Push queued PIN updates (from offline PIN setup) to the server.
 * Idempotent on both sides: duplicate sends are a no-op.
 */
export async function pushQueuedPinUpdates(db: Database): Promise<number> {
  const pending = await getPendingPinUpdates(db);
  if (pending.length === 0) return 0;

  try {
    await apiPost<{ synced: number; skipped: number }>('/pos/auth/sync-pins', {
      updates: pending.map((p) => ({ user_id: p.userId, pin_hash: p.pinHash })),
    });
    for (const row of pending) {
      await markPinUpdateSynced(db, row.id);
    }
    await logSyncOperation(db, 'push', 'pin_update', null, 'success', `${pending.length} pin updates`);
    return pending.length;
  } catch (error) {
    const message = coerceSyncError(error);
    for (const row of pending) {
      await markPinUpdateFailed(db, row.id, message);
    }
    await logSyncOperation(db, 'push', 'pin_update', null, 'error', message);
    return 0;
  }
}

/**
 * Per-tick batch budget for the audit drain. Each batch is ≤100 events; the
 * loop drains up to this many batches before yielding the tick (audit is the
 * lowest-priority sync surface — receipts/PINs/Z-reports run first). The
 * remainder, if any, drains on the next tick.
 */
export const MAX_AUDIT_BATCHES_PER_TICK = 10;

/**
 * Wall-clock time budget (ms) for a single audit drain tick. Even if
 * MAX_AUDIT_BATCHES_PER_TICK has not been exhausted, the loop breaks once
 * this many milliseconds have elapsed since the tick started. Guards against
 * unexpectedly slow network or SQLite stalls monopolising the JS thread when
 * the batch count cap alone is insufficient.
 */
export const AUDIT_DRAIN_BUDGET_MS = 2000;

/**
 * Convert a queued audit-event row into the ingest envelope expected by
 * `POST /pos/audit-events/sync`. `payload` / `metadata` are stored as JSON
 * TEXT in SQLite and re-hydrated to objects on the wire (the backend
 * validates them as arrays/objects).
 */
function auditEventToEnvelope(row: QueuedAuditEvent): Record<string, unknown> {
  return {
    event_id: row.eventId,
    event_type: row.eventType,
    aggregate_type: row.aggregateType,
    aggregate_id: row.aggregateId,
    tenant_id: row.tenantId,
    company_id: row.companyId,
    operator_id: row.operatorId,
    payload: JSON.parse(row.payload) as unknown,
    metadata: JSON.parse(row.metadata) as unknown,
    occurred_at: row.occurredAt,
  };
}

/**
 * Drain the `queued_audit_events` outbox (Sub-Spec C). Multi-batch: in one
 * tick it posts up to `MAX_AUDIT_BATCHES_PER_TICK` batches of ≤100 events,
 * stopping early when the queue is empty, a POST fails, or the elapsed-time
 * budget (`AUDIT_DRAIN_BUDGET_MS`) is exceeded (failed batch rows are marked
 * `failed` + retry_count incremented; the tick yields and the next tick
 * retries them). Wired AFTER receipts/PINs — audit is lowest priority and
 * must never block the fiscal chain.
 *
 * No-op when offline (the scheduler already gates `runFullSync` on
 * connectivity, but the explicit guard keeps the function safe to call
 * directly). Returns the number of events successfully synced this tick.
 */
export async function pushQueuedAuditEvents(db: Database): Promise<number> {
  if (!useConnectivityStore.getState().isOnline) return 0;

  let total = 0;
  const start = Date.now();
  for (let i = 0; i < MAX_AUDIT_BATCHES_PER_TICK; i++) {
    if (Date.now() - start > AUDIT_DRAIN_BUDGET_MS) break;

    const pending = await getPendingAuditEvents(db, 100);
    if (pending.length === 0) break;

    await markAuditEventsSyncing(db, pending.map((p) => p.id));

    try {
      await apiPost('/pos/audit-events/sync', {
        events: pending.map(auditEventToEnvelope),
      });
      for (const row of pending) {
        await markAuditEventSynced(db, row.id);
      }
      total += pending.length;
      await logSyncOperation(
        db,
        'push',
        'audit_event',
        null,
        'success',
        `${pending.length} audit events`,
      );
    } catch (error) {
      const message = coerceSyncError(error);
      for (const row of pending) {
        await markAuditEventFailed(db, row.id, message);
      }
      await logSyncOperation(db, 'push', 'audit_event', null, 'error', message);
      break; // stop this tick on error; retry next tick
    }
  }

  return total;
}

interface PulledActiveMenuItem {
  id: string;
  sellable_id: string;
  sellable_type: string;
  name: string;
  code: string;
  barcode?: string | null;
  base_price: string;
  effective_price: string;
  image_url?: string | null;
  tax_rate?: string | null;
  display_order: number;
  is_available: boolean;
  modifier_groups?: unknown;
}

interface PulledActiveMenuCategory {
  id: string;
  name: string;
  position: number;
  items: PulledActiveMenuItem[];
}

interface PulledActiveMenuResponse {
  categories: PulledActiveMenuCategory[];
  deleted_category_ids?: string[];
  deleted_item_ids?: string[];
}

/**
 * Pull floor/table layout from server into SQLite cache.
 * The cached layout is consulted by tableApi.getFloors() when the API is unreachable.
 */
export async function pullTables(db: Database): Promise<boolean> {
  try {
    const response = await apiGet<FloorData[] | { data: FloorData[] }>('/pos/floors');
    const floors = Array.isArray(response) ? response : response.data;

    await upsertFloors(db, floors.map((f) => ({
      id: f.id,
      name: f.name,
      position: f.position,
      is_active: f.is_active,
      updated_at: f.updated_at,
    })));

    const tableInputs = floors.flatMap((f) => (f.tables ?? []).map((t) => ({
      id: t.id,
      floor_id: t.floor_id,
      table_number: t.table_number,
      label: t.label,
      seats: t.seats,
      status: t.status,
      shape: t.shape,
      position_x: t.position_x,
      position_y: t.position_y,
      width: t.width,
      height: t.height,
      current_order_id: t.current_order_id,
      updated_at: t.updated_at,
    })));

    await upsertTables(db, tableInputs);
    await setSyncMetadata(db, 'tables_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'tables', null, 'success', `${floors.length} floors / ${tableInputs.length} tables`);
    return true;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'tables', null, 'error', message);
    return false;
  }
}

/**
 * Pull the active menu (categories + items) into SQLite cache.
 * The cached menu is consulted by fetchActiveMenu() when the API is unreachable.
 * Honours deleted_category_ids / deleted_item_ids tombstones when the server sends them.
 */
export async function pullActiveMenu(db: Database): Promise<boolean> {
  try {
    const response = await apiGet<PulledActiveMenuResponse>('/active-menu');
    const now = new Date().toISOString();

    await upsertMenuCategories(db, response.categories.map((c) => ({
      id: c.id, name: c.name, position: c.position, updated_at: now,
    })));

    const items = response.categories.flatMap((c) => c.items.map((i) => ({
      id: i.id,
      menu_category_id: c.id,
      sellable_id: i.sellable_id,
      sellable_type: i.sellable_type,
      name: i.name,
      code: i.code,
      barcode: i.barcode ?? null,
      base_price: i.base_price,
      effective_price: i.effective_price,
      image_url: i.image_url ?? null,
      tax_rate: i.tax_rate ?? null,
      display_order: i.display_order,
      is_available: i.is_available,
      modifier_groups: Array.isArray(i.modifier_groups) ? (i.modifier_groups as ModifierGroup[]) : null,
      updated_at: now,
    })));
    await upsertMenuCategoryItems(db, items);

    if (response.deleted_category_ids && response.deleted_category_ids.length > 0) {
      await deleteMenuCategories(db, response.deleted_category_ids);
    }
    if (response.deleted_item_ids && response.deleted_item_ids.length > 0) {
      await deleteMenuCategoryItems(db, response.deleted_item_ids);
    }

    await setSyncMetadata(db, 'active_menu_last_sync', new Date().toISOString());

    // C2 Day 1 — Codex round 6 P1 closure (gated by round 8 P1):
    // flatten the just-pulled menu into the `products` table for Menu
    // tenants so background sync ticks propagate menu changes
    // (additions / removals / repricing) to the cashier's POS grid
    // without waiting for the next foreground fetchProducts. CRITICAL:
    // round-8 closure — this reconcile MUST be gated on the Menu
    // module. For a standard-retail tenant, /active-menu can succeed
    // with `categories: []` (no menu module enabled, server simply
    // returns empty), and `reconcileMenuProducts(db, [])` would call
    // `wipeAllProductRows` — deleting the entire products table that
    // `pullProducts` just populated. The dynamic import of
    // productStore matches the gate pattern in `pullProducts`
    // (productStore already imports from this module — static import
    // would cycle).
    let isMenuTenant = false;
    try {
      const { useProductStore: psModule, hasModule } = await import('@/stores/productStore');
      const config = psModule.getState().companyConfig;
      isMenuTenant = config !== null && hasModule(config, 'Menu');
    } catch {
      // Defensive: dynamic-import failure (test harness, edge cases).
      // Fall through with isMenuTenant=false so the reconcile is
      // skipped — better to defer the Menu-tenant grid update one
      // tick than risk wiping a standard-retail catalog.
    }
    if (isMenuTenant) {
      const reshapedForFlatten = {
        categories: response.categories.map((c) => ({
          id: c.id,
          name: c.name,
          position: c.position,
          items: c.items.map((i) => ({
            id: i.id,
            sellable_id: i.sellable_id,
            sellable_type: i.sellable_type,
            name: i.name,
            code: i.code,
            barcode: i.barcode ?? null,
            base_price: i.base_price,
            effective_price: i.effective_price,
            image_url: i.image_url ?? null,
            tax_rate: i.tax_rate ?? null,
            display_order: i.display_order,
            is_available: i.is_available,
            modifier_groups: Array.isArray(i.modifier_groups)
              ? (i.modifier_groups as ModifierGroup[])
              : undefined,
          })),
        })),
      };
      const freshProducts = flattenMenuToProducts(reshapedForFlatten);
      await reconcileMenuProducts(db, freshProducts);
    }

    await logSyncOperation(db, 'pull', 'active_menu', null, 'success', `${response.categories.length} categories / ${items.length} items`);
    return true;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'active_menu', null, 'error', message);
    return false;
  }
}

// ─── Voucher + receipt-QR mirror sync ───────────────────────────────────────
//
// Mirror tables created in migrations 23/24/25. The pull functions hit
// server endpoints that do not yet exist (Task 44 only ships the POS-side
// scaffolding); a 404 is therefore treated as a benign "nothing to sync"
// and logged via logSyncOperation('error', …) rather than thrown. Backend
// endpoints `/pos/vouchers/sync`, `/pos/voucher-ledger/sync`, and
// `/pos/receipts/qr-index` are wired up in subsequent backend tasks.

interface VouchersSyncResponse {
  vouchers: LocalVoucher[];
  deleted_ids?: string[];
}

interface VoucherLedgerSyncResponse {
  entries: LocalVoucherLedgerEntry[];
}

// The backend now ships `partner_id` in the sync payload (Codex review M2).
// The optional widening is kept for backward compatibility with any clients
// that may receive a cached 404 or a stale response from an older server.
// `upsertReceiptQrIndexEntries` coalesces with `?? null` to ensure the DB
// column is always written, never left as undefined.
type ReceiptQrIndexSyncEntry = Omit<LocalReceiptQrIndexEntry, 'partner_id'> & {
  partner_id?: string | null;
};

interface ReceiptQrIndexSyncResponse {
  entries: ReceiptQrIndexSyncEntry[];
}

/**
 * Pull voucher mirror updates from the server. Backend endpoint pending —
 * see comment block above. Errors are swallowed and logged so a missing
 * backend doesn't break the rest of the sync run.
 */
export async function pullVouchers(
  db: Database,
  terminalId: string,
): Promise<number> {
  // Backend endpoint pending: /pos/vouchers/sync (delivered in a later backend task).
  try {
    const lastSync = await getSyncMetadata(db, 'vouchers_last_sync');
    const params: Record<string, string> = { terminal_id: terminalId };
    if (lastSync) params['updated_since'] = lastSync;

    const response = await apiGet<VouchersSyncResponse>('/pos/vouchers/sync', params);
    const vouchers = response.vouchers ?? [];
    if (vouchers.length > 0) {
      await upsertVouchers(db, vouchers);
    }

    await setSyncMetadata(db, 'vouchers_last_sync', new Date().toISOString());
    await logSyncOperation(
      db,
      'pull',
      'vouchers',
      null,
      'success',
      `${vouchers.length} vouchers`,
    );
    return vouchers.length;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'vouchers', null, 'error', message);
    return 0;
  }
}

/**
 * Pull voucher_ledger updates from the server. Backend endpoint pending.
 */
export async function pullVoucherLedger(
  db: Database,
  terminalId: string,
): Promise<number> {
  // Backend endpoint pending: /pos/voucher-ledger/sync (delivered in a later backend task).
  try {
    const lastSync = await getSyncMetadata(db, 'voucher_ledger_last_sync');
    const params: Record<string, string> = { terminal_id: terminalId };
    if (lastSync) params['updated_since'] = lastSync;

    const response = await apiGet<VoucherLedgerSyncResponse>(
      '/pos/voucher-ledger/sync',
      params,
    );
    const entries = response.entries ?? [];
    if (entries.length > 0) {
      // Server-pulled rows always arrive in 'synced' state from this terminal's
      // perspective. If the server returns a different sync_status, trust it.
      await upsertVoucherLedgerEntries(db, entries);
    }

    await setSyncMetadata(db, 'voucher_ledger_last_sync', new Date().toISOString());
    await logSyncOperation(
      db,
      'pull',
      'voucher_ledger',
      null,
      'success',
      `${entries.length} ledger entries`,
    );
    return entries.length;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'voucher_ledger', null, 'error', message);
    return 0;
  }
}

/**
 * Pull the receipt_qr_index for this terminal so the scan dispatcher (Task 50)
 * can resolve scanned QR tokens to receipts without a network round-trip.
 * Backend endpoint: GET /api/v1/pos/receipts/qr-index (shipped in Codex review M2).
 */
export async function pullReceiptQrIndex(
  db: Database,
  terminalId: string,
): Promise<number> {
  try {
    const lastSync = await getSyncMetadata(db, 'receipt_qr_index_last_sync');
    const params: Record<string, string> = { terminal_id: terminalId };
    if (lastSync) params['updated_since'] = lastSync;

    const response = await apiGet<ReceiptQrIndexSyncResponse>(
      '/pos/receipts/qr-index',
      params,
    );
    // Coalesce undefined partner_id (backward compat with older server responses)
    // to null so upsertReceiptQrIndexEntries always receives a well-typed row.
    const entries = (response.entries ?? []).map((e) => ({
      ...e,
      partner_id: e.partner_id ?? null,
    }));
    if (entries.length > 0) {
      await upsertReceiptQrIndexEntries(db, entries);
    }

    await setSyncMetadata(db, 'receipt_qr_index_last_sync', new Date().toISOString());
    await logSyncOperation(
      db,
      'pull',
      'receipt_qr_index',
      null,
      'success',
      `${entries.length} entries`,
    );
    return entries.length;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'receipt_qr_index', null, 'error', message);
    return 0;
  }
}

/**
 * Per-entry result returned by POST /pos/voucher-ledger/sync. The controller
 * (`VoucherSyncController::pushVoucherLedger`) returns a 200 with an array
 * of these alongside `synced`, `duplicates`, and `failed` counts. Per-entry
 * `status: 'failed'` is the server's structured way of reporting that the
 * row could not be ingested (e.g. `receipt_id_required_for_redemption`,
 * `voucher_not_found`) — it is NOT an HTTP-level error.
 *
 * B5-fix audit Minor 2 (2026-05-01): the previous implementation only
 * inspected HTTP-level failures and unconditionally called
 * `markVoucherLedgerEntrySynced` after `apiPost` resolved, even when the
 * 200 response carried `status: 'failed'`. That silent-drop caused offline
 * `voucher_ledger` rows to be lost on first push and never retried.
 */
interface VoucherLedgerPushEntryResult {
  id: string;
  status: 'synced' | 'duplicate' | 'failed';
  error: string | null;
}

interface VoucherLedgerPushResponse {
  results: VoucherLedgerPushEntryResult[];
  synced: number;
  duplicates: number;
  failed: number;
}

/**
 * Type guard for the controller's structured response. A stale server (or a
 * proxy that strips fields) might return `{}` — we treat that as a failure
 * so the row stays pending rather than getting silently dropped.
 */
function isVoucherLedgerPushResponse(value: unknown): value is VoucherLedgerPushResponse {
  if (typeof value !== 'object' || value === null) return false;
  const v = value as { results?: unknown };
  return Array.isArray(v.results);
}

/**
 * Push locally-written voucher_ledger entries (e.g. issued during a refund or
 * exchange while offline) to the server. The controller returns a structured
 * 200 response with per-entry `{id, status, error}` — we MUST inspect that
 * shape, not just HTTP status. Per-row errors do not halt the loop — voucher
 * chain is independent of the receipt fiscal chain.
 *
 * Per-entry status semantics:
 *   - `'synced'` or `'duplicate'` → mark the local row synced (the server
 *     accepted it, possibly idempotently).
 *   - `'failed'` → mark the local row failed with the per-entry error reason.
 *     The row stays in the `'failed'` state and is NOT retried by this
 *     function; an operator-visible failure surfaces in the sync log.
 *
 * HTTP-level errors (network failure, 5xx, 422) are caught by `apiPost` and
 * converted to thrown exceptions; the catch block marks the row failed with
 * the HTTP error message.
 */
export async function pushVoucherLedgerEntries(
  db: Database,
): Promise<{ pushed: number; failed: number; errors: string[] }> {
  const pending = await getPendingVoucherLedgerEntries(db);
  let pushed = 0;
  let failed = 0;
  const errors: string[] = [];

  if (pending.length === 0) {
    return { pushed, failed, errors };
  }

  for (const entry of pending) {
    try {
      const response = await apiPost<unknown>('/pos/voucher-ledger/sync', { entries: [entry] });

      // Defense-in-depth: a malformed / stale response shape MUST fail the
      // row. The previous implementation marked the row synced as long as
      // apiPost resolved — silent-drop bug.
      if (!isVoucherLedgerPushResponse(response)) {
        const message = 'Malformed response from /pos/voucher-ledger/sync (no per-entry results)';
        await markVoucherLedgerEntryFailed(db, entry.id, message);
        await logSyncOperation(db, 'push', 'voucher_ledger', entry.id, 'error', message);
        errors.push(`Voucher ledger ${entry.id}: ${message}`);
        failed++;
        continue;
      }

      // Find the per-entry result for this row. We posted exactly one entry
      // so there should be exactly one result, but match by id for safety.
      const perEntryResult = response.results.find((r) => r.id === entry.id) ?? response.results[0];

      if (perEntryResult === undefined) {
        const message = 'Empty per-entry results array from /pos/voucher-ledger/sync';
        await markVoucherLedgerEntryFailed(db, entry.id, message);
        await logSyncOperation(db, 'push', 'voucher_ledger', entry.id, 'error', message);
        errors.push(`Voucher ledger ${entry.id}: ${message}`);
        failed++;
        continue;
      }

      if (perEntryResult.status === 'synced' || perEntryResult.status === 'duplicate') {
        await markVoucherLedgerEntrySynced(db, entry.id);
        await logSyncOperation(db, 'push', 'voucher_ledger', entry.id, 'success', perEntryResult.status);
        pushed++;
      } else {
        // status === 'failed' — the server explicitly rejected this row.
        // Common reasons: 'receipt_id_required_for_redemption',
        // 'voucher_not_found', 'voucher_invalid_status',
        // 'voucher_insufficient_balance', 'voucher_not_for_this_terminal'.
        const reason = perEntryResult.error ?? 'unknown_failure';
        await markVoucherLedgerEntryFailed(db, entry.id, reason);
        await logSyncOperation(db, 'push', 'voucher_ledger', entry.id, 'error', reason);
        errors.push(`Voucher ledger ${entry.id}: ${reason}`);
        failed++;
      }
    } catch (error) {
      const message = coerceSyncError(error);
      await markVoucherLedgerEntryFailed(db, entry.id, message);
      await logSyncOperation(db, 'push', 'voucher_ledger', entry.id, 'error', message);
      errors.push(`Voucher ledger ${entry.id}: ${message}`);
      failed++;
    }
  }

  return { pushed, failed, errors };
}

/**
 * Full sync: push then pull.
 */
export async function runFullSync(
  db: Database,
  terminalId: string,
): Promise<SyncResult> {
  const errors: string[] = [];

  // Cleanup old data to prevent unbounded growth
  try { await cleanupOldSyncLogs(db, 7); } catch { /* non-critical */ }
  try { await cleanupSyncedReceipts(db); } catch { /* non-critical */ }
  try { await cleanupStuckReceipts(db); } catch { /* non-critical */ }
  try { await cleanupSyncedCashDrawerOps(db); } catch { /* non-critical */ }

  // Push offline PIN updates queued during offline PIN setup
  const pinUpdatesPushed = await pushQueuedPinUpdates(db);

  // Push receipts first (order matters for chain)
  const { pushed, failed, errors: pushErrors, chainBreak } = await pushOfflineReceipts(db);
  errors.push(...pushErrors);

  // Push Z-reports after receipts (Z-reports reference receipt data)
  const { pushed: zPushed, failed: zFailed, errors: zErrors } = await pushZReports(db);
  errors.push(...zErrors);

  // Push cash drawer operations (no ordering constraints)
  const { pushed: cashDrawerPushed, errors: cashDrawerErrors } = await pushCashDrawerOps(db);
  errors.push(...cashDrawerErrors);

  // Push voucher_ledger entries written locally during refunds / exchanges.
  // Failures here are independent of the receipt fiscal chain and must not
  // halt the rest of the sync run.
  const {
    pushed: voucherLedgerPushed,
    failed: voucherLedgerFailed,
    errors: voucherLedgerPushErrors,
  } = await pushVoucherLedgerEntries(db);
  errors.push(...voucherLedgerPushErrors);

  // Drain the audit / fraud-detection outbox LAST among the pushes — audit is
  // the lowest-priority sync surface (Sub-Spec C) and is best-effort: a failed
  // batch is left for the next tick and never halts the fiscal-chain pushes
  // above. Failures are logged inside the drain, not surfaced as sync errors.
  try {
    await pushQueuedAuditEvents(db);
  } catch (err) {
    console.warn('[POS][sync] audit drain failed (non-fatal)', serializeErrorForLog(err));
  }

  // Then pull (always pull even if push had failures, to keep local data fresh)
  const productsPulled = await pullProducts(db);
  const paymentConfigPulled = await pullPaymentConfig(db);
  const operatorsPulled = await pullOperatorPins(db, terminalId);
  const terminalStatePulled = await pullTerminalState(db, terminalId);
  await pullZChainState(db, terminalId);
  const tablesPulled = await pullTables(db);
  const activeMenuPulled = await pullActiveMenu(db);
  const vouchersPulled = await pullVouchers(db, terminalId);
  const voucherLedgerPulled = await pullVoucherLedger(db, terminalId);
  const receiptQrIndexPulled = await pullReceiptQrIndex(db, terminalId);

  // Task 9 — location-stock delta pull. Runs in the pull phase, which
  // ALWAYS follows the push phase above, so this single call also serves
  // as the post-receipt-drain re-baseline (spec §4.3): any receipts the
  // server just ingested are reflected in the stock the server returns
  // here. Swallow-and-log like pullProducts — a stock-pull failure must
  // never block selling or the rest of the cycle.
  try {
    await pullLocationStock(db, 'delta', { terminalId });
  } catch (error) {
    const message = coerceSyncError(error);
    try {
      await logSyncOperation(db, 'pull', 'location_stock', null, 'error', message);
    } catch { /* non-critical */ }
  }

  // Refresh company config (locale, modules) — graceful on failure.
  try {
    const { useAuthStore } = await import('@/stores/authStore');
    await useAuthStore.getState().refreshCompanyConfig();
  } catch { /* non-critical */ }

  // Phase 6.2: background reconcile (spec §4.c). Detect when the server
  // projection shows the device's open shift CLOSED (a web-admin recovery
  // close) while local SQLite still has it OPEN, and surface an advisory,
  // audited, idempotent banner. NEVER auto-closes the local shift (a sale may
  // be mid-flight; Decision 4). Non-fatal: a failure here must not break the
  // sync tick — the next tick re-reconciles.
  try {
    const verdict = await reconcileOpenShift(db, terminalId);
    const { useTerminalStore } = await import('@/stores/terminalStore');
    const { useAuthStore } = await import('@/stores/authStore');
    const auth = useAuthStore.getState();
    await applyShiftReconcileVerdict(
      db,
      verdict,
      {
        tenantId: auth.user?.tenantId ?? '',
        companyId: auth.companyId ?? null,
        operatorId: auth.user?.id ?? null,
      },
      {
        flag: (conflict) => { useTerminalStore.getState().flagRemoteShiftClose(conflict); },
        clear: () => { useTerminalStore.getState().clearRemoteShiftCloseConflict(); },
      },
    );
  } catch (err) {
    console.warn('[POS][sync] shift reconcile failed (non-fatal)', serializeErrorForLog(err));
  }

  // Process pending image downloads (non-critical)
  try {
    const { processDownloadQueue } = await import('@/lib/images/imageCache');
    await processDownloadQueue(db);
  } catch { /* image caching is non-critical */ }

  // T1.3 Step 4.3: degraded signal for the SyncButton's amber dot.
  // See SyncResult.degraded docblock for the exact heuristic + the
  // false-positive-on-empty-catalog rationale.
  const degraded = computeDegraded({
    receiptsFailed: failed,
    zReportsFailed: zFailed,
    paymentConfigPulled,
    errors,
  });

  return {
    receiptsPushed: pushed,
    receiptsFailed: failed,
    zReportsPushed: zPushed,
    zReportsFailed: zFailed,
    cashDrawerOpsPushed: cashDrawerPushed,
    pinUpdatesPushed,
    voucherLedgerPushed,
    voucherLedgerFailed,
    productsPulled,
    paymentConfigPulled,
    operatorsPulled,
    terminalStatePulled,
    tablesPulled,
    activeMenuPulled,
    vouchersPulled,
    voucherLedgerPulled,
    receiptQrIndexPulled,
    chainBreak,
    errors,
    degraded,
  };
}

interface LegacyReceiptPayloadPayment {
  payment_method_id: string;
  repository_id: string;
  amount: string;
  card_last_four: string | null;
  transaction_reference: string | null;
  method_code: string;
  instrument_type: 'store_voucher' | 'restaurant_voucher' | 'gift_card' | null;
  instrument_serial: string | null;
}

interface LegacyReceiptPayloadForTests {
  lines: unknown[];
  payments: LegacyReceiptPayloadPayment[];
  fiscal_schema_version: 2 | 3;
  is_training: boolean;
}

function unpackCompositeIdsOnLines(lines: unknown[]): unknown[] {
  return lines.map((line) => {
    if (line === null || typeof line !== 'object') return line;
    const obj = line as Record<string, unknown>;
    const next: Record<string, unknown> = { ...obj };
    let categoryId: string | null = null;
    if (typeof obj.product_id === 'string') {
      const parsed = parseMenuCompositeId(obj.product_id);
      next.product_id = parsed.sellableId;
      categoryId = parsed.categoryId;
    }
    if (typeof obj.composite_item_id === 'string') {
      const parsed = parseMenuCompositeId(obj.composite_item_id);
      next.composite_item_id = parsed.sellableId;
      categoryId = categoryId ?? parsed.categoryId;
    }
    if (categoryId !== null) {
      next.menu_category_id = categoryId;
    }
    return next;
  });
}

export function __test_receiptToPayload(receipt: OfflineReceipt): LegacyReceiptPayloadForTests {
  if (receipt.fiscal_schema_version !== 2 && receipt.fiscal_schema_version !== 3) {
    throw new Error(
      `Receipt ${receipt.receipt_number} has unsupported fiscal_schema_version=${String(receipt.fiscal_schema_version)}`,
    );
  }

  const raw = receipt.payments_json ? JSON.parse(receipt.payments_json) as unknown : [];
  const rows = Array.isArray(raw) ? raw : [];
  return {
    lines: unpackCompositeIdsOnLines(JSON.parse(receipt.lines) as unknown[]),
    payments: rows.map((row) => {
      const payment = row as Partial<LegacyReceiptPayloadPayment>;
      const instrumentType = payment.instrument_type ?? null;
      return {
        payment_method_id: String(payment.payment_method_id ?? receipt.payment_method_id),
        repository_id: String(payment.repository_id ?? receipt.payment_repository_id),
        amount: String(payment.amount ?? receipt.total),
        card_last_four: payment.card_last_four ?? null,
        transaction_reference: payment.transaction_reference ?? null,
        method_code: typeof payment.method_code === 'string' ? payment.method_code : '',
        instrument_type: instrumentType === 'store_voucher' || instrumentType === 'restaurant_voucher' || instrumentType === 'gift_card'
          ? instrumentType
          : null,
        instrument_serial: typeof payment.instrument_serial === 'string' ? payment.instrument_serial : null,
      };
    }),
    fiscal_schema_version: receipt.fiscal_schema_version,
    is_training: receipt.is_training === 1,
  };
}
