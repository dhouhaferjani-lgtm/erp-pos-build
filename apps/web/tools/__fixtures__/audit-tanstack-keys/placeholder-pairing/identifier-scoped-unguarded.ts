// FALSE-NEGATIVE CLASS 1 (gate MAJOR-A): the key is scoped by a bare approved
// identifier (`currentCompanyId`) rather than by `tenantScopedKey(...)`. That
// shape is legal per APPROVED_SCOPE_IDENTIFIERS, carries the company suffix,
// and therefore leaks the previous company's rows through `placeholderData`
// exactly like a `tenantScopedKey` read does.
// Expected: 1 finding (placeholder pairing).
import { keepPreviousData, useQuery } from '@tanstack/react-query'

export function PaymentsList({ page }: { page: number }) {
  const currentCompanyId = useCompanyId()
  const { data, isPlaceholderData } = useQuery({
    queryKey: ['payments', page, currentCompanyId],
    queryFn: () => fetchPayments(page),
    placeholderData: keepPreviousData,
  })
  return { rows: data ?? [], pending: isPlaceholderData }
}
