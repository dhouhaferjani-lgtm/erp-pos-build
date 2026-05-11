import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
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

export const MEMBERS_KEY = ['loyalty-members'] as const

export function membersInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'loyalty-members' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useMembers(params: MemberListParams = {}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...MEMBERS_KEY, params]),
    queryFn: () => listMembers(params),
    enabled: !!tenantId && !!companyId,
  })
}

export function useMember(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...MEMBERS_KEY, id]),
    queryFn: () => getMember(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useCreateMember() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (data: CreateMemberData) => createMember(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: membersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateMember() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMemberData }) => updateMember(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: membersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useEnrollments(memberId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...MEMBERS_KEY, memberId, 'enrollments']),
    queryFn: () => listEnrollments(memberId),
    enabled: !!memberId && !!tenantId && !!companyId,
  })
}

export function useEnrollMember() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ memberId, programId }: { memberId: string; programId: string }) =>
      enrollMember(memberId, programId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: membersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.enrolled'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useOptOutEnrollment() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ memberId, enrollmentId }: { memberId: string; enrollmentId: string }) =>
      optOutEnrollment(memberId, enrollmentId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: membersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.optedOut'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useReactivateEnrollment() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ memberId, enrollmentId }: { memberId: string; enrollmentId: string }) =>
      reactivateEnrollment(memberId, enrollmentId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: membersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.reactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useTransactions(memberId: string, enrollmentId: string, page: number = 1) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([
      ...MEMBERS_KEY,
      memberId,
      'enrollments',
      enrollmentId,
      'transactions',
      page,
    ]),
    queryFn: () => listTransactions(memberId, enrollmentId, page),
    enabled: !!memberId && !!enrollmentId && !!tenantId && !!companyId,
  })
}

export function useAdjustPoints() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

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
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: membersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.pointsAdjusted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
