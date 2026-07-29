import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';
import {
  computeRoundingAdjustment,
  computeShortfall,
  isCashOnlyTender,
  isValidDenomination,
  roundCashTotal,
  toleranceEffectiveMax,
  TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT,
  type TenderLeg,
} from '@/lib/payment/cashRounding';
import type { PaymentPolicy } from '@/stores/paymentPolicyStore';

/**
 * Mirrors `SaleReceiptPayloadInput.invoice_type_code`
 * (`lib/fiscal/FiscalEventEngine.ts:406`). Declared here rather than imported
 * so this module stays free of the fiscal engine's dependency graph.
 */
export type CheckoutInvoiceType = 'SALE' | 'REFUND' | 'VOID' | 'TRAINING';

/**
 * The only invoice types cash rounding applies to (spec §4.1). Refund and void
 * authoring is out of scope: their totals are derived from the ORIGINAL
 * receipt, so rounding them again would break the refund's tie to what was
 * actually signed. The membership check is load-bearing — the caller's type
 * legally admits REFUND/VOID.
 */
export const ROUNDABLE_INVOICE_TYPES: readonly CheckoutInvoiceType[] = ['SALE', 'TRAINING'];

/**
 * Why the tolerance decision came out the way it did.
 *
 * `disabled` and `not_cutover` are deliberately DISTINCT. Both refuse the
 * auto-accept, but they are different incidents: `disabled` is an operator
 * turning the switch off, `not_cutover` is a terminal still on fiscal schema
 * v2. Collapsing them made a field report of "tolerance stopped working after
 * the update" indistinguishable from a config change — the reason string is
 * the only thing that tells support which one happened.
 */
export type ToleranceReason =
  | 'not_applicable'
  | 'disabled'
  | 'not_cutover'
  | 'accepted'
  | 'exceeds_max'
  | 'shift_limit_reached';

export interface ToleranceDecision {
  readonly applied: boolean;
  readonly shortfall: string;
  readonly effectiveMax: string;
  readonly reason: ToleranceReason;
}

/**
 * The sealed decision for ONE checkout attempt (spec §4.3).
 *
 * Built ONCE at tender time and threaded through
 * `createReceiptLocalFirst -> createOfflineReceipt -> buildSaleReceiptV3Payload`.
 * What signs is the snapshot: a policy tick mid-sale can never move the total
 * between the gate and the signature.
 */
export interface CheckoutPolicySnapshot {
  readonly currency: string;
  readonly scale: number;
  readonly exactTotal: string;
  readonly roundedTotal: string;
  readonly adjustment: string;
  readonly denomination: string;
  readonly roundingApplied: boolean;
  readonly cashOnly: boolean;
  readonly toleranceDecision: ToleranceDecision;
  readonly fiscalSchemaVersion: number | null;
  readonly policyRefreshedAt: string | null;
}

export interface BuildCheckoutPolicySnapshotInput {
  readonly exactTotal: string;
  readonly currency: string;
  readonly legs: readonly TenderLeg[];
  readonly tenderedAmount: string;
  readonly isCashMethodCode: (code: string) => boolean;
  readonly policy: PaymentPolicy | null;
  /** `terminal.fiscal_schema_version` as cached by terminalStore; null when unknown. */
  readonly fiscalSchemaVersion: number | null;
  /**
   * The `invoice_type_code` this checkout will author. SALE and TRAINING round;
   * REFUND and VOID do not (see {@link ROUNDABLE_INVOICE_TYPES}).
   */
  readonly invoiceType: CheckoutInvoiceType;
  readonly autoAcceptCountThisShift: number;
}

/**
 * True when `policy` was pulled for the currency this checkout is settling in.
 *
 * Both sides are trimmed and upper-cased before comparing: `currencyCode` is a
 * server field and `input.currency` is `company.currency` with an `'EUR'`
 * fallback (`lib/currency.ts:28-32`), so neither is guaranteed normalized. A
 * raw `!==` would silently disable BOTH mechanisms on a case or whitespace
 * disagreement — a feature kill that lint, typecheck and unit tests cannot see.
 */
function policyMatchesCurrency(policy: PaymentPolicy, currency: string): boolean {
  const policyCode = typeof policy.currencyCode === 'string'
    ? policy.currencyCode.trim().toUpperCase()
    : '';
  return policyCode !== '' && policyCode === currency.trim().toUpperCase();
}

/**
 * `value` at `scale`, treating a blank/absent value as zero.
 *
 * `bcformat` calls `new Big(value)` directly and THROWS on ''; the tendered
 * amount reaches this builder straight from a numpad string, and a crash there
 * would replace the cashier's "amount is below the amount due" banner with a
 * big.js parse error. Zero is the fail-closed reading of an unusable tender:
 * maximum shortfall, so the gate refuses.
 */
function toScaleOrZero(value: string, scale: number): string {
  return bcadd(value, '0', scale);
}

export function buildCheckoutPolicySnapshot(
  input: BuildCheckoutPolicySnapshotInput,
): CheckoutPolicySnapshot {
  // The money scale comes from the CURRENCY table — the same source the cart
  // total and the receipt use — never from `policy.currencyScale`. The policy
  // slice performs no runtime shape validation, so a degenerate `{"data":{}}`
  // pull installs a NON-null policy whose `currencyScale` is `undefined` while
  // typed `number`; trusting it would silently re-scale money.
  const scale = getCurrencyDecimals(input.currency);
  const zero = bcformat('0', scale);
  const exactTotal = bcformat(input.exactTotal, scale);
  const tenderedAmount = toScaleOrZero(input.tenderedAmount, scale);
  const cashOnly = isCashOnlyTender(input.legs, input.isCashMethodCode);

  // A cached policy is only authoritative for the currency it was pulled for:
  // both the denomination and the tolerance max amount are DENOMINATED in that
  // currency, so applying them to another currency's money is never safe. A
  // mismatch — including the `undefined` a degenerate policy carries — means
  // the policy is unusable here, for BOTH mechanisms.
  let policy: PaymentPolicy | null = input.policy;
  if (policy !== null && !policyMatchesCurrency(policy, input.currency)) {
    console.warn(
      '[POS][checkoutPolicySnapshot] cached payment policy currency does not match the '
      + 'checkout currency — cash rounding AND tender tolerance are disabled for this sale',
      { policyCurrency: policy.currencyCode ?? null, checkoutCurrency: input.currency },
    );
    policy = null;
  }

  // ── Rounding gate (spec §4.1, fail-closed on every unknown) ──────────────
  const denominationCandidate = policy?.cashRoundingDenomination ?? null;
  // Hoisted so the type predicate narrows here rather than needing a cast below.
  const validDenomination = isValidDenomination(denominationCandidate, scale)
    ? bcformat(denominationCandidate, scale)
    : null;
  const roundingApplied =
    policy !== null
    && policy.cashRoundingEnabled === true
    && validDenomination !== null
    && cashOnly
    && input.fiscalSchemaVersion === 3
    && ROUNDABLE_INVOICE_TYPES.includes(input.invoiceType);

  // `validDenomination !== null` is already an arm of `roundingApplied`;
  // repeating it is TypeScript narrowing, not a second rule.
  const denomination = roundingApplied && validDenomination !== null
    ? validDenomination
    : zero;
  const roundedTotal = roundingApplied
    ? roundCashTotal(exactTotal, denomination, scale)
    : exactTotal;
  const adjustment = roundingApplied
    ? computeRoundingAdjustment(exactTotal, roundedTotal, scale)
    : zero;

  // ── Tolerance decision (evaluated against the ROUNDED due) ───────────────
  const shortfall = computeShortfall(roundedTotal, tenderedAmount, scale);
  const effectiveMax = toleranceEffectiveMax({
    exactTotal,
    percentage: policy?.tenderTolerancePercentage ?? '0',
    maxAmount: policy?.tenderToleranceMaxAmount ?? '0',
    denomination: denominationCandidate,
    roundingActive: roundingApplied,
    scale,
  });

  const toleranceDecision = decideTolerance({
    cashOnly,
    // The disable switch is NOT inert: `toleranceEffectiveMax` applies the
    // denomination floor whenever rounding is active, so a disabled tolerance
    // still REPORTS a full `D` of headroom. Only this flag stops it from being
    // spent — without it an operator who turns tolerance off would still get D
    // of silent write-off on every cash sale.
    //
    enabled: policy?.tenderToleranceEnabled === true,
    // The cutover arm is an owner ruling (2026-07-29) that deliberately
    // DEVIATES from the spec text, which states the tolerance condition with
    // no schema-version arm. On a v2 terminal an auto-accepted shortfall has
    // no fiscal trace whatsoever: the v2 payload carries no
    // `tolerance_shortfall`, the projection's `tolerance_writeoff` write is
    // v3-gated, and no PosOverrideEvidence is authored because no PIN is taken.
    // A silent cash-vs-revenue gap is worse than sending the cashier to the
    // (fully evidenced) manager-PIN path, so v2 gets no headroom at all.
    //
    // It is a SEPARATE flag from `enabled` so the refusal keeps its own
    // reason: a terminal left at v2 must not report as "the operator turned
    // tolerance off".
    cutover: input.fiscalSchemaVersion === 3,
    shortfall,
    effectiveMax,
    autoAcceptCountThisShift: input.autoAcceptCountThisShift,
  });

  return {
    currency: input.currency,
    scale,
    exactTotal,
    roundedTotal,
    adjustment,
    denomination,
    roundingApplied,
    cashOnly,
    toleranceDecision,
    fiscalSchemaVersion: input.fiscalSchemaVersion,
    policyRefreshedAt: policy?.refreshedAt ?? null,
  };
}

/** What the cash screen needs to render, all at currency scale. */
export interface CashScreenDisplay {
  /** The amount due — rounded when rounding is in play, else the exact total. */
  readonly roundedTotal: string;
  /** Signed `rounded − exact`; canonical zero when rounding is not in play. */
  readonly adjustment: string;
  /** Lowest confirmable tender: `roundedTotal` minus auto-accept headroom, clamped at zero. */
  readonly minimumAcceptable: string;
}

/**
 * The DISPLAY projection of the checkout decision, for the cash screen before
 * anything has been tendered.
 *
 * This is NOT the authoritative snapshot — `paymentStore` seals that at confirm
 * time from the same builder. This one only decides what the cashier is shown
 * and when Confirm lights up.
 *
 * Two passes, because the tolerance decision is tender-dependent while the
 * numbers it needs are not: pass 1 yields the rounded due and the tolerance
 * ceiling, pass 2 then asks the only question that actually determines the
 * floor — "would a tender AT that floor be auto-accepted?". Reading the
 * decision rather than re-deriving the rules is what keeps a disabled switch, a
 * non-cash tender, a non-cutover terminal and a spent shift budget all
 * collapsing the floor back onto the full due without this function knowing why.
 */
export function computeCashScreenDisplay(
  input: Omit<BuildCheckoutPolicySnapshotInput, 'tenderedAmount'>,
): CashScreenDisplay {
  const probe = buildCheckoutPolicySnapshot({ ...input, tenderedAmount: '0' });
  const rawFloor = bcsub(
    probe.roundedTotal,
    probe.toleranceDecision.effectiveMax,
    probe.scale,
  );
  const floor = bccomp(rawFloor, '0') > 0 ? rawFloor : bcformat('0', probe.scale);
  const atFloor = buildCheckoutPolicySnapshot({ ...input, tenderedAmount: floor });

  return {
    roundedTotal: probe.roundedTotal,
    adjustment: probe.adjustment,
    minimumAcceptable: atFloor.toleranceDecision.applied ? floor : probe.roundedTotal,
  };
}

function decideTolerance(input: {
  cashOnly: boolean;
  enabled: boolean;
  cutover: boolean;
  shortfall: string;
  effectiveMax: string;
  autoAcceptCountThisShift: number;
}): ToleranceDecision {
  const base = { shortfall: input.shortfall, effectiveMax: input.effectiveMax };
  if (bccomp(input.shortfall, '0') <= 0) {
    return { ...base, applied: false, reason: 'not_applicable' };
  }
  if (!input.cashOnly) {
    return { ...base, applied: false, reason: 'not_applicable' };
  }
  // Order matters for diagnosability: an operator who switched tolerance off
  // owns that refusal regardless of the terminal's schema version, so
  // `disabled` is reported first. `not_cutover` therefore means "the policy
  // WANTS to auto-accept and the terminal is what is stopping it" — the exact
  // signal a "tolerance stopped working after the update" report needs.
  if (!input.enabled) {
    return { ...base, applied: false, reason: 'disabled' };
  }
  if (!input.cutover) {
    return { ...base, applied: false, reason: 'not_cutover' };
  }
  if (bccomp(input.shortfall, input.effectiveMax) > 0) {
    return { ...base, applied: false, reason: 'exceeds_max' };
  }
  if (input.autoAcceptCountThisShift >= TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT) {
    return { ...base, applied: false, reason: 'shift_limit_reached' };
  }
  return { ...base, applied: true, reason: 'accepted' };
}
