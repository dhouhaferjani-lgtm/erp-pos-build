import type Database from '@tauri-apps/plugin-sql';
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
  paymentMethodId: string;
  paymentRepositoryId: string;
  tenderedAmount: number;
  transactionDiscount?: { amount: string; reason?: string };
}

interface OfflineReceiptResult {
  receiptNumber: string;
  total: string;
  subtotal: string;
  taxAmount: string;
  discountAmount: string;
  changeDue: number;
  fiscalHash: string;
}

function computeLineTotals(cartItems: CartItem[]): {
  subtotal: number;
  taxAmount: number;
} {
  let subtotal = 0;
  let taxAmount = 0;

  for (const item of cartItems) {
    subtotal += parseFloat(item.line_total);
    taxAmount += parseFloat(item.tax_amount ?? '0');
  }

  return { subtotal, taxAmount };
}

function generateReceiptNumber(
  terminalCode: string,
  sequence: number,
): string {
  const year = new Date().getFullYear();
  const paddedSeq = String(sequence).padStart(8, '0');
  return `${terminalCode}-${year}-${paddedSeq}`;
}

export async function createOfflineReceipt(
  db: Database,
  input: OfflineReceiptInput,
): Promise<OfflineReceiptResult> {
  // 1. Read terminal state
  const terminalState = await getTerminalState(db, input.terminalId);
  if (!terminalState) {
    throw new Error('Terminal hash chain not initialized. Cannot create offline receipt.');
  }

  // 2. Compute line totals
  const { subtotal, taxAmount } = computeLineTotals(input.cartItems);
  const transactionDiscountAmount = input.transactionDiscount
    ? parseFloat(input.transactionDiscount.amount)
    : 0;
  const total = Math.max(0, subtotal + taxAmount - transactionDiscountAmount);

  // 3. Generate receipt number
  const newSequence = terminalState.hash_sequence + 1;
  const receiptNumber = generateReceiptNumber(terminalState.terminal_code, newSequence);

  // 4. Compute fiscal hash
  const postedAt = new Date().toISOString();
  const fiscalHash = await computeFiscalHash({
    previousHash: terminalState.last_hash,
    receiptNumber,
    postedAt,
    total: total.toFixed(2),
    currency: input.currency,
    vatBreakdown: [], // TODO: compute VAT breakdown from cart items when tax rates are populated
    payments: [{ methodCode: 'CASH', amount: total.toFixed(2) }],
  });

  // 5. Store offline receipt
  const receiptId = crypto.randomUUID();
  const idempotencyKey = crypto.randomUUID();
  const changeDue = Math.max(0, input.tenderedAmount - total);

  const offlineReceipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error'> = {
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
        tax_amount: item.tax_amount ?? '0',
        discount_type: item.discount_type ?? null,
        discount_percent: item.discount_percent ?? null,
        discount_amount: item.discount_amount ?? null,
        discount_reason: item.discount_reason ?? null,
        modifiers: item.product.selectedModifiers ?? [],
      }))
    ),
    subtotal: subtotal.toFixed(2),
    tax_amount: taxAmount.toFixed(2),
    discount_amount: transactionDiscountAmount.toFixed(2),
    total: total.toFixed(2),
    currency: input.currency,
    fiscal_hash: fiscalHash,
    previous_hash: terminalState.last_hash,
    hash_sequence: newSequence,
    transaction_discount_amount: input.transactionDiscount?.amount ?? null,
    transaction_discount_reason: input.transactionDiscount?.reason ?? null,
    tendered_amount: input.tenderedAmount.toFixed(2),
    change_due: changeDue.toFixed(2),
    payment_method_id: input.paymentMethodId,
    payment_repository_id: input.paymentRepositoryId,
    status: 'pending',
  };

  await insertOfflineReceipt(db, offlineReceipt);

  // 6. Advance hash chain in SQLite
  await advanceHashChain(db, input.terminalId, fiscalHash, newSequence);

  return {
    receiptNumber,
    total: total.toFixed(2),
    subtotal: subtotal.toFixed(2),
    taxAmount: taxAmount.toFixed(2),
    discountAmount: transactionDiscountAmount.toFixed(2),
    changeDue,
    fiscalHash,
  };
}
