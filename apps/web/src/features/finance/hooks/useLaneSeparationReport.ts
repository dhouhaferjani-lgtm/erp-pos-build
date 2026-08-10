import { useQuery, type UseQueryResult } from '@tanstack/react-query'

import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'

export interface UninvoicedDeliveryNoteRow {
  id: string
  document_number: string
  document_date: string
  partner_id: string
  partner_name: string
  subtotal: string
  tax_amount: string
  total: string
}

export interface InvoicedNotDeliveredRow {
  id: string
  document_number: string
  document_date: string
  partner_id: string | null
  partner_name: string | null
  total: string
  currency: string
  // 📌 NO `posted_at`. The type used to declare one; the server never emits it,
  // because `documents` has no such column — the posting instant is only
  // recoverable from the T25e audit stamp's `stamped_at`, and only for documents
  // posted after this wave. A phantom field reads as data that is merely missing.
  /** `pre_policy` when the document was posted before the policy existed. */
  policy_at_post_time: string
  policy_source_at_post_time: string
}

export interface LaneSeparationReport {
  uninvoiced_delivery_notes: UninvoicedDeliveryNoteRow[]
  uninvoiced_totals: { subtotal: string; tax_amount: string; total: string; count: number }
  invoiced_not_delivered: InvoicedNotDeliveredRow[]
  policy: string
  policy_source: 'company' | 'country' | 'system'
}

export interface LaneSeparationFilters extends Record<string, unknown> {
  from_date?: string
  to_date?: string
}

/**
 * The two mirrored lane-separation populations (DPA Wave 3 T24).
 *
 * READ ONLY, and that is a contract, not an implementation detail: the server
 * endpoint creates no journal entry, and the 418 year-end accrual lives on its
 * own explicit operator-driven action. Nothing in this hook may ever be wired
 * to a mutation.
 */
export function useLaneSeparationReport(
  filters: LaneSeparationFilters = {},
): UseQueryResult<LaneSeparationReport> {
  return useQuery({
    queryKey: tenantScopedKey(['reports', 'lane-separation', filters]),
    queryFn: () =>
      apiGet<LaneSeparationReport>('/reports/lane-separation', filters),
  })
}
