import { useTranslation } from 'react-i18next'
import { cn } from '../../../lib/utils'
import { bccomp } from '../../../lib/decimal'
import { textColors } from '../../../lib/designTokens'
import { DataTable, type DataTableColumn } from '../../../components/molecules'
import type { Company } from '../../../stores/companyStore'
import { formatReportCurrency } from '../pages/reportPageUtils'
import type { LedgerLine } from '../types'

interface LedgerTableProps {
  lines: LedgerLine[]
  isLoading?: boolean
  company: Company | null | undefined
}

export function LedgerTable({ lines, isLoading = false, company }: LedgerTableProps) {
  const { t } = useTranslation(['finance'])

  /**
   * Debit/credit cells are blanked when the amount is zero so a two-sided
   * ledger reads as one number per row.
   *
   * The comparison MUST be decimal-numeric, not a string compare: the API
   * emits scale-4 strings (`GeneralLedgerReportService.php` DECIMAL_SCALE = 4
   * -> `"0.0000"`), so a `value === '0.00'` guard never matches the wire format
   * and every zero cell would render `0,000 TND`. `bccomp` goes through big.js
   * (`lib/decimal.ts`) - no float ever touches the money string (rule 19).
   */
  const formatAmount = (value: string): string => {
    if (bccomp(value, '0') === 0) return ''
    return formatReportCurrency(value, company)
  }

  const columns: DataTableColumn<LedgerLine>[] = [
    {
      key: 'date',
      header: t('finance:ledger.columns.date'),
      cellClassName: 'whitespace-nowrap',
      render: (line) => new Date(line.date).toLocaleDateString(),
    },
    {
      key: 'entry_number',
      header: t('finance:ledger.columns.entryNumber'),
      cellClassName: 'whitespace-nowrap',
      render: (line) => line.entry_number,
    },
    {
      key: 'account',
      header: t('finance:ledger.columns.account'),
      cellClassName: 'whitespace-nowrap',
      render: (line) => (
        <div>
          <div className="font-medium">{line.account_code}</div>
          <div className={textColors.tertiary}>{line.account_name}</div>
        </div>
      ),
    },
    {
      key: 'description',
      header: t('finance:ledger.columns.description'),
      render: (line) => line.description,
    },
    {
      key: 'debit',
      header: t('finance:ledger.columns.debit'),
      numeric: true,
      cellClassName: 'whitespace-nowrap font-mono',
      render: (line) => formatAmount(line.debit),
    },
    {
      key: 'credit',
      header: t('finance:ledger.columns.credit'),
      numeric: true,
      cellClassName: 'whitespace-nowrap font-mono',
      render: (line) => formatAmount(line.credit),
    },
    {
      key: 'balance',
      header: t('finance:ledger.columns.balance'),
      numeric: true,
      cellClassName: cn('whitespace-nowrap font-mono', textColors.primary),
      render: (line) => formatReportCurrency(line.balance, company),
    },
  ]

  return (
    <DataTable
      columns={columns}
      data={lines}
      keyExtractor={(line) => line.id}
      isLoading={isLoading}
      emptyTitle={t('finance:ledger.empty')}
    />
  )
}
