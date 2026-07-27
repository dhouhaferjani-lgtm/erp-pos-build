import Big from 'big.js'

import { getDecimals } from '@/hooks/useCurrency'

import type { BankStatementLine } from './api'
import type { StatementLineStatus } from './api'

type RemainingLine = Pick<BankStatementLine, 'match_status' | 'direction' | 'amount' | 'allocations'>

export function remainingForLine(line: RemainingLine): Big {
  if (line.match_status === 'ignored') return new Big(0)

  let matched = new Big(0)
  for (const allocation of line.allocations) {
    matched = allocation.movement_direction === line.direction
      ? matched.plus(allocation.matched_amount)
      : matched.minus(allocation.matched_amount)
  }
  return new Big(line.amount).minus(matched)
}

export function formatAtCurrencyScale(value: string, currency: string): string {
  return new Big(value).toFixed(getDecimals(currency))
}

export function isResolvedLineStatus(status: StatementLineStatus): boolean {
  return status === 'matched' || status === 'resolved_by_creation' || status === 'ignored'
}

export function isSuccessfulLineStatus(status: StatementLineStatus): boolean {
  return status === 'matched' || status === 'resolved_by_creation'
}
