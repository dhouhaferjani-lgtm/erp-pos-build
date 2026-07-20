import type { StatementLineStatus } from './api'

export function isResolvedLineStatus(status: StatementLineStatus): boolean {
  return status === 'matched' || status === 'resolved_by_creation' || status === 'ignored'
}

export function isSuccessfulLineStatus(status: StatementLineStatus): boolean {
  return status === 'matched' || status === 'resolved_by_creation'
}
