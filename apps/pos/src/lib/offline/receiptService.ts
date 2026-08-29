import type Database from '@tauri-apps/plugin-sql';
import i18n from '@/lib/i18n';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcsub, bcformat, bccomp } from '@/lib/decimal';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import type { FiscalEventAppendResult } from '@/lib/fiscal/FiscalEventEngine';
import type {
  BuyerBlockInput,
  SaleReceiptApprovalReferenceInput,
} from '@/lib/fiscal/FiscalEventEngine';
import {
  type SaleReceiptSellerInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { buildSaleReceiptV5Payload } from '@/lib/fiscal/payloads/SaleReceiptV5Payload';
import { computeExactCartTotal, computeExactDiscountAmount } from '@/lib/payment/cartTotals';
import type { CartTransactionDiscount } from '@/stores/cartStore';
import type { PosOverrideEvidence } from '@/lib/operatorApproval/posOverrideAuthoring';
import { getTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import {
  insertOfflineReceipt,
  scheduleDebouncedSync,
  getReceiptByIdempotencyKey,
  type OfflineReceipt,
} from '@/lib/db/repositories/offlineReceiptRepository';
import {
  findByCode as findVoucherByCode,
  updateVoucherBalanceAndStatus,
  type LocalVoucher,
  type VoucherStatus,
} from '@/lib/offline/voucherRepository';
import type { CartItem } from '@/types/cart';
import type { CheckoutPolicySnapshot } from '@/lib/payment/checkoutPolicySnapshot';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { useSyncStore } from '@/stores/syncStore';
import { withWriteTransaction } from '@/lib/db/writeGate';
import { getCustomerAlias } from '@/lib/db/repositories/pendingCustomerRepository';

interface SaleReceiptCustomerInput {
  id: string;
  tenant_id: string;
  company_id: string;
  name: string;
  tax_number: string | null;
  customer_sync_status: 'synced' | 'pending_create';
}

interface OfflineReceiptInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  operatorId: string;
  operatorName: string;
  shiftId: string;
  cartItems: CartItem[];
  currency: string;
  seller: SaleReceiptSellerInput;
  /** Customer selected at checkout; resolved into the existing sealed buyer block. */
  customer?: SaleReceiptCustomerInput | null;
  /** Primary payment method (first entry in `payments`) — used for the denormalized column on offline_receipts */
  paymentMethodId: string;
  /** Primary payment repository (first entry in `payments`) */
  paymentRepositoryId: string;
  tenderedAmount: string;
  /**
   * T0.2: Caller-allocated idempotency key for this cart submission attempt.
   * Optional for backward compatibility; if omitted, a fresh `crypto.randomUUID()`
   * is allocated internally (legacy behavior). Production callers in
   * paymentStore allocate the key once per submission attempt and pass it on
   * every retry, so a cashier double-click writes two SQLite rows that share
   * the same key — the server-side dedup-on-disk catches the second POST as
   * a duplicate and returns the existing receipt instead of creating a new one.
   */
  idempotencyKey?: string;
  transactionDiscount?: CartTransactionDiscount;
  tenderToleranceEvidence?: PosOverrideEvidence;
  /** Payments breakdown for fiscal hash + sync payload. Required. For single-payment flows, pass one entry. */
  payments: Array<{
    methodCode: string;
    amount: string;
    paymentMethodId?: string;
    repositoryId?: string;
    cardLastFour?: string;
    transactionReference?: string;
    /**
     * v3-only: payment instrument kind for voucher-bearing tenders. One of
     * 'store_voucher' | 'restaurant_voucher' | 'gift_card', or null/undefined
     * for non-instrument tenders (cash, card). Bound into the v3 fiscal hash
     * so a sealed receipt cannot be reattributed to a different instrument.
     * Codex review B2 (server) + B1 (client).
     */
    instrumentType?: 'store_voucher' | 'restaurant_voucher' | 'gift_card' | null;
    /** v3-only: actual voucher serial / gift-card code that tendered this row. */
    instrumentSerial?: string | null;
  }>;
  /** F&B: 'SUR_PLACE' | 'A_EMPORTER' — omit for retail */
  consumptionMode?: string;
  /** F&B: table UUID — omit for retail or takeout */
  tableId?: string;
  /**
   * T2.7 — set true when the active terminal is in training mode (caller
   * reads `useTerminalStore.getState().terminal?.is_training_mode`). Training
   * receipts skip the local fiscal-hash chain advance, use a TRN- prefixed
   * receipt number with a UUID-derived suffix (no shared sequence with
   * production), and persist `is_training=1` on the SQLite row. The server's
   * offline-sync path (PR #103) honors the wire flag and skips chain
   * validation, year roll-over, finalize, and hash mismatch checks.
   * Default false preserves the production path byte-for-byte.
   */
  isTraining?: boolean;
  /**
   * The sealed checkout decision (spec §4.3) taken at tender time by
   * `buildCheckoutPolicySnapshot`: the rounded due, the signed rounding
   * adjustment, the denomination and the tolerance outcome.
   *
   * REQUIRED. What signs is THIS snapshot, so a policy tick between the gate
   * and the signature can never move the total. An absent snapshot must be a
   * compile error, never a silently unrounded receipt — which is why the field
   * is not optional and there is no default.
   */
  policySnapshot: CheckoutPolicySnapshot;
}

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * Resolve checkout identity before fiscal authoring.
 *
 * A mirrored row already carries the server partner UUID. An optimistic row
 * may only use the canonical, tenant/company-scoped customer_aliases mapping;
 * its device-minted UUID is never a partner FK and therefore never enters the
 * sealed buyer.customer_id field.
 */
async function resolveSaleReceiptBuyer(
  db: Database,
  tenantId: string,
  companyId: string,
  customer: SaleReceiptCustomerInput | null | undefined,
): Promise<BuyerBlockInput | null> {
  if (customer == null) {
    return null;
  }
  if (customer.tenant_id !== tenantId || customer.company_id !== companyId) {
    throw new Error('Selected customer does not belong to the active tenant and company.');
  }

  let serverPartnerId: string | null = null;
  if (customer.customer_sync_status === 'synced') {
    serverPartnerId = UUID_PATTERN.test(customer.id) ? customer.id : null;
  } else {
    const alias = await getCustomerAlias(db, tenantId, companyId, customer.id);
    serverPartnerId = alias !== null && UUID_PATTERN.test(alias.server_partner_id)
      ? alias.server_partner_id
      : null;
  }

  const resolved = serverPartnerId !== null;
  return {
    address: null,
    codice_fiscale: null,
    // Phase 5 decision: the device has no contact mirror, so contact identity
    // remains deliberately absent from the sealed receipt snapshot.
    contact_id: null,
    customer_id: serverPartnerId,
    name: customer.name,
    tax_number: resolved ? customer.tax_number : null,
  };
}

/**
 * The cart re-totalled at authoring time does not match the total the checkout
 * gate sealed. Defense-in-depth (spec §4.3 r2 F4): Task 5 made ONE function the
 * only cart-total math on both sides, so this is unreachable by construction —
 * this is the belt.
 *
 * The MESSAGE is already translated, because `paymentStore.formatCheckoutError`
 * puts `error.message` straight on the cashier's banner. The two totals that
 * caused the refusal are carried as FIELDS (and logged at the throw site) so the
 * diagnostic survives without leaking into the UI.
 *
 * A named class so callers and tests can assert the IDENTITY of the refusal.
 * Nothing is signed and nothing is persisted when it throws: the checkout is
 * blocked and the cashier retries.
 */
export class CartTotalIntegrityError extends Error {
  constructor(readonly lineDerivedTotal: string, readonly snapshotExactTotal: string) {
    super(i18n.t('payment.totalIntegrityError', { ns: 'pos' }));
    this.name = 'CartTotalIntegrityError';
  }
}

/** One sealed `vat_breakdown[]` row, surfaced for the printed ticket. */
export interface OfflineReceiptVatGroup {
  /** Percent at `vat_rate_scale` (e.g. `'19.00'`). */
  readonly rate: string;
  /** Taxable base AFTER the ticket-level remise. */
  readonly netAmount: string;
  /** VAT on the post-remise base. */
  readonly vatAmount: string;
  /** This group's pro-rata share of the remise. */
  readonly discountAllocated: string;
}

export interface OfflineReceiptResult {
  receiptNumber: string;
  total: string;
  /** Gross (TTC) roll-up of the cart lines, BEFORE the ticket-level remise. */
  subtotal: string;
  /**
   * The SEALED `vat_total` — VAT on the POST-remise base (D-1, v5). Never the
   * pre-discount line roll-up: the printed ticket and the local mirror must
   * quote the same VAT the fiscal event declares.
   */
  taxAmount: string;
  discountAmount: string;
  /**
   * The sealed per-rate breakdown, POST-remise, in canonical order. Printed
   * verbatim so the ticket can never quote a base the chain does not carry.
   */
  vatBreakdown: readonly OfflineReceiptVatGroup[];
  /** Currency-scale decimal string — money never crosses a float boundary. */
  changeDue: string;
  fiscalHash: string;
  idempotencyKey: string;
  localId: string;
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

/**
 * Recover the sealed per-rate breakdown from a receipt's canonical bytes.
 *
 * The idempotency-replay path (cashier double-click) returns a receipt that was
 * authored on an earlier call, so the in-memory breakdown is gone — but the
 * signed bytes are on the row. Parsing them is exact and needs no schema of its
 * own. Fail-soft: a v1..v3 row (no `discount_allocated`) or an unparseable blob
 * yields an empty list and the ticket falls back to its non-fiscal totals block
 * rather than refusing to reprint.
 */
function vatBreakdownFromCanonicalBytes(bytes: string | null | undefined): OfflineReceiptVatGroup[] {
  if (typeof bytes !== 'string' || bytes === '') return [];
  let parsed: unknown;
  try {
    parsed = JSON.parse(bytes);
  } catch {
    return [];
  }
  if (typeof parsed !== 'object' || parsed === null) return [];
  const rows = (parsed as Record<string, unknown>)['vat_breakdown'];
  if (!Array.isArray(rows)) return [];

  const out: OfflineReceiptVatGroup[] = [];
  for (const row of rows) {
    if (typeof row !== 'object' || row === null) continue;
    const r = row as Record<string, unknown>;
    const rate = r['rate'];
    const net = r['net_amount'];
    const vat = r['vat_amount'];
    const allocated = r['discount_allocated'];
    if (typeof rate !== 'string' || typeof net !== 'string' || typeof vat !== 'string') continue;
    out.push({
      rate,
      netAmount: net,
      vatAmount: vat,
      discountAllocated: typeof allocated === 'string' ? allocated : '0',
    });
  }

  return out;
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

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/**
 * Codex review B5 (2026-05-01): pull out the store_voucher tenders for the
 * receipt. Each entry retains the original payment shape for amount lookup;
 * the caller resolves the local voucher row separately. Restaurant vouchers
 * and gift cards do NOT enter the local voucher mirror in Phase 1 (spec
 * §3.2.1 + §6.5), so this function only matches on `store_voucher`.
 */
function collectStoreVoucherPayments(
  payments: OfflineReceiptInput['payments'],
): Array<{ serial: string; amount: string }> {
  const out: Array<{ serial: string; amount: string }> = [];
  for (const p of payments) {
    if (p.instrumentType === 'store_voucher' && typeof p.instrumentSerial === 'string' && p.instrumentSerial !== '') {
      out.push({ serial: p.instrumentSerial, amount: p.amount });
    }
  }
  return out;
}

function approvalReferenceFromEvidence(
  evidence: PosOverrideEvidence,
): SaleReceiptApprovalReferenceInput {
  return {
    approval_event_id: evidence.approval_event_id,
    approval_id: evidence.approval_id,
    approval_scope: evidence.approval_scope,
    override_event_id: evidence.override_event_id,
    policy_version: evidence.policy_version,
    supervisor_user_id: evidence.supervisor_user_id,
    target_reference_id: evidence.target_reference_id,
  };
}

function collectApprovalReferences(input: OfflineReceiptInput): SaleReceiptApprovalReferenceInput[] {
  const references: SaleReceiptApprovalReferenceInput[] = [];
  const seen = new Set<string>();

  const add = (evidence: PosOverrideEvidence | undefined): void => {
    if (evidence === undefined || seen.has(evidence.override_event_id)) return;
    seen.add(evidence.override_event_id);
    references.push(approvalReferenceFromEvidence(evidence));
  };

  add(input.transactionDiscount?.approvalEvidence);
  for (const item of input.cartItems) {
    add(item.discount_approval_evidence);
  }
  add(input.tenderToleranceEvidence);

  return references;
}

/**
 * Codex review B5 (2026-05-01): resolve every store_voucher tender against
 * the local voucher projection. Throws on the first miss so the caller can
 * fail the whole receipt creation BEFORE entering the transaction — a
 * receipt referencing an unknown voucher cannot be redeemed server-side
 * either, so refusing here gives the cashier a clear "voucher not found"
 * message rather than letting the receipt seal and silently fail to push.
 *
 * The returned array is index-aligned with the input — caller iterates in
 * order to pair each tender with its resolved voucher.
 */
async function resolveLocalVouchers(
  db: Database,
  tenders: ReadonlyArray<{ serial: string; amount: string }>,
): Promise<LocalVoucher[]> {
  const out: LocalVoucher[] = [];
  for (const tender of tenders) {
    const voucher = await findVoucherByCode(db, tender.serial);
    if (voucher === null) {
      throw new Error(
        `Voucher tender '${tender.serial}' is not present in the local voucher mirror. `
          + `Cannot create receipt: the cashier applied a tender for a voucher this terminal has never seen. `
          + `Check that the voucher exists on the server and that this terminal has synced recently.`,
      );
    }
    out.push(voucher);
  }
  return out;
}

/**
 * T2.7 — offline-first training-aware receipt creator.
 *
 * Branches on `input.isTraining` (default false): training receipts emit a
 * fiscal-event-backed SALE_RECEIPT payload with `training_flag=true` and
 * persist `is_training=1` on the SQLite mirror row. The fiscal-event sync
 * path carries the canonical bytes to the server; no legacy receipt-sync
 * payload is built.
 *
 * Caller (paymentStore) reads `useTerminalStore.getState().terminal?.is_training_mode`
 * and forwards the flag here. Receipt-creation is a service layer; we keep
 * it free of store coupling to preserve testability.
 */
export async function createOfflineReceipt(
  db: Database,
  input: OfflineReceiptInput,
): Promise<OfflineReceiptResult> {
  // T0.2 (Codex review F-1): if a caller-provided idempotency key already has
  // a persisted row, return the existing receipt without re-running the
  // SQLite INSERT (which would throw SQLITE_CONSTRAINT_UNIQUE on
  // offline_receipts.idempotency_key), the hash-chain advance, or the
  // voucher-balance updates. This is the offline-side mirror of the server's
  // dedup-on-disk: a retry with the same key returns the prior result rather
  // than corrupting it.
  if (input.idempotencyKey) {
    const existing = await getReceiptByIdempotencyKey(db, input.idempotencyKey);
    if (existing) {
      return {
        receiptNumber: existing.receipt_number,
        total: existing.total,
        subtotal: existing.subtotal,
        taxAmount: existing.tax_amount,
        discountAmount: existing.discount_amount,
        vatBreakdown: vatBreakdownFromCanonicalBytes(existing.canonical_bytes),
        changeDue: existing.change_due ?? bcformat('0', getCurrencyDecimals(existing.currency)),
        fiscalHash: existing.fiscal_hash,
        idempotencyKey: existing.idempotency_key,
        localId: existing.id,
      };
    }
  }

  const decimals = getCurrencyDecimals(input.currency);

  // 1. Read terminal state
  const terminalState = await getTerminalState(db, input.terminalId);
  if (!terminalState) {
    throw new Error('Terminal hash chain not initialized. Cannot create offline receipt.');
  }

  // 2. Compute line totals
  const { subtotal, taxAmount } = computeLineTotals(input.cartItems);
  // Single-sourced (spec §4.3 r2 F4): the discount + total come from the SAME
  // function the checkout gate used, at the CURRENCY scale.
  const transactionDiscountAmount = computeExactDiscountAmount(
    input.cartItems,
    input.transactionDiscount,
    input.currency,
  );
  const total = computeExactCartTotal(
    input.cartItems,
    input.transactionDiscount,
    input.currency,
  );

  // Defense-in-depth (spec §4.3 r2 F4): Task 5 made this unreachable by making
  // one function the only cart-total math. If it EVER fires, nothing is signed
  // — we are still ahead of the engine append and the SQLite insert.
  if (bccomp(total, input.policySnapshot.exactTotal) !== 0) {
    console.error('[POS][offline][receipt] cart total disagrees with the sealed checkout snapshot — nothing signed', {
      lineDerivedTotal: total,
      snapshotExactTotal: input.policySnapshot.exactTotal,
      snapshotRoundedTotal: input.policySnapshot.roundedTotal,
      currency: input.currency,
      terminalId: input.terminalId,
      idempotencyKey: input.idempotencyKey ?? null,
    });
    throw new CartTotalIntegrityError(total, input.policySnapshot.exactTotal);
  }

  // ── 100 %-comp tickets: NO zero tender row (D-1, G3-A fold-in) ──────────
  // `pos_receipt_payments` carries `CHECK (amount > 0)`, so the 0.000 leg the
  // cash screen produces for a fully comped ticket made the whole receipt
  // unprojectable on PostgreSQL — the projection refused it upstream of the GL
  // bridge and the sale silently never landed. A comp tenders nothing: the
  // discount line carries the story, so the leg is dropped here, before the
  // canonical payload AND before payments_json (the sync wire), and the DB
  // CHECK stays exactly as it is. If this empties the list on a ticket whose
  // total is NOT zero, the payload validator refuses it (`payload_payments_empty`)
  // and nothing is signed.
  const tenderedPayments = input.payments.filter(
    (payment) => bccomp(bcformat(payment.amount, decimals), '0') > 0,
  );

  const isTraining = input.isTraining === true;
  if (!input.tenantId || !input.companyId || !input.shiftId || !input.seller) {
    throw new Error('tenantId, companyId, shiftId, and seller are required for fiscal-event receipt authoring.');
  }
  const receiptId = crypto.randomUUID();
  const postedAtDate = new Date();
  const postedAt = postedAtDate.toISOString();
  const businessDate = postedAt.slice(0, 10);
  // What the cashier owes, and what the receipt says: the ROUNDED total from
  // the sealed snapshot. Equal to the exact total whenever the rounding gate is
  // closed, so an un-rounded fleet keeps today's bytes exactly.
  const exactTotalFormatted = bcformat(input.policySnapshot.exactTotal, decimals);
  const totalFormatted = bcformat(input.policySnapshot.roundedTotal, decimals);
  const approvalReferences = collectApprovalReferences(input);
  const buyer = await resolveSaleReceiptBuyer(
    db,
    input.tenantId,
    input.companyId,
    input.customer,
  );
  // SaleReceiptV3 (event_version=3): `total` is the ROUNDED total, with the
  // signed `cash_rounding_adjustment` + `cash_rounding_denomination` alongside,
  // so the receipt is verifiable against its OWN authoring policy forever.
  // The V2 delegate underneath is handed the EXACT total (that is what its
  // aggregate assert reconciles against subtotal/vat/discount); the builder
  // then swaps in the rounded total and runs the v3 binds. Since M4 the signed
  // canonical line items also carry the variant identity, so the sealed record
  // matches the printed ticket.
  const canonicalPayload = buildSaleReceiptV5Payload(
    {
      receiptId,
      terminalId: input.terminalId,
      operatorId: input.operatorId,
      operatorName: input.operatorName,
      shiftId: input.shiftId,
      currency: input.currency,
      eventTimeDevice: postedAtDate,
      businessDate,
      cartItems: input.cartItems,
      subtotalGross: subtotal,
      taxAmount,
      total: exactTotalFormatted,
      transactionDiscountAmount,
      transactionDiscountReason: input.transactionDiscount?.reason ?? null,
      payments: tenderedPayments.map((payment) => ({
        methodCode: payment.methodCode,
        amount: payment.amount,
        instrumentType: payment.instrumentType ?? null,
        instrumentSerial: payment.instrumentSerial ?? null,
      })),
      consumptionMode: input.consumptionMode ?? null,
      tableId: input.tableId ?? null,
      // NOT the snapshot's `invoiceType`: this is the payload builder's own
      // training discriminator (it drives `training_flag` and the TRAINING
      // invoice_type_code). The snapshot's invoiceType only decides whether
      // the sale is ROUNDABLE.
      isTraining,
      seller: input.seller,
      buyer,
      approvalReferences,
    },
    {
      exactTotal: exactTotalFormatted,
      roundedTotal: totalFormatted,
      // Handed over raw. The builder's `canonicalMoney` is the ONE place that
      // formats these and collapses big.js's '-0.000' onto the unsigned
      // canonical zero the server demands (`payload_money_negative_zero`);
      // formatting here first would only add a second, unpinned rounding step.
      adjustment: input.policySnapshot.adjustment,
      denomination: input.policySnapshot.denomination,
    },
  );
  // Read back off the CANONICAL payload, never re-derived: what the ticket
  // prints and what the local mirror stores are the sealed bytes themselves.
  const sealedVatTotal = canonicalPayload.vat_total;
  const sealedVatBreakdown: OfflineReceiptVatGroup[] = canonicalPayload.vat_breakdown.map(
    (group) => ({
      rate: group.rate,
      netAmount: group.net_amount,
      vatAmount: group.vat_amount,
      discountAllocated: group.discount_allocated,
    }),
  );

  const primaryApprovalReferenceEventId =
    approvalReferences[0]?.override_event_id ?? null;

  // 5. Store offline receipt
  // T0.2: prefer caller-provided key (paymentStore allocates once per cart
  // submission attempt and reuses on retry); fall back to a fresh UUID for
  // legacy callers (e.g. test fixtures) that don't supply one.
  const idempotencyKey = input.idempotencyKey ?? crypto.randomUUID();
  // tenderedAmount is a decimal string — subtract directly with bcmath, no
  // String() wrap needed. Netted against the ROUNDED due (`totalFormatted`),
  // never the exact total: change is what the cashier hands back against the
  // amount that was actually signed, and the cash screen already quotes the
  // rounded due. A tolerance write-off is money the store forgives, so a short
  // tender still yields zero change via the clamp below.
  const changeDueRaw = bcsub(input.tenderedAmount, totalFormatted, decimals);
  const changeDueFormatted = bccomp(changeDueRaw, '0') >= 0 ? bcformat(changeDueRaw, decimals) : bcformat('0', decimals);

  // Codex review B3 (2026-04-30): persist methodCode, instrumentType, and
  // instrumentSerial on every row in payments_json so the sync layer can
  // forward them to the server. The v3 fiscal hash is computed with these
  // fields included (lines 167-173 above) — if payments_json strips them,
  // the server will recompute the hash from null instrument fields and
  // reject the payload as a chain break.
  const paymentsJson = JSON.stringify(
    tenderedPayments.map((p) => ({
      payment_method_id: p.paymentMethodId ?? input.paymentMethodId,
      repository_id: p.repositoryId ?? input.paymentRepositoryId,
      amount: p.amount,
      card_last_four: p.cardLastFour ?? null,
      transaction_reference: p.transactionReference ?? null,
      method_code: p.methodCode,
      instrument_type: p.instrumentType ?? null,
      instrument_serial: p.instrumentSerial ?? null,
    }))
  );

  const fiscalSchemaVersion = 3 as const;

  // 5b. Codex review B5 (2026-05-01): pre-resolve every store_voucher payment
  //     against the local voucher mirror BEFORE entering the transaction. We
  //     need (a) the local voucher.id to update the projection row, and
  //     (b) the current_balance to compute the new balance with bcsub. The
  //     lookup is read-only and side-effect-free, so it is safe outside the
  //     transaction; the balance update inside the transaction uses these
  //     pre-resolved snapshots.
  //
  //     If a voucher is missing from the local mirror, fail loud — the cashier
  //     applied a tender for which we have no projection, which means the
  //     server cannot map the redemption to a real voucher either. This is
  //     the offline analogue of `VoucherRedemptionService` throwing
  //     `VoucherInvalidStatusException` for an unknown code.
  // T2.7 — for training receipts the server-side sync path does NOT call
  // VoucherRedemptionService::redeem (PR #103). If we still decremented the
  // local voucher balance here, the cashier's mirror would diverge from the
  // server's canonical balance until the next pullVoucherLedger overwrite
  // (which itself never sees the redemption because it never happened
  // server-side). Skipping voucher resolution + mutation entirely on
  // training keeps the local mirror authoritative.
  // Note: the voucher tender row is still preserved in payments_json on the
  // receipt, so the server sees the cashier's intent — it just doesn't
  // create a redemption ledger entry for it.
  const voucherTenders = isTraining
    ? []
    : collectStoreVoucherPayments(tenderedPayments);
  const resolvedVouchers = await resolveLocalVouchers(db, voucherTenders);

  // 5c. Wrap receipt insert + hash chain advance + voucher balance updates
  //     in a single transaction. Either the receipt + chain + balance all
  //     commit, or none do — this prevents the cashier from sealing a
  //     fiscal receipt that references a voucher whose local projection
  //     never moved.
  //
  //     Single-writer architecture (2026-06-12 design spec): the whole
  //     transaction runs as ONE exclusive write-gate job on the Rust-owned
  //     single connection — `fiscal` lane, so it preempts queued sync
  //     chunks. The pooled `db` handle above is reads-only; issuing
  //     BEGIN…COMMIT through the pool splits statements across physical
  //     connections (self-deadlock + transaction poisoning). The gate's
  //     BEGIN IMMEDIATE also serialises the chain read-modify-write.
  const engine = await getFiscalEventEngine(input.companyId, db);
  const txStartedAt = performance.now();
  // Mutable log context — populated inside the tx body so the catch can
  // report how far the transaction got (object-property mutation dodges
  // TS closure-narrowing on plain lets).
  const txLog: { sequence: number | null; previousHash: string | null; receiptNumber: string } = {
    sequence: null,
    previousHash: null,
    receiptNumber: '',
  };
  let fiscalEventResult: FiscalEventAppendResult;
  let receiptNumber: string;
  try {
    ({ fiscalEventResult, receiptNumber } = await withWriteTransaction('fiscal', async (tx) => {
      const appended = await engine.append(tx, {
        event_type: 'SALE_RECEIPT',
        tenant_id: input.tenantId,
        company_id: input.companyId,
        terminal_id: input.terminalId,
        operator_id: input.operatorId,
        event_time_device: isoSecondsUtc(postedAtDate),
        business_date: businessDate,
        payload: canonicalPayload,
        reference_event_id: primaryApprovalReferenceEventId ?? undefined,
        source_event_class: 'offline_receipts',
        source_event_id: receiptId,
      });
      txLog.sequence = appended.sequence_number;
      txLog.previousHash = appended.previous_hash;
      const number = generateReceiptNumber(
        terminalState.location_code,
        terminalState.terminal_code,
        appended.sequence_number,
      );
      txLog.receiptNumber = number;

      const offlineReceipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'> = {
        id: receiptId,
        idempotency_key: idempotencyKey,
        receipt_number: number,
        terminal_id: input.terminalId,
        terminal_code: terminalState.terminal_code,
        operator_id: input.operatorId,
        operator_name: input.operatorName,
        lines: JSON.stringify(
          input.cartItems.map((item) => ({
            product_id: item.product.sellableType === 'composite_item' ? undefined : item.product.id,
            composite_item_id: item.product.sellableType === 'composite_item' ? item.product.id : undefined,
            // T2 — variant identity persisted alongside the line so the stored
            // sale records exactly which variant was sold. Since M4
            // (SaleReceiptV2) the variant identity ALSO travels in the signed
            // canonical line items; this mirror stays for the local receipt
            // store + Wave-2 sync.
            variant_id: item.product.variant_id ?? undefined,
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
        // D-1: the SEALED vat_total (post-remise), not the pre-discount line
        // roll-up. `subtotal` stays the gross TTC roll-up, so the local mirror
        // still satisfies `total == subtotal − discount (+ rounding)` while
        // `tax_amount` is the VAT actually declared by the chain.
        tax_amount: sealedVatTotal,
        discount_amount: bcformat(transactionDiscountAmount, decimals),
        total: totalFormatted,
        currency: input.currency,
        fiscal_hash: appended.current_hash,
        previous_hash: appended.previous_hash,
        hash_sequence: appended.sequence_number,
        transaction_discount_amount: input.transactionDiscount
          ? bcformat(transactionDiscountAmount, decimals)
          : null,
        transaction_discount_reason: input.transactionDiscount?.reason ?? null,
        tendered_amount: bcformat(input.tenderedAmount, decimals),
        change_due: changeDueFormatted,
        payment_method_id: input.paymentMethodId,
        payment_repository_id: input.paymentRepositoryId,
        status: 'pending',
        payments_json: paymentsJson,
        consumption_mode: input.consumptionMode ?? null,
        table_id: input.tableId ?? null,
        // The v3 signed rounding, mirrored LOCALLY inside the same write-gate
        // transaction as the fiscal event — read from the snapshot, never
        // re-derived. The sync wire is the fiscal-event envelope, so the
        // canonical bytes already carry these values to the server; these
        // columns exist for device-side reporting (Z / EOD).
        // Null, not a canonical zero, on an unrounded receipt: "no rounding
        // happened here" and "rounding happened and came to zero" must stay
        // distinguishable in a local report.
        cash_rounding_adjustment: input.policySnapshot.roundingApplied
          ? bcformat(input.policySnapshot.adjustment, decimals)
          : null,
        cash_rounding_denomination: input.policySnapshot.roundingApplied
          ? bcformat(input.policySnapshot.denomination, decimals)
          : null,
        tolerance_shortfall: input.policySnapshot.toleranceDecision.applied
          ? bcformat(input.policySnapshot.toleranceDecision.shortfall, decimals)
          : null,
        fiscal_schema_version: fiscalSchemaVersion,
        is_training: isTraining ? 1 : 0,
        canonical_bytes: appended.canonical_bytes,
      };

      await insertOfflineReceipt(tx as unknown as Database, offlineReceipt);

      // B5-fix audit decision Option B (2026-05-01): the offline path NO LONGER
      // writes a local voucher_ledger Redeemed row. The canonical voucher_ledger
      // entry is server-authored from the fiscal-event projection path and is
      // tied to the synced receipt with a server-controlled UUID. The local
      // mirror picks up that row on the next pullVoucherLedger.
      //
      // Why we keep the local balance update:
      //   - The cashier sees the right voucher balance immediately on this
      //     terminal (e.g. for stacked redemptions in the same session) until
      //     sync reconciles the canonical state.
      //   - The atomicity guarantee is unchanged: a balance-update failure
      //     rolls back the receipt insert + chain advance.
      //
      // Why we removed the local voucher_ledger row:
      //   - The previous write produced a row with `receipt_id = null` because
      //     no server receipt id existed yet at offline-write time.
      //   - `VoucherLedgerPushService::push()` rejects every such row with
      //     `'receipt_id_required_for_redemption'`, and the audit found the
      //     push client silently dropped the failure (Minor 2 — fixed in the
      //     companion commit). Even with the silent-drop fixed, the server
      //     cannot ingest this row shape because there is no canonical
      //     receipt to bind the GL leg against.
      //   - Removing the local write removes the bug at the root: the
      //     server-side redemption during sync is now the single canonical
      //     entry point. The push pipeline (`pushVoucherLedgerEntries`)
      //     becomes reserved for future offline-issued voucher operations
      //     not tied to a synced receipt (e.g., goodwill issuance from a
      //     back-office screen), per the
      //     `VoucherLedgerPushService` docblock contract.
      for (let i = 0; i < voucherTenders.length; i++) {
        const tender = voucherTenders[i]!;
        const voucher = resolvedVouchers[i]!;
        const newBalance = bcsub(voucher.current_balance, tender.amount, decimals);
        // Defensive: never let local balance go negative. The B4 server-side
        // validator catches over-redemption on the wire, but a stale local
        // mirror could theoretically race; clamp to zero rather than persist
        // a negative balance that would corrupt subsequent local reads.
        const clampedBalance = bccomp(newBalance, '0') < 0
          ? bcformat('0', decimals)
          : bcformat(newBalance, decimals);
        const newStatus: VoucherStatus = bccomp(clampedBalance, '0') === 0
          ? 'FullyRedeemed'
          : 'PartiallyRedeemed';

        await updateVoucherBalanceAndStatus(tx as unknown as Database, voucher.id, clampedBalance, newStatus);
      }

      return { fiscalEventResult: appended, receiptNumber: number };
    }));
  } catch (error) {
    console.error('[POS][offline][receipt] tx failed — rolled back', {
      ...serializeErrorForLog(error),
      receiptNumber: txLog.receiptNumber,
      hashSequence: txLog.sequence,
      previousHash: txLog.previousHash,
      terminalId: input.terminalId,
    });
    throw error;
  }
  console.info('[POS][perf][receipt] fiscal tx committed', {
    ms: Math.round(performance.now() - txStartedAt),
    receiptNumber,
  });

  // T2.2 Step 5.1: update sync UI state and fire the debounced sync trigger
  // AFTER the COMMIT has durably persisted. Pre-T2.2 the trigger lived inside
  // insertOfflineReceipt, letting the 250 ms debounced syncStore read race
  // the commit under load.
  // Catch-block above re-throws on any pre-COMMIT failure, so reaching this
  // line means the receipt + chain advance + voucher balances are all
  // durably persisted.
  useSyncStore.getState().incrementPendingCount();
  scheduleDebouncedSync();

  return {
    receiptNumber,
    total: totalFormatted,
    subtotal: bcformat(subtotal, decimals),
    taxAmount: sealedVatTotal,
    discountAmount: bcformat(transactionDiscountAmount, decimals),
    vatBreakdown: sealedVatBreakdown,
    changeDue: changeDueFormatted,
    fiscalHash: fiscalEventResult.current_hash,
    idempotencyKey,
    localId: receiptId,
  };
}
