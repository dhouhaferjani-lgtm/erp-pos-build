import { useQuery } from '@tanstack/react-query'
import { progressionApi } from '../api/progressionApi'
import type { CompanyProfile, Milestone } from '../api/types'

/**
 * Query key factory for progression queries.
 */
export const progressionKeys = {
  all: ['progression'] as const,
  profile: () => [...progressionKeys.all, 'profile'] as const,
  milestones: () => [...progressionKeys.all, 'milestones'] as const,
  modules: () => [...progressionKeys.all, 'modules'] as const,
  recommendations: () => [...progressionKeys.all, 'recommendations'] as const,
}

/**
 * Fetch the company's growth profile.
 */
export function useCompanyProfile() {
  return useQuery<CompanyProfile>({
    queryKey: progressionKeys.profile(),
    queryFn: progressionApi.getProfile,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}

/**
 * Fetch all milestones for the company.
 */
export function useMilestones() {
  return useQuery<Milestone[]>({
    queryKey: progressionKeys.milestones(),
    queryFn: progressionApi.getMilestones,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}
