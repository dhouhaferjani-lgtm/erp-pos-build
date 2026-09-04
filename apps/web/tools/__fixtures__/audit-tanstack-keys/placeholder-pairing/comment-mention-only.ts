// FALSE-NEGATIVE CLASS 3: the original guard check was
// `sourceFile.text.includes('usePlaceholderScopeGuard')`, so merely NAMING the
// guard in a comment — or in a string, or in a dead import — suppressed the
// rule. Nothing here calls it.
// Expected: 1 finding (placeholder pairing).
import { keepPreviousData, useQuery } from '@tanstack/react-query'

import { usePlaceholderScopeGuard } from '../../hooks/usePlaceholderScopeGuard'

const TODO = 'wire up usePlaceholderScopeGuard before shipping'

export function PaymentsList({ page }: { page: number }) {
  // We keep the previous page while the next loads. usePlaceholderScopeGuard
  // would blank it on a company switch, but it is not wired up yet.
  const { data, isPlaceholderData } = useQuery({
    queryKey: tenantScopedKey(['payments', page]),
    queryFn: () => fetchPayments(page),
    placeholderData: keepPreviousData,
  })
  return { rows: data ?? [], pending: isPlaceholderData, hint: TODO }
}
