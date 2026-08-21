/**
 * Offline Z-report generation service.
 *
 * Generates Z-reports locally in SQLite with fiscal hash chain,
 * receipt snapshots, and grand total perpetual counters.
 * Mirrors the server's ReportGenerationService for offline-first operation.
 */

import type Database from '@tauri-apps/plugin-sql';
import Big from 'big.js';
import { queryAll } from '@/lib/db';
import { toSqliteUtc } from '@/lib/db/sqliteTime';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcabs, bcsub, bcformat, bccomp } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import { computeZReportHash } from '@/lib/fiscal/zReportHashService';
import { insertZReport, getZReportByShift } from '@/lib/db/repositories/zReportRepository';
import {
  getTerminalState,
  getZChainState,
  advanceZChain,
  updateGrandTotals,
} from '@/lib/db/repositories/terminalStateRepository';
import { insertZReportCounts } from '@/lib/db/repositories/zReportCountRepository';
import type { ZReportCountRow } from '@/lib/db/repositories/zReportCountRepository';
import { getCashDrawerOpsForShift } from '@/lib/db/repositories/cashDrawerRepository';
import { getAccountPaymentRecordsForShift } from '@/lib/db/repositories/localAccountPaymentRecordRepository';
import {
  getRefundRecordsForShift,
  type LocalRefundRecord,
} from '@/lib/db/repositories/localRefundRecordRepository';
import { getShiftReceiptAnchor } from '@/lib/db/repositories/shiftReceiptAnchorRepository';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import type {
  LocalZReport,
  ZReportData,
  ZReportVatBreakdown,
  ZReportPaymentMethod,
  ZReportCountEntry,
  ReceiptSnapshot,
  ReceiptSnapshotTaxLine,
  ReceiptSnapshotLine,
  GrandTotals,
} from '@/lib/offline/types';
import {
  computeCashCountSeverity,
  type CashCountThresholds,
} from '@/lib/offline/cashCountValidation';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { withWriteTransaction } from '@/lib/db/writeGate';
import {
  appendZSessionCloseAndZReport,
  type AuthorZSessionCloseInput,
} from '@/lib/fiscal/zSessionAuthoring';

// Cumulative fields are persisted at 3 decimals (TND max); see terminalStateRepository.
const CUMULATIVE_SCALE = 3;

/**
 * Scale of the two report_data summary blocks — FIXED at 3, never the currency
 * scale.
 *
 * `tolerance_summary.totalAmount` is a scale-3 string by contract
 * (`ZReportToleranceSummary` in types.ts; server side
 * `TolerancePaymentTotalsDTO` / ReportGenerationService.php:284-295, whose
 * zero-shape is `'0.000'` for EVERY currency), and
 * `cash_rounding_summary.total_adjustment` mirrors
 * `ZReportProjection::ROUNDING_SUMMARY_SCALE`, also 3. Both are additionally
 * re-normalized to 3 by `normalizeForHash` on both sides, so emitting a
 * currency-scale string here would only ever produce a device/server byte
 * divergence outside the hash (fiscal Z_REPORT payload, sync body, UI) with
 * nothing gained.
 */
const SUMMARY_SCALE = 3;

// ─── Cash count input / opts types ───────────────────────────────────────────

export interface CashCountInputForGeneration {
  payment_method_id: string;
  currency_code: string;
  actual_amount: string;
}

export interface GenerateZReportOpts {
  cashCounts?: CashCountInputForGeneration[];
  varianceReason?: string | null;
  managerUserId?: string | null;
  blindCountUsed?: boolean;
  tenantId?: string;
  fiscalShiftId?: string;
  fiscalSessionId?: string;
  terminalLabel?: string;
  operatorId?: string;
  operatorName?: string;
  isTraining?: boolean;
  requireFiscalEvents?: boolean;
  /** Fraud-settings thresholds. When provided, variance_severity is computed
   *  from the aggregated per-tender variances and stamped into shift_fields. */
  fraudSettings?: CashCountThresholds | null;
}

/**
 * Format a Date to match Carbon's toIso8601String() output.
 * JS: "2026-03-13T14:30:00.000Z"  →  Carbon: "2026-03-13T14:30:00+00:00"
 * The fiscal hash MUST use the same format as the server.
 */
function toIso8601(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, '+00:00');
}

interface ReceiptLineJson {
  name?: string;
  quantity?: number;
  unit_price?: string;
  line_total?: string;
  tax_rate?: string;
  tax_amount?: string;
  discount_amount?: string | null;
}

/**
 * Generate a Z-report for the current shift, stored locally in SQLite.
 *
 * All operations are wrapped in a single transaction to prevent inconsistent state.
 *
 * @param db - SQLite database connection
 * @param terminalId - Terminal UUID
 * @param shiftId - Current shift UUID
 * @param shiftOpenedAt - ISO 8601 timestamp of when the shift was opened
 * @param openingCash - Opening cash amount for the shift
 * @param opts - Optional cash count inputs and shift metadata (closes G2/G3)
 */
export async function generateZReport(
  db: Database,
  terminalId: string,
  shiftId: string,
  shiftOpenedAt: string,
  openingCash: string,
  opts: GenerateZReportOpts = {},
): Promise<LocalZReport> {
  const authState = useAuthStore.getState();
  const company = authState.companies.find((c) => c.id === authState.companyId);
  const decimals = getCurrencyDecimals(company?.currency ?? 'EUR');
  const companyId = authState.companyId;
  assertRequiredFiscalCloseContext(companyId, opts);

  // 1. Guard: check no Z-report already exists for this shift
  const existing = await getZReportByShift(db, shiftId);
  if (existing) {
    throw new Error(`Z-report already exists for shift ${shiftId}`);
  }

  // 2. Read terminal state
  const terminalState = await getTerminalState(db, terminalId);
  if (!terminalState) {
    throw new Error('Terminal hash chain not initialized. Cannot generate Z-report.');
  }

  const zChainState = await getZChainState(db, terminalId);
  if (!zChainState) {
    throw new Error('Z-chain state not found in terminal_state.');
  }

  // 3. Aggregate receipts for this shift
  // Receipts are queried by terminal_id + shift open time because offline_receipts
  // does not have a shift_id column. This is acceptable because shifts are sequential
  // per terminal — only one shift can be open at a time.
  // T2.7 — filter out is_training=1 rows. Training receipts must not enter local
  // Z-report totals or the Z-chain hash; this mirrors the server-side
  // `Terminal::scopeProduction()` exclusion that NF525 / ReportGenerationService
  // already apply on the canonical reporting path.
  // M3: prefer the monotonic hash_sequence window (rollback-immune) when the
  // shift recorded an opening anchor; fall back to the wall-clock window for
  // legacy shifts opened before anchors were captured. No upper bound is needed
  // — the Z is generated at close, before any later shift opens, so every
  // receipt after the anchor belongs to this shift.
  const anchor = await getShiftReceiptAnchor(db, shiftId);
  const receipts = anchor
    ? await queryAll<OfflineReceipt>(
        db,
        `SELECT * FROM offline_receipts
         WHERE terminal_id = $1 AND hash_sequence > $2 AND is_training = 0
         ORDER BY hash_sequence ASC`,
        [terminalId, anchor.opening_hash_sequence],
      )
    : await queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE terminal_id = $1 AND created_at >= $2 AND is_training = 0
     ORDER BY hash_sequence ASC`,
    // created_at is `datetime('now')` format (space separator, UTC); the ISO
    // shift timestamp must be normalized or the TEXT comparison excludes
    // every same-day receipt (' ' < 'T').
    [terminalId, toSqliteUtc(shiftOpenedAt)]
  );

  // v3-refund-chain-integration spec §7.1/§7.3 — a v4 refund inserts its
  // own `offline_receipts` row (`receipt_kind = 'refund'`), so it is
  // ALREADY included in the `receipts` array queried above; a refund-only
  // shift (open → refund → close) naturally has a non-empty `receipts`
  // array now (no separate empty-shift guard is needed for that case any
  // more than for a sale-only shift).
  //
  // Wave-2 review fix (TREASURY CRITICAL) — `local_refund_records` was
  // NOT superseded by the v4 mechanism: it is the LEGACY refund path's
  // ONLY write (`recordRefundSettlementForZ`, `refundZAccounting.ts`,
  // reached by `refundCheckoutStore.ts`'s legacy branch, §9.3's
  // coexistence ruling — the legacy path stays live for every terminal
  // that has not yet completed its v4 capability rollout). Removing this
  // term made every legacy refund overstate expected_cash by its full
  // amount and sign a Z with refunds=0 — a regression against shipping
  // behavior. The two sources are DISJOINT BY CONSTRUCTION (a v4 refund
  // never writes `local_refund_records`; a legacy refund never writes an
  // `offline_receipts` `receipt_kind='refund'` row), so folding both in
  // is a plain, non-overlapping sum — never a double-count.
  const refundRecords = await getRefundRecordsForShift(db, shiftId);

  // Cash drawer movements + customer account collections also close into the
  // signed Z. (H3 — allow empty Z: a cashier can always close the register; an
  // empty shift produces a nil Z with all-zero totals and expected_cash =
  // opening float, matching the server ReportGenerationService which has no
  // empty-shift guard. There is therefore no closeability guard here.)
  const drawerOps = await getCashDrawerOpsForShift(db, shiftId);
  const accountPayments = await getAccountPaymentRecordsForShift(db, shiftId);

  // Build payment method lookup for names
  const paymentMethodMap = await buildPaymentMethodMap(db);

  // 4. Compute report data — refundRecords (LEGACY) seeds refunds_count/
  // refunds_amount; the receipt_kind branch inside (v4) continues
  // accumulating on top of that seed (disjoint sources, see above).
  const reportData = aggregateReportData(receipts, paymentMethodMap, decimals, refundRecords);

  // 5. Compute expected cash BEFORE hashing — must be embedded in report_data
  // to match the server's ReportGenerationService which adds opening_cash/expected_cash
  // to report_data before hash computation.
  // v3-refund-chain-integration spec §7.3 — `cashSales` (below) is
  // ALREADY net of V4 refunds by construction of aggregateReportData()'s
  // per-row receipt_kind branching (a v4 refund's CASH leg is SUBTRACTED
  // from paymentByType there). LEGACY refunds do NOT flow through that
  // branch at all (local_refund_records carries no per-line/per-method
  // detail, only a shift-level `cash_impact` magnitude) — restored below
  // as the standalone `cashRefundImpact` term, subtracted independently.
  const cashPayments = reportData.payment_methods.find((p) => p.payment_type === 'CASH');
  const cashSales = cashPayments ? cashPayments.total_amount : '0';

  // Wave-2 review fix (TREASURY CRITICAL) — the LEGACY refund path's cash
  // impact (positive magnitude that physically left the drawer, '0' for
  // non-cash destinations). Never double-counted against `cashSales`
  // above: disjoint sources (see the refundRecords fetch's own comment).
  let cashRefundImpact = '0';
  for (const record of refundRecords) {
    cashRefundImpact = bcadd(cashRefundImpact, record.cash_impact, decimals);
  }

  // Cash drawer movements (NF525 / DSFinV-K, 2026-06-11 research): paid-ins
  // (deposit) raise the theoretical drawer, payouts lower it. The device is the
  // source of truth, so expected_cash must mirror the real drawer. Sign matches
  // the device's fetchDrawerBalance: deposit=+, payout=− (NOT the server's
  // bank-deposit sign).
  let drawerNet = '0';
  for (const op of drawerOps) {
    drawerNet =
      op.type === 'deposit'
        ? bcadd(drawerNet, op.amount, decimals)
        : bcsub(drawerNet, op.amount, decimals);
  }

  // Cash collected against customer credit accounts physically enters this
  // drawer (cash_impact is the CASH-only positive impact; non-cash = '0').
  let cashAccountCollections = '0';
  for (const ap of accountPayments) {
    cashAccountCollections = bcadd(cashAccountCollections, ap.cash_impact, decimals);
  }

  const expectedCash = bcsub(
    bcadd(
      bcadd(
        bcadd(openingCash, cashSales, decimals),
        drawerNet,
        decimals,
      ),
      cashAccountCollections,
      decimals,
    ),
    cashRefundImpact,
    decimals,
  );

  reportData.opening_cash = new Big(openingCash).toFixed(decimals);
  reportData.expected_cash = new Big(expectedCash).toFixed(decimals);
  reportData.variance = null;

  const companyCurrency = company?.currency ?? 'EUR';

  // 5a. Real tolerance + rounding aggregation (spec §4.3; closes the
  // TODO(payment-tolerance-v3) zero-shape below). Both read the receipt-level
  // columns receiptService writes inside the fiscal transaction — never
  // re-derived here, so the Z reports exactly what was signed.
  //
  // `tolerance_shortfall` is written ONLY on the AUTO-ACCEPT path
  // (receiptService.ts: `policySnapshot.toleranceDecision.applied`); a
  // manager-PIN-approved shortfall carries its evidence in PosOverrideEvidence
  // instead and is deliberately NOT counted here.
  let toleranceTotal = bcformat('0', SUMMARY_SCALE);
  let toleranceCount = 0;
  let roundingTotal = bcformat('0', SUMMARY_SCALE);
  let roundingCount = 0;
  for (const receipt of receipts) {
    // `tolerance_shortfall` is ALWAYS NULL on a refund row (§7.2a — no
    // tender-tolerance concept applies to a refund payout), so this is
    // already sale-only by construction — no receipt_kind branch needed,
    // matching endOfDayPreview.ts's own identical reasoning.
    const shortfall = receipt.tolerance_shortfall;
    if (shortfall !== null && shortfall !== undefined && shortfall !== '' && bccomp(shortfall, '0') !== 0) {
      toleranceTotal = bcadd(toleranceTotal, shortfall, SUMMARY_SCALE);
      toleranceCount += 1;
    }
    // v3-refund-chain-integration spec §7.2a/§7.3 — `cash_rounding_adjustment`
    // is signed and mirrors the fiscal payload's own value on EVERY row,
    // sale or refund. A sale's rounding ADDS to (or subtracts from) the
    // drawer the same way its total does; a refund's own rounding
    // reverses the sale's, because a refund PAYS OUT of the drawer where
    // a sale pays IN — so the same-signed adjustment has the OPPOSITE
    // net effect on cash and must be SUBTRACTED here, exactly mirroring
    // `endOfDayPreview.ts`'s own `isRefund`-branched rounding total
    // (this signed Z's own rounding aggregation had never been updated
    // for receipt_kind until now — a gap surfaced while extending this
    // file's test coverage, not a deliberate design difference from
    // endOfDayPreview.ts).
    const adjustment = receipt.cash_rounding_adjustment;
    if (adjustment !== null && adjustment !== undefined && adjustment !== '' && bccomp(adjustment, '0') !== 0) {
      roundingTotal = receipt.receipt_kind === 'refund'
        ? bcsub(roundingTotal, adjustment, SUMMARY_SCALE)
        : bcadd(roundingTotal, adjustment, SUMMARY_SCALE);
      roundingCount += 1;
    }
  }
  const toleranceSummary = {
    totalAmount: toleranceTotal,
    currencyCode: companyCurrency,
    writeoffCount: toleranceCount,
  };
  // ABSENT, not a zero-shape, when nothing rounded: a shift with no rounded
  // receipt must keep the legacy report_data shape byte-for-byte so its Z
  // hashes identically on device and server (zReportHashService parity pin).
  const cashRoundingSummary = roundingCount > 0
    ? { total_adjustment: roundingTotal, receipt_count: roundingCount }
    : null;

  // 5b. Compute cash count rows when opts.cashCounts is provided.
  //     Adds schema_version=2, cash_counts[], and tolerance_summary to report_data.
  let zReportCountRows: ZReportCountRow[] | null = null;
  let cashCountEntries: ZReportCountEntry[] | null = null;

  if (opts.cashCounts && opts.cashCounts.length > 0) {
    // Build a map of payment_method_id → expected_amount for the entries provided.
    // For CASH, expected = opening_cash + Σ(cash_sales from receipts).
    // For other physical methods, expected = Σ(sales for that method from receipts).
    const pmIdToSales = new Map<string, string>();
    for (const pm of reportData.payment_methods) {
      // Look up the payment_method_id from the code
      const pmId = [...paymentMethodMap.entries()].find(([, code]) => code === pm.payment_type)?.[0];
      if (pmId) {
        pmIdToSales.set(pmId, pm.total_amount);
      }
    }

    const countEntries: ZReportCountEntry[] = [];
    const countRows: ZReportCountRow[] = [];

    for (const input of opts.cashCounts) {
      // Compute expected_amount for this payment method
      const methodSales = pmIdToSales.get(input.payment_method_id) ?? '0';
      const methodCode = paymentMethodMap.get(input.payment_method_id) ?? '';

      // For CASH: expected = opening_float + cash_receipts_amount
      // For others: expected = sales_amount for that method
      const expectedAmount =
        methodCode === 'CASH'
          ? bcformat(expectedCash, decimals)
          : bcformat(methodSales, decimals);

      // variance = actual - expected (positive = over, negative = under)
      const variance = bcsub(input.actual_amount, expectedAmount, decimals);
      const varianceCmp = bccomp(variance, '0');
      const varianceDirection: 'over' | 'under' | 'balanced' =
        varianceCmp > 0 ? 'over' : varianceCmp < 0 ? 'under' : 'balanced';

      const entry: ZReportCountEntry = {
        payment_method_id: input.payment_method_id,
        currency_code: input.currency_code,
        expected_amount: expectedAmount,
        actual_amount: bcformat(input.actual_amount, decimals),
        variance_amount: variance,
        variance_direction: varianceDirection,
        transaction_count: receipts.filter(
          (r) => r.payment_method_id === input.payment_method_id,
        ).length,
      };

      countEntries.push(entry);
      countRows.push({
        id: crypto.randomUUID(),
        z_report_id: '', // filled in after we know the Z-report id below
        payment_method_id: entry.payment_method_id,
        currency_code: entry.currency_code,
        expected_amount: entry.expected_amount,
        actual_amount: entry.actual_amount,
        variance_amount: entry.variance_amount,
        variance_direction: entry.variance_direction,
        transaction_count: entry.transaction_count,
      });
    }

    cashCountEntries = countEntries;
    zReportCountRows = countRows;

    // Stamp schema_version = 2 and cash_counts into report_data
    reportData.schema_version = 2;
    reportData.cash_counts = countEntries;
    // Real per-shift tolerance write-offs (was a hardcoded zero-shape). Still a
    // v2-only key: it is stamped only alongside cash_counts, exactly as before,
    // so a no-cash-count Z keeps its v1 report_data shape.
    reportData.tolerance_summary = toleranceSummary;
  }

  // Local rounding observability. Stamped OUTSIDE the cash-count block so a
  // rounding-only shift closed without counts still records it — and omitted
  // entirely when nothing rounded (see `cashRoundingSummary` above).
  if (cashRoundingSummary !== null) {
    reportData.cash_rounding_summary = cashRoundingSummary;
  }

  // 6. Build receipt snapshots for fiscal export
  const receiptSnapshots = buildReceiptSnapshots(receipts, paymentMethodMap);

  // 7. Increment Z-number
  const newZNumber = zChainState.z_number + 1;
  const formattedZNumber = `Z${String(newZNumber).padStart(4, '0')}`;
  const newHashSequence = zChainState.z_hash_sequence + 1;
  const generatedAt = toIso8601(new Date());

  // 8. Compute fiscal hash (must match server format exactly)
  const fiscalHash = await computeZReportHash({
    previousHash: zChainState.z_last_hash,
    zNumber: newZNumber,
    terminalId,
    generatedAt,
    reportData,
  });

  // 9. Compute updated grand totals using Big.js (no IEEE 754 coercion).
  const grossSalesStr = reportData.gross_sales;
  const taxStr = reportData.tax_amount;
  const refundsStr = reportData.refunds_amount;
  const salesCount = reportData.sales_count;

  const netDeltaStr = bcsub(grossSalesStr, refundsStr, CUMULATIVE_SCALE);
  const grandTotals: GrandTotals = {
    cumulative_sales: bcadd(zChainState.cumulative_sales, grossSalesStr, CUMULATIVE_SCALE),
    cumulative_tax: bcadd(zChainState.cumulative_tax, taxStr, CUMULATIVE_SCALE),
    cumulative_refunds: bcadd(zChainState.cumulative_refunds, refundsStr, CUMULATIVE_SCALE),
    perpetual_grand_total: bcadd(zChainState.perpetual_grand_total, netDeltaStr, CUMULATIVE_SCALE),
    receipt_count_lifetime: zChainState.receipt_count_lifetime + salesCount,
  };

  // 10. Build shift_fields when cash count opts are provided.
  //     Compute variance_severity from the aggregated per-tender variances when
  //     fraud settings thresholds are available; otherwise leave null.
  const zReportId = crypto.randomUUID();

  let varianceSeverity: string | null = null;
  if (cashCountEntries !== null && opts.fraudSettings != null) {
    const severityResult = computeCashCountSeverity(
      cashCountEntries,
      opts.fraudSettings,
      decimals,
    );
    varianceSeverity = severityResult.severity;
  }

  const shiftFields =
    cashCountEntries !== null
      ? {
          blind_count_used: opts.blindCountUsed ?? false,
          variance_severity: varianceSeverity,
          variance_reason: opts.varianceReason ?? null,
          manager_override_by: opts.managerUserId ?? null,
        }
      : null;

  // 11. Build the Z-report record
  const zReport: LocalZReport = {
    id: zReportId,
    terminal_id: terminalId,
    shift_id: shiftId,
    z_number: newZNumber,
    formatted_z_number: formattedZNumber,
    generated_at: generatedAt,
    fiscal_hash: fiscalHash,
    previous_hash: zChainState.z_last_hash,
    hash_sequence: newHashSequence,
    report_data: reportData,
    opening_cash: reportData.opening_cash ?? new Big(openingCash).toFixed(decimals),
    expected_cash: reportData.expected_cash ?? new Big(expectedCash).toFixed(decimals),
    receipt_snapshots: receiptSnapshots,
    grand_totals: grandTotals,
    synced: false,
    synced_at: null,
    cash_counts: cashCountEntries ?? undefined,
    shift_fields: shiftFields,
    manager_user_id: opts.managerUserId ?? null,
    tolerance_summary: cashCountEntries !== null ? toleranceSummary : undefined,
    currency_code: companyCurrency,
  };

  // Fix z_report_id on count rows now that we have the report id
  if (zReportCountRows !== null) {
    for (const row of zReportCountRows) {
      row.z_report_id = zReportId;
    }
  }

  // 12. Persist atomically: insert Z-report + (optionally) cash count rows + advance Z-chain + update grand totals
  // Single-writer architecture: the whole block is ONE exclusive write-gate
  // job on the single connection (fiscal lane) — see writeGate.ts.
  await withWriteTransaction('fiscal', async (tx) => {
    const txDb = tx as unknown as Database;
    await insertZReport(txDb, zReport);
    if (zReportCountRows !== null && zReportCountRows.length > 0) {
      await insertZReportCounts(txDb, zReportCountRows);
    }
    await advanceZChain(txDb, terminalId, fiscalHash, newHashSequence, newZNumber);
    await updateGrandTotals(txDb, terminalId, grossSalesStr, taxStr, refundsStr, salesCount);

    const closeInput = buildFiscalCloseInput({
      companyId,
      companyName: company?.name ?? null,
      currencyCode: companyCurrency,
      decimals,
      fiscalHash,
      generatedAt,
      grandTotals,
      newHashSequence,
      opts,
      receiptSnapshots,
      reportData,
      shiftId,
      shiftOpenedAt,
      terminalId,
      zChainState,
      zReport,
      zReportCountRows,
    });
    if (closeInput !== null && companyId !== null) {
      const engine = await getFiscalEventEngine(companyId, db);
      await appendZSessionCloseAndZReport(tx, engine, closeInput);
    }
  });

  return zReport;
}

function assertRequiredFiscalCloseContext(companyId: string | null, opts: GenerateZReportOpts): void {
  if (opts.requireFiscalEvents !== true) {
    return;
  }

  const missing: string[] = [];
  if (companyId === null) missing.push('companyId');
  if (opts.tenantId === undefined) missing.push('tenantId');
  if (opts.fiscalShiftId === undefined) missing.push('fiscalShiftId');
  if (opts.fiscalSessionId === undefined) missing.push('fiscalSessionId');
  if (opts.terminalLabel === undefined) missing.push('terminalLabel');
  if (opts.operatorId === undefined) missing.push('operatorId');
  if (opts.operatorName === undefined) missing.push('operatorName');

  if (missing.length > 0) {
    throw new Error(
      `Cannot generate cutover Z-report without fiscal event close context: missing ${missing.join(', ')}.`,
    );
  }
}

interface FiscalCloseInputContext {
  companyId: string | null;
  companyName: string | null;
  currencyCode: string;
  decimals: number;
  fiscalHash: string;
  generatedAt: string;
  grandTotals: GrandTotals;
  newHashSequence: number;
  opts: GenerateZReportOpts;
  receiptSnapshots: ReceiptSnapshot[];
  reportData: ZReportData;
  shiftId: string;
  shiftOpenedAt: string;
  terminalId: string;
  zChainState: NonNullable<Awaited<ReturnType<typeof getZChainState>>>;
  zReport: LocalZReport;
  zReportCountRows: ZReportCountRow[] | null;
}

function buildFiscalCloseInput(ctx: FiscalCloseInputContext): AuthorZSessionCloseInput | null {
  const {
    companyId,
    companyName,
    currencyCode,
    decimals,
    fiscalHash,
    generatedAt,
    grandTotals,
    newHashSequence,
    opts,
    receiptSnapshots,
    reportData,
    shiftId,
    shiftOpenedAt,
    terminalId,
    zChainState,
    zReport,
    zReportCountRows,
  } = ctx;

  if (
    companyId === null ||
    opts.tenantId === undefined ||
    opts.fiscalShiftId === undefined ||
    opts.fiscalSessionId === undefined ||
    opts.terminalLabel === undefined ||
    opts.operatorId === undefined ||
    opts.operatorName === undefined
  ) {
    return null;
  }

  const cashCountLines = (zReportCountRows ?? []).map((row) => ({
    actual_amount: row.actual_amount,
    currency_code: row.currency_code,
    expected_amount: row.expected_amount,
    payment_method_id: row.payment_method_id,
    transaction_count: row.transaction_count,
    variance_amount: row.variance_amount,
    variance_direction: row.variance_direction,
  }));
  const firstReceipt = receiptSnapshots[0] ?? null;
  const lastReceipt = receiptSnapshots[receiptSnapshots.length - 1] ?? null;
  const cashCountSummary = summarizeCashCountLines(cashCountLines, decimals);

  return {
    tenantId: opts.tenantId,
    companyId,
    terminalId,
    terminalLabel: opts.terminalLabel,
    shiftId: opts.fiscalShiftId,
    sessionId: opts.fiscalSessionId,
    businessDate: shiftOpenedAt.slice(0, 10),
    operatorId: opts.operatorId,
    operatorName: opts.operatorName,
    currencyCode,
    currencyScale: fiscalCurrencyScale(decimals),
    periodStart: new Date(shiftOpenedAt).toISOString(),
    periodEnd: new Date(generatedAt).toISOString(),
    zReportUuid: zReport.id,
    zNumber: zReport.z_number,
    formattedZNumber: zReport.formatted_z_number,
    expectedCash: zReport.expected_cash,
    countedCash: cashCountSummary.countedCash,
    varianceAmount: cashCountSummary.varianceAmount,
    varianceDirection: cashCountSummary.varianceDirection,
    varianceSeverity: zReport.shift_fields?.variance_severity ?? null,
    varianceReason: zReport.shift_fields?.variance_reason ?? null,
    reportTotals: {
      sales_count: reportData.sales_count,
      gross_sales: reportData.gross_sales,
      net_sales: reportData.net_sales,
      tax_amount: reportData.tax_amount,
      refunds_count: reportData.refunds_count,
      refunds_amount: reportData.refunds_amount,
      voided_count: reportData.voided_count,
    },
    vatBreakdown: reportData.vat_breakdown.map((row) => ({
      gross_amount: row.gross_amount,
      net_amount: row.net_amount,
      tax_rate: row.tax_rate,
      vat_amount: row.vat_amount,
    })),
    paymentMethodTotals: reportData.payment_methods.map((row) => ({
      payment_type: row.payment_type,
      total_amount: row.total_amount,
      transaction_count: row.transaction_count,
    })),
    cashCountLines,
    cashDrawerTotals: {
      expected_cash: zReport.expected_cash,
      opening_cash: zReport.opening_cash,
    },
    grandTotalsBefore: {
      cumulative_refunds: zChainState.cumulative_refunds,
      cumulative_sales: zChainState.cumulative_sales,
      cumulative_tax: zChainState.cumulative_tax,
      perpetual_grand_total: zChainState.perpetual_grand_total,
      receipt_count_lifetime: zChainState.receipt_count_lifetime,
    },
    grandTotalsAfter: {
      cumulative_refunds: grandTotals.cumulative_refunds,
      cumulative_sales: grandTotals.cumulative_sales,
      cumulative_tax: grandTotals.cumulative_tax,
      perpetual_grand_total: grandTotals.perpetual_grand_total,
      receipt_count_lifetime: grandTotals.receipt_count_lifetime,
    },
    toleranceSummary: zReport.tolerance_summary === undefined || zReport.tolerance_summary === null
      ? null
      : {
          currencyCode: zReport.tolerance_summary.currencyCode,
          totalAmount: zReport.tolerance_summary.totalAmount,
          writeoffCount: zReport.tolerance_summary.writeoffCount,
        },
    legacyReportReference: {
      fiscal_hash: fiscalHash,
      hash_sequence: newHashSequence,
      local_z_report_id: zReport.id,
      shift_id: shiftId,
    },
    companySnapshot: {
      company_id: companyId,
      name: companyName,
    },
    seller: null,
    operationalEventRange: {
      first_receipt_hash: firstReceipt?.fiscal_hash ?? null,
      first_receipt_sequence: firstReceipt?.hash_sequence ?? null,
      last_receipt_hash: lastReceipt?.fiscal_hash ?? null,
      last_receipt_sequence: lastReceipt?.hash_sequence ?? null,
      receipt_count: receiptSnapshots.length,
    },
    isTraining: opts.isTraining ?? false,
    closedAtDevice: new Date(generatedAt),
  };
}

function fiscalCurrencyScale(decimals: number): 0 | 2 | 3 {
  if (decimals === 0 || decimals === 2 || decimals === 3) return decimals;
  throw new Error(`Unsupported fiscal currency scale ${decimals}`);
}

function summarizeCashCountLines(
  cashCountLines: ReadonlyArray<{
    actual_amount: string;
    expected_amount: string;
  }>,
  decimals: number,
): {
  countedCash: string | null;
  varianceAmount: string | null;
  varianceDirection: 'over' | 'under' | 'balanced' | null;
} {
  if (cashCountLines.length === 0) {
    return {
      countedCash: null,
      varianceAmount: null,
      varianceDirection: null,
    };
  }

  const totals = cashCountLines.reduce(
    (acc, line) => ({
      actual: bcadd(acc.actual, line.actual_amount, decimals),
      expected: bcadd(acc.expected, line.expected_amount, decimals),
    }),
    { actual: bcformat('0', decimals), expected: bcformat('0', decimals) },
  );
  const varianceAmount = bcsub(totals.actual, totals.expected, decimals);
  const varianceCompare = bccomp(varianceAmount, '0');

  return {
    countedCash: totals.actual,
    varianceAmount,
    varianceDirection: varianceCompare > 0
      ? 'over'
      : varianceCompare < 0
        ? 'under'
        : 'balanced',
  };
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

async function buildPaymentMethodMap(db: Database): Promise<Map<string, string>> {
  const methods = await queryAll<{ id: string; code: string }>(
    db,
    'SELECT id, code FROM payment_methods'
  );
  const map = new Map<string, string>();
  for (const m of methods) {
    map.set(m.id, m.code);
  }
  return map;
}

function aggregateReportData(
  receipts: OfflineReceipt[],
  paymentMethodMap: Map<string, string>,
  decimals: number,
  refundRecords: LocalRefundRecord[],
): ZReportData {
  let grossSales = '0';
  let netSales = '0';
  let taxAmount = '0';
  let salesCount = 0;
  // v3-refund-chain-integration spec §7.3 — v4 refund rows live in the
  // SAME `offline_receipts` table (`receipt_kind = 'refund'`), branched
  // below per row. Wave-2 review fix: this does NOT replace
  // `local_refund_records`/`getRefundRecordsForShift` — that remains the
  // LEGACY refund path's only write (disjoint source, see the caller's
  // own comment), seeded here BEFORE the receipts loop below continues
  // accumulating on top of it. refunds_amount is a POSITIVE magnitude:
  // the server pins these semantics (ReportGenerationService::
  // calculateShiftTotals asserts a positive refunds_amount in
  // ZReportV3AggregationTest, and GrandtotalService advances
  // perpetual_grand_total by gross_sales − refunds_amount). `receipt.total`
  // is stored negative per §7.2's convention, so bcabs() recovers the
  // existing positive-magnitude semantics unchanged; `record.total` (the
  // legacy record) is likewise signed negative for a return, same bcabs()
  // treatment. Void counters remain 0 offline — voiding is a server-side
  // operation tracked after sync.
  let refundsCount = refundRecords.length;
  let refundsAmount = '0';
  for (const record of refundRecords) {
    refundsAmount = bcadd(refundsAmount, bcabs(record.total, decimals), decimals);
  }
  const voidedCount = 0;

  const vatByRate = new Map<string, { net: string; vat: string; gross: string }>();
  const paymentByType = new Map<string, { amount: string; count: number }>();

  for (const receipt of receipts) {
    // Skip voided receipts (status === 'voided' or similar) — for now we count all
    // since offline receipts don't have a voided flag. Voided receipts are tracked server-side.
    if (receipt.receipt_kind === 'refund') {
      // -- §7.3 refund branch — does NOT touch salesCount/grossSales/
      //    netSales/taxAmount (those remain sale-only aggregates, matching
      //    the existing server-pinned semantics: refunds_amount is
      //    tracked as its own field, never folded into gross/net sales). --
      refundsCount++;
      refundsAmount = bcadd(refundsAmount, bcabs(receipt.total, decimals), decimals);

      // VAT breakdown — SUBTRACTED (a refund reverses VAT collected).
      //
      // Wave-2 review fix (finding 4 / codex C-3): `lines[].line_total` is
      // the GROSS/TTC line amount, NOT the net. The POS cart's
      // `unit_price` is tax-INCLUSIVE (precision contract) and
      // `cartStore.recalcLineTotal()` derives `line_total = unit_price ×
      // qty − discount`, then EXTRACTS `tax_amount` out of that gross
      // figure (`computeTaxAmount`). `refundReceiptService.ts` copies
      // those two cart values verbatim onto the refund row. Treating
      // `line_total` as the net and ADDING the VAT on top therefore
      // reversed net 12.00 / gross 14.00 for a 12.00-gross, 2.00-VAT
      // refund instead of net 10.00 / gross 12.00 — a double-count on
      // the SIGNED Z. Net is now derived the only way it can be:
      // gross − vat, at the currency scale.
      const refundLines = JSON.parse(receipt.lines) as ReceiptLineJson[];
      for (const line of refundLines) {
        const rate = line.tax_rate ?? '0';
        const lineVat = bcabs(line.tax_amount ?? '0', decimals);
        const lineGross = bcabs(line.line_total ?? '0', decimals);
        const lineNet = bcsub(lineGross, lineVat, decimals);

        const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };
        existing.net = bcsub(existing.net, lineNet, decimals);
        existing.vat = bcsub(existing.vat, lineVat, decimals);
        existing.gross = bcsub(existing.gross, lineGross, decimals);
        vatByRate.set(rate, existing);
      }

      // Payment-method breakdown — SUBTRACTED. `payments_json` is a
      // POSITIVE magnitude on every row, sale or refund (§7.2a) —
      // consumed here as a magnitude to subtract, not a pre-signed delta
      // (storing it negative would double-negate against this branch).
      let refundPayments: Array<{ method_code: string; amount: string }> = [];
      if (receipt.payments_json) {
        const parsedRefund = JSON.parse(receipt.payments_json) as unknown;
        if (Array.isArray(parsedRefund)) {
          refundPayments = parsedRefund as Array<{ method_code: string; amount: string }>;
        }
      }
      for (const p of refundPayments) {
        const ex = paymentByType.get(p.method_code) ?? { amount: '0', count: 0 };
        ex.amount = bcsub(ex.amount, p.amount, decimals);
        ex.count += 1;
        paymentByType.set(p.method_code, ex);
      }
      continue;
    }

    salesCount++;
    grossSales = bcadd(grossSales, receipt.total);
    // C-6 fix (z-headline-net-sales) — the HEADLINE sibling of the C-2 per-rate
    // fix below. `offline_receipts.subtotal` is a MISNOMER: the writer stores
    // Σ GROSS `line_total` in it (`receiptService.ts:149-157` `computeLineTotals`
    // → `:547`), so accumulating the column straight into `net_sales` made the
    // SIGNED headline `net_sales == gross_sales` on every taxed shift — exported
    // by NF525 as `<VentesNettes>` (`Nf525XmlBuilder.php:299`) and passed through
    // verbatim by `ZReportProjection.php:149`.
    //
    // THE IDENTITY, settled against the writer (not `total − tax_amount`):
    // the canonical, server-validated SALE_RECEIPT field also called `subtotal`
    // is the NET, derived at `SaleReceiptPayload.ts:121` as exactly
    // `subtotalGross − taxAmount` from the same two values `receiptService.ts:368`
    // hands the builder. So `receipt.subtotal − receipt.tax_amount` reproduces
    // the SEALED per-receipt net byte for byte, and `net_sales` becomes Σ of the
    // sealed corpus — the same corpus tie the M1 ruling used (V-14).
    //
    // `total − tax_amount` was rejected because the three columns do not share a
    // base: `subtotal` and `tax_amount` are PRE transaction-discount and PRE
    // cash-rounding, while `total` is the ROUNDED, POST-discount gross
    // (`receiptService.ts:548` ← `:290`). Mixing them yields a figure that is
    // neither the receipt's net nor the sum of the per-rate nets below — and
    // would break `Σ vat_breakdown[].net_amount == net_sales`, the invariant the
    // F-4 server tripwire enforces (SALE_RECEIPT #2/#3,
    // `FiscalPayloadConstraintValidator.php:1155-1170`).
    //
    // Consequence, stated rather than glossed: on a discounted or cash-rounded
    // shift `net_sales + tax_amount != gross_sales`. The wedge is the discount
    // plus the rounding, exactly as on the canonical receipt (whose identity #1
    // adds `transaction_discount_amount` back). The Z payload records neither
    // field, so no such identity is claimed anywhere.
    netSales = bcadd(netSales, bcsub(receipt.subtotal, receipt.tax_amount, decimals), decimals);
    taxAmount = bcadd(taxAmount, receipt.tax_amount);

    // VAT breakdown from receipt lines.
    //
    // C-2 fix (z-sale-branch-decomposition, ruling: Option B — in-place
    // semantic correction at the current event_version): `lines[].line_total`
    // is the GROSS/TTC line amount on a SALE row exactly as on a refund row.
    // The POS cart's `unit_price` is tax-INCLUSIVE and
    // `cartStore.recalcLineTotal()` derives `line_total = unit_price × qty −
    // discount`, then EXTRACTS `tax_amount` out of that gross figure
    // (`computeTaxAmount`); `receiptService.ts` copies both values verbatim
    // onto the row. Calling `line_total` the net and ADDING the VAT on top
    // therefore booked net 12.00 / gross 14.00 for a 12.00-gross, 2.00-VAT
    // line instead of net 10.00 / gross 12.00 — the same double-count the
    // refund branch above carried until the Lane C wave-2 fix, in the SIGNED
    // Z_REPORT and SESSION_CLOSE bytes. Net is derived the only way it can
    // be: gross − vat, at the currency scale.
    //
    // No `bcabs` here (unlike the refund branch): a sale row is
    // positive-signed, and `bcabs` would silently swallow a legitimately
    // negative row rather than surfacing it.
    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const lineVat = line.tax_amount ?? '0';
      const lineGross = line.line_total ?? '0';
      const lineNet = bcsub(lineGross, lineVat, decimals);

      const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };
      existing.net = bcadd(existing.net, lineNet, decimals);
      existing.vat = bcadd(existing.vat, lineVat, decimals);
      existing.gross = bcadd(existing.gross, lineGross, decimals);
      vatByRate.set(rate, existing);
    }

    // Payment method breakdown (NF525 / DSFinV-K, 2026-06-11 research):
    // attribute each tender to its OWN method (split tenders), and record the
    // cash figure NET of change — change is netted into the cash line, never the
    // whole receipt total against the primary method. Legacy receipts without
    // payments_json fall back to the single primary method (cash net of change).
    let payments: Array<{ method_code: string; amount: string }> = [];
    if (receipt.payments_json) {
      const parsed = JSON.parse(receipt.payments_json) as unknown;
      if (Array.isArray(parsed)) {
        payments = parsed as Array<{ method_code: string; amount: string }>;
      }
    }

    if (payments.length > 0) {
      let cashTendered = '0';
      let receiptHasCash = false;
      for (const p of payments) {
        if (p.method_code === 'CASH') {
          cashTendered = bcadd(cashTendered, p.amount);
          receiptHasCash = true;
          continue;
        }
        const ex = paymentByType.get(p.method_code) ?? { amount: '0', count: 0 };
        ex.amount = bcadd(ex.amount, p.amount);
        ex.count += 1;
        paymentByType.set(p.method_code, ex);
      }
      if (receiptHasCash) {
        const netCash = bcsub(cashTendered, receipt.change_due ?? '0');
        const ex = paymentByType.get('CASH') ?? { amount: '0', count: 0 };
        ex.amount = bcadd(ex.amount, netCash);
        ex.count += 1;
        paymentByType.set('CASH', ex);
      }
    } else {
      // Legacy receipt without payments_json: attribute the whole sale to the
      // primary method. receipt.total is ALREADY the net amount allocated to
      // the receipt (for a fully-cash receipt the drawer gains tendered − change
      // = total), so do NOT subtract change_due again here — that would double-net.
      const methodCode = paymentMethodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';
      const existing = paymentByType.get(methodCode) ?? { amount: '0', count: 0 };
      existing.amount = bcadd(existing.amount, receipt.total);
      existing.count += 1;
      paymentByType.set(methodCode, existing);
    }
  }

  // tax_rate intentionally remains a number in report_data because this shape is
  // persisted and used for fiscal event authoring. UI formatting happens later.
  const vatBreakdown: ZReportVatBreakdown[] = [];
  for (const [rate, totals] of vatByRate) {
    vatBreakdown.push({
      tax_rate: parseFloat(rate),
      net_amount: bcformat(totals.net, decimals),
      vat_amount: bcformat(totals.vat, decimals),
      gross_amount: bcformat(totals.gross, decimals),
    });
  }
  vatBreakdown.sort((a, b) => a.tax_rate - b.tax_rate);

  const paymentMethods: ZReportPaymentMethod[] = [];
  for (const [type, data] of paymentByType) {
    paymentMethods.push({
      payment_type: type,
      total_amount: bcformat(data.amount, decimals),
      transaction_count: data.count,
    });
  }

  return {
    sales_count: salesCount,
    gross_sales: bcformat(grossSales, decimals),
    net_sales: bcformat(netSales, decimals),
    tax_amount: bcformat(taxAmount, decimals),
    refunds_count: refundsCount,
    refunds_amount: bcformat(refundsAmount, decimals),
    voided_count: voidedCount,
    vat_breakdown: vatBreakdown,
    payment_methods: paymentMethods,
  };
}

function buildReceiptSnapshots(
  receipts: OfflineReceipt[],
  paymentMethodMap: Map<string, string>,
): ReceiptSnapshot[] {
  return receipts.map((receipt) => {
    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    const methodCode = paymentMethodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';

    // Build tax lines grouped by rate.
    // Map key is a numeric tax rate (percentage, not monetary) for sort stability.
    const taxByRate = new Map<number, { rate: number; net: string; vat: string; gross: string }>();
    for (const line of lines) {
      // tax_rate is a percentage frozen as a number in receipt snapshots; parse
      // here, format only when rendering.
      const rate = parseFloat(line.tax_rate ?? '0');
      const lineNet = line.line_total ?? '0';
      const lineVat = line.tax_amount ?? '0';
      const lineGross = bcadd(lineNet, lineVat);

      const existing = taxByRate.get(rate) ?? { rate, net: '0', vat: '0', gross: '0' };
      existing.net = bcadd(existing.net, lineNet);
      existing.vat = bcadd(existing.vat, lineVat);
      existing.gross = bcadd(existing.gross, lineGross);
      taxByRate.set(rate, existing);
    }

    const taxLines: ReceiptSnapshotTaxLine[] = Array.from(taxByRate.values()).map((t) => ({
      rate: t.rate,
      net_amount: t.net,
      tax_amount: t.vat,
      gross_amount: t.gross,
    }));

    const snapshotLines: ReceiptSnapshotLine[] = lines.map((line) => ({
      description: line.name ?? '',
      quantity: line.quantity ?? 1,
      unit_price: line.unit_price ?? '0',
      total: line.line_total ?? '0',
      // tax_rate is a percentage frozen as a number in receipt snapshots; parse
      // here, format only when rendering.
      tax_rate: parseFloat(line.tax_rate ?? '0'),
      discount_amount: line.discount_amount ?? '0',
    }));

    return {
      receipt_number: receipt.receipt_number,
      fiscal_hash: receipt.fiscal_hash,
      hash_sequence: receipt.hash_sequence,
      created_at: receipt.created_at,

      total_ht: receipt.subtotal,
      total_ttc: receipt.total,
      total_tax: receipt.tax_amount,

      tax_lines: taxLines,

      payment_type: methodCode,
      payment_amount: receipt.total,
      change_given: receipt.change_due ?? '0',

      lines: snapshotLines,

      voided: false,
      void_reason: null,
      refund_of: null,

      operator_id: receipt.operator_id,
      operator_name: receipt.operator_name,
    };
  });
}
