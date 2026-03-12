import type Database from '@tauri-apps/plugin-sql';
import { apiGet, apiPost } from '@/lib/api';
import { upsertProducts } from '@/lib/db/repositories/productRepository';
import {
  upsertPaymentMethods,
  upsertPaymentRepositories,
} from '@/lib/db/repositories/paymentRepository';
import { upsertOperators } from '@/lib/db/repositories/operatorPinRepository';
import { upsertTerminalState, type TerminalHashState } from '@/lib/db/repositories/terminalStateRepository';
import {
  getPendingReceipts,
  updateReceiptStatus,
  type OfflineReceipt,
} from '@/lib/db/repositories/offlineReceiptRepository';
import { logSyncOperation, getSyncMetadata, setSyncMetadata } from '@/lib/db/repositories/syncLogRepository';
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
  productsPulled: number;
  paymentConfigPulled: boolean;
  operatorsPulled: number;
  terminalStatePulled: boolean;
  errors: string[];
}

/**
 * Push offline receipts to server.
 * Processes one at a time to maintain hash chain order.
 */
export async function pushOfflineReceipts(db: Database): Promise<{ pushed: number; failed: number; errors: string[] }> {
  const pending = await getPendingReceipts(db);
  let pushed = 0;
  let failed = 0;
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
      await updateReceiptStatus(db, receipt.id, 'failed', message);
      await logSyncOperation(db, 'push', 'receipt', receipt.id, 'error', message);
      errors.push(`Receipt ${receipt.receipt_number}: ${message}`);
      failed++;
    }
  }

  return { pushed, failed, errors };
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

/**
 * Full sync: push then pull.
 */
export async function runFullSync(
  db: Database,
  terminalId: string,
): Promise<SyncResult> {
  const errors: string[] = [];

  // Push first
  const { pushed, failed, errors: pushErrors } = await pushOfflineReceipts(db);
  errors.push(...pushErrors);

  // Then pull
  const productsPulled = await pullProducts(db);
  const paymentConfigPulled = await pullPaymentConfig(db);
  const operatorsPulled = await pullOperatorPins(db);
  const terminalStatePulled = await pullTerminalState(db, terminalId);

  return {
    receiptsPushed: pushed,
    receiptsFailed: failed,
    productsPulled,
    paymentConfigPulled,
    operatorsPulled,
    terminalStatePulled,
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
