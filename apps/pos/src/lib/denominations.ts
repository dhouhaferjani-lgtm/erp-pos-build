/** Bill denominations by currency code. */
const DENOMINATIONS: Record<string, number[]> = {
  EUR: [5, 10, 20, 50, 100],
  TND: [5, 10, 20, 50],
  GBP: [5, 10, 20, 50],
  USD: [5, 10, 20, 50, 100],
};

const DEFAULT_DENOMINATIONS = [5, 10, 20, 50, 100];

/**
 * Returns denomination buttons for a given currency and total.
 * Shows bills >= total, up to 3.
 */
export function getDenominations(currency: string, total: number): number[] {
  const bills = DENOMINATIONS[currency] ?? DEFAULT_DENOMINATIONS;
  return bills.filter((d) => d >= total).slice(0, 3);
}
