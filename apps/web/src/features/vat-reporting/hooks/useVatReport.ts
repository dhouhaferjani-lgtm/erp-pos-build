import { useQuery } from '@tanstack/react-query'
import { getVatReportSummary, getVatExportFormats } from '../api'

export function useVatReport(periodId: string | undefined) {
  return useQuery({
    queryKey: ['vat-report', periodId],
    queryFn: () => getVatReportSummary(periodId!),
    enabled: !!periodId,
  })
}

export function useVatExportFormats(periodId: string | undefined) {
  return useQuery({
    queryKey: ['vat-export-formats', periodId],
    queryFn: () => getVatExportFormats(periodId!),
    enabled: !!periodId,
  })
}
