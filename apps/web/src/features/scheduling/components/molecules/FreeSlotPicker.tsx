import { useTranslation } from 'react-i18next'
import { tokens, textColors } from '@/lib/designTokens'
import { TimeSlotLabel } from '../atoms/TimeSlotLabel'
import type { FreeSlotDTO } from '../../types'

const buttonTokens = tokens.button


interface FreeSlotPickerProps {
  slots: FreeSlotDTO[]
  isLoading?: boolean
  onPick: (slot: FreeSlotDTO) => void
}

/**
 * Lists free slots and lets the user pick one. Molecule: receives `slots`
 * from its parent organism — does NOT query the backend itself.
 */
export function FreeSlotPicker({ slots, isLoading = false, onPick }: FreeSlotPickerProps) {
  const { t } = useTranslation('scheduling')

  if (isLoading) {
    return <p className={`text-sm ${textColors.tertiary}`}>{t('scheduler.loading')}</p>
  }

  if (slots.length === 0) {
    return <p className={`text-sm ${textColors.tertiary}`}>{t('availability.emptySlots')}</p>
  }

  return (
    <ul className="flex flex-col gap-2">
      {slots.map((slot, idx) => (
        <li
          key={`${slot.bay_id}-${slot.start}-${String(idx)}`}
          className={`${tokens.card.base} flex items-center justify-between`}
        >
          <TimeSlotLabel start={slot.start} end={slot.end} mode="full" />
          <button
            type="button"
            onClick={() => { onPick(slot) }}
            className={`${buttonTokens.base} ${buttonTokens.primary} ${buttonTokens.sizes.sm}`}
          >
            {t('availability.pick')}
          </button>
        </li>
      ))}
    </ul>
  )
}
