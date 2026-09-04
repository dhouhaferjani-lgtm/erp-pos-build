// FALSE-NEGATIVE CLASS 2: the read lives in a `useQueries({ queries: [...] })`
// entry. checkOptionsObject is called with factoryName 'useQueries.queries[]',
// which the original PLACEHOLDER_BEARING_FACTORIES set did not contain, so the
// pairing rule never ran on it.
// Expected: 1 finding (placeholder pairing) — only the second entry carries
// placeholderData.
import { keepPreviousData, useQueries } from '@tanstack/react-query'

export function Dashboard({ page }: { page: number }) {
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
  return { totals: totals.data, movements: movements.data }
}
