import { describe, expect, it, vi } from 'vitest';

// `@/lib/decimal` pulls in `@/lib/currency`, which imports the auth store at
// module level. Mock it so this stays a pure unit test with no Tauri/store
// runtime (same treatment as `src/lib/__tests__/decimal.test.ts`).
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import { bcabs, bccomp, bcdiv, bcmod, bcmul, bcsub } from '@/lib/decimal';
import {
  computeChange,
  computeRoundingAdjustment,
  computeShortfall,
  isCashOnlyTender,
  isValidDenomination,
  roundCashTotal,
  sumCashLegs,
  toleranceEffectiveMax,
} from '@/lib/payment/cashRounding';

const TND = 3;
const EUR = 2;
const JPY = 0;

describe('roundCashTotal — Swedish rounding to the nearest denomination', () => {
  it.each([
    ['9.997', '10.000'],
    ['9.973', '9.950'],
    ['9.975', '10.000'], // exact tie → UP
    ['0.025', '0.050'], // smallest tie → UP
    ['0.024', '0.000'],
    ['0.026', '0.050'],
    ['9.950', '9.950'], // exact multiple → unchanged
    ['0.000', '0.000'],
  ])('TND 0.050: %s rounds to %s', (exact, expected) => {
    expect(roundCashTotal(exact, '0.050', TND)).toBe(expected);
  });

  it.each(['0.005', '0.010', '0.050', '0.100', '1.000'])(
    'TND %s: |adjustment| <= D/2 and rounded stays on the grid across a full sweep',
    (denomination) => {
      // D/2 carried one digit past currency scale so the bound is exact at any
      // denomination, not just ones whose half happens to be scale-representable.
      const halfDenomination = bcdiv(denomination, '2', TND + 1);

      for (let millimes = 0; millimes <= 2000; millimes += 1) {
        // Built by exact decimal multiplication (never `millimes / 1000`) so no
        // float ever touches a monetary value, not even in the fixture.
        const exact = bcmul(String(millimes), '0.001', TND);
        const rounded = roundCashTotal(exact, denomination, TND);
        const adj = computeRoundingAdjustment(exact, rounded, TND);

        // |adj| <= D/2, inclusive — the tie cases sit exactly on the bound.
        expect(bccomp(bcabs(adj, TND + 1), halfDenomination)).toBeLessThanOrEqual(0);
        // The rounded total is an exact multiple of the denomination.
        expect(bcmod(rounded, denomination, TND)).toBe('0.000');
        // THE SERVER BIND, device-side: `rounded - adjustment == exact`. Task 8's
        // v3 aggregate invariant is this identity; a one-ulp gap here throws
        // SaleReceiptAggregateInvariantError on an already-signed receipt.
        expect(bcsub(rounded, adj, TND)).toBe(exact);
      }
    },
  );

  it('returns a signed adjustment string at currency scale', () => {
    expect(computeRoundingAdjustment('9.997', '10.000', TND)).toBe('0.003');
    expect(computeRoundingAdjustment('9.973', '9.950', TND)).toBe('-0.023');
    expect(computeRoundingAdjustment('9.950', '9.950', TND)).toBe('0.000');
  });

  it.each([
    // [over-precise exact, scale-2 exact, rounded, adjustment]
    ['9.985', '9.99', '10.00', '0.01'], // rounding the DIFFERENCE gives 0.02 → bind breaks
    ['9.9749999', '9.97', '9.95', '-0.02'],
    ['0.024999', '0.02', '0.00', '-0.02'],
  ])(
    'normalizes an over-precise total (%s) the same way roundCashTotal does',
    (overPrecise, normalizedExact, expectedRounded, expectedAdj) => {
      // `roundCashTotal` rounds bcformat(exact, scale); the adjustment MUST be
      // taken against that SAME normalized value. Subtracting the raw total
      // instead rounds the difference rather than differencing the roundings —
      // for 9.985 that yields 0.02, so `rounded - adj` = 9.98 while the payload
      // carries 9.99, and Task 8's v3 aggregate bind throws on a signed receipt.
      const rounded = roundCashTotal(overPrecise, '0.05', EUR);
      const adj = computeRoundingAdjustment(overPrecise, rounded, EUR);

      expect(rounded).toBe(expectedRounded);
      expect(adj).toBe(expectedAdj);
      expect(bcsub(rounded, adj, EUR)).toBe(normalizedExact);
    },
  );

  it('EUR scale 2 with a 0.05 denomination rounds at the cent', () => {
    expect(roundCashTotal('9.97', '0.05', EUR)).toBe('9.95');
    expect(roundCashTotal('9.98', '0.05', EUR)).toBe('10.00');
    expect(roundCashTotal('9.975', '0.05', EUR)).toBe('10.00'); // 9.98 → tie up
    expect(roundCashTotal('0.00', '0.05', EUR)).toBe('0.00');
  });

  it('JPY scale 0 with a 10 denomination rounds at the ten', () => {
    expect(roundCashTotal('1234', '10', JPY)).toBe('1230');
    expect(roundCashTotal('1235', '10', JPY)).toBe('1240'); // exact tie → UP
    expect(roundCashTotal('1240', '10', JPY)).toBe('1240'); // exact multiple
    expect(roundCashTotal('0', '10', JPY)).toBe('0');
    expect(computeRoundingAdjustment('1234', '1230', JPY)).toBe('-4');
  });
});

describe('isValidDenomination — fail-closed validity', () => {
  it('accepts a scale-representable positive denomination', () => {
    expect(isValidDenomination('0.050', TND)).toBe(true);
    expect(isValidDenomination('0.05', EUR)).toBe(true);
    expect(isValidDenomination('10', JPY)).toBe(true);
  });

  it('rejects null, empty, zero, negative and non-numeric values', () => {
    expect(isValidDenomination(null, TND)).toBe(false);
    expect(isValidDenomination('', TND)).toBe(false);
    expect(isValidDenomination('   ', TND)).toBe(false);
    expect(isValidDenomination('0.000', TND)).toBe(false);
    expect(isValidDenomination('-0.050', TND)).toBe(false);
    expect(isValidDenomination('abc', TND)).toBe(false);
  });

  it('rejects an undefined field smuggled in by an unvalidated policy response', () => {
    // A degenerate `{"data":{}}` pull installs a non-null policy whose fields
    // are `undefined` while typed `string`. This module is the last line of
    // defence and must not blow up on `.trim()` of undefined.
    expect(isValidDenomination(undefined, TND)).toBe(false);
  });

  it('rejects a denomination at a currency scale it has no cap for', () => {
    expect(isValidDenomination('0.050', 4)).toBe(false);
  });

  it('rejects a denomination that is not representable at the currency scale', () => {
    // 0.0025 would truncate/round to 0.003 — the signed value would no longer
    // divide the signed total, so the payload must never carry it.
    expect(isValidDenomination('0.0025', TND)).toBe(false);
  });

  it('rejects a denomination beyond the static history-stable cap', () => {
    expect(isValidDenomination('1.000', TND)).toBe(true);
    expect(isValidDenomination('5.000', TND)).toBe(false);
    expect(isValidDenomination('1.00', EUR)).toBe(true);
    expect(isValidDenomination('2.00', EUR)).toBe(false);
    expect(isValidDenomination('10', JPY)).toBe(true);
    expect(isValidDenomination('50', JPY)).toBe(false);
  });
});

describe('isCashOnlyTender — union over payment legs and voucher tenders', () => {
  const isCash = (code: string) => code === 'CASH';

  it('is true when every leg is a cash method', () => {
    expect(isCashOnlyTender([{ methodCode: 'CASH', amount: '10.000' }], isCash)).toBe(true);
    expect(
      isCashOnlyTender(
        [
          { methodCode: 'CASH', amount: '5.000' },
          { methodCode: 'CASH', amount: '5.000' },
        ],
        isCash,
      ),
    ).toBe(true);
  });

  it('is false when a MEAL_VOUCHER leg is present (physical, no maturity, NOT cash)', () => {
    expect(
      isCashOnlyTender(
        [
          { methodCode: 'CASH', amount: '5.000' },
          { methodCode: 'MEAL_VOUCHER', amount: '5.000' },
        ],
        isCash,
      ),
    ).toBe(false);
  });

  it('is false for a voucher-partial tender (voucher legs ARE payment legs)', () => {
    expect(
      isCashOnlyTender(
        [
          { methodCode: 'CASH', amount: '5.000' },
          { methodCode: 'store_voucher', amount: '5.000' },
        ],
        isCash,
      ),
    ).toBe(false);
  });

  it('is false for card-only and for an empty leg set', () => {
    expect(isCashOnlyTender([{ methodCode: 'CARD', amount: '10.000' }], isCash)).toBe(false);
    expect(isCashOnlyTender([], isCash)).toBe(false);
  });
});

describe('toleranceEffectiveMax — min(pct, max) with the denomination floor', () => {
  const base = {
    percentage: '0.0050',
    maxAmount: '0.100',
    denomination: '0.050',
    scale: TND,
  };

  it('takes the percentage cap when it is the smaller of the two', () => {
    // 9.973 * 0.005 = 0.049865 -> truncated at scale 3 = 0.049; floor lifts it to D.
    expect(toleranceEffectiveMax({ ...base, exactTotal: '9.973', roundingActive: false })).toBe(
      '0.049',
    );
  });

  it('takes max_amount when the percentage cap exceeds it', () => {
    // 100.000 * 0.005 = 0.500 > 0.100
    expect(toleranceEffectiveMax({ ...base, exactTotal: '100.000', roundingActive: false })).toBe(
      '0.100',
    );
  });

  it('applies the denomination floor when rounding is active', () => {
    expect(toleranceEffectiveMax({ ...base, exactTotal: '9.973', roundingActive: true })).toBe(
      '0.050',
    );
  });

  it('never floors a zero-total sale', () => {
    expect(toleranceEffectiveMax({ ...base, exactTotal: '0.000', roundingActive: true })).toBe(
      '0.000',
    );
  });

  it('ignores the floor when the denomination is invalid', () => {
    expect(
      toleranceEffectiveMax({
        ...base,
        denomination: '0.0025',
        exactTotal: '9.973',
        roundingActive: true,
      }),
    ).toBe('0.049');
  });

  it('never lowers the cap when the denomination is below it', () => {
    // pct cap 0.500 vs max 1.000 → 0.500; D = 0.050 must NOT pull it down.
    expect(
      toleranceEffectiveMax({
        ...base,
        maxAmount: '1.000',
        exactTotal: '100.000',
        roundingActive: true,
      }),
    ).toBe('0.500');
  });

  it('fails closed on missing policy fields (degenerate {} response)', () => {
    const degenerate = {
      exactTotal: '9.973',
      percentage: undefined as unknown as string,
      maxAmount: undefined as unknown as string,
      denomination: undefined as unknown as string | null,
      roundingActive: false,
      scale: TND,
    };
    expect(toleranceEffectiveMax(degenerate)).toBe('0.000');
    // Rounding "active" cannot conjure a floor out of a missing denomination.
    expect(toleranceEffectiveMax({ ...degenerate, roundingActive: true })).toBe('0.000');
  });

  it('truncates max_amount rather than rounding the cap up', () => {
    // 0.1005 half-up at scale 3 would be 0.101 — a cap rounded UP, which
    // contradicts "the accepted ceiling can only ever be conservative".
    expect(
      toleranceEffectiveMax({
        ...base,
        maxAmount: '0.1005',
        exactTotal: '100.000',
        roundingActive: false,
      }),
    ).toBe('0.100');
  });

  it('does NOT let the denomination floor manufacture headroom from a corrupt policy', () => {
    // A VALID denomination with rounding on, but garbage tolerance values: the
    // floor widens a configured cap, it is never itself a source of headroom.
    // Without the early return this would hand back '0.050' of auto-accept.
    expect(
      toleranceEffectiveMax({
        ...base,
        percentage: undefined as unknown as string,
        maxAmount: undefined as unknown as string,
        exactTotal: '9.973',
        roundingActive: true,
      }),
    ).toBe('0.000');
    expect(
      toleranceEffectiveMax({ ...base, percentage: 'abc', exactTotal: '9.973', roundingActive: true }),
    ).toBe('0.000');
    expect(
      toleranceEffectiveMax({ ...base, maxAmount: '', exactTotal: '9.973', roundingActive: true }),
    ).toBe('0.000');
  });

  it('still applies the floor to a legitimately zero-configured tolerance', () => {
    // '0.0000'/'0.000' PARSE — that is a configured zero, not a corrupt field,
    // so the spec's floor applies. This is the line between the two cases.
    expect(
      toleranceEffectiveMax({
        ...base,
        percentage: '0.0000',
        maxAmount: '0.000',
        exactTotal: '9.973',
        roundingActive: true,
      }),
    ).toBe('0.050');
  });

  it('fails closed on non-numeric or negative tolerance values', () => {
    expect(
      toleranceEffectiveMax({ ...base, percentage: 'abc', exactTotal: '9.973', roundingActive: false }),
    ).toBe('0.000');
    expect(
      toleranceEffectiveMax({ ...base, maxAmount: '', exactTotal: '9.973', roundingActive: false }),
    ).toBe('0.000');
    expect(
      toleranceEffectiveMax({
        ...base,
        percentage: '-0.0050',
        exactTotal: '9.973',
        roundingActive: false,
      }),
    ).toBe('0.000');
    expect(
      toleranceEffectiveMax({ ...base, exactTotal: 'abc', roundingActive: false }),
    ).toBe('0.000');
    expect(
      toleranceEffectiveMax({ ...base, exactTotal: '-1.000', roundingActive: true }),
    ).toBe('0.000');
  });
});

describe('shortfall / change / cash-leg sum', () => {
  it('clamps shortfall and change at zero', () => {
    expect(computeShortfall('10.000', '7.500', TND)).toBe('2.500');
    expect(computeShortfall('10.000', '12.000', TND)).toBe('0.000');
    expect(computeShortfall('10.000', '10.000', TND)).toBe('0.000');

    expect(computeChange('10.000', '12.000', TND)).toBe('2.000');
    expect(computeChange('10.000', '7.500', TND)).toBe('0.000');
    expect(computeChange('10.000', '10.000', TND)).toBe('0.000');
  });

  it('sums only the cash legs at currency scale', () => {
    const isCash = (code: string) => code === 'CASH';
    expect(
      sumCashLegs(
        [
          { methodCode: 'CASH', amount: '5.000' },
          { methodCode: 'CARD', amount: '3.000' },
          { methodCode: 'CASH', amount: '2.500' },
        ],
        isCash,
        TND,
      ),
    ).toBe('7.500');
    expect(sumCashLegs([], isCash, TND)).toBe('0.000');
    expect(sumCashLegs([{ methodCode: 'CARD', amount: '3.000' }], isCash, TND)).toBe('0.000');
  });
});
