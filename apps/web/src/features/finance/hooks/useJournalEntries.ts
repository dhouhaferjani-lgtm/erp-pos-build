import { useQuery } from '@tanstack/react-query'
import { getJournalEntries, getJournalEntry } from '../api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export function useJournalEntries(page = 1) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['journal-entries', page]),
    queryFn: () => getJournalEntries(page),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function useJournalEntry(id: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['journal-entry', id]),
    queryFn: () => getJournalEntry(id!),
    enabled: !!id && tenantId !== null && companyId !== null,
  })
}
