// GATE FIX ROUND 1 — MAJOR-2: `placeholderData` reaches the read through a
// SPREAD. The property lookup on the options object matched on `p.name.text`,
// and a `SpreadAssignment` has no `name`, so the whole pairing rule was
// invisible for this shape — the cheapest accidental re-admission of the
// company-leak defect.
// Expected: 1 finding (spread options).
import { keepPreviousData, useQuery } from '@tanstack/react-query'

const listOptions = { placeholderData: keepPreviousData }

export function PaymentsList({ page }: { page: number }) {
  const { data, isPlaceholderData } = useQuery({
    queryKey: tenantScopedKey(['payments', page]),
    queryFn: () => fetchPayments(page),
    ...listOptions,
  })
  return { rows: data ?? [], pending: isPlaceholderData }
}
