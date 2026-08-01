/**
 * Shared test fixtures for `OriginalFiscalEventLocalView` — the resolved
 * ORIGINAL a v4 refund is authored against.
 *
 * Wave-2 fix wave, finding 8 (§9.6): `assertOriginalRefundable()` now
 * REFUSES any original that was not tendered as a single CASH leg, so an
 * empty `payments: []` is no longer a neutral placeholder — it is a
 * refusal. Every fixture representing a LEGALLY REFUNDABLE original must
 * therefore carry a real single cash leg, and it is defined once here so
 * the four suites that need it cannot drift apart.
 */
import type { PaymentInput } from '@/lib/fiscal/FiscalEventEngine';

/** A single, launch-legal CASH tender on the ORIGINAL sale. */
export const CASH_ORIGINAL_PAYMENTS: readonly PaymentInput[] = [
  {
    amount: '12.000',
    foreign_currency_amount: null,
    foreign_currency_code: null,
    instrument_serial: null,
    instrument_type: null,
    method_code: 'CASH',
  },
];

/** A CARD tender — §9.6 refuses this original outright. */
export const CARD_ORIGINAL_PAYMENTS: readonly PaymentInput[] = [
  {
    amount: '12.000',
    foreign_currency_amount: null,
    foreign_currency_code: null,
    instrument_serial: null,
    instrument_type: 'card',
    method_code: 'CARD',
  },
];
