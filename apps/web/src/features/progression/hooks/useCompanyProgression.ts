import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { progressionApi } from '../api/progressionApi'
import type { CompanyProfile, Milestone } from '../api/types'

/**
 * Query key factory for progression queries. Returns un-scoped structural
 * prefixes; tenant + company are appended at the useQuery callsite via
 * tenantScopedKey([...]).
 */
export const progressionKeys = {
  all: ['progression'] as const,
  profile: () => [...progressionKeys.all, 'profile'] as const,
  milestones: () => [...progressionKeys.all, 'milestones'] as const,
  modules: () => [...progressionKeys.all, 'modules'] as const,
  recommendations: () => [...progressionKeys.all, 'recommendations'] as const,
}

/**
 * Predicate factories for tenant-scoped invalidation across the
 * `[progression, <kind>, ...]` namespaces. tenantScopedKey() suffixes
 * t/c on leaf keys, so a fixed wrap [...progressionKeys.modules(), t, c]
 * = [progression, modules, t, c] IS already a leaf shape — but using
 * predicates keeps a single uniform invalidation contract regardless of
 * whether the queryKey carries additional positional segments later.
 */
export function progressionProfileInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'progression' &&
      k[1] === 'profile' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function progressionModulesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'progression' &&
      k[1] === 'modules' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function progressionRecommendationsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'progression' &&
      k[1] === 'recommendations' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * Fetch the company's growth profile.
 */
export function useCompanyProfile() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery<CompanyProfile>({
    queryKey: tenantScopedKey([...progressionKeys.profile()]),
    queryFn: progressionApi.getProfile,
    enabled: !!tenantId && !!companyId,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}

/**
 * Fetch all milestones for the company.
 */
export function useMilestones() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery<Milestone[]>({
    queryKey: tenantScopedKey([...progressionKeys.milestones()]),
    queryFn: progressionApi.getMilestones,
    enabled: !!tenantId && !!companyId,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}
