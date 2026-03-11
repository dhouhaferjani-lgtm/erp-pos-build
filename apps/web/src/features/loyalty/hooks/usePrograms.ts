import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import {
  listPrograms,
  getProgram,
  createProgram,
  updateProgram,
  deleteProgram,
  activateProgram,
  deactivateProgram,
  listActivePrograms,
} from '../api/programApi'
import type { CreateProgramData, UpdateProgramData } from '../types/loyalty'

const PROGRAMS_KEY = ['loyalty-programs']

export function usePrograms() {
  return useQuery({
    queryKey: PROGRAMS_KEY,
    queryFn: listPrograms,
  })
}

export function useProgram(id: string) {
  return useQuery({
    queryKey: [...PROGRAMS_KEY, id],
    queryFn: () => getProgram(id),
    enabled: !!id,
  })
}

export function useActivePrograms() {
  return useQuery({
    queryKey: [...PROGRAMS_KEY, 'active'],
    queryFn: listActivePrograms,
  })
}

export function useCreateProgram() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateProgramData) => createProgram(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROGRAMS_KEY })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateProgram() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateProgramData }) => updateProgram(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROGRAMS_KEY })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteProgram() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteProgram(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROGRAMS_KEY })
      toast.success(i18n.t('loyalty:actions.deleted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useActivateProgram() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => activateProgram(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROGRAMS_KEY })
      toast.success(i18n.t('loyalty:actions.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeactivateProgram() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deactivateProgram(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: PROGRAMS_KEY })
      toast.success(i18n.t('loyalty:actions.deactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
