import { apiGet, apiPost } from '@/lib/api'
import type {
  VatPeriod,
  VatReportSummary,
  VatExportFormat,
  VatPeriodsFilters,
} from './types'

export async function getVatPeriods(filters?: VatPeriodsFilters): Promise<VatPeriod[]> {
  const params = new URLSearchParams()

  if (filters?.year) {
    params.append('year', String(filters.year))
  }

  if (filters?.status) {
    params.append('status', filters.status)
  }

  const queryString = params.toString()
  const url = queryString ? `/vat/periods?${queryString}` : '/vat/periods'

  return apiGet<VatPeriod[]>(url)
}

export async function getVatPeriod(id: string): Promise<VatPeriod> {
  return apiGet<VatPeriod>(`/vat/periods/${id}`)
}

export async function generateVatPeriods(year: number): Promise<VatPeriod[]> {
  return apiPost<VatPeriod[]>('/vat/periods/generate', { year })
}

export async function closeVatPeriod(id: string, notes?: string): Promise<VatPeriod> {
  return apiPost<VatPeriod>(`/vat/periods/${id}/close`, { notes })
}

export async function reopenVatPeriod(id: string): Promise<VatPeriod> {
  return apiPost<VatPeriod>(`/vat/periods/${id}/reopen`)
}

export async function fileVatPeriod(id: string, filingReference?: string): Promise<VatPeriod> {
  return apiPost<VatPeriod>(`/vat/periods/${id}/file`, { filing_reference: filingReference })
}

export async function getVatReportSummary(periodId: string): Promise<VatReportSummary> {
  return apiGet<VatReportSummary>(`/vat/reports/${periodId}/summary`)
}

export async function getVatAdHocSummary(
  dateFrom: string,
  dateTo: string
): Promise<VatReportSummary> {
  return apiGet<VatReportSummary>(
    `/vat/reports/summary?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`
  )
}

export async function getVatExportFormats(periodId: string): Promise<VatExportFormat[]> {
  return apiGet<VatExportFormat[]>(`/vat/reports/${periodId}/export-formats`)
}
