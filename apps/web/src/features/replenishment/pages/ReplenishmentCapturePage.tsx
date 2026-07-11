import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { QuantityInput } from '@/components/atoms'
import { StatusBadge } from '@/components/atoms/StatusBadge/StatusBadge'
import { LineItemEntryBar } from '@/components/molecules/line-items/LineItemEntryBar'
import { PageHeader } from '@/components/molecules/PageHeader'
import { QueryError } from '@/components/QueryError'
import { useLocations } from '@/features/locations/hooks/useLocations'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { useCaptureReplenishment } from '../api/queries'
import type { ReplenishmentLine } from '../types'

type SubmittedLine = Pick<ReplenishmentLine, 'id' | 'product_name' | 'status'>

function hasProperty<Key extends string>(value: object, key: Key): value is Record<Key, unknown> {
  return key in value
}

function isSubmittedLine(value: unknown): value is SubmittedLine {
  if (typeof value !== 'object' || value === null) return false
  if (!hasProperty(value, 'id') || typeof value.id !== 'string') return false
  if (!hasProperty(value, 'product_name') || typeof value.product_name !== 'string') return false
  if (!hasProperty(value, 'status')) return false

  return value.status === 'pending'
    || value.status === 'in_progress'
    || value.status === 'fulfilled'
    || value.status === 'rejected'
    || value.status === 'cancelled'
}

export function ReplenishmentCapturePage() {
  const { t } = useTranslation('replenishment')
  const locationsQuery = useLocations()
  const capture = useCaptureReplenishment()
  const [locationId, setLocationId] = useState('')
  const [quantity, setQuantity] = useState('')
  const [note, setNote] = useState('')
  const [submitted, setSubmitted] = useState<SubmittedLine[]>([])

  const locations = (locationsQuery.data ?? []).filter((location) => location.isActive)

  return (
    <div className="space-y-6">
      <PageHeader title={t('capture.title')} />

      <section className={`space-y-4 rounded-lg border p-4 ${borderColors.light} ${colors.white}`}>
        <div>
          <label htmlFor="replenishment-location" className={tokens.label.base}>
            {t('capture.location')}
          </label>
          <select
            id="replenishment-location"
            value={locationId}
            onChange={(event) => {
              setLocationId(event.target.value)
            }}
            className={tokens.select.base}
          >
            <option value="">{t('capture.select_location')}</option>
            {locations.map((location) => (
              <option key={location.id} value={location.id}>{location.name}</option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="replenishment-quantity" className={tokens.label.base}>
              {t('capture.quantity')}
            </label>
            <QuantityInput
              id="replenishment-quantity"
              value={quantity}
              onChange={setQuantity}
              decimalPlaces={4}
            />
          </div>
          <div>
            <label htmlFor="replenishment-note" className={tokens.label.base}>
              {t('capture.note')}
            </label>
            <input
              id="replenishment-note"
              value={note}
              onChange={(event) => {
                setNote(event.target.value)
              }}
              className={tokens.input.base}
            />
          </div>
        </div>

        <LineItemEntryBar
          disabled={capture.isPending}
          onBeforeAdd={() => locationId !== ''}
          onAddProduct={(product, meta) => {
            void capture.mutateAsync({
              location_id: locationId,
              product_id: product.id,
              variant_id: meta.variantId ?? null,
              requested_qty: quantity === '' ? null : quantity,
              note: note.trim() === '' ? null : note,
            }).then((result) => {
              if (isSubmittedLine(result)) {
                setSubmitted((current) => [result, ...current])
              }
              setQuantity('')
              setNote('')
              toast.success(t('capture.submitted'))
            }).catch(() => undefined)
          }}
        />

        {capture.error ? <QueryError error={capture.error} compact /> : null}
      </section>

      {submitted.length > 0 ? (
        <section className="space-y-3">
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>{t('capture.session')}</h2>
          <ul className={`divide-y rounded-lg border ${borderColors.light} ${borderColors.divideLight} ${colors.white}`}>
            {submitted.map((line) => (
              <li key={line.id} className="flex items-center justify-between gap-3 p-4">
                <span className={textColors.primary}>{line.product_name}</span>
                <StatusBadge tone={line.status === 'in_progress' ? 'info' : 'pending'}>
                  {t(`status.${line.status}`)}
                </StatusBadge>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  )
}
