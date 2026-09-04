// FALSE-NEGATIVE CLASS 1b: the key is scoped through the approved store object
// (`companyStore.currentCompanyId`), the other shape APPROVED_STORE_OBJECTS
// accepts. Same leak, same pairing obligation.
// Expected: 1 finding (placeholder pairing).
import { keepPreviousData, useQuery } from '@tanstack/react-query'

export function InvoiceList({ page }: { page: number }) {
  const companyStore = useCompanyStore()
  const { data, isPlaceholderData } = useQuery({
    queryKey: ['invoices', page, companyStore.currentCompanyId],
    queryFn: () => fetchInvoices(page),
    placeholderData: (previous) => previous,
  })
  return { rows: data ?? [], pending: isPlaceholderData }
}
