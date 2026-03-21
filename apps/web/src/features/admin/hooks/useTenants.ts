import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  getTenants,
  getTenant,
  extendTrial,
  changePlan,
  suspendTenant,
  activateTenant,
  updateTenantExtras,
} from '../api'
import { getErrorMessage } from '@/lib/api'

export function useTenants(params?: { search?: string; status?: string }) {
  return useQuery({
    queryKey: ['admin', 'tenants', params],
    queryFn: () => getTenants(params),
  })
}

export function useTenant(id: string) {
  return useQuery({
    queryKey: ['admin', 'tenant', id],
    queryFn: () => getTenant(id),
    enabled: !!id,
  })
}

export function useExtendTrial() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ tenantId, days }: { tenantId: string; days: number }) =>
      extendTrial(tenantId, days),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenant'] })
      toast.success('Trial extended successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useChangePlan() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ tenantId, planId }: { tenantId: string; planId: string }) =>
      changePlan(tenantId, planId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenant'] })
      toast.success('Plan changed successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useSuspendTenant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      tenantId,
      reason,
    }: {
      tenantId: string
      reason?: string
    }) => suspendTenant(tenantId, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenant'] })
      toast.success('Tenant suspended successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useActivateTenant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (tenantId: string) => activateTenant(tenantId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenant'] })
      toast.success('Tenant activated successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateTenantExtras() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      tenantId,
      enabledExtras,
    }: {
      tenantId: string
      enabledExtras: string[]
    }) => updateTenantExtras(tenantId, enabledExtras),
    onSuccess: (_data, { tenantId }) => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'tenant', tenantId] })
      toast.success('Modules updated successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
