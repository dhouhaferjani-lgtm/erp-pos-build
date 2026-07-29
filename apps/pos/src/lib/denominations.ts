import { bcadd, bccomp, bcmod, bcsub } from '@/lib/decimal';

/** Bill denominations by currency code. */
const DENOMINATIONS: Record<string, number[]> = {
  EUR: [5, 10, 20, 50, 100],
  TND: [5, 10, 20, 50],
  GBP: [5, 10, 20, 50],
  USD: [5, 10, 20, 50, 100],
};

const DEFAULT_DENOMINATIONS = [5, 10, 20, 50, 100];

/**
 * Returns quick-tender amounts (>= total) for a given currency and total,
 * capped at 3.
 *
 * - When standard bills cover the total, returns those bills (>= total).
 * - When the total exceeds the largest bill, no single note suffices, so we
 *   return round-up suggestions: the total rounded up to the next multiple of
 *   the largest bill, plus the following two multiples. This guarantees the
 *   cashier always sees quick-tenders instead of only "Exact".
 *
 * `total` is a currency-scale DECIMAL STRING — money must never cross a float
 * boundary (precision contract). The returned bill values are whole-unit UI
 * button amounts, exact as integers.
 */
export function getDenominations(currency: string, total: string): number[] {
  const bills = DENOMINATIONS[currency] ?? DEFAULT_DENOMINATIONS;
  const covering = bills.filter((d) => bccomp(String(d), total) >= 0).slice(0, 3);
  if (covering.length > 0) return covering;

  // Total exceeds every standard bill — suggest round-up multiples of the
  // largest bill so there's always a "give this much" option.
  const largest = bills[bills.length - 1] ?? DEFAULT_DENOMINATIONS[DEFAULT_DENOMINATIONS.length - 1];
  if (!largest || bccomp(total, '0') <= 0) return [];

  // ceil(total / largest) * largest, done in the decimal domain: subtract the
  // remainder, then add one more bill unless the total already sits on a
  // multiple. The result is an exact whole multiple of a bill (<= 100), so the
  // single Number() below is safe across the float boundary — the value does go
  // on to become receipt money (button -> bcformat -> tenderedStr -> onConfirm
  // -> input.tenderedAmount, which drives change_due), but it round-trips
  // exactly and re-enters the decimal domain via bcformat before any arithmetic.
  const remainder = bcmod(total, String(largest));
  const firstMultipleDecimal = bccomp(remainder, '0') === 0
    ? total
    : bcadd(bcsub(total, remainder), String(largest));
  const firstMultiple = Number(firstMultipleDecimal);
  return [firstMultiple, firstMultiple + largest, firstMultiple + largest * 2];
}
