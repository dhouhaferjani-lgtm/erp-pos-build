import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  fetchTerminals,
  fetchTerminal,
  createTerminal,
  updateTerminal,
  archiveTerminal,
  deleteTerminal,
  activateTerminal,
  deactivateTerminal,
  type Terminal,
  type CreateTerminalInput,
  type UpdateTerminalInput,
  type DeactivateTerminalInput,
} from '../api/terminalApi'

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

/**
 * Fetch all terminals
 */
export function useTerminals() {
  return useQuery({
    queryKey: terminalKeys.lists(),
    queryFn: fetchTerminals,
  })
}

/**
 * Fetch a single terminal by ID
 */
export function useTerminal(id: string | undefined) {
  return useQuery({
    queryKey: terminalKeys.detail(id!),
    queryFn: () => fetchTerminal(id!),
    enabled: !!id,
  })
}

/**
 * Create a new terminal
 */
export function useCreateTerminal() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateTerminalInput) => createTerminal(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: terminalKeys.lists() })
    },
  })
}

/**
 * Update an existing terminal
 */
export function useUpdateTerminal() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTerminalInput }) =>
      updateTerminal(id, data),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: terminalKeys.lists() })
      queryClient.invalidateQueries({ queryKey: terminalKeys.detail(variables.id) })
    },
  })
}

/**
 * Archive a terminal (soft delete)
 */
export function useArchiveTerminal() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => archiveTerminal(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: terminalKeys.lists() })
    },
  })
}

/**
 * Permanently delete a terminal
 */
export function useDeleteTerminal() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => deleteTerminal(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: terminalKeys.lists() })
    },
  })
}

/**
 * Activate a terminal
 */
export function useActivateTerminal() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => activateTerminal(id),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: terminalKeys.lists() })
      queryClient.invalidateQueries({ queryKey: terminalKeys.detail(data.id) })
    },
  })
}

/**
 * Deactivate a terminal
 */
export function useDeactivateTerminal() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data?: DeactivateTerminalInput | undefined }) =>
      deactivateTerminal(id, data),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: terminalKeys.lists() })
      queryClient.invalidateQueries({ queryKey: terminalKeys.detail(data.id) })
    },
  })
}

// Export types for convenience
export type { Terminal, CreateTerminalInput, UpdateTerminalInput, DeactivateTerminalInput }
