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
 */
export function getDenominations(currency: string, total: number): number[] {
  const bills = DENOMINATIONS[currency] ?? DEFAULT_DENOMINATIONS;
  const covering = bills.filter((d) => d >= total).slice(0, 3);
  if (covering.length > 0) return covering;

  // Total exceeds every standard bill — suggest round-up multiples of the
  // largest bill so there's always a "give this much" option.
  const largest = bills[bills.length - 1] ?? DEFAULT_DENOMINATIONS[DEFAULT_DENOMINATIONS.length - 1];
  if (!largest || total <= 0) return [];

  const firstMultiple = Math.ceil(total / largest) * largest;
  return [firstMultiple, firstMultiple + largest, firstMultiple + largest * 2];
}
