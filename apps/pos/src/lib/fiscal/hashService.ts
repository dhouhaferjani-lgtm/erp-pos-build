/**
 * Client-side fiscal hash service — port of FiscalHashService.php
 * Uses Web Crypto API (crypto.subtle) for SHA-256 hashing.
 */

export interface FiscalHashInput {
  previousHash: string;
  receiptNumber: string;
  postedAt: string; // ISO 8601
  total: string;
  currency: string;
  vatBreakdown: Array<{ rate: string; amount: string }>;
  payments: Array<{ methodCode: string; amount: string }>;
}

export async function sha256(input: string): Promise<string> {
  const encoder = new TextEncoder();
  const data = encoder.encode(input);
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

function buildVatHash(vatBreakdown: FiscalHashInput['vatBreakdown']): string {
  if (vatBreakdown.length === 0) return 'NO_VAT';
  return vatBreakdown
    .map((v) => `${v.rate}:${v.amount}`)
    .sort()
    .join('|');
}

function buildPaymentHash(payments: FiscalHashInput['payments']): string {
  if (payments.length === 0) return 'NO_PAYMENT';
  return payments
    .map((p) => `${p.methodCode}:${p.amount}`)
    .sort()
    .join('|');
}

export async function computeFiscalHash(input: FiscalHashInput): Promise<string> {
  const vatHash = buildVatHash(input.vatBreakdown);
  const paymentHash = buildPaymentHash(input.payments);

  const hashInput = [
    input.previousHash,
    input.receiptNumber,
    input.postedAt,
    input.total,
    input.currency,
    vatHash,
    paymentHash,
  ].join('|');

  return sha256(hashInput);
}

export async function computeGenesisHash(genesisSeed: string): Promise<string> {
  return sha256(`GENESIS|${genesisSeed}`);
}
