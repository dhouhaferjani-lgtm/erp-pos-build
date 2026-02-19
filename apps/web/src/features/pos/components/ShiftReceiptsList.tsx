import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { cn } from '@/lib/utils'
import { textColors, borderColors } from '@/lib/designTokens'
import { useReceiptPrint } from '../hooks/useReceiptPrint'

export interface ShiftReceipt {
  id: string
  receipt_number: string
  total: string
  created_at: string
  is_voided: boolean
}

export interface ShiftReceiptsListProps {
  receipts: ShiftReceipt[]
  className?: string
}

/**
 * ShiftReceiptsList - Display receipts in shift report with reprint functionality
 *
 * This component shows all receipts created during a shift and provides
 * a reprint button for each receipt.
 *
 * Usage:
 * ```tsx
 * <ShiftReceiptsList receipts={shiftReceipts} />
 * ```
 */
export function ShiftReceiptsList({ receipts, className }: ShiftReceiptsListProps) {
  const { t } = useTranslation(['pos'])
  const { printReceipt, isPrinting } = useReceiptPrint()

  const handleReprint = (receiptId: string) => {
    void printReceipt(receiptId)
  }

  const formatDateTime = (isoString: string) => {
    const date = new Date(isoString)
    return date.toLocaleString()
  }

  return (
    <div className={cn('overflow-auto', className)}>
      <table className={cn('min-w-full divide-y', borderColors.default)}>
        <thead className="bg-gray-50">
          <tr>
            <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('shift.receiptNumber')}
            </th>
            <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('shift.time')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('shift.total')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('shift.actions')}
            </th>
          </tr>
        </thead>
        <tbody className={cn('bg-white divide-y', borderColors.default)}>
          {receipts.map((receipt) => (
            <tr
              key={receipt.id}
              className={cn(
                'hover:bg-gray-50',
                receipt.is_voided && 'opacity-50 line-through'
              )}
            >
              <td className="px-6 py-4 whitespace-nowrap">
                <span className={cn('text-sm font-medium', textColors.primary)}>
                  {receipt.receipt_number}
                </span>
                {receipt.is_voided && (
                  <span className={cn('ms-2 text-xs font-semibold', textColors.error)}>
                    ({t('pos:receiptSearch.voided')})
                  </span>
                )}
              </td>
              <td className="px-6 py-4 whitespace-nowrap">
                <span className={cn('text-sm', textColors.secondary)}>
                  {formatDateTime(receipt.created_at)}
                </span>
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-end">
                <span className={cn('text-sm font-medium', textColors.primary)}>
                  {receipt.total} TND
                </span>
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-end">
                <button
                  onClick={() => {
                    handleReprint(receipt.id)
                  }}
                  disabled={isPrinting || receipt.is_voided}
                  className={cn(
                    'inline-flex items-center gap-1 px-3 py-1 rounded',
                    'text-sm font-medium transition-colors',
                    receipt.is_voided || isPrinting
                      ? 'text-gray-400 cursor-not-allowed'
                      : 'text-blue-600 hover:text-blue-800 hover:bg-blue-50'
                  )}
                  title={t('receipt.reprint')}
                >
                  <Printer className="w-4 h-4" />
                  <span>{t('receipt.reprint')}</span>
                </button>
              </td>
            </tr>
          ))}
          {receipts.length === 0 && (
            <tr>
              <td
                colSpan={4}
                className={cn('px-6 py-8 text-center text-sm', textColors.tertiary)}
              >
                {t('pos:shiftHistory.noReceipts')}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}
