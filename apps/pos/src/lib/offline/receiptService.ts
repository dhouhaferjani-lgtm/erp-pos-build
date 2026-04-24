import type Database from '@tauri-apps/plugin-sql';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcsub, bcmul, bcdiv, bcformat, bccomp } from '@/lib/decimal';
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
  subtotal: string;
  taxAmount: string;
} {
  let subtotal = '0';
  let taxAmount = '0';

  for (const item of cartItems) {
    subtotal = bcadd(subtotal, item.line_total);
    taxAmount = bcadd(taxAmount, item.tax_amount);
  }

  return { subtotal, taxAmount };
}

function computeVatBreakdown(cartItems: CartItem[], decimals: number): VatBreakdownEntry[] {
  const byRate = new Map<string, string>();

  for (const item of cartItems) {
    const rate = item.tax_rate;
    if (bccomp(item.tax_amount, '0') === 0) continue;
    byRate.set(rate, bcadd(byRate.get(rate) ?? '0', item.tax_amount));
  }

  const entries: VatBreakdownEntry[] = [];
  for (const [rate, total] of byRate) {
    entries.push({ rate, amount: bcformat(total, decimals) });
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
  let transactionDiscountAmount = '0';
  if (input.transactionDiscount) {
    if (input.transactionDiscount.type === 'percentage') {
      // percentage is not a monetary value — safe to use for rate arithmetic
      transactionDiscountAmount = bcdiv(
        bcmul(subtotal, input.transactionDiscount.value),
        '100',
      );
    } else {
      // fixed-amount currency discount — keep as string, no parseFloat
      transactionDiscountAmount = input.transactionDiscount.value;
    }
  }
  const rawTotal = bcsub(subtotal, transactionDiscountAmount);
  const total = bccomp(rawTotal, '0') >= 0 ? rawTotal : '0';

  // 3. Generate receipt number
  const newSequence = terminalState.hash_sequence + 1;
  const receiptNumber = generateReceiptNumber(terminalState.location_code, terminalState.terminal_code, newSequence);

  // 4. Compute fiscal hash with real VAT breakdown
  const postedAt = new Date().toISOString();
  const vatBreakdown = computeVatBreakdown(input.cartItems, decimals);
  const totalFormatted = bcformat(total, decimals);
  const fiscalHash = await computeFiscalHash({
    previousHash: terminalState.last_hash,
    receiptNumber,
    postedAt,
    total: totalFormatted,
    currency: input.currency,
    vatBreakdown,
    payments: input.payments.map((p) => ({ methodCode: p.methodCode, amount: p.amount })),
  });

  // 5. Store offline receipt
  const receiptId = crypto.randomUUID();
  const idempotencyKey = crypto.randomUUID();
  // tenderedAmount is a number (UI input), subtracted from string total via bcformat round-trip
  const changeDueRaw = bcsub(String(input.tenderedAmount), total);
  const changeDueFormatted = bccomp(changeDueRaw, '0') >= 0 ? bcformat(changeDueRaw, decimals) : bcformat('0', decimals);

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
    subtotal: bcformat(subtotal, decimals),
    tax_amount: bcformat(taxAmount, decimals),
    discount_amount: bcformat(transactionDiscountAmount, decimals),
    total: totalFormatted,
    currency: input.currency,
    fiscal_hash: fiscalHash,
    previous_hash: terminalState.last_hash,
    hash_sequence: newSequence,
    transaction_discount_amount: input.transactionDiscount
      ? bcformat(transactionDiscountAmount, decimals)
      : null,
    transaction_discount_reason: input.transactionDiscount?.reason ?? null,
    tendered_amount: bcformat(String(input.tenderedAmount), decimals),
    change_due: changeDueFormatted,
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
    total: totalFormatted,
    subtotal: bcformat(subtotal, decimals),
    taxAmount: bcformat(taxAmount, decimals),
    discountAmount: bcformat(transactionDiscountAmount, decimals),
    changeDue: parseFloat(changeDueFormatted),
    fiscalHash,
    idempotencyKey,
    localId: receiptId,
  };
}
