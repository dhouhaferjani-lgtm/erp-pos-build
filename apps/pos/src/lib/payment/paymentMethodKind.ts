/**
 * Codex review B4 (2026-04-30) — TS counterpart of the PHP enum predicate at
 * `apps/api/app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php`
 * (PaymentInstrumentKind::requiresInstrumentForMethodCode).
 *
 * Single source of truth on the POS side for which `payment_methods.code`
 * values are instrument-bearing — meaning a tender of that method MUST carry
 * `instrument_type` + `instrument_serial`, never null. The validators and
 * writers on the server enforce the same rule; the UI uses this helper to
 * route taps on instrument-bearing method tiles through the dedicated voucher
 * tender flow rather than allowing a free-form payment line that the server
 * would 422 on.
 *
 * The list mirrors the non-`None` cases of the PHP PaymentInstrumentKind enum.
 * Adding a new instrument kind = add a case to the PHP enum + add an entry
 * here. Both must stay in sync — if they drift, the UI can either show a
 * stale tile (will 422 on submit) or hide a legitimate one (cashier can't
 * apply it).
 */

export const INSTRUMENT_BEARING_METHOD_CODES = [
  'store_voucher',
  'restaurant_voucher',
  'gift_card',
] as const;

export type InstrumentBearingMethodCode =
  (typeof INSTRUMENT_BEARING_METHOD_CODES)[number];

/**
 * Returns true when the given `payment_methods.code` requires the
 * `instrument_type` + `instrument_serial` pair to be present on every tender
 * row of that method. Normalization (lowercase + trim) mirrors the PHP helper
 * so STORE_VOUCHER and "  store_voucher  " also match.
 */
export function requiresInstrumentForMethodCode(methodCode: string): boolean {
  const normalized = methodCode.toLowerCase().trim();
  if (normalized === '') {
    return false;
  }
  return (INSTRUMENT_BEARING_METHOD_CODES as readonly string[]).includes(
    normalized,
  );
}
