import { useQuery } from '@tanstack/react-query'
import { getLedger, getJournalEntries } from '../api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { LedgerFilters } from '../types'

export function useLedger(filters?: LedgerFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['ledger', filters]),
    queryFn: () => getLedger(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function useJournalEntries(page = 1) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['journal-entries', page]),
    queryFn: () => getJournalEntries(page),
    enabled: tenantId !== null && companyId !== null,
  })
}
