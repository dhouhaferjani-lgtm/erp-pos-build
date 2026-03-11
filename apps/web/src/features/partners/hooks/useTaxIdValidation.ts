import { useMutation } from '@tanstack/react-query'
import { apiPost } from '../../../lib/api'

interface TaxIdValidationResult {
  is_valid: boolean
  format: string
  errors: string[]
}

export function useTaxIdValidation() {
  return useMutation({
    mutationFn: (partnerId: string) =>
      apiPost<TaxIdValidationResult>(`/partners/${partnerId}/validate-tax-id`, {}),
  })
}
