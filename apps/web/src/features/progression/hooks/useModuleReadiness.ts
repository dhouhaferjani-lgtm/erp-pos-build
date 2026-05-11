import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18next from 'i18next'
import { progressionApi } from '../api/progressionApi'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  progressionKeys,
  progressionModulesInvalidationPredicate,
  progressionProfileInvalidationPredicate,
} from './useCompanyProgression'
import type { ModuleReadiness } from '../api/types'

/**
 * Fetch all modules with their readiness status.
 */
export function useModules() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery<ModuleReadiness[]>({
    queryKey: tenantScopedKey([...progressionKeys.modules()]),
    queryFn: progressionApi.getModules,
    enabled: !!tenantId && !!companyId,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}

/**
 * Activate a module. Invalidates modules and profile queries on success.
 */
export function useActivateModule() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (moduleId: string) => progressionApi.activateModule(moduleId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: progressionModulesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: progressionProfileInvalidationPredicate(tenantId, companyId),
        }),
      ])
      toast.success(i18next.t('progression:modules.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
