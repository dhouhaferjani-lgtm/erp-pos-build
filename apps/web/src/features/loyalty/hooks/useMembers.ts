import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import {
  listMembers,
  getMember,
  createMember,
  updateMember,
  listEnrollments,
  enrollMember,
  optOutEnrollment,
  reactivateEnrollment,
  listTransactions,
  adjustPoints,
} from '../api/memberApi'
import type { MemberListParams, CreateMemberData, UpdateMemberData, AdjustPointsData } from '../types/loyalty'

const MEMBERS_KEY = ['loyalty-members']

export function useMembers(params: MemberListParams = {}) {
  return useQuery({
    queryKey: [...MEMBERS_KEY, params],
    queryFn: () => listMembers(params),
  })
}

export function useMember(id: string) {
  return useQuery({
    queryKey: [...MEMBERS_KEY, id],
    queryFn: () => getMember(id),
    enabled: !!id,
  })
}

export function useCreateMember() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateMemberData) => createMember(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MEMBERS_KEY })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateMember() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMemberData }) => updateMember(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MEMBERS_KEY })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useEnrollments(memberId: string) {
  return useQuery({
    queryKey: [...MEMBERS_KEY, memberId, 'enrollments'],
    queryFn: () => listEnrollments(memberId),
    enabled: !!memberId,
  })
}

export function useEnrollMember() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ memberId, programId }: { memberId: string; programId: string }) =>
      enrollMember(memberId, programId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MEMBERS_KEY })
      toast.success(i18n.t('loyalty:actions.enrolled'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useOptOutEnrollment() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ memberId, enrollmentId }: { memberId: string; enrollmentId: string }) =>
      optOutEnrollment(memberId, enrollmentId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MEMBERS_KEY })
      toast.success(i18n.t('loyalty:actions.optedOut'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useReactivateEnrollment() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ memberId, enrollmentId }: { memberId: string; enrollmentId: string }) =>
      reactivateEnrollment(memberId, enrollmentId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MEMBERS_KEY })
      toast.success(i18n.t('loyalty:actions.reactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useTransactions(memberId: string, enrollmentId: string, page: number = 1) {
  return useQuery({
    queryKey: [...MEMBERS_KEY, memberId, 'enrollments', enrollmentId, 'transactions', page],
    queryFn: () => listTransactions(memberId, enrollmentId, page),
    enabled: !!memberId && !!enrollmentId,
  })
}

export function useAdjustPoints() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      memberId,
      enrollmentId,
      data,
    }: {
      memberId: string
      enrollmentId: string
      data: AdjustPointsData
    }) => adjustPoints(memberId, enrollmentId, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: MEMBERS_KEY })
      toast.success(i18n.t('loyalty:actions.pointsAdjusted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
