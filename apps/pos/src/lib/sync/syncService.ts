import type Database from '@tauri-apps/plugin-sql';
import { apiGet, apiPost } from '@/lib/api';
import { upsertProducts } from '@/lib/db/repositories/productRepository';
import {
  upsertPaymentMethods,
  upsertPaymentRepositories,
} from '@/lib/db/repositories/paymentRepository';
import { upsertOperators } from '@/lib/db/repositories/operatorPinRepository';
import { upsertTerminalState, upsertZChainState, type TerminalHashState } from '@/lib/db/repositories/terminalStateRepository';
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
import type { LocalZReport } from '@/lib/offline/types';
import type { POSProduct } from '@/types/product';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import { usePaymentStore } from '@/stores/paymentStore';

interface SyncReceiptPayloadPayment {
  payment_method_id: string;
  repository_id: string;
  amount: string;
  card_last_four: string | null;
  transaction_reference: string | null;
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
}

interface SyncReceiptResponseItem {
  idempotency_key: string;
  status: 'synced' | 'duplicate' | 'failed' | 'chain_broken';
  receipt_id: string | null;
  server_fiscal_hash: string | null;
  error: string | null;
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
}

export interface SyncResult {
  receiptsPushed: number;
  receiptsFailed: number;
  zReportsPushed: number;
  zReportsFailed: number;
  cashDrawerOpsPushed: number;
  productsPulled: number;
  paymentConfigPulled: boolean;
  operatorsPulled: number;
  terminalStatePulled: boolean;
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
      // Send as batch-of-one so the response shape is always { results: [...] }
      const response = await apiPost<SyncReceiptBatchResponse>('/pos/receipts/sync', { receipts: [payload] });

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

function zReportToSyncPayload(report: LocalZReport): Record<string, unknown> {
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
  };
}

/**
 * Pull products delta from server with pagination.
 * Uses per_page=500 and loops until a page returns fewer than 500 items.
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

    while (hasMore) {
      const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products', { ...params, page: String(page) });
      const products = Array.isArray(result) ? result : result.data;

      if (products.length > 0) {
        await upsertProducts(db, products);
        totalPulled += products.length;
      }

      hasMore = products.length === 500;
      page++;
    }

    if (totalPulled > 0) {
      await setSyncMetadata(db, 'products_last_sync', new Date().toISOString());
      await logSyncOperation(db, 'pull', 'products', null, 'success', `${totalPulled} products`);
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
    const hashState: TerminalHashState = {
      terminal_id: state.id,
      terminal_code: state.code,
      location_code: (state.location?.code ?? 'MAIN').toUpperCase(),
      genesis_seed: state.genesis_seed,
      last_hash: initialHash,
      hash_sequence: state.hash_sequence,
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
  grand_totals: {
    cumulative_sales: number;
    cumulative_tax: number;
    cumulative_refunds: number;
    perpetual_grand_total: number;
    receipt_count_lifetime: number;
  } | null;
}

/**
 * Pull Z-chain state from server (for recovery after local DB loss).
 * Updates only Z-chain columns in terminal_state without affecting receipt chain.
 */
export async function pullZChainState(
  db: Database,
  terminalId: string,
): Promise<boolean> {
  try {
    const state = await apiGet<ZChainStateResponse>(`/pos/terminals/${terminalId}/z-chain-state`);
    await upsertZChainState(db, terminalId, state);
    await logSyncOperation(db, 'pull', 'z_chain_state', terminalId, 'success');
    return true;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    await logSyncOperation(db, 'pull', 'z_chain_state', terminalId, 'error', message);
    return false;
  }
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

  // Push receipts first (order matters for chain)
  const { pushed, failed, errors: pushErrors, chainBreak } = await pushOfflineReceipts(db);
  errors.push(...pushErrors);

  // Push Z-reports after receipts (Z-reports reference receipt data)
  const { pushed: zPushed, failed: zFailed, errors: zErrors } = await pushZReports(db);
  errors.push(...zErrors);

  // Push cash drawer operations (no ordering constraints)
  const { pushed: cashDrawerPushed, errors: cashDrawerErrors } = await pushCashDrawerOps(db);
  errors.push(...cashDrawerErrors);

  // Then pull (always pull even if push had failures, to keep local data fresh)
  const productsPulled = await pullProducts(db);
  const paymentConfigPulled = await pullPaymentConfig(db);
  const operatorsPulled = await pullOperatorPins(db);
  const terminalStatePulled = await pullTerminalState(db, terminalId);
  await pullZChainState(db, terminalId);

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
    productsPulled,
    paymentConfigPulled,
    operatorsPulled,
    terminalStatePulled,
    chainBreak,
    errors,
  };
}

function receiptToPayload(receipt: OfflineReceipt): SyncReceiptPayload {
  const synthesize = (): SyncReceiptPayloadPayment[] => [{
    payment_method_id: receipt.payment_method_id,
    repository_id: receipt.payment_repository_id,
    amount: receipt.total,
    card_last_four: null,
    transaction_reference: null,
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
      parsedPayments = (raw as Array<Partial<SyncReceiptPayloadPayment>>).map((p) => ({
        payment_method_id: String(p.payment_method_id ?? receipt.payment_method_id),
        repository_id: String(p.repository_id ?? receipt.payment_repository_id),
        amount: String(p.amount ?? receipt.total),
        card_last_four: p.card_last_four ?? null,
        transaction_reference: p.transaction_reference ?? null,
      }));
    } catch (parseError) {
      // v16+ receipt with malformed payments_json — fall back but log loudly.
      console.warn(
        `[sync] malformed payments_json for receipt ${receipt.receipt_number}; falling back to flat columns`,
        parseError,
      );
      parsedPayments = synthesize();
    }
  }

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
  };
}
