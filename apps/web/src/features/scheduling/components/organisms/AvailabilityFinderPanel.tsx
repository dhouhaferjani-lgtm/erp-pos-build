import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { Button, Input } from '@/components/atoms'
import { FreeSlotPicker } from '../molecules/FreeSlotPicker'
import { useFreeSlots } from '../../hooks/useScheduling'
import type { FreeSlotDTO } from '../../types'

interface AvailabilityFinderPanelProps {
  /** Called when the user picks a slot — typically opens the booking drawer. */
  onPick?: (slot: FreeSlotDTO) => void
}

interface SearchParams {
  duration: number
  from: string
  to: string
}

/**
 * Controls for the `/calendar/free-slots` endpoint: duration + date range
 * inputs + picker. Organism: owns the `useFreeSlots` query (lazy — only
 * runs once the user submits the range).
 */
export function AvailabilityFinderPanel({ onPick }: AvailabilityFinderPanelProps) {
  const { t } = useTranslation('scheduling')
  const [duration, setDuration] = useState<number>(60)
  const today = new Date().toISOString().slice(0, 10)
  const [from, setFrom] = useState<string>(today)
  const [to, setTo] = useState<string>(today)
  const [params, setParams] = useState<SearchParams | null>(null)
  const slots = useFreeSlots(params)

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>): void => {
    e.preventDefault()
    setParams({ duration, from, to })
  }

  return (
    <section className={tokens.card.base}>
      <h3 className={`mb-3 text-sm font-semibold ${textColors.primary}`}>
        {t('availability.title')}
      </h3>
      <form onSubmit={handleSubmit} className="grid grid-cols-1 gap-3 md:grid-cols-4">
        <label className="block">
          <span className={tokens.label.base}>{t('availability.duration')}</span>
          <Input
            type="number"
            min={1}
            value={duration}
            onChange={(e) => { setDuration(Number(e.target.value)) }}
          />
        </label>
        <label className="block">
          <span className={tokens.label.base}>{t('availability.from')}</span>
          <Input
            type="date"
            value={from}
            onChange={(e) => { setFrom(e.target.value) }}
          />
        </label>
        <label className="block">
          <span className={tokens.label.base}>{t('availability.to')}</span>
          <Input
            type="date"
            value={to}
            onChange={(e) => { setTo(e.target.value) }}
          />
        </label>
        <div className="flex items-end">
          <Button
            type="submit"
            variant="primary"
            size="md"
            className="w-full"
          >
            {t('availability.search')}
          </Button>
        </div>
      </form>
      <div className="mt-4">
        <FreeSlotPicker
          slots={slots.data ?? []}
          isLoading={slots.isLoading}
          onPick={(slot) => {
            if (onPick) onPick(slot)
          }}
        />
      </div>
    </section>
  )
}
