/**
 * Codex review B4 (2026-04-30) — single source of truth on the TS side for
 * which `payment_methods.code` values are instrument-bearing. Mirrors the PHP
 * helper at `apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php`
 * (PaymentInstrumentKind::requiresInstrumentForMethodCode).
 *
 * The two sources MUST stay in sync. Adding an instrument kind is a two-file
 * change: enum case in PHP + entry in INSTRUMENT_BEARING_METHOD_CODES here.
 */

import { describe, it, expect } from 'vitest';
import {
  INSTRUMENT_BEARING_METHOD_CODES,
  requiresInstrumentForMethodCode,
} from '../paymentMethodKind';

describe('requiresInstrumentForMethodCode', () => {
  it('exports the canonical instrument-bearing code list', () => {
    expect(INSTRUMENT_BEARING_METHOD_CODES).toEqual([
      'store_voucher',
      'restaurant_voucher',
      'gift_card',
    ]);
  });

  it('returns true for store_voucher', () => {
    expect(requiresInstrumentForMethodCode('store_voucher')).toBe(true);
  });

  it('returns true for restaurant_voucher', () => {
    expect(requiresInstrumentForMethodCode('restaurant_voucher')).toBe(true);
  });

  it('returns true for gift_card', () => {
    expect(requiresInstrumentForMethodCode('gift_card')).toBe(true);
  });

  it('normalizes uppercase method codes', () => {
    expect(requiresInstrumentForMethodCode('STORE_VOUCHER')).toBe(true);
    expect(requiresInstrumentForMethodCode('Restaurant_Voucher')).toBe(true);
    expect(requiresInstrumentForMethodCode('GIFT_CARD')).toBe(true);
  });

  it('normalizes whitespace in method codes', () => {
    expect(requiresInstrumentForMethodCode('  store_voucher  ')).toBe(true);
    expect(requiresInstrumentForMethodCode('\tgift_card\n')).toBe(true);
  });

  it('returns false for cash / card / other non-instrument methods', () => {
    expect(requiresInstrumentForMethodCode('cash')).toBe(false);
    expect(requiresInstrumentForMethodCode('CASH')).toBe(false);
    expect(requiresInstrumentForMethodCode('card')).toBe(false);
    expect(requiresInstrumentForMethodCode('CB')).toBe(false);
    expect(requiresInstrumentForMethodCode('check')).toBe(false);
    expect(requiresInstrumentForMethodCode('transfer')).toBe(false);
  });

  it('returns false for empty / whitespace-only input', () => {
    expect(requiresInstrumentForMethodCode('')).toBe(false);
    expect(requiresInstrumentForMethodCode('   ')).toBe(false);
  });

  it('returns false for the none sentinel', () => {
    // The PHP enum has a `None` case (`none`); it must not be classified as
    // instrument-bearing — that would be circular and break the writer's
    // null-input handling for plain cash/card rows.
    expect(requiresInstrumentForMethodCode('none')).toBe(false);
  });

  it('returns false for unknown method codes', () => {
    expect(requiresInstrumentForMethodCode('crypto_token')).toBe(false);
    expect(requiresInstrumentForMethodCode('paypal')).toBe(false);
  });
});
