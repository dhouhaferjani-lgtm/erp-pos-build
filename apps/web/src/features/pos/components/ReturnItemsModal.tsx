import { useState, useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { getReceiptDetail, processReturn } from '../api/receiptApi'
import type { ProcessReturnRequest } from '../api/receiptApi'
import { Loader2, RotateCcw, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useCurrency } from '@/hooks/useCurrency'

interface ReturnLineState {
  lineId: string
  productName: string
  originalQuantity: number
  returnQuantity: number
  maxReturnable: number
  unitPrice: string
  lineTotal: string
  selected: boolean
}

interface ReturnItemsModalProps {
  isOpen: boolean
  onClose: () => void
  receiptId: string
  terminalId: string
  onSuccess: () => void
}

const RETURN_REASONS = [
  'defective',
  'wrong_item',
  'customer_changed_mind',
  'other',
] as const

type ReturnReasonValue = typeof RETURN_REASONS[number]

export function ReturnItemsModal({
  isOpen,
  onClose,
  receiptId,
  terminalId,
  onSuccess,
}: ReturnItemsModalProps) {
  const { t } = useTranslation(['pos', 'common'])
  const { decimals } = useCurrency()
  const [returnLines, setReturnLines] = useState<ReturnLineState[]>([])
  const [returnReason, setReturnReason] = useState<ReturnReasonValue>('customer_changed_mind')
  const [notes, setNotes] = useState('')

  const { data: receipt, isLoading } = useQuery({
    queryKey: ['pos', 'receipt-detail', receiptId],
    queryFn: () => getReceiptDetail(receiptId),
    enabled: isOpen && !!receiptId,
  })

  // Initialize return lines when receipt loads
  useEffect(() => {
    if (receipt?.lines) {
      setReturnLines(
        receipt.lines
          .filter((line) => parseFloat(line.quantity) > 0) // Only sale lines (positive qty)
          .map((line) => {
            const maxReturnable =
              parseFloat(line.quantity) - parseFloat(line.returned_quantity ?? '0')
            return {
              lineId: line.id,
              productName: line.product_name,
              originalQuantity: parseFloat(line.quantity),
              returnQuantity: 0,
              maxReturnable,
              unitPrice: line.unit_price,
              lineTotal: line.line_total,
              selected: false,
            }
          })
          .filter((line) => line.maxReturnable > 0) // Hide fully returned lines
      )
    }
  }, [receipt])

  const returnMutation = useMutation({
    mutationFn: (data: ProcessReturnRequest) =>
      processReturn(receiptId, data),
    onSuccess: () => {
      onSuccess()
      onClose()
    },
  })

  const toggleLine = (lineId: string) => {
    setReturnLines((prev) =>
      prev.map((line) =>
        line.lineId === lineId
          ? {
              ...line,
              selected: !line.selected,
              returnQuantity: !line.selected ? line.maxReturnable : 0,
            }
          : line
      )
    )
  }

  const updateQuantity = (lineId: string, quantity: number) => {
    setReturnLines((prev) =>
      prev.map((line) =>
        line.lineId === lineId
          ? {
              ...line,
              returnQuantity: Math.min(Math.max(0, quantity), line.maxReturnable),
              selected: quantity > 0,
            }
          : line
      )
    )
  }

  const selectedLines = useMemo(
    () => returnLines.filter((line) => line.selected && line.returnQuantity > 0),
    [returnLines]
  )

  const returnTotal = useMemo(() => {
    return selectedLines.reduce((sum, line) => {
      const ratio = line.returnQuantity / line.originalQuantity
      const lineReturn = parseFloat(line.lineTotal) * ratio
      return sum + lineReturn
    }, 0)
  }, [selectedLines])

  const handleSubmit = () => {
    if (selectedLines.length === 0) return

    returnMutation.mutate({
      terminal_id: terminalId,
      return_reason: returnReason,
      lines: selectedLines.map((line) => ({
        line_id: line.lineId,
        quantity: line.returnQuantity.toFixed(decimals),
      })),
      notes: notes || undefined,
    })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('pos:returns.title')}
      size="lg"
    >
      <ModalContent>
        {isLoading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          </div>
        ) : !receipt ? (
          <div className="text-center py-8">
            <AlertTriangle className="h-8 w-8 text-yellow-500 mx-auto mb-2" />
            <p className="text-gray-500">{t('pos:returns.receiptNotFound')}</p>
          </div>
        ) : (
          <div className="space-y-6">
            {/* Original receipt info */}
            <div className="bg-gray-50 rounded-lg p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-900">
                    {t('pos:returns.originalReceipt')}
                  </p>
                  <p className="text-sm font-mono text-gray-600">
                    {receipt.receipt_number}
                  </p>
                </div>
                <div className="text-right">
                  <p className="text-sm text-gray-500">{t('pos:receiptSearch.total')}</p>
                  <p className="text-lg font-semibold text-gray-900">
                    {receipt.total} {receipt.currency}
                  </p>
                </div>
              </div>
            </div>

            {/* Return reason */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('pos:returns.reason')}
              </label>
              <select
                className="w-full rounded-md border-gray-300 text-sm"
                value={returnReason}
                onChange={(e) => { setReturnReason(e.target.value as ReturnReasonValue); }}
              >
                {RETURN_REASONS.map((reason) => (
                  <option key={reason} value={reason}>
                    {t(`pos:returns.reasons.${reason}`)}
                  </option>
                ))}
              </select>
            </div>

            {/* Line items */}
            <div>
              <p className="text-sm font-medium text-gray-700 mb-2">
                {t('pos:returns.selectItems')}
              </p>
              <div className="space-y-2 max-h-64 overflow-y-auto">
                {returnLines.map((line) => (
                  <div
                    key={line.lineId}
                    className={cn(
                      'flex items-center gap-3 p-3 rounded-lg border transition-colors',
                      line.selected
                        ? 'border-blue-300 bg-blue-50'
                        : 'border-gray-200 bg-white hover:bg-gray-50'
                    )}
                  >
                    <input
                      type="checkbox"
                      checked={line.selected}
                      onChange={() => { toggleLine(line.lineId); }}
                      className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                    />
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-900 truncate">
                        {line.productName}
                      </p>
                      <p className="text-xs text-gray-500">
                        {line.unitPrice} x {line.originalQuantity} = {line.lineTotal}
                      </p>
                    </div>
                    {line.selected && (
                      <div className="flex items-center gap-2">
                        <label className="text-xs text-gray-500">
                          {t('pos:returns.qty')}
                        </label>
                        <input
                          type="number"
                          min={0.001}
                          max={line.maxReturnable}
                          step={1}
                          value={line.returnQuantity}
                          onChange={(e) =>
                            { updateQuantity(line.lineId, parseFloat(e.target.value) || 0); }
                          }
                          className="w-20 rounded-md border-gray-300 text-sm text-center"
                        />
                        <span className="text-xs text-gray-400">
                          / {line.maxReturnable}
                        </span>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>

            {/* Notes */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('pos:returns.notes')}
              </label>
              <textarea
                className="w-full rounded-md border-gray-300 text-sm"
                rows={2}
                placeholder={t('pos:returns.notesPlaceholder')}
                value={notes}
                onChange={(e) => { setNotes(e.target.value); }}
              />
            </div>

            {/* Return summary */}
            {selectedLines.length > 0 && (
              <div className="bg-red-50 rounded-lg p-4 border border-red-200">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-red-800">
                      {t('pos:returns.refundSummary')}
                    </p>
                    <p className="text-xs text-red-600">
                      {t('pos:returns.itemCount', { count: selectedLines.length })}
                    </p>
                  </div>
                  <p className="text-lg font-bold text-red-700">
                    -{returnTotal.toFixed(decimals)} {receipt.currency}
                  </p>
                </div>
              </div>
            )}

            {/* Error message */}
            {returnMutation.isError && (
              <div className="bg-red-50 border border-red-200 rounded-lg p-3">
                <p className="text-sm text-red-700">
                  {returnMutation.error instanceof Error
                    ? returnMutation.error.message
                    : t('pos:returns.error')}
                </p>
              </div>
            )}
          </div>
        )}
      </ModalContent>
      <ModalFooter>
        <button
          type="button"
          onClick={onClose}
          className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          {t('common:actions.cancel')}
        </button>
        <button
          type="button"
          onClick={handleSubmit}
          disabled={selectedLines.length === 0 || returnMutation.isPending}
          className="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {returnMutation.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" />
          ) : (
            <RotateCcw className="h-4 w-4" />
          )}
          {returnMutation.isPending
            ? t('common:status.processing')
            : t('pos:returns.processReturn')}
        </button>
      </ModalFooter>
    </Modal>
  )
}
