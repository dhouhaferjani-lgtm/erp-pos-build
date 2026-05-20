import type Database from '@tauri-apps/plugin-sql';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcsub, bcmul, bcdiv, bcformat, bccomp } from '@/lib/decimal';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import type { FiscalEventAppendResult } from '@/lib/fiscal/FiscalEventEngine';
import {
  buildSaleReceiptPayload,
  type SaleReceiptSellerInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
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
import { serializeErrorForLog } from '@/lib/errorLogging';
import { useSyncStore } from '@/stores/syncStore';

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
  /** Primary payment method (first entry in `payments`) — used for the denormalized column on offline_receipts */
  paymentMethodId: string;
  /** Primary payment repository (first entry in `payments`) */
  paymentRepositoryId: string;
  tenderedAmount: number;
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
  transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string };
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
        changeDue: parseFloat(existing.change_due ?? '0'),
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

  const isTraining = input.isTraining === true;
  if (!input.tenantId || !input.companyId || !input.shiftId || !input.seller) {
    throw new Error('tenantId, companyId, shiftId, and seller are required for fiscal-event receipt authoring.');
  }
  const receiptId = crypto.randomUUID();
  const postedAtDate = new Date();
  const postedAt = postedAtDate.toISOString();
  const businessDate = postedAt.slice(0, 10);
  const totalFormatted = bcformat(total, decimals);
  const canonicalPayload = buildSaleReceiptPayload({
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
    total: totalFormatted,
    transactionDiscountAmount,
    transactionDiscountReason: input.transactionDiscount?.reason ?? null,
    payments: input.payments.map((payment) => ({
      methodCode: payment.methodCode,
      amount: payment.amount,
      instrumentType: payment.instrumentType ?? null,
      instrumentSerial: payment.instrumentSerial ?? null,
    })),
    consumptionMode: input.consumptionMode ?? null,
    tableId: input.tableId ?? null,
    isTraining,
    seller: input.seller,
  });

  // 5. Store offline receipt
  // T0.2: prefer caller-provided key (paymentStore allocates once per cart
  // submission attempt and reuses on retry); fall back to a fresh UUID for
  // legacy callers (e.g. test fixtures) that don't supply one.
  const idempotencyKey = input.idempotencyKey ?? crypto.randomUUID();
  // tenderedAmount is a number (UI input), subtracted from string total via bcformat round-trip
  const changeDueRaw = bcsub(String(input.tenderedAmount), total);
  const changeDueFormatted = bccomp(changeDueRaw, '0') >= 0 ? bcformat(changeDueRaw, decimals) : bcformat('0', decimals);

  // Codex review B3 (2026-04-30): persist methodCode, instrumentType, and
  // instrumentSerial on every row in payments_json so the sync layer can
  // forward them to the server. The v3 fiscal hash is computed with these
  // fields included (lines 167-173 above) — if payments_json strips them,
  // the server will recompute the hash from null instrument fields and
  // reject the payload as a chain break.
  const paymentsJson = JSON.stringify(
    input.payments.map((p) => ({
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
    : collectStoreVoucherPayments(input.payments);
  const resolvedVouchers = await resolveLocalVouchers(db, voucherTenders);

  // 5c. Wrap receipt insert + hash chain advance + voucher balance updates
  //     in a single transaction. Either the receipt + chain + balance all
  //     commit, or none do — this prevents the cashier from sealing a
  //     fiscal receipt that references a voucher whose local projection
  //     never moved.
  await db.execute('BEGIN TRANSACTION');
  let fiscalEventResult: FiscalEventAppendResult | null = null;
  let receiptNumber = '';
  try {
    const engine = await getFiscalEventEngine(input.companyId, db);
    fiscalEventResult = await engine.append(db, {
      event_type: 'SALE_RECEIPT',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(postedAtDate),
      business_date: businessDate,
      payload: canonicalPayload,
      source_event_class: 'offline_receipts',
      source_event_id: receiptId,
    });
    receiptNumber = generateReceiptNumber(
      terminalState.location_code,
      terminalState.terminal_code,
      fiscalEventResult.sequence_number,
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
      fiscal_hash: fiscalEventResult.current_hash,
      previous_hash: fiscalEventResult.previous_hash,
      hash_sequence: fiscalEventResult.sequence_number,
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
      fiscal_schema_version: fiscalSchemaVersion,
      is_training: isTraining ? 1 : 0,
      canonical_bytes: fiscalEventResult.canonical_bytes,
    };

    await insertOfflineReceipt(db, offlineReceipt);

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

      await updateVoucherBalanceAndStatus(db, voucher.id, clampedBalance, newStatus);
    }

    await db.execute('COMMIT');
  } catch (error) {
    console.error('[POS][offline][receipt] tx body threw — rolling back', {
      ...serializeErrorForLog(error),
      receiptNumber,
      hashSequence: fiscalEventResult?.sequence_number ?? null,
      previousHash: fiscalEventResult?.previous_hash ?? null,
      terminalId: input.terminalId,
    });
    try {
      await db.execute('ROLLBACK');
    } catch (rollbackError) {
      console.error('[POS][offline][receipt] ROLLBACK also threw — connection may be in bad state', {
        ...serializeErrorForLog(rollbackError),
        receiptNumber,
        terminalId: input.terminalId,
      });
    }
    throw error;
  }

  // T2.2 Step 5.1: update sync UI state and fire the debounced sync trigger
  // AFTER the COMMIT has durably persisted. Pre-T2.2 the trigger lived inside
  // insertOfflineReceipt, letting the 250 ms debounced syncStore read race
  // the commit under load.
  // Catch-block above re-throws on any pre-COMMIT failure, so reaching this
  // line means the receipt + chain advance + voucher balances are all
  // durably persisted.
  useSyncStore.getState().incrementPendingCount();
  scheduleDebouncedSync();

  if (fiscalEventResult === null) {
    throw new Error('Fiscal event append did not return a result.');
  }

  return {
    receiptNumber,
    total: totalFormatted,
    subtotal: bcformat(subtotal, decimals),
    taxAmount: bcformat(taxAmount, decimals),
    discountAmount: bcformat(transactionDiscountAmount, decimals),
    changeDue: parseFloat(changeDueFormatted),
    fiscalHash: fiscalEventResult.current_hash,
    idempotencyKey,
    localId: receiptId,
  };
}
