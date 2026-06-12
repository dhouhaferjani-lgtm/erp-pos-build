import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import axios from 'axios'
import { getVerticals, updateVerticalConfig } from '../api'
import type { UpdateVerticalConfigRequest } from '../types'
import { getErrorMessage } from '@/lib/api'

/**
 * The verticals endpoints return validation failures as a flat
 * `{ error: string, valid_modules: string[] }` 422 body (NOT the standard
 * `{ error: { code, message } }` envelope), so getErrorMessage() cannot
 * extract it. This pulls the flat string out when present.
 */
export function getVerticalConfigErrorMessage(error: unknown): string | null {
  if (!axios.isAxiosError(error)) {
    return null
  }
  const data: unknown = error.response?.data
  if (typeof data !== 'object' || data === null || !('error' in data)) {
    return null
  }
  const { error: errorValue } = data
  return typeof errorValue === 'string' ? errorValue : null
}

export function useVerticals() {
  return useQuery({
    queryKey: ['admin', 'verticals'],
    queryFn: () => getVerticals(),
  })
}

export function useUpdateVerticalConfig() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('admin')

  return useMutation({
    mutationFn: ({
      vertical,
      payload,
    }: {
      vertical: string
      payload: UpdateVerticalConfigRequest
    }) => updateVerticalConfig(vertical, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'verticals'] })
      toast.success(t('verticals.updateSuccess'))
    },
    onError: (error) => {
      toast.error(getVerticalConfigErrorMessage(error) ?? getErrorMessage(error))
    },
  })
}
