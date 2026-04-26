import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
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

  return useMutation({
    mutationFn: () => closeInvoiceWithTolerance(invoiceId),
    onSuccess: (response) => {
      void queryClient.invalidateQueries({ queryKey: ['document', 'invoice', invoiceId] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      onSuccess?.(response)
    },
    onError: (error: AxiosError<CloseWithToleranceErrorBody>) => {
      onError?.(error)
    },
  })
}
