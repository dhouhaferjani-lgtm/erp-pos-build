import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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

const BUNDLES_KEY = ['workshop-bundles'] as const

export function useBundles(params: ListBundlesParams = {}) {
  return useQuery({
    queryKey: [...BUNDLES_KEY, 'list', params],
    queryFn: () => listBundles(params),
  })
}

export function useBundle(id: string | undefined) {
  return useQuery({
    queryKey: [...BUNDLES_KEY, 'detail', id],
    queryFn: () => {
      if (id === undefined) {
        throw new Error('Bundle id required')
      }
      return getBundle(id)
    },
    enabled: id !== undefined,
  })
}

export function useCreateBundle() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateBundlePayload) => createBundle(payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: BUNDLES_KEY })
    },
  })
}

export function useUpdateBundle(id: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: UpdateBundlePayload) => updateBundle(id, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: BUNDLES_KEY })
    },
  })
}

export function useDeleteBundle() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteBundle(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: BUNDLES_KEY })
    },
  })
}

export function useApplicableBundles(params: ApplicableBundlesParams = {}) {
  return useQuery({
    queryKey: [...BUNDLES_KEY, 'applicable', params],
    queryFn: () => listApplicableBundles(params),
  })
}

export function useBundleExpansion(
  bundleId: string | undefined,
  qty = '1',
  vehicleId?: string,
) {
  return useQuery({
    queryKey: [...BUNDLES_KEY, 'expansion', bundleId, qty, vehicleId],
    queryFn: () => {
      if (bundleId === undefined) {
        throw new Error('Bundle id required')
      }
      return getBundleExpansion(bundleId, qty, vehicleId)
    },
    enabled: bundleId !== undefined,
  })
}
