import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  cancelInvoice,
  getCanCancel,
  type CancelInvoiceRequest,
  type CancelInvoiceResponse,
  type CanCancelResponse,
} from '../api/cancelInvoice'

interface CancelErrorBody {
  error?: {
    code?: string
    message?: string
    [key: string]: unknown
  }
}

/**
 * Reusable filter for a tenant-scoped namespace.
 *
 * NOTE(chore: tanstack-helpers) — byte-identical to the copy at
 * `CreateReturnNotePage.tsx`, and to 25 others. Extracting a helper used by 26 files
 * inside a cancel-flow lane would put 26 unrelated files into this lane's diff and its
 * revert blast radius, so the deferral is deliberate and ticketed at
 * `docs/superpowers/tickets/2026-08-08-extract-scoped-namespace-predicate.md`. Keep this
 * copy byte-identical: a drifting copy turns the future extraction from a delete into a
 * merge exercise.
 */
function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * The modal's content query.
 *
 * CF-D5 and CF-D6 make `can-cancel` load-bearing for WHAT the modal renders, not just
 * for whether the Cancel button is live — which options appear, which are disabled and
 * why, whether someone already decided, and how a multi-location delivery will split.
 *
 * A `tenantScopedKey` STORAGE key (rule: storage keys get the scope, cache filters stay
 * bare literal prefixes), plus an `enabled` predicate so it does NOT fire on the six
 * non-invoice detail pages that share `DocumentActionBar`.
 */
export function useCanCancelInvoice(invoiceId: string | undefined, enabled: boolean) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['invoice-can-cancel', invoiceId]),
    queryFn: () => getCanCancel(invoiceId as string),
    enabled: enabled && !!invoiceId && tenantId !== null && companyId !== null,
  })
}

interface UseCancelInvoiceOptions {
  invoiceId: string
  onSuccess?: (response: CancelInvoiceResponse) => void
  onError?: (error: AxiosError<CancelErrorBody>) => void
}

/**
 * The guided cancel mutation.
 *
 * Invalidates `invoice`, `documents`, `return-notes` and `stock` — a successful cancel
 * with a goods-bearing decision moves ALL FOUR, and leaving stock stale would show the
 * user pre-return quantities immediately after telling them the goods came back.
 */
export function useCancelInvoice({ invoiceId, onSuccess, onError }: UseCancelInvoiceOptions) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (request: CancelInvoiceRequest) => cancelInvoice(invoiceId, request),
    onSuccess: async (response) => {
      await Promise.all([
        // Bare literal prefixes: `invalidateQueries` is a cache FILTER factory whose
        // `queryKey` is a positional PREFIX matcher, while `tenantScopedKey` appends
        // SUFFIXES — wrapping a filter is a proven no-op that `audit-tanstack-keys.mjs`
        // flags as an error.
        // THE key the invoice detail page actually stores under —
        // `tenantScopedKey(['document', 'invoice', id])` (InvoiceDetailPage.tsx). Gate
        // CF round 1, Blocker B1: this set was copied from `useReturnNotes`, whose
        // `['invoice', id]` target is `CreateReturnNotePage`'s key, not this page's.
        // `invalidateQueries.queryKey` is a positional PREFIX matcher and
        // `'document' !== 'invoice'`, so after a successful cancel the badge still read
        // *posted*, the Cancel action stayed live, and the T16 recorded-decision block —
        // the entire FE half of CF-D5's "the choice is recorded" — never appeared. The
        // `documents` predicate did not rescue it either: it requires `k[0] ===
        // 'documents'`, and this key's head is the singular `'document'`.
        queryClient.invalidateQueries({ queryKey: ['document', 'invoice', invoiceId] }),
        // Kept: this is `CreateReturnNotePage`'s key, and a cancel changes what that page
        // may return against.
        queryClient.invalidateQueries({ queryKey: ['invoice', invoiceId] }),
        queryClient.invalidateQueries({ queryKey: ['invoice-can-cancel', invoiceId] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('return-notes', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('stock', tenantId, companyId),
        }),
      ])
      onSuccess?.(response)
    },
    onError: (error: AxiosError<CancelErrorBody>) => {
      onError?.(error)
    },
  })
}

export type { CanCancelResponse }
