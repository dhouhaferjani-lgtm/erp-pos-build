import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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

export const tableKeys = {
  all: ['tables'] as const,
  floors: () => [...tableKeys.all, 'floors'] as const,
  tables: () => [...tableKeys.all, 'list'] as const,
  tableList: (params?: TableListParams) => [...tableKeys.tables(), params] as const,
}

export function useFloors() {
  return useQuery<FloorData[]>({
    queryKey: tableKeys.floors(),
    queryFn: getFloors,
  })
}

export function useTables(params?: TableListParams) {
  return useQuery<TableData[]>({
    queryKey: tableKeys.tableList(params),
    queryFn: () => getTables(params),
  })
}

export function useCreateFloor() {
  const queryClient = useQueryClient()

  return useMutation<FloorData, Error, CreateFloorRequest>({
    mutationFn: createFloor,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.floors() })
    },
  })
}

export function useUpdateFloor() {
  const queryClient = useQueryClient()

  return useMutation<FloorData, Error, { id: string; data: UpdateFloorRequest }>({
    mutationFn: ({ id, data }) => updateFloor(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.floors() })
    },
  })
}

export function useDeleteFloor() {
  const queryClient = useQueryClient()

  return useMutation<unknown, Error, string>({
    mutationFn: deleteFloor,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.floors() })
    },
  })
}

export function useCreateTable() {
  const queryClient = useQueryClient()

  return useMutation<TableData, Error, CreateTableRequest>({
    mutationFn: createTable,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.all })
    },
  })
}

export function useUpdateTable() {
  const queryClient = useQueryClient()

  return useMutation<TableData, Error, { id: string; data: UpdateTableRequest }>({
    mutationFn: ({ id, data }) => updateTable(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.all })
    },
  })
}

export function useDeleteTable() {
  const queryClient = useQueryClient()

  return useMutation<unknown, Error, string>({
    mutationFn: deleteTable,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.all })
    },
  })
}

export function useReleaseTable() {
  const queryClient = useQueryClient()

  return useMutation<TableData, Error, string>({
    mutationFn: releaseTable,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.all })
    },
  })
}

export function useSetTableStatus() {
  const queryClient = useQueryClient()

  return useMutation<TableData, Error, { id: string; status: string }>({
    mutationFn: ({ id, status }) => setTableStatus(id, status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tableKeys.all })
    },
  })
}

export type { FloorData, TableData, CreateFloorRequest, UpdateFloorRequest, CreateTableRequest, UpdateTableRequest }
