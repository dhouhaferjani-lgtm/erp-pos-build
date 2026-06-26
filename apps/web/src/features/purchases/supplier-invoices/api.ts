/**
 * API layer for Supplier Invoices.
 *
 * Conventions:
 * - Paginated list: `api.get` (keeps `meta`) — NOT `apiGet` (which drops meta).
 * - Single-resource GET / POST actions: `apiGet` / `apiPost` (unwraps data.data).
 * - Attachments: reuse generic documents/{id}/attachments endpoints.
 * - Payment: reuse POST /payments (gated on C4 re-review — see contract).
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, apiGet, apiPost, apiDelete, authenticatedDownload } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type {
  SupplierInvoiceListResponse,
  SupplierInvoiceDetail,
  SupplierInvoiceListParams,
  CreateSupplierInvoicePayload,
  DocumentAttachment,
  RecordPaymentPayload,
  InvoiceMatch,
} from './types'

// ── Query key factories ────────────────────────────────────────────────────

export const supplierInvoiceKeys = {
  list: (params: SupplierInvoiceListParams) =>
    tenantScopedKey(['supplier-invoices', 'list', params]),
  detail: (id: string) =>
    tenantScopedKey(['supplier-invoices', 'detail', id]),
  attachments: (id: string) =>
    tenantScopedKey(['supplier-invoices', 'attachments', id]),
} as const

// ── List ───────────────────────────────────────────────────────────────────

/**
 * Paginated list hook. Uses `api.get` to preserve `meta`.
 * Do NOT switch to apiGet — it drops the meta block.
 */
export function useSupplierInvoiceList(params: SupplierInvoiceListParams) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null

  return useQuery({
    queryKey: supplierInvoiceKeys.list(params),
    queryFn: async () => {
      // Strip undefined values so axios doesn't send empty query params.
      // Backend uses cursor-based pagination — pass cursor token, not page number.
      const cleanParams: Record<string, string | number> = {}
      if (params.partner_id) cleanParams['partner_id'] = params.partner_id
      if (params.status) cleanParams['status'] = params.status
      if (params.match_status) cleanParams['match_status'] = params.match_status
      if (params.date_from) cleanParams['date_from'] = params.date_from
      if (params.date_to) cleanParams['date_to'] = params.date_to
      if (params.cursor) cleanParams['cursor'] = params.cursor

      const response = await api.get<SupplierInvoiceListResponse>('/supplier-invoices', {
        params: cleanParams,
      })
      // api.get returns the axios response; the backend wraps in { data, meta }
      // but the list response itself has its own data[] + meta pagination.
      // The response.data IS the paginated payload (not nested under data.data).
      return response.data
    },
    enabled,
  })
}

// ── Detail ─────────────────────────────────────────────────────────────────

export function useSupplierInvoiceDetail(id: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null && id !== ''

  return useQuery({
    queryKey: supplierInvoiceKeys.detail(id),
    queryFn: () => apiGet<SupplierInvoiceDetail>(`/supplier-invoices/${id}`),
    enabled,
  })
}

// ── Create ─────────────────────────────────────────────────────────────────

export function useCreateSupplierInvoice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CreateSupplierInvoicePayload) =>
      apiPost<SupplierInvoiceDetail>('/supplier-invoices', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: (q) => {
          const k = q.queryKey
          return Array.isArray(k) && k[0] === 'supplier-invoices' && k[1] === 'list'
        },
      })
    },
  })
}

// ── Match ──────────────────────────────────────────────────────────────────

export function useRematchSupplierInvoice(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    // The /match endpoint returns only the match block (InvoiceMatch), NOT the full detail.
    // Invalidate the detail query so the full invoice is re-fetched with the updated match_status.
    mutationFn: () =>
      apiPost<InvoiceMatch>(`/supplier-invoices/${id}/match`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: supplierInvoiceKeys.detail(id),
      })
    },
  })
}

// ── Post ───────────────────────────────────────────────────────────────────

export function usePostSupplierInvoice(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () =>
      apiPost<SupplierInvoiceDetail>(`/supplier-invoices/${id}/post`, {}),
    onSuccess: (updated) => {
      queryClient.setQueryData(supplierInvoiceKeys.detail(id), updated)
      void queryClient.invalidateQueries({
        predicate: (q) => {
          const k = q.queryKey
          return Array.isArray(k) && k[0] === 'supplier-invoices' && k[1] === 'list'
        },
      })
    },
  })
}

// ── Attachments ────────────────────────────────────────────────────────────

export function useSupplierInvoiceAttachments(documentId: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null && documentId !== ''

  return useQuery({
    queryKey: supplierInvoiceKeys.attachments(documentId),
    queryFn: () => apiGet<DocumentAttachment[]>(`/documents/${documentId}/attachments`),
    enabled,
  })
}

export function useUploadAttachment(documentId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('role', 'source_document')
      return apiPost<DocumentAttachment>(
        `/documents/${documentId}/attachments`,
        formData,
      )
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: supplierInvoiceKeys.attachments(documentId),
      })
    },
  })
}

export function useDeleteAttachment(documentId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (attachmentId: string) =>
      apiDelete<{ message: string }>(
        `/documents/${documentId}/attachments/${attachmentId}`,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: supplierInvoiceKeys.attachments(documentId),
      })
    },
  })
}

export function downloadAttachment(documentId: string, attachmentId: string, filename: string): Promise<void> {
  return authenticatedDownload(
    `/documents/${documentId}/attachments/${attachmentId}/download`,
    filename,
  )
}

// ── Payment ────────────────────────────────────────────────────────────────

/**
 * Record a supplier payment via POST /payments.
 *
 * ASSUMPTION (2026-06-26): Payment endpoint is gated on the C4 Codex re-review.
 * The UI shows the "Record Payment" button on posted invoices but the mutation
 * calls the existing single-payment supplier path. Confirm C4 ships before
 * enabling this in production.
 */
export function useRecordSupplierPayment(invoiceId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: RecordPaymentPayload) =>
      apiPost<{ id: string }>('/payments', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: supplierInvoiceKeys.detail(invoiceId),
      })
    },
  })
}
