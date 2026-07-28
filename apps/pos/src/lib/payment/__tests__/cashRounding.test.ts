import { describe, expect, it, vi } from 'vitest';

// `@/lib/decimal` pulls in `@/lib/currency`, which imports the auth store at
// module level. Mock it so this stays a pure unit test with no Tauri/store
// runtime (same treatment as `src/lib/__tests__/decimal.test.ts`).
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import { bcabs, bccomp, bcdiv, bcmod, bcmul } from '@/lib/decimal';
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

  it('|adjustment| never exceeds D/2 across a full denomination sweep', () => {
    const denomination = '0.050';
    // D/2 carried one digit past currency scale so the bound is exact at any
    // denomination, not just ones whose half happens to be scale-representable.
    const halfDenomination = bcdiv(denomination, '2', TND + 1); // '0.0250'

    for (let millimes = 0; millimes <= 200; millimes += 1) {
      // Built by exact decimal multiplication (never `millimes / 1000`) so no
      // float ever touches a monetary value, not even in the fixture.
      const exact = bcmul(String(millimes), '0.001', TND);
      const rounded = roundCashTotal(exact, denomination, TND);
      const adj = computeRoundingAdjustment(exact, rounded, TND);

      // |adj| <= D/2, inclusive — the tie cases sit exactly on the bound.
      expect(bccomp(bcabs(adj, TND + 1), halfDenomination)).toBeLessThanOrEqual(0);
      // The rounded total is an exact multiple of the denomination.
      expect(bcmod(rounded, denomination, TND)).toBe('0.000');
    }
  });

  it('returns a signed adjustment string at currency scale', () => {
    expect(computeRoundingAdjustment('9.997', '10.000', TND)).toBe('0.003');
    expect(computeRoundingAdjustment('9.973', '9.950', TND)).toBe('-0.023');
    expect(computeRoundingAdjustment('9.950', '9.950', TND)).toBe('0.000');
  });

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
    expect(isValidDenomination(undefined as unknown as string | null, TND)).toBe(false);
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
