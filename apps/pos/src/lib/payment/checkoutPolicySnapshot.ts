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

export type ToleranceReason =
  | 'not_applicable'
  | 'disabled'
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
   * True when the terminal is in training mode, i.e. the receipt's
   * `invoice_type_code` is TRAINING rather than SALE. Both codes are IN scope
   * for rounding, so this NEVER gates — it is carried so the gate's
   * "invoice type ∈ {SALE, TRAINING}" arm is explicit at the call site and a
   * future out-of-scope invoice type has an obvious place to land.
   */
  readonly isTraining: boolean;
  readonly autoAcceptCountThisShift: number;
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

  // A cached policy is only authoritative for the currency it was pulled for.
  // Nothing clears a previously-loaded policy on a company switch
  // (`usePaymentPolicyStore.reset()` has no production caller and
  // `hydratePaymentPolicyFromCache` treats a null row as a no-op), and both the
  // denomination and the tolerance max amount are DENOMINATED in that currency.
  // A mismatch — including the `undefined` a degenerate policy carries — means
  // the policy is not usable here at all, for either mechanism.
  const policy = input.policy !== null
    && input.policy.currencyCode === input.currency
    ? input.policy
    : null;

  // ── Rounding gate (spec §4.1, fail-closed on every unknown) ──────────────
  // invoice_type_code is SALE or TRAINING for every path that reaches here
  // (refund/void authoring is out of scope), so `isTraining` only selects
  // between the two in-scope codes and never disables rounding.
  const denominationCandidate = policy?.cashRoundingDenomination ?? null;
  const roundingApplied =
    policy !== null
    && policy.cashRoundingEnabled === true
    && isValidDenomination(denominationCandidate, scale)
    && cashOnly
    && input.fiscalSchemaVersion === 3;

  const denomination = roundingApplied
    ? bcformat(denominationCandidate as string, scale)
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
    enabled: policy?.tenderToleranceEnabled === true,
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
  if (!input.enabled) {
    return { ...base, applied: false, reason: 'disabled' };
  }
  if (bccomp(input.shortfall, input.effectiveMax) > 0) {
    return { ...base, applied: false, reason: 'exceeds_max' };
  }
  if (input.autoAcceptCountThisShift >= TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT) {
    return { ...base, applied: false, reason: 'shift_limit_reached' };
  }
  return { ...base, applied: true, reason: 'accepted' };
}
