import { useQuery } from '@tanstack/react-query'
import { getVatReportSummary, getVatExportFormats } from '../api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export function useVatReport(periodId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['vat-report', periodId]),
    queryFn: () => getVatReportSummary(periodId!),
    enabled: !!periodId && tenantId !== null && companyId !== null,
  })
}

export function useVatExportFormats(periodId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['vat-export-formats', periodId]),
    queryFn: () => getVatExportFormats(periodId!),
    enabled: !!periodId && tenantId !== null && companyId !== null,
  })
}
