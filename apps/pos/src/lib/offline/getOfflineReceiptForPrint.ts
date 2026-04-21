import { getDatabase } from '@/lib/db';
import { getReceiptByIdempotencyKey } from '@/lib/db/repositories/offlineReceiptRepository';
import { useAuthStore } from '@/stores/authStore';
import { usePaymentStore } from '@/stores/paymentStore';
import type { FullReceiptResponse } from '@/types/receipt';

interface OfflineReceiptLine {
  product_id?: string;
  composite_item_id?: string;
  name: string;
  sku: string;
  quantity: number;
  unit_price: string;
  line_total: string;
  tax_rate: string;
  tax_amount: string;
  discount_amount?: string | null;
  modifiers?: Array<{ name: string; price: string }>;
}

interface OfflinePaymentEntry {
  payment_method_id: string;
  repository_id: string;
  amount: string;
  card_last_four?: string | null;
  transaction_reference?: string | null;
}

/**
 * Build a FullReceiptResponse-shaped payload from SQLite for pre-sync print.
 *
 * Used by HomePage's print-on-success flow when the receipt has not yet reached
 * the server (server_receipt_id IS NULL). Assembles company/terminal context
 * from authStore + the offline row itself.
 */
export async function getOfflineReceiptForPrint(
  idempotencyKey: string,
): Promise<FullReceiptResponse> {
  const companyId = useAuthStore.getState().companyId;
  if (!companyId) {
    throw new Error('No company selected');
  }
  const db = await getDatabase(companyId);
  const receipt = await getReceiptByIdempotencyKey(db, idempotencyKey);
  if (!receipt) {
    throw new Error(`Offline receipt not found for key ${idempotencyKey}`);
  }

  const company = useAuthStore.getState().companies.find((c) => c.id === companyId);

  const lines = (JSON.parse(receipt.lines) as OfflineReceiptLine[]).map((l, idx) => ({
    id: `local-line-${String(idx)}`,
    line_number: idx + 1,
    product_code: l.sku,
    product_name: l.name,
    quantity: String(l.quantity),
    unit_price: l.unit_price,
    line_total: l.line_total,
    tax_rate: l.tax_rate,
    tax_amount: l.tax_amount,
    discount_amount: l.discount_amount ?? '0.00',
    modifiers: l.modifiers ?? null,
  }));

  const methods = usePaymentStore.getState().paymentMethods;
  const payments = (JSON.parse(receipt.payments_json) as OfflinePaymentEntry[]).map((p, idx) => {
    const method = methods.find((m) => m.id === p.payment_method_id);
    return {
      id: `local-pay-${String(idx)}`,
      payment_method_id: p.payment_method_id,
      payment_type: method?.code ?? 'unknown',
      amount: p.amount,
      payment_method: {
        id: p.payment_method_id,
        name: method?.name ?? p.payment_method_id,
        code: method?.code ?? 'unknown',
      },
    };
  });

  return {
    id: receipt.id,
    receipt_number: receipt.receipt_number,
    receipt_type: 'sale',
    posted_at: receipt.created_at,
    cashier_name: receipt.operator_name,
    subtotal: receipt.subtotal,
    tax_amount: receipt.tax_amount,
    discount_amount: receipt.discount_amount,
    total: receipt.total,
    currency: receipt.currency,
    fiscal_hash: receipt.fiscal_hash,
    customer_name: null,
    notes: null,
    company: {
      name: company?.name ?? '',
      address_street: null,
      address_street_2: null,
      address_city: null,
      address_postal_code: null,
      country_code: company?.countryCode ?? 'FR',
      tax_id: null,
      phone: null,
    },
    terminal: {
      id: receipt.terminal_id,
      name: receipt.terminal_code,
      code: receipt.terminal_code,
    },
    lines,
    vat_details: [],
    payments,
  };
}
