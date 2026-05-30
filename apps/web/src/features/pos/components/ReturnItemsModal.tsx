import { useState, useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { getReceiptDetail, processReturn } from '../api/receiptApi'
import type { ProcessReturnRequest } from '../api/receiptApi'
import { Loader2, RotateCcw, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { QuantityInput } from '@/components/atoms/QuantityInput'
import { useCurrency } from '@/hooks/useCurrency'
import { bcadd, bcmul, bcdiv, bcsub, bccomp } from '@/lib/decimal'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

/**
 * Quantity scale for returned-line quantities (matches the backend
 * inventory quantity precision — decimal(.,4)). F-FRONTEND-RETURN: the
 * returned quantity must be canonicalized at QUANTITY scale (4), NOT the
 * currency scale, so fractional-unit returns are not silently truncated.
 */
const QUANTITY_SCALE = 4

interface ReturnLineState {
  lineId: string
  productName: string
  originalQuantity: string
  /** Returned quantity as a canonical decimal string at QUANTITY_SCALE. */
  returnQuantity: string
  maxReturnable: string
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

/**
 * Trim trailing zeros (and a dangling decimal point) from a canonical quantity
 * string for compact display, e.g. "2.0000" → "2", "1.5000" → "1.5". The wire
 * payload still uses the full QUANTITY_SCALE string — this is display-only.
 */
function displayQuantity(value: string): string {
  if (!value.includes('.')) return value
  return value.replace(/\.?0+$/, '')
}

export function ReturnItemsModal({
  isOpen,
  onClose,
  receiptId,
  terminalId,
  onSuccess,
}: ReturnItemsModalProps) {
  const { t } = useTranslation(['pos', 'common'])
  const { decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [returnLines, setReturnLines] = useState<ReturnLineState[]>([])
  const [returnReason, setReturnReason] = useState<ReturnReasonValue>('customer_changed_mind')
  const [notes, setNotes] = useState('')

  const { data: receipt, isLoading } = useQuery({
    queryKey: tenantScopedKey(['pos', 'receipt-detail', receiptId]),
    queryFn: () => getReceiptDetail(receiptId),
    enabled: tenantId !== null && companyId !== null && isOpen && !!receiptId,
  })

  // Initialize return lines when receipt loads
  useEffect(() => {
    if (receipt?.lines) {
      setReturnLines(
        receipt.lines
          .filter((line) => parseFloat(line.quantity) > 0) // Only sale lines (positive qty)
          .map((line) => {
            // bc-safe at quantity scale — never a float subtraction.
            const maxReturnable = bcsub(
              line.quantity,
              line.returned_quantity ?? '0',
              QUANTITY_SCALE,
            )
            return {
              lineId: line.id,
              productName: line.product_name,
              originalQuantity: line.quantity,
              returnQuantity: '0',
              maxReturnable,
              unitPrice: line.unit_price,
              lineTotal: line.line_total,
              selected: false,
            }
          })
          .filter((line) => bccomp(line.maxReturnable, '0') > 0) // Hide fully returned lines
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
              returnQuantity: !line.selected ? line.maxReturnable : '0',
            }
          : line
      )
    )
  }

  const updateQuantity = (lineId: string, quantity: string) => {
    setReturnLines((prev) =>
      prev.map((line) => {
        if (line.lineId !== lineId) return line
        // Clamp the raw input string into [0, maxReturnable] without ever
        // entering the float pipeline. Empty / invalid input clamps to '0'.
        let next = quantity
        if (quantity.trim() === '' || bccomp(quantity, '0') < 0) {
          next = '0'
        } else if (bccomp(quantity, line.maxReturnable) > 0) {
          next = line.maxReturnable
        }
        return {
          ...line,
          returnQuantity: next,
          selected: bccomp(next, '0') > 0,
        }
      })
    )
  }

  const selectedLines = useMemo(
    () => returnLines.filter((line) => line.selected && bccomp(line.returnQuantity, '0') > 0),
    [returnLines]
  )

  const returnTotal = useMemo(() => {
    // Sum exact line refunds: lineTotal * (returnQuantity / originalQuantity).
    // Intermediate ratio kept at high precision; final sum at currency scale.
    return selectedLines.reduce((sum, line) => {
      const ratio = bcdiv(line.returnQuantity, line.originalQuantity, QUANTITY_SCALE + 4)
      const lineReturn = bcmul(line.lineTotal, ratio, decimals)
      return bcadd(sum, lineReturn, decimals)
    }, '0')
  }, [selectedLines, decimals])

  const handleSubmit = () => {
    if (selectedLines.length === 0) return

    returnMutation.mutate({
      terminal_id: terminalId,
      return_reason: returnReason,
      lines: selectedLines.map((line) => ({
        line_id: line.lineId,
        // F-FRONTEND-RETURN: send the quantity at QUANTITY scale (4), not the
        // currency scale, and as a canonical string straight from the input.
        // `bcadd(x, '0')` canonicalizes to a fixed-scale numeric string.
        quantity: bcadd(line.returnQuantity, '0', QUANTITY_SCALE),
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
                        {line.unitPrice} x {displayQuantity(line.originalQuantity)} = {line.lineTotal}
                      </p>
                    </div>
                    {line.selected && (
                      <div className="flex items-center gap-2">
                        <label className="text-xs text-gray-500">
                          {t('pos:returns.qty')}
                        </label>
                        <QuantityInput
                          decimalPlaces={QUANTITY_SCALE}
                          min="0"
                          max={line.maxReturnable}
                          value={line.returnQuantity}
                          onChange={(value) => { updateQuantity(line.lineId, value); }}
                          className="w-20 rounded-md border-gray-300 text-sm text-center"
                        />
                        <span className="text-xs text-gray-400">
                          / {displayQuantity(line.maxReturnable)}
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
                    -{returnTotal} {receipt.currency}
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
