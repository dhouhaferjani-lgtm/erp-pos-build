import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  createBundle,
  deleteBundle,
  getBundle,
  getBundleExpansion,
  listApplicableBundles,
  listBundles,
  updateBundle,
  type ApplicableBundlesParams,
  type CreateBundlePayload,
  type ListBundlesParams,
  type UpdateBundlePayload,
} from '../api/bundleApi'
import {
  addBundleComponent,
  deleteBundleComponent,
  replaceBundleApplicabilities,
  updateBundleComponent,
  type AddComponentPayload,
  type SetApplicabilitiesPayload,
  type UpdateComponentPayload,
} from '../api/bundleComponentApi'

const BUNDLES_KEY = ['workshop-bundles'] as const

function useWorkshopBundlesTenantScope(): {
  tenantId: string | null
  companyId: string | null
  hasTenantScope: boolean
} {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return {
    tenantId,
    companyId,
    hasTenantScope: tenantId !== null && companyId !== null,
  }
}

function workshopBundlesPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === BUNDLES_KEY[0] &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useBundles(params: ListBundlesParams = {}) {
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...BUNDLES_KEY, 'list', params]),
    queryFn: () => listBundles(params),
    enabled: hasTenantScope,
  })
}

export function useBundle(id: string | undefined) {
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...BUNDLES_KEY, 'detail', id]),
    queryFn: () => {
      if (id === undefined) {
        throw new Error('Bundle id required')
      }
      return getBundle(id)
    },
    enabled: id !== undefined && hasTenantScope,
  })
}

export function useCreateBundle() {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopBundlesTenantScope()

  return useMutation({
    mutationFn: (payload: CreateBundlePayload) => createBundle(payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: workshopBundlesPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateBundle(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopBundlesTenantScope()

  return useMutation({
    mutationFn: (payload: UpdateBundlePayload) => updateBundle(id, payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: workshopBundlesPredicate(tenantId, companyId),
      })
    },
  })
}

export function useDeleteBundle() {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopBundlesTenantScope()

  return useMutation({
    mutationFn: (id: string) => deleteBundle(id),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: workshopBundlesPredicate(tenantId, companyId),
      })
    },
  })
}

export function useApplicableBundles(params: ApplicableBundlesParams = {}) {
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...BUNDLES_KEY, 'applicable', params]),
    queryFn: () => listApplicableBundles(params),
    enabled: hasTenantScope,
  })
}

export function useAddBundleComponent(bundleId: string) {
  const qc = useQueryClient()
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useMutation({
    mutationFn: (payload: AddComponentPayload) => {
      if (!hasTenantScope) throw new Error('Tenant scope required')
      return addBundleComponent(bundleId, payload)
    },
    onSuccess: async () => {
      await qc.invalidateQueries({
        queryKey: tenantScopedKey([...BUNDLES_KEY, 'detail', bundleId]),
      })
    },
  })
}

export function useUpdateBundleComponent(bundleId: string) {
  const qc = useQueryClient()
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useMutation({
    mutationFn: (args: { componentId: string; payload: UpdateComponentPayload }) => {
      if (!hasTenantScope) throw new Error('Tenant scope required')
      return updateBundleComponent(bundleId, args.componentId, args.payload)
    },
    onSuccess: async () => {
      await qc.invalidateQueries({
        queryKey: tenantScopedKey([...BUNDLES_KEY, 'detail', bundleId]),
      })
    },
  })
}

export function useDeleteBundleComponent(bundleId: string) {
  const qc = useQueryClient()
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useMutation({
    mutationFn: (componentId: string) => {
      if (!hasTenantScope) throw new Error('Tenant scope required')
      return deleteBundleComponent(bundleId, componentId)
    },
    onSuccess: async () => {
      await qc.invalidateQueries({
        queryKey: tenantScopedKey([...BUNDLES_KEY, 'detail', bundleId]),
      })
    },
  })
}

export function useReplaceBundleApplicabilities(bundleId: string) {
  const qc = useQueryClient()
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useMutation({
    mutationFn: (payload: SetApplicabilitiesPayload) => {
      if (!hasTenantScope) throw new Error('Tenant scope required')
      return replaceBundleApplicabilities(bundleId, payload)
    },
    onSuccess: async () => {
      await qc.invalidateQueries({
        queryKey: tenantScopedKey([...BUNDLES_KEY, 'detail', bundleId]),
      })
    },
  })
}

export function useBundleExpansion(
  bundleId: string | undefined,
  qty = '1',
  vehicleId?: string,
) {
  const { hasTenantScope } = useWorkshopBundlesTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...BUNDLES_KEY, 'expansion', bundleId, qty, vehicleId]),
    queryFn: () => {
      if (bundleId === undefined) {
        throw new Error('Bundle id required')
      }
      return getBundleExpansion(bundleId, qty, vehicleId)
    },
    enabled: bundleId !== undefined && hasTenantScope,
  })
}
