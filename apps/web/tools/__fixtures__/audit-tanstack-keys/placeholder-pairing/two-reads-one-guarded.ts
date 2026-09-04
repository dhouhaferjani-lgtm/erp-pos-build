// FALSE-NEGATIVE CLASS 4: two scoped placeholder reads in ONE file, only one of
// them guarded. Per-FILE pairing saw the single guard call and cleared both.
// Expected: exactly 1 finding — the unguarded read in UnguardedList.
import { keepPreviousData, useQuery } from '@tanstack/react-query'

import { usePlaceholderScopeGuard } from '../../hooks/usePlaceholderScopeGuard'

export function GuardedList({ page }: { page: number }) {
  const { data, isPlaceholderData } = useQuery({
    queryKey: tenantScopedKey(['payments', page]),
    queryFn: () => fetchPayments(page),
    placeholderData: keepPreviousData,
  })
  const isStaleScopeData = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined)
  return { rows: isStaleScopeData ? [] : data ?? [] }
}

export function UnguardedList({ page }: { page: number }) {
  const { data, isPlaceholderData } = useQuery({
    queryKey: tenantScopedKey(['receipts', page]),
    queryFn: () => fetchReceipts(page),
    placeholderData: keepPreviousData,
  })
  return { rows: data ?? [], pending: isPlaceholderData }
}
