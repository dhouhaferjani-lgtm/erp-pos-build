import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiGet, apiPatch } from '@/lib/api'

interface ContactProfile {
  id: string
  profile_metadata: {
    skin_type?: string
    hair_type?: string
    updated_at?: string
  } | null
}

export function useContactProfile(contactId: string | null | undefined) {
  const queryClient = useQueryClient()

  const { data: profile } = useQuery({
    queryKey: ['contact-profile', contactId],
    queryFn: () => apiGet<ContactProfile>(`/contacts/${contactId}`),
    enabled: !!contactId,
    staleTime: 5 * 60 * 1000,
  })

  const { mutate: updateProfileMetadata } = useMutation({
    mutationFn: (metadata: Record<string, string>) =>
      apiPatch(`/contacts/${contactId}`, {
        profile_metadata: {
          ...profile?.profile_metadata,
          ...metadata,
          updated_at: new Date().toISOString(),
        },
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['contact-profile', contactId] })
    },
  })

  return {
    profileMetadata: profile?.profile_metadata ?? null,
    updateProfileMetadata,
  }
}
