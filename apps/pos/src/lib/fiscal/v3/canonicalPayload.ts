/**
 * Builds the v3 canonical-JSON payload for a receipt hash.
 *
 * Top-level keys (lexicographic): audit_hash, currency, exchange_group_id,
 * payment_methods_hash, posted_at, previous_hash, receipt_number,
 * schema_version (3), total, vat_breakdown_hash, voucher_ledger_hash.
 *
 * **Decimal formatting contract**: all monetary fields in the input
 * (`total`, every `payments[].amount`, every
 * `voucher_ledger_entries[].amount`, every `vat_breakdown[].amount`,
 * `vat_breakdown[].rate`) MUST be pre-formatted decimal strings at the
 * appropriate scale (currency_scale for tender amounts, currency_scale + 2
 * for voucher ledger internal precision per spec §5.5). The builder treats
 * them as opaque strings; it does NOT round, reformat, or validate scale.
 */

import { canonicalJson, canonicalJsonList } from './canonicalJson';

export interface V3CanonicalInput {
  receipt_number: string;
  posted_at: string;
  previous_hash: string | null;
  total: string;
  currency: string;
  vat_breakdown: Array<{ rate: string; amount: string }>;
  payments: Array<{
    method_code: string;
    payment_type: string;
    amount: string;
    instrument_type: string | null;
    instrument_serial: string | null;
  }>;
  voucher_ledger_entries: Array<{
    voucher_id: string;
    voucher_code: string;
    event: string;
    amount: string;
    gl_journal_entry_id: string | null;
  }>;
  exchange_group_id: string | null;
  audit: {
    authorized_by_user_id: string | null;
    override_reason: string | null;
    policy_trigger: string | null;
    out_of_window: boolean | null;
    refund_request_id: string | null;
  } | null;
}

async function sha256Hex(input: string): Promise<string> {
  const enc = new TextEncoder().encode(input);
  const buf = await crypto.subtle.digest('SHA-256', enc);
  return Array.from(new Uint8Array(buf))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

export async function buildCanonicalPayload(input: V3CanonicalInput): Promise<string> {
  // Sort payments by [method_code, instrument_type ?? '', instrument_serial ?? '', amount]
  const payments = [...input.payments].sort((a, b) => {
    const aKey = [a.method_code, a.instrument_type ?? '', a.instrument_serial ?? '', a.amount];
    const bKey = [b.method_code, b.instrument_type ?? '', b.instrument_serial ?? '', b.amount];
    for (let i = 0; i < aKey.length; i++) {
      const ak = aKey[i] as string;
      const bk = bKey[i] as string;
      if (ak < bk) return -1;
      if (ak > bk) return 1;
    }
    return 0;
  });

  // Sort vat_breakdown by rate (string compare ascending)
  const vat = [...input.vat_breakdown].sort((a, b) =>
    a.rate < b.rate ? -1 : a.rate > b.rate ? 1 : 0,
  );

  // Sort voucher_ledger_entries by voucher_code (string compare ascending)
  const voucher = [...input.voucher_ledger_entries].sort((a, b) =>
    a.voucher_code < b.voucher_code ? -1 : a.voucher_code > b.voucher_code ? 1 : 0,
  );

  // Compute sub-hashes
  // null audit becomes {} (empty object) which hashes to the SHA-256 sentinel
  const auditSubject: Record<string, unknown> = (input.audit as Record<string, unknown>) ?? {};
  const auditHash = await sha256Hex(canonicalJson(auditSubject));
  const paymentMethodsHash = await sha256Hex(
    canonicalJsonList(payments as unknown[]),
  );
  const vatBreakdownHash = await sha256Hex(canonicalJsonList(vat as unknown[]));
  const voucherLedgerHash = await sha256Hex(canonicalJsonList(voucher as unknown[]));

  // Compose top-level object — canonicalJson will sort keys lexicographically
  const payload: Record<string, unknown> = {
    audit_hash: auditHash,
    currency: input.currency.toUpperCase(),
    exchange_group_id: input.exchange_group_id,
    payment_methods_hash: paymentMethodsHash,
    posted_at: input.posted_at,
    previous_hash: input.previous_hash,
    receipt_number: input.receipt_number,
    schema_version: 3,
    total: input.total,
    vat_breakdown_hash: vatBreakdownHash,
    voucher_ledger_hash: voucherLedgerHash,
  };

  return canonicalJson(payload);
}
