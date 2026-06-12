import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { getVerticals, updateVerticalConfig } from '../api'
import type { UpdateVerticalConfigRequest } from '../types'

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
    // No onError toast: VerticalConfigModal owns error display and renders
    // the server message inline (see getVerticalConfigErrorMessage in ../api).
  })
}
