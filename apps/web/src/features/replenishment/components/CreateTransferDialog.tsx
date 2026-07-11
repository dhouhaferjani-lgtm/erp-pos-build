import { useMemo, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { RequirePermission } from '@/components/auth'
import { Button } from '@/components/atoms/Button/Button'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { Modal } from '@/components/organisms/Modal/Modal'
import { useLocations } from '@/features/locations/hooks/useLocations'
import { bccomp } from '@/lib/decimal'
import { getErrorMessage } from '@/lib/api'
import { textColors, tokens } from '@/lib/designTokens'
import { useCreateTransferAction } from '../api/queries'
import type { ReplenishmentLine } from '../types'

export interface CreateTransferDialogProps {
  selected: ReplenishmentLine[]
  isOpen: boolean
  onClose: () => void
}

function validQuantity(value: string): boolean {
  return /^\d+(?:\.\d{1,4})?$/.test(value) && bccomp(value, '0') > 0
}

export function CreateTransferDialog({ selected, isOpen, onClose }: CreateTransferDialogProps) {
  const { t } = useTranslation('replenishment')
  const locations = (useLocations().data ?? []).filter((location) => location.isActive)
  const mutation = useCreateTransferAction()
  const [sourceLocationId, setSourceLocationId] = useState('')
  const [quantities, setQuantities] = useState<Record<string, string>>(() => Object.fromEntries(
    selected.map((line) => [line.id, line.requested_qty ?? '1']),
  ))
  const destinationIds = useMemo(() => new Set(selected.map((line) => line.location_id)), [selected])
  const destinationGroups = useMemo(() => {
    const groups = new Map<string, { name: string; count: number }>()
    for (const line of selected) {
      const group = groups.get(line.location_id) ?? { name: line.location_name, count: 0 }
      group.count += 1
      groups.set(line.location_id, group)
    }
    return [...groups.values()]
  }, [selected])
  const source = locations.find((location) => location.id === sourceLocationId)
  const canSubmit = sourceLocationId !== ''
    && !destinationIds.has(sourceLocationId)
    && selected.length > 0
    && selected.every((line) => validQuantity(quantities[line.id] ?? ''))

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canSubmit) return
    try {
      const result = await mutation.mutateAsync({
        source_location_id: sourceLocationId,
        lines: selected.map((line) => ({ request_id: line.id, quantity: quantities[line.id] ?? '' })),
      })
      toast.success(
        <span>
          {t('dialog.transfers_created')}{' '}
          {result.transfer_ids.map((id, index) => (
            <span key={id}>
              {index > 0 ? ', ' : null}
              <a className={textColors.brand} href={`/inventory/stock-transfers/${id}`}>{id}</a>
            </span>
          ))}
        </span>,
      )
      onClose()
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  return (
    <RequirePermission permission="replenishment.process">
      <Modal isOpen={isOpen} onClose={onClose} title={t('actions.create_transfer')} size="lg">
        <form className="space-y-4" onSubmit={(event) => { void submit(event) }}>
          <div>
            <label htmlFor="replenishment-transfer-source" className={tokens.label.base}>{t('dialog.source')}</label>
            <select
              id="replenishment-transfer-source"
              value={sourceLocationId}
              onChange={(event) => { setSourceLocationId(event.target.value) }}
              className={tokens.select.base}
            >
              <option value="">{t('capture.select_location')}</option>
              {locations.filter((location) => !destinationIds.has(location.id)).map((location) => (
                <option key={location.id} value={location.id}>{location.name}</option>
              ))}
            </select>
          </div>

          <div className={tokens.alert.info}>
            <p>{t('dialog.transfer_preview', { count: destinationGroups.length })}</p>
            {destinationGroups.map((group) => (
              <p key={group.name} className="mt-1">
                {source?.name ?? '—'} → {group.name} ({group.count})
              </p>
            ))}
          </div>

          <ul className="space-y-3">
            {selected.map((line) => (
              <li key={line.id}>
                <label htmlFor={`transfer-qty-${line.id}`} className={tokens.label.base}>{line.product_name}</label>
                <QuantityInput
                  id={`transfer-qty-${line.id}`}
                  aria-label={`${t('dialog.quantity')} ${line.product_name}`}
                  value={quantities[line.id] ?? ''}
                  onChange={(value) => { setQuantities((current) => ({ ...current, [line.id]: value })) }}
                  decimalPlaces={4}
                  min="0.0001"
                />
              </li>
            ))}
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
