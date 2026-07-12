import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  closeInvoiceWithTolerance,
  type CloseWithToleranceResponse,
  type CloseWithToleranceErrorBody,
} from '../api/closeWithTolerance'

interface UseCloseWithToleranceOptions {
  invoiceId: string
  onSuccess?: (response: CloseWithToleranceResponse) => void
  onError?: (error: AxiosError<CloseWithToleranceErrorBody>) => void
}

export function useCloseWithTolerance({
  invoiceId,
  onSuccess,
  onError,
}: UseCloseWithToleranceOptions) {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: () => closeInvoiceWithTolerance(invoiceId),
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['document', 'invoice', invoiceId] }),
        queryClient.invalidateQueries({
          predicate: (q) => {
            const k = q.queryKey
            return Array.isArray(k) && k[0] === 'documents' && k[k.length - 2] === tenantId && k[k.length - 1] === companyId
          },
        }),
        queryClient.invalidateQueries({
          predicate: (q) => {
            const k = q.queryKey
            return Array.isArray(k) && k[0] === 'payments' && k[k.length - 2] === tenantId && k[k.length - 1] === companyId
          },
        }),
      ])
      onSuccess?.(response)
    },
    onError: (error: AxiosError<CloseWithToleranceErrorBody>) => {
      onError?.(error)
    },
  })
}
