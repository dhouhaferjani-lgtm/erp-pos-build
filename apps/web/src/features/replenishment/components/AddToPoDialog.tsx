import { useState, type FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { RequirePermission } from '@/components/auth'
import { Button } from '@/components/atoms/Button/Button'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { Modal } from '@/components/organisms/Modal/Modal'
import { PartnerPicker } from '@/components/molecules/pickers'
import { useLocations } from '@/features/locations/hooks/useLocations'
import { api, getErrorMessage } from '@/lib/api'
import { bccomp } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { tokens } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useCreatePoAction } from '../api/queries'
import type { ReplenishmentLine } from '../types'

interface DraftPurchaseOrder {
  id: string
  document_number: string | null
}

interface DraftPurchaseOrderResponse {
  data: DraftPurchaseOrder[]
}

export interface AddToPoDialogProps {
  selected: ReplenishmentLine[]
  isOpen: boolean
  onClose: () => void
}

function validQuantity(value: string): boolean {
  return /^\d+(?:\.\d{1,4})?$/.test(value) && bccomp(value, '0') > 0
}

export function AddToPoDialog({ selected, isOpen, onClose }: AddToPoDialogProps) {
  // 'sales' joins the list for `documents.draftNumberPlaceholder` — the label an
  // unnumbered DRAFT purchase order shows (R-2 / LEDGER D-T9-1).
  const { t } = useTranslation(['replenishment', 'sales'])
  const locations = (useLocations().data ?? []).filter((location) => location.isActive)
  const defaultDestinationId = locations.find((location) => location.type === 'warehouse')?.id ?? ''
  const [supplierId, setSupplierId] = useState('')
  const [destinationOverride, setDestinationOverride] = useState('')
  const [existingDocumentId, setExistingDocumentId] = useState('')
  const [quantities, setQuantities] = useState<Record<string, string>>(() => Object.fromEntries(
    selected.map((line) => [line.id, line.requested_qty ?? '1']),
  ))
  const mutation = useCreatePoAction()
  const destinationLocationId = destinationOverride || defaultDestinationId
  const drafts = useQuery({
    queryKey: tenantScopedKey(['purchase-orders', 'draft', supplierId]),
    queryFn: async () => {
      const response = await api.get<DraftPurchaseOrderResponse>('/purchase-orders', {
        params: { status: 'draft', partner_id: supplierId },
      })
      return response.data.data
    },
    enabled: supplierId !== '',
  })
  const canSubmit = supplierId !== ''
    && destinationLocationId !== ''
    && selected.length > 0
    && selected.every((line) => validQuantity(quantities[line.id] ?? ''))

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canSubmit) return
    try {
      const result = await mutation.mutateAsync({
        supplier_id: supplierId,
        destination_location_id: destinationLocationId,
        ...(existingDocumentId ? { existing_document_id: existingDocumentId } : {}),
        lines: selected.map((line) => ({ request_id: line.id, quantity: quantities[line.id] ?? '' })),
      })
      const fulfilledCount = selected.filter((line) => line.location_id === destinationLocationId).length
      const toastKey = fulfilledCount === selected.length
        ? 'dialog.po_fulfilled'
        : fulfilledCount > 0
          ? 'dialog.po_mixed'
          : 'dialog.po_sourced'
      toast.success(t(toastKey, { id: result.document_id }))
      onClose()
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  return (
    <RequirePermission permission="replenishment.process">
      <Modal isOpen={isOpen} onClose={onClose} title={t('actions.add_to_po')} size="lg">
        <form className="space-y-4" onSubmit={(event) => { void submit(event) }}>
          <div>
            <PartnerPicker
              value={supplierId === '' ? null : supplierId}
              onChange={(next) => {
                setSupplierId(next?.id ?? '')
                setExistingDocumentId('')
              }}
              partnerType="supplier"
              label={t('dialog.supplier')}
              placeholder={t('dialog.supplier')}
            />
          </div>
          <div>
            <label htmlFor="replenishment-po-destination" className={tokens.label.base}>{t('dialog.destination')}</label>
            <select
              id="replenishment-po-destination"
              value={destinationLocationId}
              onChange={(event) => { setDestinationOverride(event.target.value) }}
              className={tokens.select.base}
            >
              <option value="">{t('capture.select_location')}</option>
              {locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}
            </select>
          </div>
          <div>
            <label htmlFor="replenishment-existing-po" className={tokens.label.base}>{t('dialog.existing_po')}</label>
            <select
              id="replenishment-existing-po"
              value={existingDocumentId}
              onChange={(event) => { setExistingDocumentId(event.target.value) }}
              className={tokens.select.base}
              disabled={supplierId === '' || drafts.isLoading}
            >
              <option value="">{t('dialog.existing_po')}</option>
              {(drafts.data ?? []).map((document) => (
                <option key={document.id} value={document.id}>{document.document_number ?? t('sales:documents.draftNumberPlaceholder')}</option>
              ))}
            </select>
          </div>
          <ul className="space-y-3">
            {selected.map((line) => {
              const dp = getQuantityDecimals(line)
              const minForDp = dp === 0 ? '1' : `0.${'0'.repeat(dp - 1)}1`
              return (
                <li key={line.id}>
                  <label htmlFor={`po-qty-${line.id}`} className={tokens.label.base}>{line.product_name}</label>
                  <QuantityInput
                    id={`po-qty-${line.id}`}
                    aria-label={`${t('dialog.quantity')} ${line.product_name}`}
                    value={quantities[line.id] ?? ''}
                    onChange={(value) => { setQuantities((current) => ({ ...current, [line.id]: value })) }}
                    decimalPlaces={dp}
                    min={minForDp}
                  />
                </li>
              )
            })}
          </ul>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>{t('dialog.cancel')}</Button>
            <Button type="submit" disabled={!canSubmit || mutation.isPending}>{t('dialog.submit')}</Button>
          </div>
        </form>
      </Modal>
    </RequirePermission>
  )
}
