// NEGATIVE fixture: every legitimate pairing shape must stay silent.
//   1. plain destructured `isPlaceholderData` (the two shipped list pages),
//   2. a RENAMED destructured flag,
//   3. a whole-result binding read as `result.isPlaceholderData`,
//   4. a `useQueries` entry paired through its array-destructured element,
//   5. an identifier-scoped key paired the same way.
// Expected: 0 findings.
import { keepPreviousData, useQueries, useQuery } from '@tanstack/react-query'

import { usePlaceholderScopeGuard } from '../../hooks/usePlaceholderScopeGuard'

export function PlainPairing({ page }: { page: number }) {
  const { data, isPlaceholderData } = useQuery({
    queryKey: tenantScopedKey(['payments', page]),
    queryFn: () => fetchPayments(page),
    placeholderData: keepPreviousData,
  })
  const isStaleScopeData = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined)
  return { rows: isStaleScopeData ? [] : data ?? [] }
}

export function RenamedPairing({ page }: { page: number }) {
  const { data, isPlaceholderData: isMovementsPlaceholder } = useQuery({
    queryKey: locationScopedKey(['stock-movements', page], scope),
    queryFn: () => fetchMovements(page),
    placeholderData: keepPreviousData,
  })
  const isStaleScopeData = usePlaceholderScopeGuard(isMovementsPlaceholder, data !== undefined, [
    normalizeViewScope(scope),
  ])
  return { rows: isStaleScopeData ? [] : data ?? [] }
}

export function WholeResultPairing({ page }: { page: number }) {
  const result = useQuery({
    queryKey: tenantScopedKey(['invoices', page]),
    queryFn: () => fetchInvoices(page),
    placeholderData: keepPreviousData,
  })
  const isStaleScopeData = usePlaceholderScopeGuard(
    result.isPlaceholderData,
    result.data !== undefined,
  )
  return { rows: isStaleScopeData ? [] : result.data ?? [] }
}

export function UseQueriesPairing({ page }: { page: number }) {
  const [totals, movements] = useQueries({
    queries: [
      {
        queryKey: tenantScopedKey(['dashboard-totals']),
        queryFn: () => fetchTotals(),
      },
      {
        queryKey: tenantScopedKey(['dashboard-movements', page]),
        queryFn: () => fetchMovements(page),
        placeholderData: keepPreviousData,
      },
    ],
  })
  const isStaleScopeData = usePlaceholderScopeGuard(
    movements.isPlaceholderData,
    movements.data !== undefined,
  )
  return { totals: totals.data, movements: isStaleScopeData ? [] : movements.data }
}

export function IdentifierScopedPairing({ page }: { page: number }) {
  const currentCompanyId = useCompanyId()
  const { data, isPlaceholderData } = useQuery({
    queryKey: ['receipts', page, currentCompanyId],
    queryFn: () => fetchReceipts(page),
    placeholderData: keepPreviousData,
  })
  const isStaleScopeData = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined)
  return { rows: isStaleScopeData ? [] : data ?? [] }
}
