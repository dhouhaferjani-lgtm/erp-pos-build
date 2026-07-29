import { describe, expect, it } from 'vitest';
import { bcsub } from '@/lib/decimal';
import {
  buildCheckoutPolicySnapshot,
  computeCashScreenDisplay,
} from '@/lib/payment/checkoutPolicySnapshot';
import type { PaymentPolicy } from '@/stores/paymentPolicyStore';

const isCash = (code: string) => code === 'CASH';

const policy: PaymentPolicy = {
  cashRoundingEnabled: true,
  cashRoundingDenomination: '0.050',
  tenderToleranceEnabled: true,
  tenderTolerancePercentage: '0.0050',
  tenderToleranceMaxAmount: '0.100',
  currencyCode: 'TND',
  currencyScale: 3,
  refreshedAt: '2026-07-27 08:00:00',
};

function build(overrides: Partial<Parameters<typeof buildCheckoutPolicySnapshot>[0]> = {}) {
  return buildCheckoutPolicySnapshot({
    exactTotal: '9.997',
    currency: 'TND',
    legs: [{ methodCode: 'CASH', amount: '10.000' }],
    tenderedAmount: '10.000',
    isCashMethodCode: isCash,
    policy,
    fiscalSchemaVersion: 3,
    isTraining: false,
    autoAcceptCountThisShift: 0,
    ...overrides,
  });
}

describe('buildCheckoutPolicySnapshot — rounding gate', () => {
  it('rounds a cash-only sale on a fiscal_schema_version 3 terminal', () => {
    const s = build();
    expect(s.roundingApplied).toBe(true);
    expect(s.roundedTotal).toBe('10.000');
    expect(s.adjustment).toBe('0.003');
    expect(s.denomination).toBe('0.050');
  });

  it('does NOT round on a fiscal_schema_version 2 terminal (the cutover gate)', () => {
    const s = build({ fiscalSchemaVersion: 2 });
    expect(s.roundingApplied).toBe(false);
    expect(s.roundedTotal).toBe('9.997');
    expect(s.adjustment).toBe('0.000');
    expect(s.denomination).toBe('0.000');
  });

  it('does NOT round when the terminal version is unknown (fail-closed)', () => {
    expect(build({ fiscalSchemaVersion: null }).roundingApplied).toBe(false);
  });

  it('does NOT round without a policy, with rounding disabled, or with a bad denomination', () => {
    expect(build({ policy: null }).roundingApplied).toBe(false);
    expect(build({ policy: { ...policy, cashRoundingEnabled: false } }).roundingApplied).toBe(false);
    expect(build({ policy: { ...policy, cashRoundingDenomination: '0.0025' } }).roundingApplied)
      .toBe(false);
    expect(build({ policy: { ...policy, cashRoundingDenomination: null } }).roundingApplied)
      .toBe(false);
  });

  it('does NOT round a mixed tender (voucher leg present)', () => {
    const s = build({
      legs: [
        { methodCode: 'CASH', amount: '5.000' },
        { methodCode: 'store_voucher', amount: '5.000' },
      ],
    });
    expect(s.cashOnly).toBe(false);
    expect(s.roundingApplied).toBe(false);
    expect(s.roundedTotal).toBe('9.997');
  });

  it('rounds a TRAINING sale (invoice_type_code TRAINING is in scope)', () => {
    expect(build({ isTraining: true }).roundingApplied).toBe(true);
  });
});

describe('buildCheckoutPolicySnapshot — tolerance decision', () => {
  it('auto-accepts a shortfall inside the denomination floor', () => {
    // exact 9.973 -> rounded 9.950; tendered 9.900 -> shortfall 0.050.
    // pct cap 0.049, max 0.100 -> min 0.049; floor D=0.050 lifts it to 0.050.
    const s = build({ exactTotal: '9.973', tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }] });
    expect(s.roundedTotal).toBe('9.950');
    expect(s.toleranceDecision).toEqual({
      applied: true,
      shortfall: '0.050',
      effectiveMax: '0.050',
      reason: 'accepted',
    });
  });

  it('refuses a shortfall beyond the effective max (PIN path stays the escape hatch)', () => {
    const s = build({ exactTotal: '9.973', tenderedAmount: '9.800',
      legs: [{ methodCode: 'CASH', amount: '9.800' }] });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('exceeds_max');
  });

  it('escalates to the PIN path once the per-shift auto-accept limit is reached', () => {
    const s = build({ exactTotal: '9.973', tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }], autoAcceptCountThisShift: 10 });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('shift_limit_reached');
  });

  it('never auto-accepts a non-cash-only tender', () => {
    const s = build({
      exactTotal: '10.000',
      tenderedAmount: '9.960',
      legs: [{ methodCode: 'CARD', amount: '9.960' }],
    });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('not_applicable');
  });

  it('never auto-accepts when pos tolerance is disabled', () => {
    const s = build({
      policy: { ...policy, tenderToleranceEnabled: false },
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }],
    });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('disabled');
  });

  it('reports no shortfall when the tender covers the rounded total', () => {
    const s = build();
    expect(s.toleranceDecision).toEqual({
      applied: false,
      shortfall: '0.000',
      effectiveMax: '0.050',
      reason: 'not_applicable',
    });
  });
});

// ── Carried review findings (Tasks 2-4 reviewers -> Task 6) ──────────────────

describe('buildCheckoutPolicySnapshot — the tolerance disable switch is not inert', () => {
  /**
   * `toleranceEffectiveMax` applies the denomination floor whenever rounding is
   * active, so a DISABLED tolerance still reports a full `D` of headroom. The
   * decision — not the ceiling — is what must refuse, otherwise an operator who
   * turns tolerance off still gets D of silent write-off on every cash sale.
   */
  it('reports the D floor as the ceiling but refuses to apply it when disabled', () => {
    const s = build({
      policy: { ...policy, tenderToleranceEnabled: false },
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }],
    });
    // Rounding is an INDEPENDENT switch — it stays on.
    expect(s.roundingApplied).toBe(true);
    expect(s.toleranceDecision.effectiveMax).toBe('0.050');
    expect(s.toleranceDecision.shortfall).toBe('0.050');
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('disabled');
  });

  it('a policy-less device never auto-accepts even a one-millime shortfall', () => {
    const s = build({
      policy: null,
      exactTotal: '9.973',
      tenderedAmount: '9.972',
      legs: [{ methodCode: 'CASH', amount: '9.972' }],
    });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('disabled');
    expect(s.toleranceDecision.effectiveMax).toBe('0.000');
  });

  it('an ENABLED tolerance configured at zero still gets the spec D floor', () => {
    // The spec's owner decision (§8.1): while rounding is active a full-D
    // shortfall is acceptable. That is a property of ENABLED tolerance only —
    // pinned here so the disable-switch test above cannot be "fixed" by
    // removing the floor.
    const s = build({
      policy: {
        ...policy,
        tenderTolerancePercentage: '0.0000',
        tenderToleranceMaxAmount: '0.000',
      },
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }],
    });
    expect(s.toleranceDecision.effectiveMax).toBe('0.050');
    expect(s.toleranceDecision.applied).toBe(true);
    expect(s.toleranceDecision.reason).toBe('accepted');
  });

  it('does NOT apply the D floor when rounding is gated off by the terminal version', () => {
    const s = build({
      fiscalSchemaVersion: 2,
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }],
    });
    // pct cap 0.049 / max 0.100 -> 0.049; no floor because rounding is off.
    expect(s.toleranceDecision.effectiveMax).toBe('0.049');
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('exceeds_max');
  });
});

describe('buildCheckoutPolicySnapshot — the policy object is never trusted', () => {
  /**
   * The payment-policy slice performs no runtime shape validation: a degenerate
   * `{"data":{}}` pull installs a NON-null policy whose fields are `undefined`
   * while typed `string` / `number` / `boolean`.
   */
  const degenerate = {} as unknown as PaymentPolicy;

  it('a degenerate policy object disables both mechanisms', () => {
    const s = build({
      policy: degenerate,
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }],
    });
    expect(s.roundingApplied).toBe(false);
    expect(s.roundedTotal).toBe('9.973');
    expect(s.adjustment).toBe('0.000');
    expect(s.denomination).toBe('0.000');
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.effectiveMax).toBe('0.000');
    expect(s.policyRefreshedAt).toBeNull();
  });

  it('takes the money scale from the currency, never from policy.currencyScale', () => {
    // A policy claiming scale 2 must not re-scale TND money. '0.050' stays a
    // valid scale-3 denomination and the snapshot stays at scale 3.
    const s = build({ policy: { ...policy, currencyScale: 2 } });
    expect(s.scale).toBe(3);
    expect(s.roundedTotal).toBe('10.000');
    expect(s.roundingApplied).toBe(true);
  });

  it('fails closed when the cached policy belongs to a different currency', () => {
    // Stale policy after a company switch: nothing clears a previously-loaded
    // policy, and its denomination / max amount are denominated in ITS
    // currency. Applying them to another currency's money is never safe.
    const s = build({
      policy: { ...policy, currencyCode: 'EUR' },
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }],
    });
    expect(s.roundingApplied).toBe(false);
    expect(s.roundedTotal).toBe('9.973');
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('disabled');
  });
});

describe('buildCheckoutPolicySnapshot — totality and invariants', () => {
  it('an empty tender is never cash-only and never rounds', () => {
    const s = build({ legs: [] });
    expect(s.cashOnly).toBe(false);
    expect(s.roundingApplied).toBe(false);
  });

  it('a blank tendered amount counts as zero tendered, not as a crash', () => {
    const s = build({ tenderedAmount: '' });
    expect(s.toleranceDecision.shortfall).toBe('10.000');
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('exceeds_max');
  });

  it('normalizes the exact total to currency scale', () => {
    const s = build({ exactTotal: '9.9970' });
    expect(s.exactTotal).toBe('9.997');
  });

  it('holds the v3 aggregate identity roundedTotal - adjustment == exactTotal', () => {
    for (const exact of ['9.997', '9.973', '0.024', '12.500', '7.001']) {
      const s = build({ exactTotal: exact, tenderedAmount: '100.000',
        legs: [{ methodCode: 'CASH', amount: '100.000' }] });
      expect(bcsub(s.roundedTotal, s.adjustment, s.scale)).toBe(s.exactTotal);
    }
  });

  it('carries the terminal version, currency and policy refresh stamp through', () => {
    const s = build();
    expect(s.currency).toBe('TND');
    expect(s.scale).toBe(3);
    expect(s.fiscalSchemaVersion).toBe(3);
    expect(s.policyRefreshedAt).toBe('2026-07-27 08:00:00');
  });

  it('scale-2 currencies round at their own scale', () => {
    const s = build({
      currency: 'EUR',
      policy: { ...policy, currencyCode: 'EUR', cashRoundingDenomination: '0.05' },
      exactTotal: '9.97',
      tenderedAmount: '10.00',
      legs: [{ methodCode: 'CASH', amount: '10.00' }],
    });
    expect(s.scale).toBe(2);
    expect(s.roundedTotal).toBe('9.95');
    expect(s.adjustment).toBe('-0.02');
    expect(s.denomination).toBe('0.05');
  });
});

// ── The cash-screen display derivation ───────────────────────────────────────

function display(overrides: Partial<Parameters<typeof computeCashScreenDisplay>[0]> = {}) {
  return computeCashScreenDisplay({
    exactTotal: '9.973',
    currency: 'TND',
    legs: [{ methodCode: 'CASH', amount: '9.973' }],
    isCashMethodCode: isCash,
    policy,
    fiscalSchemaVersion: 3,
    isTraining: false,
    autoAcceptCountThisShift: 0,
    ...overrides,
  });
}

describe('computeCashScreenDisplay', () => {
  it('shows the rounded due, the adjustment and the auto-accept floor', () => {
    expect(display()).toEqual({
      roundedTotal: '9.950',
      adjustment: '-0.023',
      minimumAcceptable: '9.900',
    });
  });

  it('collapses the floor onto the due when tolerance is disabled', () => {
    const d = display({ policy: { ...policy, tenderToleranceEnabled: false } });
    expect(d.roundedTotal).toBe('9.950');
    expect(d.minimumAcceptable).toBe('9.950');
  });

  it('collapses the floor onto the due when the shift auto-accept budget is spent', () => {
    expect(display({ autoAcceptCountThisShift: 10 }).minimumAcceptable).toBe('9.950');
  });

  it('shows the exact total and no floor when the device knows no cash method', () => {
    // Migration v63's DEFAULT 0 upgrade window: no leg, so nothing is cash-only.
    expect(display({ legs: [] })).toEqual({
      roundedTotal: '9.973',
      adjustment: '0.000',
      minimumAcceptable: '9.973',
    });
  });

  it('shows the exact total on a non-cutover terminal', () => {
    const d = display({ fiscalSchemaVersion: 2 });
    expect(d.roundedTotal).toBe('9.973');
    expect(d.adjustment).toBe('0.000');
    // Tolerance is a separate mechanism and still offers its percentage cap.
    expect(d.minimumAcceptable).toBe('9.924');
  });

  it('clamps the floor at zero rather than going negative', () => {
    const d = display({
      exactTotal: '0.040',
      legs: [{ methodCode: 'CASH', amount: '0.040' }],
    });
    expect(d.roundedTotal).toBe('0.050');
    expect(d.minimumAcceptable).toBe('0.000');
  });

  it('is inert on an empty cart', () => {
    expect(display({ exactTotal: '0.000', legs: [{ methodCode: 'CASH', amount: '0.000' }] }))
      .toEqual({ roundedTotal: '0.000', adjustment: '0.000', minimumAcceptable: '0.000' });
  });
});
