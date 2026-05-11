import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  fetchTerminals,
  fetchTerminal,
  createTerminal,
  updateTerminal,
  archiveTerminal,
  deleteTerminal,
  activateTerminal,
  deactivateTerminal,
  toggleTrainingMode,
  type Terminal,
  type CreateTerminalInput,
  type UpdateTerminalInput,
  type DeactivateTerminalInput,
} from '../api/terminalApi'
import { usePosTenantScope } from './usePosTenantScope'

/**
 * Query key factory for terminal-related queries
 */
export const terminalKeys = {
  all: ['terminals'] as const,
  lists: () => [...terminalKeys.all, 'list'] as const,
  list: (filters?: Record<string, unknown>) =>
    [...terminalKeys.lists(), filters] as const,
  details: () => [...terminalKeys.all, 'detail'] as const,
  detail: (id: string) => [...terminalKeys.details(), id] as const,
}

function scopedTerminalListPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'terminals' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * Fetch all terminals
 */
export function useTerminals() {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...terminalKeys.lists()]),
    queryFn: fetchTerminals,
    enabled: hasTenantScope,
  })
}

/**
 * Fetch a single terminal by ID
 */
export function useTerminal(id: string | undefined) {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...terminalKeys.detail(id!)]),
    queryFn: () => fetchTerminal(id!),
    enabled: !!id && hasTenantScope,
  })
}

/**
 * Create a new terminal
 */
export function useCreateTerminal() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (data: CreateTerminalInput) => createTerminal(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedTerminalListPredicate(tenantId, companyId),
      })
    },
  })
}

/**
 * Update an existing terminal
 */
export function useUpdateTerminal() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTerminalInput }) =>
      updateTerminal(id, data),
    onSuccess: async (_data, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedTerminalListPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...terminalKeys.detail(variables.id)]),
        }),
      ])
    },
  })
}

/**
 * Archive a terminal (soft delete)
 */
export function useArchiveTerminal() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (id: string) => archiveTerminal(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedTerminalListPredicate(tenantId, companyId),
      })
    },
  })
}

/**
 * Permanently delete a terminal
 */
export function useDeleteTerminal() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (id: string) => deleteTerminal(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedTerminalListPredicate(tenantId, companyId),
      })
    },
  })
}

/**
 * Activate a terminal
 */
export function useActivateTerminal() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (id: string) => activateTerminal(id),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedTerminalListPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...terminalKeys.detail(data.id)]),
        }),
      ])
    },
  })
}

/**
 * Deactivate a terminal
 */
export function useDeactivateTerminal() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data?: DeactivateTerminalInput | undefined }) =>
      deactivateTerminal(id, data),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedTerminalListPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...terminalKeys.detail(data.id)]),
        }),
      ])
    },
  })
}

/**
 * Toggle training mode on a terminal
 */
export function useToggleTrainingMode() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (id: string) => toggleTrainingMode(id),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedTerminalListPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...terminalKeys.detail(data.id)]),
        }),
      ])
    },
  })
}

// Export types for convenience
export type { Terminal, CreateTerminalInput, UpdateTerminalInput, DeactivateTerminalInput }
