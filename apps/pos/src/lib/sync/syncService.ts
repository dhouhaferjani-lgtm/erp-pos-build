import type Database from '@tauri-apps/plugin-sql';
import { apiGet, apiPost } from '@/lib/api';
import { upsertProducts } from '@/lib/db/repositories/productRepository';
import {
  upsertPaymentMethods,
  upsertPaymentRepositories,
} from '@/lib/db/repositories/paymentRepository';
import { upsertOperators } from '@/lib/db/repositories/operatorPinRepository';
import { upsertTerminalState, upsertZChainState, type TerminalHashState } from '@/lib/db/repositories/terminalStateRepository';
import {
  getPendingReceiptsForSync,
  updateReceiptStatus,
  incrementRetryCount,
  cleanupSyncedReceipts,
  cleanupStuckReceipts,
  type OfflineReceipt,
} from '@/lib/db/repositories/offlineReceiptRepository';
import {
  getUnsyncedZReports,
  markZReportSynced,
} from '@/lib/db/repositories/zReportRepository';
import { logSyncOperation, getSyncMetadata, setSyncMetadata, cleanupOldSyncLogs } from '@/lib/db/repositories/syncLogRepository';
import type { LocalZReport } from '@/lib/offline/types';
import type { POSProduct } from '@/types/product';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';

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
  terminal_id: string;
  terminal_code: string;
  genesis_seed: string;
  last_hash: string;
  hash_sequence: number;
}

export interface SyncResult {
  receiptsPushed: number;
  receiptsFailed: number;
  zReportsPushed: number;
  zReportsFailed: number;
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
  if (error instanceof Error) {
    const msg = error.message.toLowerCase();
    return msg.includes('hash chain') || msg.includes('hash mismatch') || msg.includes('chain break');
  }
  return false;
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
      await apiPost('/pos/receipts/sync', payload);

      await updateReceiptStatus(db, receipt.id, 'synced');
      await logSyncOperation(db, 'push', 'receipt', receipt.id, 'success');
      pushed++;
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown error';
      await incrementRetryCount(db, receipt.id);
      await updateReceiptStatus(db, receipt.id, 'failed', message);
      await logSyncOperation(db, 'push', 'receipt', receipt.id, 'error', message);
      errors.push(`Receipt ${receipt.receipt_number}: ${message}`);
      failed++;

      // On chain-break, halt sync immediately — remaining receipts depend on this one
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
 * Pull products delta from server.
 */
export async function pullProducts(db: Database): Promise<number> {
  const lastSync = await getSyncMetadata(db, 'products_last_sync');
  const params: Record<string, string> = {};
  if (lastSync) {
    params['updated_since'] = lastSync;
  }

  const result = await apiGet<POSProduct[] | { data: POSProduct[] }>('/products', { ...params, per_page: 1000 });
  const products = Array.isArray(result) ? result : result.data;

  if (products.length > 0) {
    await upsertProducts(db, products);
    await setSyncMetadata(db, 'products_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'products', null, 'success', `${products.length} products`);
  }

  return products.length;
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
 */
export async function pullTerminalState(
  db: Database,
  terminalId: string,
): Promise<boolean> {
  try {
    const state = await apiGet<TerminalStateResponse>(`/pos/terminals/${terminalId}`);
    if (state.genesis_seed) {
      const hashState: TerminalHashState = {
        terminal_id: state.terminal_id,
        terminal_code: state.terminal_code,
        genesis_seed: state.genesis_seed,
        last_hash: state.last_hash,
        hash_sequence: state.hash_sequence,
      };
      await upsertTerminalState(db, hashState);
      await logSyncOperation(db, 'pull', 'terminal_state', terminalId, 'success');
      return true;
    }
    return false;
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

  // Push receipts first (order matters for chain)
  const { pushed, failed, errors: pushErrors, chainBreak } = await pushOfflineReceipts(db);
  errors.push(...pushErrors);

  // Push Z-reports after receipts (Z-reports reference receipt data)
  const { pushed: zPushed, failed: zFailed, errors: zErrors } = await pushZReports(db);
  errors.push(...zErrors);

  // Then pull (always pull even if push had failures, to keep local data fresh)
  const productsPulled = await pullProducts(db);
  const paymentConfigPulled = await pullPaymentConfig(db);
  const operatorsPulled = await pullOperatorPins(db);
  const terminalStatePulled = await pullTerminalState(db, terminalId);
  await pullZChainState(db, terminalId);

  return {
    receiptsPushed: pushed,
    receiptsFailed: failed,
    zReportsPushed: zPushed,
    zReportsFailed: zFailed,
    productsPulled,
    paymentConfigPulled,
    operatorsPulled,
    terminalStatePulled,
    chainBreak,
    errors,
  };
}

function receiptToPayload(receipt: OfflineReceipt): SyncReceiptPayload {
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
  };
}
