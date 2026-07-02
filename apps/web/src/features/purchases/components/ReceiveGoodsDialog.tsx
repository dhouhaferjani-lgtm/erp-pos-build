import { type FormEvent, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Button, Input, QuantityInput } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal'
import { bccomp, bcsub } from '@/lib/decimal'
import { tokens } from '@/lib/designTokens'

export interface ReceiveBatchPayload {
  batch_number: string
  expiry_date: string
}

export interface ReceiveGoodsRequest {
  quantities: Record<string, string>
  batches?: Record<string, ReceiveBatchPayload>
}

export interface ReceivableLine {
  id: string
  description: string
  product_name?: string | null
  quantity: string | number
  quantity_received?: string | number | null
  quantity_decimals?: number
  requires_batch_tracking?: boolean
}

export interface ReceivablePurchaseOrder {
  lines?: ReceivableLine[]
}

interface ReceiveLineState {
  quantity: string
  batchNumber: string
  expiryDate: string
}

function quantityScale(line: ReceivableLine): number {
  return line.quantity_decimals ?? 4
}

function decimalString(value: string | number | null | undefined): string {
  return value == null ? '0' : String(value)
}

function remainingQuantity(line: ReceivableLine): string {
  return bcsub(decimalString(line.quantity), decimalString(line.quantity_received), quantityScale(line))
}

function lineLabel(line: ReceivableLine): string {
  return line.product_name ?? line.description
}

function isBatchTracked(line: ReceivableLine): boolean {
  return line.requires_batch_tracking === true
}

function initialReceiveState(lines: ReceivableLine[]): Record<string, ReceiveLineState> {
  return Object.fromEntries(
    lines.map((line) => [
      line.id,
      {
        quantity: remainingQuantity(line),
        batchNumber: '',
        expiryDate: '',
      },
    ]),
  )
}

export function ReceiveGoodsDialog({
  isOpen,
  purchaseOrder,
  isLoading,
  onClose,
  onConfirm,
}: {
  isOpen: boolean
  purchaseOrder: ReceivablePurchaseOrder
  isLoading: boolean
  onClose: () => void
  onConfirm: (request: ReceiveGoodsRequest) => void
}) {
  const { t } = useTranslation(['sales', 'common'])
  const receivableLines = useMemo(
    () => (purchaseOrder.lines ?? []).filter((line) => bccomp(remainingQuantity(line), '0') === 1),
    [purchaseOrder.lines],
  )
  const [lineState, setLineState] = useState<Partial<Record<string, ReceiveLineState>>>(() =>
    initialReceiveState(receivableLines),
  )

  const hasPositiveQuantity = receivableLines.some((line) => {
    const quantity = lineState[line.id]?.quantity ?? '0'
    return bccomp(quantity, '0') === 1
  })

  const hasInvalidQuantity = receivableLines.some((line) => {
    const quantity = lineState[line.id]?.quantity ?? '0'
    const remaining = remainingQuantity(line)
    return bccomp(quantity, '0') === -1 || bccomp(quantity, remaining) === 1
  })

  const hasMissingBatchData = receivableLines.some((line) => {
    const state = lineState[line.id]
    const quantity = state?.quantity ?? '0'
    return (
      isBatchTracked(line) &&
      bccomp(quantity, '0') === 1 &&
      ((state?.batchNumber.trim() ?? '') === '' || (state?.expiryDate ?? '') === '')
    )
  })

  const canSubmit =
    receivableLines.length > 0 &&
    hasPositiveQuantity &&
    !hasInvalidQuantity &&
    !hasMissingBatchData &&
    !isLoading

  function updateLine(lineId: string, patch: Partial<ReceiveLineState>) {
    setLineState((current) => ({
      ...current,
      [lineId]: {
        ...(current[lineId] ?? {
          quantity: '0',
          batchNumber: '',
          expiryDate: '',
        }),
        ...patch,
      },
    }))
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canSubmit) {
      return
    }

    const quantities: Record<string, string> = {}
    const batches: Record<string, ReceiveBatchPayload> = {}

    receivableLines.forEach((line) => {
      const state = lineState[line.id]
      const quantity = state?.quantity ?? '0'
      if (bccomp(quantity, '0') !== 1) {
        return
      }

      quantities[line.id] = quantity
      if (isBatchTracked(line) && state !== undefined) {
        batches[line.id] = {
          batch_number: state.batchNumber.trim(),
          expiry_date: state.expiryDate,
        }
      }
    })

    onConfirm({
      quantities,
      ...(Object.keys(batches).length > 0 ? { batches } : {}),
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('purchaseOrders.receiveGoodsTitle')} size="xl">
      <form className="space-y-4" onSubmit={handleSubmit}>
        {receivableLines.map((line) => {
          const label = lineLabel(line)
          const remaining = remainingQuantity(line)
          const state = lineState[line.id] ?? {
            quantity: remaining,
            batchNumber: '',
            expiryDate: '',
          }

          return (
            <div key={line.id} className={tokens.card.base}>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                  <div className={tokens.label.base}>{label}</div>
                  <div className={tokens.helperText.base}>
                    {t('purchaseOrders.receive.remaining')}: {remaining}
                  </div>
                </div>
                <label className={tokens.label.base}>
                  {t('purchaseOrders.receive.quantity')}
                  <QuantityInput
                    value={state.quantity}
                    onChange={(value) => { updateLine(line.id, { quantity: value }); }}
                    decimalPlaces={quantityScale(line)}
                    min="0"
                    max={remaining}
                    aria-label={`${t('purchaseOrders.receive.quantity')} ${label}`}
                  />
                </label>
                {isBatchTracked(line) && (
                  <div className="grid grid-cols-1 gap-4 md:col-span-3 md:grid-cols-2">
                    <label className={tokens.label.base}>
                      {t('purchaseOrders.receive.batchNumber')}
                      <Input
                        value={state.batchNumber}
                        onChange={(event) => { updateLine(line.id, { batchNumber: event.target.value }); }}
                        aria-label={`${t('purchaseOrders.receive.batchNumber')} ${label}`}
                      />
                    </label>
                    <label className={tokens.label.base}>
                      {t('purchaseOrders.receive.expiryDate')}
                      <Input
                        type="date"
                        value={state.expiryDate}
                        onChange={(event) => { updateLine(line.id, { expiryDate: event.target.value }); }}
                        aria-label={`${t('purchaseOrders.receive.expiryDate')} ${label}`}
                      />
                    </label>
                  </div>
                )}
              </div>
            </div>
          )
        })}

        <div className={tokens.modal.footer}>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={!canSubmit}>
            {isLoading ? t('common:status.loading') : t('purchaseOrders.receive.submit')}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
