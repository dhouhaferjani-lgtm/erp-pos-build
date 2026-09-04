// GATE FIX ROUND 1 — MAJOR-1: `const results = useQueries({ queries: [A, B] })`
// binds the WHOLE results array to one identifier. The first cut of the pairing
// rule returned that identifier as the handle for EVERY entry, so a single
// guard on `results[0]` also cleared the unguarded sibling `results[1]` — the
// class-4 defect ("one guard must not clear a sibling read") surviving inside a
// single useQueries call.
// Expected: exactly 1 finding — the second entry ('receipts').
import { keepPreviousData, useQueries } from '@tanstack/react-query'

import { usePlaceholderScopeGuard } from '../../hooks/usePlaceholderScopeGuard'

export function Dashboard({ page }: { page: number }) {
  const results = useQueries({
    queries: [
      {
        queryKey: tenantScopedKey(['payments', page]),
        queryFn: () => fetchPayments(page),
        placeholderData: keepPreviousData,
      },
      {
        queryKey: tenantScopedKey(['receipts', page]),
        queryFn: () => fetchReceipts(page),
        placeholderData: keepPreviousData,
      },
    ],
  })
  const isStalePayments = usePlaceholderScopeGuard(
    results[0].isPlaceholderData,
    results[0].data !== undefined,
  )
  return {
    payments: isStalePayments ? [] : results[0].data,
    receipts: results[1].data,
  }
}
