/**
 * API layer for Supplier Invoices.
 *
 * Conventions:
 * - Paginated list: `api.get` (keeps `meta`) — NOT `apiGet` (which drops meta).
 * - Single-resource GET / POST actions: `apiGet` / `apiPost` (unwraps data.data).
 * - Attachments: reuse generic documents/{id}/attachments endpoints.
 * - Payment: reuse POST /payments (gated on C4 re-review — see contract).
 */

import { useQuery, useMutation, useQueryClient, useQueries } from '@tanstack/react-query'
import { api, apiGet, apiPost, apiDelete, authenticatedDownload } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { Document } from '../../../types/document'
import type {
  SupplierInvoiceListResponse,
  SupplierInvoiceDetail,
  SupplierInvoiceListParams,
  CreateSupplierInvoicePayload,
  LinkSupplierInvoiceReceiptsPayload,
  DuplicateSupplierInvoiceReferenceResult,
  DocumentAttachment,
  RecordPaymentPayload,
  InvoiceMatch,
  OpenPurchaseOrderForSupplierInvoice,
  PurchaseOrderForSupplierInvoice,
  PurchaseOrderReceiptLine,
} from './types'

// ── Query key factories ────────────────────────────────────────────────────

export const supplierInvoiceKeys = {
  list: (params: SupplierInvoiceListParams) =>
    ['supplier-invoices', 'list', params] as const,
  detail: (id: string) =>
    ['supplier-invoices', 'detail', id] as const,
  attachments: (id: string) =>
    ['supplier-invoices', 'attachments', id] as const,
  purchaseOrder: (id: string) =>
    ['supplier-invoices', 'purchase-order', id] as const,
  receiptLines: (purchaseOrderId: string) =>
    ['supplier-invoices', 'purchase-order', purchaseOrderId, 'receipt-lines'] as const,
  openPurchaseOrders: (partnerId: string) =>
    ['supplier-invoices', 'open-purchase-orders', partnerId] as const,
  duplicateReference: (partnerId: string, reference: string) =>
    ['supplier-invoices', 'duplicate-reference', partnerId, reference] as const,
} as const

function supplierInvoiceListInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'supplier-invoices' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

function supplierInvoiceDetailInvalidationPredicate(
  id: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k[0] === 'supplier-invoices' &&
      k[1] === 'detail' &&
      k[2] === id &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

function supplierInvoiceAttachmentsInvalidationPredicate(
  documentId: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k[0] === 'supplier-invoices' &&
      k[1] === 'attachments' &&
      k[2] === documentId &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

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
    queryKey: tenantScopedKey([...supplierInvoiceKeys.list(params)]),
    queryFn: async () => {
      // Strip undefined values so axios doesn't send empty query params.
      // Backend uses cursor-based pagination — pass cursor token, not page number.
      const cleanParams: Record<string, string | number> = {}
      if (params.partner_id) cleanParams['partner_id'] = params.partner_id
      if (params.status) cleanParams['status'] = params.status
      if (params.match_status) cleanParams['match_status'] = params.match_status
      if (params.date_from) cleanParams['date_from'] = params.date_from
      if (params.date_to) cleanParams['date_to'] = params.date_to
      if (params.search) cleanParams['search'] = params.search
      if (params.pending_receipt) cleanParams['pending_receipt'] = params.pending_receipt
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
    queryKey: tenantScopedKey([...supplierInvoiceKeys.detail(id)]),
    queryFn: () => apiGet<SupplierInvoiceDetail>(`/supplier-invoices/${id}`),
    enabled,
  })
}

// ── Create ─────────────────────────────────────────────────────────────────

export function useCreateSupplierInvoice() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: CreateSupplierInvoicePayload) =>
      apiPost<SupplierInvoiceDetail>('/supplier-invoices', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: supplierInvoiceListInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function usePurchaseOrderForSupplierInvoice(id: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null && id !== ''

  return useQuery({
    queryKey: tenantScopedKey([...supplierInvoiceKeys.purchaseOrder(id)]),
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`/purchase-orders/${id}`)
      const document = response.data.data
      return {
        id: document.id,
        document_number: document.document_number ?? document.id,
        partner_id: document.partner_id ?? '',
        partner_name: document.partner_name ?? '',
        currency: document.currency,
        lines: (document.lines ?? []).map((line) => ({
          id: line.id,
          description: line.description,
          product_id: line.product_id,
          product_name: line.product_name,
          unit_price: line.unit_price,
          tax_rate: line.tax_rate,
        })),
      } satisfies PurchaseOrderForSupplierInvoice
    },
    enabled,
  })
}

export function usePurchaseOrdersForSupplierInvoice(ids: string[]): { data: PurchaseOrderForSupplierInvoice[]; isLoading: boolean } {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null
  const uniqueIds = Array.from(new Set(ids.filter((id) => id !== '')))

  const queries = useQueries({
    queries: uniqueIds.map((id) => ({
      queryKey: tenantScopedKey([...supplierInvoiceKeys.purchaseOrder(id)]),
      queryFn: async (): Promise<PurchaseOrderForSupplierInvoice> => {
        const response = await api.get<{ data: Document }>(`/purchase-orders/${id}`)
        const document = response.data.data
        return {
          id: document.id,
          document_number: document.document_number ?? document.id,
          partner_id: document.partner_id ?? '',
          partner_name: document.partner_name ?? '',
          currency: document.currency,
          lines: (document.lines ?? []).map((line) => ({
            id: line.id,
            description: line.description,
            product_id: line.product_id,
            product_name: line.product_name,
            unit_price: line.unit_price,
            tax_rate: line.tax_rate,
          })),
        }
      },
      enabled,
    })),
  })

  return {
    data: queries
      .map((query) => query.data)
      .filter((document): document is PurchaseOrderForSupplierInvoice => document !== undefined),
    isLoading: queries.some((query) => query.isLoading),
  }
}

export function usePurchaseOrderReceiptLines(purchaseOrderId: string, enabled = true) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const queryEnabled = tenantId !== null && companyId !== null && purchaseOrderId !== '' && enabled

  return useQuery({
    queryKey: tenantScopedKey([...supplierInvoiceKeys.receiptLines(purchaseOrderId)]),
    queryFn: () =>
      apiGet<PurchaseOrderReceiptLine[]>(
        `/purchase-orders/${purchaseOrderId}/receipt-lines`,
        { uninvoiced: '1' },
      ),
    enabled: queryEnabled,
  })
}

export function usePurchaseOrderReceiptLinesForSupplierInvoice(purchaseOrderIds: string[], enabled = true) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const queryEnabled = tenantId !== null && companyId !== null && enabled
  const uniqueIds = Array.from(new Set(purchaseOrderIds.filter((id) => id !== '')))

  const queries = useQueries({
    queries: uniqueIds.map((purchaseOrderId) => ({
      queryKey: tenantScopedKey([...supplierInvoiceKeys.receiptLines(purchaseOrderId)]),
      queryFn: () =>
        apiGet<PurchaseOrderReceiptLine[]>(
          `/purchase-orders/${purchaseOrderId}/receipt-lines`,
          { uninvoiced: '1' },
        ),
      enabled: queryEnabled,
    })),
  })

  return {
    data: queries.flatMap((query) => query.data ?? []),
    isLoading: queries.some((query) => query.isLoading),
  }
}

export function useOpenPurchaseOrdersForSupplier(partnerId: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const enabled = tenantId !== null && companyId !== null && partnerId !== ''

  return useQuery({
    queryKey: tenantScopedKey([...supplierInvoiceKeys.openPurchaseOrders(partnerId)]),
    queryFn: async () => {
      const response = await api.get<{ data: Document[] }>('/purchase-orders', {
        params: {
          partner_id: partnerId,
          status: 'received',
          has_uninvoiced: '1',
          per_page: 100,
        },
      })
      return response.data.data.map((document) => ({
        id: document.id,
        document_number: document.document_number ?? document.id,
        currency: document.currency,
        total: document.total,
      } satisfies OpenPurchaseOrderForSupplierInvoice))
    },
    enabled,
  })
}

export function useDuplicateSupplierInvoiceReference(
  partnerId: string,
  reference: string,
  enabled: boolean,
) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const normalizedReference = reference.trim()
  const queryEnabled =
    tenantId !== null &&
    companyId !== null &&
    partnerId !== '' &&
    normalizedReference !== '' &&
    enabled

  return useQuery({
    queryKey: tenantScopedKey([...supplierInvoiceKeys.duplicateReference(partnerId, normalizedReference)]),
    queryFn: () =>
      apiGet<DuplicateSupplierInvoiceReferenceResult>('/supplier-invoices/duplicate-reference', {
        partner_id: partnerId,
        reference: normalizedReference,
      }),
    enabled: queryEnabled,
  })
}

// ── Match ──────────────────────────────────────────────────────────────────

export function useRematchSupplierInvoice(id: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    // The /match endpoint returns only the match block (InvoiceMatch), NOT the full detail.
    // Invalidate the detail query so the full invoice is re-fetched with the updated match_status.
    mutationFn: () =>
      apiPost<InvoiceMatch>(`/supplier-invoices/${id}/match`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: supplierInvoiceDetailInvalidationPredicate(id, tenantId, companyId),
      })
    },
  })
}

// ── Post ───────────────────────────────────────────────────────────────────

export function usePostSupplierInvoice(id: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: () =>
      apiPost<SupplierInvoiceDetail>(`/supplier-invoices/${id}/post`, {}),
    onSuccess: (updated) => {
      queryClient.setQueryData(tenantScopedKey([...supplierInvoiceKeys.detail(id)]), updated)
      void queryClient.invalidateQueries({
        predicate: supplierInvoiceListInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useLinkSupplierInvoiceReceipts(id: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: LinkSupplierInvoiceReceiptsPayload) =>
      apiPost<SupplierInvoiceDetail>(`/supplier-invoices/${id}/link-receipts`, payload),
    onSuccess: (updated) => {
      queryClient.setQueryData(tenantScopedKey([...supplierInvoiceKeys.detail(id)]), updated)
      void queryClient.invalidateQueries({
        predicate: supplierInvoiceListInvalidationPredicate(tenantId, companyId),
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
    queryKey: tenantScopedKey([...supplierInvoiceKeys.attachments(documentId)]),
    queryFn: () => apiGet<DocumentAttachment[]>(`/documents/${documentId}/attachments`),
    enabled,
  })
}

export function useUploadAttachment(documentId: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (input: File | { documentId: string; file: File }) => {
      const targetDocumentId = 'file' in input ? input.documentId : documentId
      const file = 'file' in input ? input.file : input
      const formData = new FormData()
      formData.append('file', file)
      formData.append('role', 'source_document')
      return apiPost<DocumentAttachment>(
        `/documents/${targetDocumentId}/attachments`,
        formData,
      )
    },
    onSuccess: (_attachment, input) => {
      const targetDocumentId = 'file' in input ? input.documentId : documentId
      void queryClient.invalidateQueries({
        predicate: supplierInvoiceAttachmentsInvalidationPredicate(targetDocumentId, tenantId, companyId),
      })
    },
  })
}

export function useDeleteAttachment(documentId: string) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (attachmentId: string) =>
      apiDelete<{ message: string }>(
        `/documents/${documentId}/attachments/${attachmentId}`,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: supplierInvoiceAttachmentsInvalidationPredicate(documentId, tenantId, companyId),
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: RecordPaymentPayload) =>
      apiPost<{ id: string }>('/payments', payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        predicate: supplierInvoiceDetailInvalidationPredicate(invoiceId, tenantId, companyId),
      })
    },
  })
}
