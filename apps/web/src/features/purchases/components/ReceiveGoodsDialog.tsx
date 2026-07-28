import { type FormEvent, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Button, Input, MoneyInput, QuantityInput, Select, Textarea } from '@/components/atoms'
import { ProductCell } from '@/components/molecules/line-items'
import { Modal } from '@/components/organisms/Modal'
import { bccomp, bcdiv, bcmul, bcsub, formatQuantity } from '@/lib/decimal'
import { tokens, textColors } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { useTransactionLocations } from '@/features/locations/hooks/useTransactionLocations'

export interface ReceiveBatchPayload {
  batch_number: string
  expiry_date: string
}

export interface ReceiveGoodsRequest {
  quantities: Record<string, string>
  save_as_draft?: boolean
  free_quantities?: Record<string, string>
  received_unit_prices?: Record<string, string>
  price_override_reason?: string
  batches?: Record<string, ReceiveBatchPayload>
  location_id?: string
}

export interface ReceivableLine {
  id: string
  description: string
  product_name?: string | null
  product_code?: string | null
  product_barcode?: string | null
  primary_image_url?: string | null
  quantity: string | number
  quantity_received?: string | number | null
  free_quantity?: string | number | null
  free_quantity_received?: string | number | null
  quantity_decimals?: number
  requires_batch_tracking?: boolean
  unit_price?: string | number
}

export interface ReceivablePurchaseOrder {
  currency?: string | null
  location_id?: string | null
  lines?: ReceivableLine[]
}

interface ReceiveLineState {
  quantity: string
  freeQuantity: string
  deliveredUnitPrice: string
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

function remainingFreeQuantity(line: ReceivableLine): string {
  return bcsub(decimalString(line.free_quantity), decimalString(line.free_quantity_received), quantityScale(line))
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
        freeQuantity: remainingFreeQuantity(line),
        deliveredUnitPrice: '',
        batchNumber: '',
        expiryDate: '',
      },
    ]),
  )
}

interface ReceiveGoodsDialogProps {
  isOpen: boolean
  purchaseOrder: ReceivablePurchaseOrder
  isLoading: boolean
  onClose: () => void
  onConfirm: (request: ReceiveGoodsRequest) => void
}

export function ReceiveGoodsDialog({ isOpen, purchaseOrder, isLoading, onClose, onConfirm }: ReceiveGoodsDialogProps) {
  const { t } = useTranslation(['sales', 'common'])

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('purchaseOrders.receiveGoodsTitle')} size="xl">
      <ReceiveGoodsForm
        purchaseOrder={purchaseOrder}
        isLoading={isLoading}
        onClose={onClose}
        onConfirm={onConfirm}
      />
    </Modal>
  )
}

function ReceiveGoodsForm({
  purchaseOrder,
  isLoading,
  onClose,
  onConfirm,
}: Omit<ReceiveGoodsDialogProps, 'isOpen'> & { isOpen?: never }) {
  const { t } = useTranslation(['sales', 'common'])
  const { hasPermission } = usePermissions()
  const { data: locations = [] } = useTransactionLocations()
  const canEditReceiptPrice = hasPermission('goods-receipt.edit-price')
  const currency = purchaseOrder.currency ?? 'TND'
  const receivableLines = useMemo(
    () => (purchaseOrder.lines ?? []).filter((line) =>
      bccomp(remainingQuantity(line), '0') === 1 ||
      bccomp(remainingFreeQuantity(line), '0') === 1
    ),
    [purchaseOrder.lines],
  )
  const [lineState, setLineState] = useState<Partial<Record<string, ReceiveLineState>>>(() =>
    initialReceiveState(receivableLines),
  )
  const [priceOverrideReason, setPriceOverrideReason] = useState('')
  const [destinationLocationId, setDestinationLocationId] = useState(purchaseOrder.location_id ?? '')
  const firstLocationId = locations.length > 0 ? locations[0].id : ''
  const configuredDestinationLocationId = purchaseOrder.location_id != null && purchaseOrder.location_id !== ''
    ? purchaseOrder.location_id
    : locations.find((location) => location.isDefault)?.id ?? firstLocationId
  const resolvedDestinationLocationId = destinationLocationId !== ''
    ? destinationLocationId
    : configuredDestinationLocationId

  const hasEditedUnitPrice = receivableLines.some((line) =>
    (lineState[line.id]?.deliveredUnitPrice.trim() ?? '') !== ''
  )

  const hasPositiveQuantity = receivableLines.some((line) => {
    const quantity = lineState[line.id]?.quantity ?? '0'
    const freeQuantity = lineState[line.id]?.freeQuantity ?? '0'
    return bccomp(quantity, '0') === 1 || bccomp(freeQuantity, '0') === 1
  })

  const hasInvalidQuantity = receivableLines.some((line) => {
    const quantity = lineState[line.id]?.quantity ?? '0'
    const freeQuantity = lineState[line.id]?.freeQuantity ?? '0'
    const remaining = remainingQuantity(line)
    const remainingFree = remainingFreeQuantity(line)
    return (
      bccomp(quantity, '0') === -1 ||
      bccomp(quantity, remaining) === 1 ||
      bccomp(freeQuantity, '0') === -1 ||
      bccomp(freeQuantity, remainingFree) === 1
    )
  })

  const hasMissingBatchData = receivableLines.some((line) => {
    const state = lineState[line.id]
    const quantity = state?.quantity ?? '0'
    const freeQuantity = state?.freeQuantity ?? '0'
    return (
      isBatchTracked(line) &&
      (bccomp(quantity, '0') === 1 || bccomp(freeQuantity, '0') === 1) &&
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
          freeQuantity: '0',
          deliveredUnitPrice: '',
          batchNumber: '',
          expiryDate: '',
        }),
        ...patch,
      },
    }))
  }

  function buildRequest(saveAsDraft: boolean): ReceiveGoodsRequest {
    const quantities: Record<string, string> = {}
    const freeQuantities: Record<string, string> = {}
    const receivedUnitPrices: Record<string, string> = {}
    const batches: Record<string, ReceiveBatchPayload> = {}

    receivableLines.forEach((line) => {
      const state = lineState[line.id]
      const quantity = state?.quantity ?? '0'
      const freeQuantity = state?.freeQuantity ?? '0'
      const hasPaidQuantity = bccomp(quantity, '0') === 1
      const hasFreeQuantity = bccomp(freeQuantity, '0') === 1

      if (hasPaidQuantity) {
        quantities[line.id] = quantity
        const deliveredUnitPrice = state?.deliveredUnitPrice.trim() ?? ''
        if (deliveredUnitPrice !== '') {
          receivedUnitPrices[line.id] = deliveredUnitPrice
        }
      }
      if (hasFreeQuantity) {
        freeQuantities[line.id] = freeQuantity
      }

      if ((hasPaidQuantity || hasFreeQuantity) && isBatchTracked(line) && state !== undefined) {
        batches[line.id] = {
          batch_number: state.batchNumber.trim(),
          expiry_date: state.expiryDate,
        }
      }
    })

    return {
      quantities,
      ...(resolvedDestinationLocationId !== '' ? { location_id: resolvedDestinationLocationId } : {}),
      ...(saveAsDraft ? { save_as_draft: true } : {}),
      ...(Object.keys(freeQuantities).length > 0 ? { free_quantities: freeQuantities } : {}),
      ...(Object.keys(receivedUnitPrices).length > 0 ? { received_unit_prices: receivedUnitPrices } : {}),
      ...(Object.keys(receivedUnitPrices).length > 0 && priceOverrideReason.trim() !== ''
        ? { price_override_reason: priceOverrideReason.trim() }
        : {}),
      ...(Object.keys(batches).length > 0 ? { batches } : {}),
    }
  }

  function submitRequest(saveAsDraft: boolean) {
    if (!canSubmit) {
      return
    }

    onConfirm(buildRequest(saveAsDraft))
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    submitRequest(false)
  }

  function unitPrice(line: ReceivableLine): string {
    return decimalString(line.unit_price)
  }

  function variancePercent(orderedUnitPrice: string, deliveredUnitPrice: string): string | null {
    const delivered = deliveredUnitPrice.trim()
    if (delivered === '' || bccomp(orderedUnitPrice, '0') === 0 || bccomp(delivered, orderedUnitPrice) === 0) {
      return null
    }

    const delta = bcsub(delivered, orderedUnitPrice, 6)
    return bcmul(bcdiv(delta, orderedUnitPrice, 6), '100', 1)
  }

  function signedPercent(percent: string | null): string {
    if (percent === null) {
      return t('purchaseOrders.receive.noVariance')
    }

    const formatted = formatQuantity(percent, 1)
    return `${bccomp(formatted, '0') === 1 ? '+' : ''}${formatted}%`
  }

  return (
    <form className="space-y-4" onSubmit={handleSubmit}>
        <label className={tokens.label.base}>
          {t('purchaseOrders.receive.destination')}
          <Select
            value={resolvedDestinationLocationId}
            onChange={(event) => { setDestinationLocationId(event.target.value) }}
            aria-label={t('purchaseOrders.receive.destination')}
          >
            <option value="">{t('purchaseOrders.receive.selectDestination')}</option>
            {locations.filter((location) => location.isActive).map((location) => (
              <option key={location.id} value={location.id}>{location.name}</option>
            ))}
          </Select>
        </label>
        {receivableLines.map((line) => {
          const label = lineLabel(line)
          const remaining = remainingQuantity(line)
          const remainingFree = remainingFreeQuantity(line)
          const state = lineState[line.id] ?? {
            quantity: remaining,
            freeQuantity: remainingFree,
            deliveredUnitPrice: '',
            batchNumber: '',
            expiryDate: '',
          }
          const canReceivePaid = bccomp(remaining, '0') === 1
          const canReceiveFree = bccomp(remainingFree, '0') === 1
          const orderedUnitPrice = unitPrice(line)
          const percent = variancePercent(orderedUnitPrice, state.deliveredUnitPrice)
          const varianceClass = percent === null
            ? tokens.badge.gray
            : bccomp(percent, '0') === 1 ? tokens.badge.yellow : tokens.badge.green

          return (
            <div key={line.id} className={tokens.card.base}>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-6">
                <div>
                  <ProductCell
                    product={{
                      name: label,
                      sku: line.product_code ?? null,
                      barcode: line.product_barcode ?? null,
                      primary_image_url: line.primary_image_url ?? null,
                    }}
                    size="sm"
                  />
                  <div className={tokens.helperText.base}>
                    {t('purchaseOrders.receive.remaining')}: {remaining}
                  </div>
                  {canReceiveFree && (
                    <div className={tokens.helperText.base}>
                      {t('purchaseOrders.receive.freeOrderedSummary', {
                        ordered: decimalString(line.free_quantity),
                        received: decimalString(line.free_quantity_received),
                      })}
                    </div>
                  )}
                </div>
                {canReceivePaid && (
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
                )}
                {canReceiveFree && (
                  <label className={tokens.label.base}>
                    {t('purchaseOrders.receive.freeQuantity')}
                    <QuantityInput
                      value={state.freeQuantity}
                      onChange={(value) => { updateLine(line.id, { freeQuantity: value }); }}
                      decimalPlaces={quantityScale(line)}
                      min="0"
                      max={remainingFree}
                      aria-label={`${t('purchaseOrders.receive.freeQuantity')} ${label}`}
                    />
                  </label>
                )}
                <div>
                  <div className={tokens.label.base}>{t('purchaseOrders.receive.orderedUnitPrice')}</div>
                  <div className={`mt-1 text-sm font-medium ${textColors.primary}`}>{orderedUnitPrice}</div>
                </div>
                <div>
                  {canEditReceiptPrice ? (
                    <label className={tokens.label.base}>
                      {t('purchaseOrders.receive.deliveredUnitPrice')}
                      <MoneyInput
                        value={state.deliveredUnitPrice}
                        onChange={(value) => { updateLine(line.id, { deliveredUnitPrice: value }); }}
                        currency={currency}
                        min="0"
                        placeholder={orderedUnitPrice}
                        aria-label={`${t('purchaseOrders.receive.deliveredUnitPrice')} ${label}`}
                      />
                    </label>
                  ) : (
                    <>
                      <div className={tokens.label.base}>{t('purchaseOrders.receive.deliveredUnitPrice')}</div>
                      <div className={`mt-1 text-sm font-medium ${textColors.primary}`}>{orderedUnitPrice}</div>
                      <div className={tokens.helperText.base}>{t('purchaseOrders.receive.priceEditReadOnly')}</div>
                    </>
                  )}
                </div>
                <div>
                  <div className={tokens.label.base}>{t('purchaseOrders.receive.variance')}</div>
                  <span className={`${tokens.badge.base} ${varianceClass} mt-1`}>
                    {signedPercent(percent)}
                  </span>
                </div>
                {isBatchTracked(line) && (
                  <div className="grid grid-cols-1 gap-4 md:col-span-6 md:grid-cols-2">
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

        {hasEditedUnitPrice && (
          <label className={tokens.label.base}>
            {t('purchaseOrders.receive.priceOverrideReason')}
            <Textarea
              value={priceOverrideReason}
              onChange={(event) => { setPriceOverrideReason(event.target.value); }}
              rows={3}
              aria-label={t('purchaseOrders.receive.priceOverrideReason')}
            />
          </label>
        )}

        <div className={tokens.modal.footer}>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            disabled={!canSubmit}
            onClick={() => { submitRequest(true) }}
          >
            {isLoading ? t('common:status.loading') : t('purchaseOrders.receive.saveDraft')}
          </Button>
          <Button type="submit" disabled={!canSubmit}>
            {isLoading ? t('common:status.loading') : t('purchaseOrders.receive.saveAndPost')}
          </Button>
        </div>
    </form>
  )
}
