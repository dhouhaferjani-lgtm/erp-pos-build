import { formatCurrency } from '../../../lib/format'
import type { Company } from '../../../stores/companyStore'

type ReportCompany = Pick<Company, 'currency' | 'locale'>

function padDatePart(value: number): string {
  return String(value).padStart(2, '0')
}

export function formatDateInputValue(date: Date): string {
  return [
    date.getFullYear(),
    padDatePart(date.getMonth() + 1),
    padDatePart(date.getDate()),
  ].join('-')
}

export function getTodayDateInputValue(): string {
  return formatDateInputValue(new Date())
}

export function getCurrentMonthStartInputValue(): string {
  const today = new Date()
  return formatDateInputValue(new Date(today.getFullYear(), today.getMonth(), 1))
}

export function formatReportCurrency(
  amount: string,
  company: ReportCompany | null | undefined
): string {
  const locale = company ? company.locale.replace('_', '-') : undefined

  return formatCurrency(amount, {
    currency: company?.currency ?? 'EUR',
    ...(locale ? { locale } : {}),
  })
}
