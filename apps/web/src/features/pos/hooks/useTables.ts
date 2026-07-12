import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  getFloors,
  getTables,
  createFloor,
  updateFloor,
  deleteFloor,
  createTable,
  updateTable,
  deleteTable,
  releaseTable,
  setTableStatus,
  type FloorData,
  type TableData,
  type CreateFloorRequest,
  type UpdateFloorRequest,
  type CreateTableRequest,
  type UpdateTableRequest,
  type TableListParams,
} from '../api/tableApi'
import { scopedKeyPredicate, usePosTenantScope } from './usePosTenantScope'

export const tableKeys = {
  all: ['tables'] as const,
  floors: () => [...tableKeys.all, 'floors'] as const,
  tables: () => [...tableKeys.all, 'list'] as const,
  tableList: (params?: TableListParams) => [...tableKeys.tables(), params] as const,
}

export function useFloors() {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery<FloorData[]>({
    queryKey: tenantScopedKey([...tableKeys.floors()]),
    queryFn: getFloors,
    enabled: hasTenantScope,
  })
}

export function useTables(params?: TableListParams) {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery<TableData[]>({
    queryKey: tenantScopedKey([...tableKeys.tableList(params)]),
    queryFn: () => getTables(params),
    enabled: hasTenantScope,
  })
}

export function useCreateFloor() {
  const queryClient = useQueryClient()

  return useMutation<FloorData, Error, CreateFloorRequest>({
    mutationFn: createFloor,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: [...tableKeys.floors()],
      })
    },
  })
}

export function useUpdateFloor() {
  const queryClient = useQueryClient()

  return useMutation<FloorData, Error, { id: string; data: UpdateFloorRequest }>({
    mutationFn: ({ id, data }) => updateFloor(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: [...tableKeys.floors()],
      })
    },
  })
}

export function useDeleteFloor() {
  const queryClient = useQueryClient()

  return useMutation<unknown, Error, string>({
    mutationFn: deleteFloor,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: [...tableKeys.floors()],
      })
    },
  })
}

export function useCreateTable() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<TableData, Error, CreateTableRequest>({
    mutationFn: createTable,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('tables', tenantId, companyId),
      })
    },
  })
}

export function useUpdateTable() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<TableData, Error, { id: string; data: UpdateTableRequest }>({
    mutationFn: ({ id, data }) => updateTable(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('tables', tenantId, companyId),
      })
    },
  })
}

export function useDeleteTable() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<unknown, Error, string>({
    mutationFn: deleteTable,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('tables', tenantId, companyId),
      })
    },
  })
}

export function useReleaseTable() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<TableData, Error, string>({
    mutationFn: releaseTable,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('tables', tenantId, companyId),
      })
    },
  })
}

export function useSetTableStatus() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<TableData, Error, { id: string; status: string }>({
    mutationFn: ({ id, status }) => setTableStatus(id, status),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('tables', tenantId, companyId),
      })
    },
  })
}

export type { FloorData, TableData, CreateFloorRequest, UpdateFloorRequest, CreateTableRequest, UpdateTableRequest }
