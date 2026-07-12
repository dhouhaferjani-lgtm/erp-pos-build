import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { createJournalEntry, postJournalEntry } from '../api'
import { getErrorMessage } from '@/lib/api'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { CreateJournalEntryData } from '../api'

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
) {
  return (q: { queryKey: readonly unknown[] }) => {
    const k = q.queryKey
    return Array.isArray(k) && k[0] === namespace && k[k.length - 2] === tenantId && k[k.length - 1] === companyId
  }
}

export function useCreateJournalEntry() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (data: CreateJournalEntryData) => createJournalEntry(data),
    onSuccess: async (data) => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('journal-entries', tenantId, companyId),
      })
      toast.success('Journal entry created successfully')
      navigate(`/finance/journal-entries/${data.id}`)
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function usePostJournalEntry() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => postJournalEntry(id),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('journal-entries', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['journal-entry', data.id] }),
      ])
      toast.success('Journal entry posted successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
