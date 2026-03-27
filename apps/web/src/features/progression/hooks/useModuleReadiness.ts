import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18next from 'i18next'
import { progressionApi } from '../api/progressionApi'
import { getErrorMessage } from '@/lib/api'
import { progressionKeys } from './useCompanyProgression'
import type { ModuleReadiness } from '../api/types'

/**
 * Fetch all modules with their readiness status.
 */
export function useModules() {
  return useQuery<ModuleReadiness[]>({
    queryKey: progressionKeys.modules(),
    queryFn: progressionApi.getModules,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}

/**
 * Activate a module. Invalidates modules and profile queries on success.
 */
export function useActivateModule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (moduleId: string) => progressionApi.activateModule(moduleId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: progressionKeys.modules() })
      void queryClient.invalidateQueries({ queryKey: progressionKeys.profile() })
      toast.success(i18next.t('progression:modules.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
