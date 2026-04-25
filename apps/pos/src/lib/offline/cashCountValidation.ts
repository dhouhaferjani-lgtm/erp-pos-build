/**
 * FE mirror of backend CashCountValidationService severity logic.
 *
 * Algorithm (matches backend abs(signed-sum) approach):
 *   1. Aggregate all signed per-tender variance_amount strings into one signed sum.
 *   2. Determine direction from the sign: positive = over, negative = under, zero = balanced.
 *   3. Take the absolute value of the sum.
 *   4. Compare abs against the appropriate soft/hard thresholds.
 *   5. Map to severity: 0 = balanced, >0 and <=soft = info, >soft and <=hard = warning, >hard = critical.
 *
 * Closes G12 (D1).
 */

import { bcadd, bccomp, bcformat } from '@/lib/decimal';

export type Severity = 'balanced' | 'info' | 'warning' | 'critical';
export type Direction = 'over' | 'under' | 'balanced';

export interface CashCountThresholds {
  cash_variance_over_soft: string;
  cash_variance_over_hard: string;
  cash_variance_under_soft: string;
  cash_variance_under_hard: string;
}

export interface VarianceLike {
  variance_amount: string;
}

export interface SeverityResult {
  aggregateAmount: string;
  absoluteAmount: string;
  direction: Direction;
  severity: Severity;
}

export function computeCashCountSeverity(
  rows: VarianceLike[],
  settings: CashCountThresholds,
  scale: number,
): SeverityResult {
  // Step 1: aggregate all signed per-tender variances into one signed sum
  let aggregate = '0';
  for (const row of rows) {
    aggregate = bcadd(aggregate, row.variance_amount, scale);
  }

  // Step 2: determine direction from sign
  const cmp = bccomp(aggregate, '0');
  const direction: Direction = cmp === 0 ? 'balanced' : cmp > 0 ? 'over' : 'under';

  if (direction === 'balanced') {
    const zero = bcformat('0', scale);
    return {
      aggregateAmount: zero,
      absoluteAmount: zero,
      direction,
      severity: 'balanced',
    };
  }

  // Step 3: take absolute value — strip leading minus if negative
  const absoluteAmount =
    cmp < 0 && aggregate.startsWith('-')
      ? bcformat(aggregate.slice(1), scale)
      : bcformat(aggregate, scale);

  // Step 4: pick thresholds based on direction
  const soft =
    direction === 'over'
      ? settings.cash_variance_over_soft
      : settings.cash_variance_under_soft;
  const hard =
    direction === 'over'
      ? settings.cash_variance_over_hard
      : settings.cash_variance_under_hard;

  // Step 5: map to severity
  let severity: Severity;
  if (bccomp(absoluteAmount, soft) <= 0) {
    severity = 'info';
  } else if (bccomp(absoluteAmount, hard) <= 0) {
    severity = 'warning';
  } else {
    severity = 'critical';
  }

  return {
    aggregateAmount: bcformat(aggregate, scale),
    absoluteAmount,
    direction,
    severity,
  };
}
