/**
 * Bank Reconciliation API Functions
 * Treasury Module - Bank Reconciliation Features
 */

import { apiGet, apiPost } from '@/lib/api'
import type {
  BankReconciliation,
  ReconciliationSummary,
  StartReconciliationRequest,
  MatchItemRequest,
  BankReconciliationItem,
  PaymentRepository,
} from '@/types/treasury'

/**
 * List all reconciliations for the current company.
 *
 * GET /api/v1/bank-reconciliations
 *
 * @param filters - Optional filters (repository_id, status)
 */
export async function listReconciliations(filters?: {
  repository_id?: string;
  status?: string;
}): Promise<BankReconciliation[]> {
  const params = new URLSearchParams()
  if (filters?.repository_id) {
    params.append('repository_id', filters.repository_id)
  }
  if (filters?.status) {
    params.append('status', filters.status)
  }
  const queryString = params.toString()
  const url = queryString ? `/bank-reconciliations?${queryString}` : '/bank-reconciliations'
  return apiGet<BankReconciliation[]>(url)
}

/**
 * Get a single reconciliation with its items.
 *
 * GET /api/v1/bank-reconciliations/{id}
 */
export async function getReconciliation(id: string): Promise<BankReconciliation> {
  return apiGet<BankReconciliation>(`/bank-reconciliations/${id}`)
}

/**
 * Get reconciliation summary (stats, can_complete, etc.)
 *
 * GET /api/v1/bank-reconciliations/{id}/summary
 */
export async function getReconciliationSummary(id: string): Promise<ReconciliationSummary> {
  return apiGet<ReconciliationSummary>(`/bank-reconciliations/${id}/summary`)
}

/**
 * Start a new reconciliation session.
 *
 * POST /api/v1/bank-reconciliations
 */
export async function startReconciliation(
  request: StartReconciliationRequest
): Promise<BankReconciliation> {
  return apiPost<BankReconciliation>('/bank-reconciliations', request)
}

/**
 * Match a payment item in a reconciliation.
 *
 * POST /api/v1/bank-reconciliations/{reconciliationId}/match/{paymentId}
 */
export async function matchItem(
  reconciliationId: string,
  paymentId: string,
  request?: MatchItemRequest
): Promise<BankReconciliationItem> {
  return apiPost<BankReconciliationItem>(
    `/bank-reconciliations/${reconciliationId}/match/${paymentId}`,
    request ?? {}
  )
}

/**
 * Unmatch a payment item in a reconciliation.
 *
 * POST /api/v1/bank-reconciliations/{reconciliationId}/unmatch/{paymentId}
 */
export async function unmatchItem(
  reconciliationId: string,
  paymentId: string
): Promise<BankReconciliationItem> {
  return apiPost<BankReconciliationItem>(
    `/bank-reconciliations/${reconciliationId}/unmatch/${paymentId}`,
    {}
  )
}

/**
 * Complete a reconciliation.
 *
 * POST /api/v1/bank-reconciliations/{id}/complete
 */
export async function completeReconciliation(id: string): Promise<BankReconciliation> {
  return apiPost<BankReconciliation>(
    `/bank-reconciliations/${id}/complete`,
    {}
  )
}

/**
 * Cancel a reconciliation.
 *
 * POST /api/v1/bank-reconciliations/{id}/cancel
 */
export async function cancelReconciliation(id: string): Promise<BankReconciliation> {
  return apiPost<BankReconciliation>(
    `/bank-reconciliations/${id}/cancel`,
    {}
  )
}

/**
 * List payment repositories.
 *
 * GET /api/v1/payment-repositories
 */
export async function listRepositories(): Promise<PaymentRepository[]> {
  return apiGet<PaymentRepository[]>('/payment-repositories')
}
