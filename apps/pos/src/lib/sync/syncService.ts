import type Database from '@tauri-apps/plugin-sql';
import { apiGet, apiPost } from '@/lib/api';
import { upsertProducts, deleteProducts } from '@/lib/db/repositories/productRepository';
import {
  upsertPaymentMethods,
  upsertPaymentRepositories,
} from '@/lib/db/repositories/paymentRepository';
import { upsertOperators } from '@/lib/db/repositories/operatorPinRepository';
import Big from 'big.js';
import {
  upsertTerminalState,
  upsertZChainState,
  advanceHashChain,
  getZChainState,
  type TerminalHashState,
} from '@/lib/db/repositories/terminalStateRepository';
import {
  getPendingPinUpdates,
  markPinUpdateSynced,
  markPinUpdateFailed,
} from '@/lib/db/repositories/queuedPinUpdateRepository';
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
  getPendingReceiptsForSync,
  updateReceiptStatus,
  incrementRetryCount,
  cleanupSyncedReceipts,
  cleanupStuckReceipts,
  setServerReceiptId,
  type OfflineReceipt,
} from '@/lib/db/repositories/offlineReceiptRepository';
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
import { usePaymentStore } from '@/stores/paymentStore';
import { serializeErrorForLog } from '@/lib/errorLogging';

interface SyncReceiptPayloadPayment {
  payment_method_id: string;
  repository_id: string;
  amount: string;
  card_last_four: string | null;
  transaction_reference: string | null;
  /**
   * Snapshot of `payment_methods.code` at the time the offline receipt was
   * sealed. The POS computes the v3 fiscal hash with this value; the server
   * MUST persist the same string into `pos_receipt_payments.payment_method_code`
   * or the recomputed hash will diverge. Codex review B3 (2026-04-30).
   *
   * B3-followup audit (Finding 2, 2026-05-01): this field is REQUIRED on the
   * sync wire (server validator promoted from `nullable` to `required`). A
   * payload that emits `method_code: ''` (e.g. legacy synthesized fallback
   * for pre-v16 receipts with NULL payments_json) will be rejected with 422.
   * That outcome is correct — those payloads cannot reproduce the offline
   * hash anyway, so failing loud at the wire is preferable to a silent
   * server-side fallback that the audit explicitly flagged.
   */
  method_code: string;
  /**
   * Voucher / instrument discriminator. Bound into the v3 fiscal hash by
   * `buildCanonicalPayload`. Null for non-instrument tenders (cash, card).
   * Codex review B3 (2026-04-30).
   */
  instrument_type: 'store_voucher' | 'restaurant_voucher' | 'gift_card' | null;
  /**
   * Voucher serial / gift-card code that tendered this row. Bound into the
   * v3 fiscal hash. Null for non-instrument tenders. Codex review B3 (2026-04-30).
   */
  instrument_serial: string | null;
}

interface SyncReceiptPayload {
  idempotency_key: string;
  receipt_number: string;
  terminal_id: string;
  operator_id: string;
  lines: unknown[];
  subtotal: string;
  tax_amount: string;
  discount_amount: string;
  total: string;
  currency: string;
  offline_fiscal_hash: string;
  previous_hash: string;
  hash_sequence: number;
  transaction_discount_amount: string | null;
  transaction_discount_reason: string | null;
  tendered_amount: string | null;
  change_due: string | null;
  payment_method_id: string;
  payment_repository_id: string;
  created_at: string;
  payments: SyncReceiptPayloadPayment[];
  consumption_mode: string | null;
  table_id: string | null;
  /**
   * Fiscal hash schema version this receipt was sealed under (Codex review B1).
   * Required: the server hard-rejects any payload whose declared version does
   * not match the terminal's current `fiscal_schema_version`. There is no
   * compatibility window — terminals must drain pending v2 receipts before
   * the cutover flips them to v3.
   */
  fiscal_schema_version: 2 | 3;
}

interface SyncReceiptResponseItem {
  idempotency_key: string;
  status: 'synced' | 'duplicate' | 'failed' | 'chain_broken';
  receipt_id: string | null;
  server_fiscal_hash: string | null;
  error: string | null;
  /** Server-authoritative hash after this receipt was sealed. Null for failed/chain_broken. */
  terminal_last_hash: string | null;
  /** Server-authoritative sequence number AFTER sealing this receipt. */
  terminal_hash_sequence: number | null;
}

interface SyncReceiptBatchResponse {
  results: SyncReceiptResponseItem[];
  total: number;
  synced: number;
  duplicates: number;
  failed: number;
}

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
 * Push offline receipts to server.
 * Processes one at a time to maintain hash chain order.
 * On chain-break conflict, halts sync immediately to preserve chain integrity.
 * Tracks retry count per receipt; receipts exceeding max retries are skipped.
 */
export async function pushOfflineReceipts(db: Database): Promise<{
  pushed: number;
  failed: number;
  errors: string[];
  chainBreak: boolean;
}> {
  const pending = await getPendingReceiptsForSync(db);
  let pushed = 0;
  let failed = 0;
  let chainBreak = false;
  const errors: string[] = [];

  for (const receipt of pending) {
    try {
      await updateReceiptStatus(db, receipt.id, 'syncing');

      const payload = receiptToPayload(receipt);
      // Send as batch-of-one so the response shape is always { results: [...] }.
      // T0.3: 30s ceiling (vs the 10s default) — the receipt sync path runs
      // server-side fiscal-hash verification, voucher resolution, and ledger
      // writes; the longer ceiling matches that worst-case while still
      // unblocking the JS caller if the response is dropped on the wire.
      // On FetchTimeoutError, the catch at the bottom of this loop marks the
      // receipt 'failed' with the typed error message; the next sync tick
      // reconciles via T0.2's stable idempotency key (this is the T0.4
      // chain-break-recovery downstream contract).
      const response = await apiPost<SyncReceiptBatchResponse>(
        '/pos/receipts/sync',
        { receipts: [payload] },
        { timeoutMs: 30_000 },
      );

      const resultItem = response.results.find((r) => r.idempotency_key === receipt.idempotency_key);
      if (!resultItem) {
        throw new Error(`Sync response missing result for ${receipt.receipt_number}`);
      }

      if (resultItem.status === 'synced' || resultItem.status === 'duplicate') {
        await updateReceiptStatus(db, receipt.id, 'synced');
        if (resultItem.receipt_id) {
          try {
            await setServerReceiptId(db, receipt.idempotency_key, resultItem.receipt_id);
            // If this is the receipt currently shown in the success modal, update the store
            // so HomePage can switch from local SQLite print to the richer API receipt.
            if (usePaymentStore.getState().lastReceiptIdempotencyKey === receipt.idempotency_key) {
              usePaymentStore.setState({ lastReceiptServerId: resultItem.receipt_id });
            }
          } catch (writebackError) {
            const msg = writebackError instanceof Error ? writebackError.message : 'unknown';
            await logSyncOperation(db, 'push', 'receipt', receipt.id, 'error', `server_receipt_id writeback failed (server sync succeeded): ${msg}`);
          }
        }
        if (
          resultItem.terminal_last_hash &&
          typeof resultItem.terminal_hash_sequence === 'number'
        ) {
          try {
            await advanceHashChain(
              db,
              receipt.terminal_id,
              resultItem.terminal_last_hash,
              resultItem.terminal_hash_sequence,
            );
          } catch (reconcileError) {
            // Regression guard fired — local is ahead of server. Log and carry on.
            // This is the offline-first invariant: local counters are authoritative
            // once seeded.
            const msg = reconcileError instanceof Error ? reconcileError.message : 'unknown';
            await logSyncOperation(
              db,
              'push',
              'receipt',
              receipt.id,
              'success',
              `reconcile skipped (local ahead): ${msg}`,
            );
          }
        }
        await logSyncOperation(db, 'push', 'receipt', receipt.id, 'success', resultItem.status);
        pushed++;
      } else if (resultItem.status === 'chain_broken' || (resultItem.status === 'failed' && isChainBreakError(resultItem.error))) {
        await incrementRetryCount(db, receipt.id);
        await updateReceiptStatus(db, receipt.id, 'failed', resultItem.error ?? 'chain_broken');
        await logSyncOperation(db, 'push', 'receipt', receipt.id, 'error', `chain_broken: ${resultItem.error ?? ''}`);
        chainBreak = true;
        errors.push(`CHAIN_BREAK at receipt ${receipt.receipt_number}`);
        failed++;
        break;
      } else {
        await incrementRetryCount(db, receipt.id);
        await updateReceiptStatus(db, receipt.id, 'failed', resultItem.error ?? 'failed');
        await logSyncOperation(db, 'push', 'receipt', receipt.id, 'error', resultItem.error ?? 'failed');
        errors.push(`Receipt ${receipt.receipt_number}: ${resultItem.error ?? 'failed'}`);
        failed++;
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown error';
      console.error('[POS][sync][pushOfflineReceipts] receipt push threw', {
        ...serializeErrorForLog(error),
        receiptId: receipt.id,
        receiptNumber: receipt.receipt_number,
        retryCount: receipt.retry_count,
        idempotencyKey: receipt.idempotency_key,
      });
      await incrementRetryCount(db, receipt.id);
      await updateReceiptStatus(db, receipt.id, 'failed', message);
      await logSyncOperation(db, 'push', 'receipt', receipt.id, 'error', message);
      errors.push(`Receipt ${receipt.receipt_number}: ${message}`);
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
      const message = error instanceof Error ? error.message : 'Unknown error';
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
      await apiPost('/pos/cash-drawer/sync', {
        idempotency_key: op.idempotency_key,
        type: op.type,
        amount: op.amount,
        reason: op.reason,
        terminal_id: op.terminal_id,
        shift_id: op.shift_id,
        operator_id: op.operator_id,
        created_at: op.created_at,
      });
      await updateCashDrawerOpStatus(db, op.id, 'synced');
      await logSyncOperation(db, 'push', 'cash_drawer_op', op.id, 'success');
      pushed++;
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown error';
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
export async function pullProducts(db: Database): Promise<number> {
  try {
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
      const result = await apiGet<
        POSProduct[] | { data: POSProduct[]; deleted_ids?: string[] }
      >('/products', { ...params, page: String(page) });

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

    return totalPulled;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    await logSyncOperation(db, 'pull', 'products', null, 'error', message);
    return 0;
  }
}

/**
 * Pull payment configuration (methods and repositories).
 */
export async function pullPaymentConfig(db: Database): Promise<boolean> {
  try {
    const [methods, repositories] = await Promise.all([
      apiGet<PaymentMethod[]>('/treasury/payment-methods'),
      apiGet<PaymentRepository[]>('/treasury/payment-repositories'),
    ]);

    await upsertPaymentMethods(db, methods);
    await upsertPaymentRepositories(db, repositories);
    await setSyncMetadata(db, 'payment_config_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'payment_config', null, 'success');
    return true;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    await logSyncOperation(db, 'pull', 'payment_config', null, 'error', message);
    return false;
  }
}

/**
 * Pull operator PIN hashes for offline verification.
 */
export async function pullOperatorPins(db: Database): Promise<number> {
  try {
    const operators = await apiGet<OperatorPinData[]>('/pos/auth/pin-data');
    await upsertOperators(db, operators);
    await setSyncMetadata(db, 'operators_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'operators', null, 'success', `${operators.length} operators`);
    return operators.length;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
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
    const message = error instanceof Error ? error.message : 'Unknown error';
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
    const message = error instanceof Error ? error.message : 'Unknown error';
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
    const message = error instanceof Error ? error.message : 'Unknown error';
    for (const row of pending) {
      await markPinUpdateFailed(db, row.id, message);
    }
    await logSyncOperation(db, 'push', 'pin_update', null, 'error', message);
    return 0;
  }
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
    const message = error instanceof Error ? error.message : 'Unknown error';
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
    await logSyncOperation(db, 'pull', 'active_menu', null, 'success', `${response.categories.length} categories / ${items.length} items`);
    return true;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
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
    const message = error instanceof Error ? error.message : 'Unknown error';
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
    const message = error instanceof Error ? error.message : 'Unknown error';
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
    const message = error instanceof Error ? error.message : 'Unknown error';
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
      const message = error instanceof Error ? error.message : 'Unknown error';
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

  // Then pull (always pull even if push had failures, to keep local data fresh)
  const productsPulled = await pullProducts(db);
  const paymentConfigPulled = await pullPaymentConfig(db);
  const operatorsPulled = await pullOperatorPins(db);
  const terminalStatePulled = await pullTerminalState(db, terminalId);
  await pullZChainState(db, terminalId);
  const tablesPulled = await pullTables(db);
  const activeMenuPulled = await pullActiveMenu(db);
  const vouchersPulled = await pullVouchers(db, terminalId);
  const voucherLedgerPulled = await pullVoucherLedger(db, terminalId);
  const receiptQrIndexPulled = await pullReceiptQrIndex(db, terminalId);

  // Refresh company config (locale, modules) — graceful on failure.
  try {
    const { useAuthStore } = await import('@/stores/authStore');
    await useAuthStore.getState().refreshCompanyConfig();
  } catch { /* non-critical */ }

  // Process pending image downloads (non-critical)
  try {
    const { processDownloadQueue } = await import('@/lib/images/imageCache');
    await processDownloadQueue(db);
  } catch { /* image caching is non-critical */ }

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
  };
}

function receiptToPayload(receipt: OfflineReceipt): SyncReceiptPayload {
  // Codex review B3 (2026-04-30): the synthesized fallback covers pre-v16
  // receipts that had no payments_json. They are cash-only by construction
  // (no v3 voucher tender existed before the multi-payment column shipped),
  // so empty method_code + null instrument fields is a faithful default.
  const synthesize = (): SyncReceiptPayloadPayment[] => [{
    payment_method_id: receipt.payment_method_id,
    repository_id: receipt.payment_repository_id,
    amount: receipt.total,
    card_last_four: null,
    transaction_reference: null,
    method_code: '',
    instrument_type: null,
    instrument_serial: null,
  }];

  let parsedPayments: SyncReceiptPayloadPayment[];
  if (!receipt.payments_json) {
    // Pre-v16 receipt: column was NULL/empty. Synthesize silently.
    parsedPayments = synthesize();
  } else {
    try {
      const raw = JSON.parse(receipt.payments_json) as unknown;
      if (!Array.isArray(raw)) {
        throw new Error('payments_json is not an array');
      }
      // Codex review B3 (2026-04-30): preserve method_code, instrument_type,
      // and instrument_serial. The POS-side v3 hash was computed with these
      // fields included; dropping them here causes the server to recompute a
      // mismatched hash and reject the offline receipt as a chain break.
      // Defensive fallbacks: rows queued before B3 may be missing these
      // fields entirely — coerce to empty/null rather than NaN-typed strings.
      parsedPayments = (raw as Array<Partial<SyncReceiptPayloadPayment>>).map((p) => {
        const instrumentType = p.instrument_type ?? null;
        return {
          payment_method_id: String(p.payment_method_id ?? receipt.payment_method_id),
          repository_id: String(p.repository_id ?? receipt.payment_repository_id),
          amount: String(p.amount ?? receipt.total),
          card_last_four: p.card_last_four ?? null,
          transaction_reference: p.transaction_reference ?? null,
          method_code: typeof p.method_code === 'string' ? p.method_code : '',
          instrument_type: instrumentType === 'store_voucher' || instrumentType === 'restaurant_voucher' || instrumentType === 'gift_card'
            ? instrumentType
            : null,
          instrument_serial: typeof p.instrument_serial === 'string' ? p.instrument_serial : null,
        };
      });
    } catch (parseError) {
      // v16+ receipt with malformed payments_json — fall back but log loudly.
      console.warn(
        `[sync] malformed payments_json for receipt ${receipt.receipt_number}; falling back to flat columns`,
        parseError,
      );
      parsedPayments = synthesize();
    }
  }

  // Codex review B1: stamp the version this receipt was sealed under. We do
  // NOT default-to-2 here — that would silently let a v3-sealed receipt sync
  // as v2 if the column ever read back as undefined.
  //
  // B3-followup audit (Finding 4, 2026-05-01): the prior implementation said
  // "any unexpected value is a schema bug and should fail loudly" in this
  // comment but actually coerced anything that wasn't 3 to 2 silently. Now
  // it actually fails loudly. The error names the receipt and the bad value
  // so an on-call engineer can locate the row in the offline_receipts table.
  // The server's hard-reject path remains as a defense in depth for any
  // payload that slips through — but we should not be sending it in the
  // first place.
  const fiscalSchemaVersion: 2 | 3 = (() => {
    if (receipt.fiscal_schema_version === 2 || receipt.fiscal_schema_version === 3) {
      return receipt.fiscal_schema_version;
    }
    throw new Error(
      `[sync] Receipt ${receipt.receipt_number} (idempotency_key=${receipt.idempotency_key}) ` +
      `has invalid fiscal_schema_version=${String(receipt.fiscal_schema_version)}; ` +
      `expected 2 or 3. This indicates a schema or migration bug — investigate the offline_receipts row.`,
    );
  })();

  return {
    idempotency_key: receipt.idempotency_key,
    receipt_number: receipt.receipt_number,
    terminal_id: receipt.terminal_id,
    operator_id: receipt.operator_id,
    lines: JSON.parse(receipt.lines) as unknown[],
    subtotal: receipt.subtotal,
    tax_amount: receipt.tax_amount,
    discount_amount: receipt.discount_amount,
    total: receipt.total,
    currency: receipt.currency,
    offline_fiscal_hash: receipt.fiscal_hash,
    previous_hash: receipt.previous_hash,
    hash_sequence: receipt.hash_sequence,
    transaction_discount_amount: receipt.transaction_discount_amount,
    transaction_discount_reason: receipt.transaction_discount_reason,
    tendered_amount: receipt.tendered_amount,
    change_due: receipt.change_due,
    payment_method_id: receipt.payment_method_id,
    payment_repository_id: receipt.payment_repository_id,
    created_at: receipt.created_at,
    payments: parsedPayments,
    consumption_mode: receipt.consumption_mode,
    table_id: receipt.table_id,
    fiscal_schema_version: fiscalSchemaVersion,
  };
}

/**
 * Test-only export of `receiptToPayload`.
 *
 * B3-followup audit (Finding 3, 2026-05-01): the `OfflineV3CutoverSyncTest`
 * voucher case precomputes the expected hash via PHP `ReceiptFinalizationService`
 * rather than the TS canonicalizer. That bridge is correct (PHP=PHP) but it
 * does not catch a future TS-only drift in `receiptService.ts:167-173` (canonical
 * input builder) or `syncService.ts:receiptToPayload` (wire payload parser).
 *
 * The TS-side test at `receiptService.test.ts` ("the wire payload produced by
 * receiptToPayload matches the canonical input the hash was sealed against")
 * imports this symbol to assert the production sync code path produces a wire
 * payload from which the server can reproduce the offline-sealed hash. Drift
 * in either mapper will fail that test loudly.
 *
 * Naming: prefixed with `__test_` so it is unmistakable that this is not
 * production API. The implementation it points at IS production code.
 */
export const __test_receiptToPayload = receiptToPayload;
