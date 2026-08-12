import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import * as api from '../api/countryDefaultsApi'
import type { TemplateDomain } from '../types'

export function useTemplates(domain: TemplateDomain) {
  return useQuery({
    queryKey: ['admin', 'country-defaults', 'templates', domain],
    queryFn: () => api.listTemplates(domain),
  })
}

export function useTemplate(id: string) {
  return useQuery({
    queryKey: ['admin', 'country-defaults', 'templates', id],
    queryFn: () => api.getTemplate(id),
    enabled: id !== '',
  })
}

export function useAssignments(domain: TemplateDomain) {
  return useQuery({
    queryKey: ['admin', 'country-defaults', 'assignments', domain],
    queryFn: () => api.listAssignments(domain),
  })
}

export function useCountryDefaultsMutation<TVariables, TResult>(
  mutationFn: (variables: TVariables) => Promise<TResult>,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    onSuccess: async () => queryClient.invalidateQueries({ queryKey: ['admin', 'country-defaults'] }),
  })
}
