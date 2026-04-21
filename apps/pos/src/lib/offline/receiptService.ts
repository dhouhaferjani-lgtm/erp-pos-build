import type Database from '@tauri-apps/plugin-sql';
import { getCurrencyDecimals } from '@/lib/currency';
import { computeFiscalHash } from '@/lib/fiscal/hashService';
import {
  getTerminalState,
  advanceHashChain,
} from '@/lib/db/repositories/terminalStateRepository';
import {
  insertOfflineReceipt,
  type OfflineReceipt,
} from '@/lib/db/repositories/offlineReceiptRepository';
import type { CartItem } from '@/types/cart';

interface OfflineReceiptInput {
  terminalId: string;
  operatorId: string;
  operatorName: string;
  cartItems: CartItem[];
  currency: string;
  /** Primary payment method (first entry in `payments`) — used for the denormalized column on offline_receipts */
  paymentMethodId: string;
  /** Primary payment repository (first entry in `payments`) */
  paymentRepositoryId: string;
  tenderedAmount: number;
  transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string };
  /** Payments breakdown for fiscal hash + sync payload. Required. For single-payment flows, pass one entry. */
  payments: Array<{
    methodCode: string;
    amount: string;
    paymentMethodId?: string;
    repositoryId?: string;
    cardLastFour?: string;
    transactionReference?: string;
  }>;
  /** F&B: 'SUR_PLACE' | 'A_EMPORTER' — omit for retail */
  consumptionMode?: string;
  /** F&B: table UUID — omit for retail or takeout */
  tableId?: string;
}

export interface OfflineReceiptResult {
  receiptNumber: string;
  total: string;
  subtotal: string;
  taxAmount: string;
  discountAmount: string;
  changeDue: number;
  fiscalHash: string;
  idempotencyKey: string;
  localId: string;
}

interface VatBreakdownEntry {
  rate: string;
  amount: string;
}

function computeLineTotals(cartItems: CartItem[]): {
  subtotal: number;
  taxAmount: number;
} {
  let subtotal = 0;
  let taxAmount = 0;

  for (const item of cartItems) {
    subtotal += parseFloat(item.line_total);
    taxAmount += parseFloat(item.tax_amount);
  }

  return { subtotal, taxAmount };
}

function computeVatBreakdown(cartItems: CartItem[], decimals: number): VatBreakdownEntry[] {
  const byRate = new Map<string, number>();

  for (const item of cartItems) {
    const rate = item.tax_rate;
    const amount = parseFloat(item.tax_amount);
    if (amount === 0) continue;
    byRate.set(rate, (byRate.get(rate) ?? 0) + amount);
  }

  const entries: VatBreakdownEntry[] = [];
  for (const [rate, total] of byRate) {
    entries.push({ rate, amount: total.toFixed(decimals) });
  }

  return entries.sort((a, b) => a.rate.localeCompare(b.rate));
}

function generateReceiptNumber(
  locationCode: string,
  terminalCode: string,
  sequence: number,
): string {
  const year = new Date().getFullYear();
  const paddedSeq = String(sequence).padStart(8, '0');
  return `${locationCode}-${terminalCode}-${year}-${paddedSeq}`;
}

export async function createOfflineReceipt(
  db: Database,
  input: OfflineReceiptInput,
): Promise<OfflineReceiptResult> {
  const decimals = getCurrencyDecimals(input.currency);

  // 1. Read terminal state
  const terminalState = await getTerminalState(db, input.terminalId);
  if (!terminalState) {
    throw new Error('Terminal hash chain not initialized. Cannot create offline receipt.');
  }

  // 2. Compute line totals
  const { subtotal, taxAmount } = computeLineTotals(input.cartItems);
  let transactionDiscountAmount = 0;
  if (input.transactionDiscount) {
    if (input.transactionDiscount.type === 'percentage') {
      transactionDiscountAmount = (subtotal * parseFloat(input.transactionDiscount.value)) / 100;
    } else {
      transactionDiscountAmount = parseFloat(input.transactionDiscount.value);
    }
  }
  const total = Math.max(0, subtotal - transactionDiscountAmount);

  // 3. Generate receipt number
  const newSequence = terminalState.hash_sequence + 1;
  const receiptNumber = generateReceiptNumber(terminalState.location_code, terminalState.terminal_code, newSequence);

  // 4. Compute fiscal hash with real VAT breakdown
  const postedAt = new Date().toISOString();
  const vatBreakdown = computeVatBreakdown(input.cartItems, decimals);
  const fiscalHash = await computeFiscalHash({
    previousHash: terminalState.last_hash,
    receiptNumber,
    postedAt,
    total: total.toFixed(decimals),
    currency: input.currency,
    vatBreakdown,
    payments: input.payments.map((p) => ({ methodCode: p.methodCode, amount: p.amount })),
  });

  // 5. Store offline receipt
  const receiptId = crypto.randomUUID();
  const idempotencyKey = crypto.randomUUID();
  const changeDue = Math.max(0, input.tenderedAmount - total);

  const paymentsJson = JSON.stringify(
    input.payments.map((p) => ({
      payment_method_id: p.paymentMethodId ?? input.paymentMethodId,
      repository_id: p.repositoryId ?? input.paymentRepositoryId,
      amount: p.amount,
      card_last_four: p.cardLastFour ?? null,
      transaction_reference: p.transactionReference ?? null,
    }))
  );

  const offlineReceipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'> = {
    id: receiptId,
    idempotency_key: idempotencyKey,
    receipt_number: receiptNumber,
    terminal_id: input.terminalId,
    terminal_code: terminalState.terminal_code,
    operator_id: input.operatorId,
    operator_name: input.operatorName,
    lines: JSON.stringify(
      input.cartItems.map((item) => ({
        product_id: item.product.sellableType === 'composite_item' ? undefined : item.product.id,
        composite_item_id: item.product.sellableType === 'composite_item' ? item.product.id : undefined,
        name: item.product.name,
        sku: item.product.sku,
        quantity: item.quantity,
        unit_price: item.unit_price,
        line_total: item.line_total,
        tax_rate: item.tax_rate,
        tax_amount: item.tax_amount,
        discount_type: item.discount_type ?? null,
        discount_percent: item.discount_percent ?? null,
        discount_amount: item.discount_amount ?? null,
        discount_reason: item.discount_reason ?? null,
        modifiers: item.product.selectedModifiers ?? [],
      }))
    ),
    subtotal: subtotal.toFixed(decimals),
    tax_amount: taxAmount.toFixed(decimals),
    discount_amount: transactionDiscountAmount.toFixed(decimals),
    total: total.toFixed(decimals),
    currency: input.currency,
    fiscal_hash: fiscalHash,
    previous_hash: terminalState.last_hash,
    hash_sequence: newSequence,
    transaction_discount_amount: input.transactionDiscount
      ? transactionDiscountAmount.toFixed(decimals)
      : null,
    transaction_discount_reason: input.transactionDiscount?.reason ?? null,
    tendered_amount: input.tenderedAmount.toFixed(decimals),
    change_due: changeDue.toFixed(decimals),
    payment_method_id: input.paymentMethodId,
    payment_repository_id: input.paymentRepositoryId,
    status: 'pending',
    payments_json: paymentsJson,
    consumption_mode: input.consumptionMode ?? null,
    table_id: input.tableId ?? null,
  };

  // 5b. Wrap receipt insert + hash chain advance in a transaction
  //     to prevent inconsistent state if either operation fails
  await db.execute('BEGIN TRANSACTION');
  try {
    await insertOfflineReceipt(db, offlineReceipt);
    await advanceHashChain(db, input.terminalId, fiscalHash, newSequence);
    await db.execute('COMMIT');
  } catch (error) {
    await db.execute('ROLLBACK');
    throw error;
  }

  return {
    receiptNumber,
    total: total.toFixed(decimals),
    subtotal: subtotal.toFixed(decimals),
    taxAmount: taxAmount.toFixed(decimals),
    discountAmount: transactionDiscountAmount.toFixed(decimals),
    changeDue,
    fiscalHash,
    idempotencyKey,
    localId: receiptId,
  };
}
