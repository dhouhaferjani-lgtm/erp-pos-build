import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'
import { TimeSlotLabel } from '../atoms/TimeSlotLabel'
import type { ConflictDetail } from '../../types'

interface ConflictAlertProps {
  conflict: ConflictDetail
  onViewExisting?: (appointmentId: string) => void
}

/**
 * Red callout shown when the backend rejects a booking with a 409
 * `appointment_conflict` payload. Molecule: pure presentation of the
 * `ConflictDetail` VO — no data fetching.
 */
export function ConflictAlert({ conflict, onViewExisting }: ConflictAlertProps) {
  const { t } = useTranslation('scheduling')

  const handleView = (): void => {
    if (onViewExisting !== undefined && conflict.conflicting_appointment_id !== null) {
      onViewExisting(conflict.conflicting_appointment_id)
    }
  }

  return (
    <div className={`${tokens.alert.base} ${tokens.alert.error}`} role="alert">
      <p className="font-semibold">{t('conflict.title')}</p>
      <p className="mt-1 text-sm">{conflict.reason}</p>
      {conflict.conflicting_window !== null ? (
        <div className="mt-2 text-sm">
          <TimeSlotLabel
            start={conflict.conflicting_window.start}
            end={conflict.conflicting_window.end}
            mode="full"
          />
        </div>
      ) : null}
      {onViewExisting !== undefined && conflict.conflicting_appointment_id !== null ? (
        <button
          type="button"
          onClick={handleView}
          className="mt-2 text-sm font-semibold underline"
        >
          {t('conflict.seeExisting')}
        </button>
      ) : null}
    </div>
  )
}
