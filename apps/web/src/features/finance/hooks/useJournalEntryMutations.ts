import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { createJournalEntry, postJournalEntry } from '../api'
import { getErrorMessage } from '@/lib/api'
import type { CreateJournalEntryData } from '../api'

export function useCreateJournalEntry() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: (data: CreateJournalEntryData) => createJournalEntry(data),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
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

  return useMutation({
    mutationFn: (id: string) => postJournalEntry(id),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
      void queryClient.invalidateQueries({ queryKey: ['journal-entry', data.id] })
      toast.success('Journal entry posted successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
