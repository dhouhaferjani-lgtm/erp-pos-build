import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '@/lib/designTokens'
import type { VehicleOwnershipData } from '../../types'

interface OwnershipTimelineEntryProps {
  ownership: VehicleOwnershipData
}

export function OwnershipTimelineEntry({ ownership }: OwnershipTimelineEntryProps) {
  const { t } = useTranslation('vehicle-ownership')
  const isOpen = ownership.released_at === null

  const acquiredDate = new Date(ownership.acquired_at).toLocaleDateString()
  const releasedDate = ownership.released_at === null
    ? null
    : new Date(ownership.released_at).toLocaleDateString()

  return (
    <li
      className={`flex flex-col gap-1 border-l-2 px-3 py-2 ${isOpen ? borderColors.primary : borderColors.light}`}
    >
      <div className="flex items-center justify-between gap-2">
        <span className={`text-sm font-medium ${textColors.primary}`}>
          {ownership.owner_display_name}
        </span>
        <span className={`text-xs ${textColors.tertiary}`}>
          {isOpen ? t('ownership.current') : t('ownership.closed')}
        </span>
      </div>
      <div className={`text-xs ${textColors.tertiary}`}>
        {t('ownership.acquiredAt')}: {acquiredDate}
        {releasedDate !== null ? ` · ${t('ownership.releasedAt')}: ${releasedDate}` : ''}
      </div>
      <div className={`text-xs ${textColors.tertiary}`}>
        {t('ownership.reason')}: {t(`ownershipReason.${ownership.reason_code}`)}
      </div>
      {ownership.notes !== null && ownership.notes !== '' ? (
        <div className={`text-xs ${textColors.tertiary}`}>{ownership.notes}</div>
      ) : null}
    </li>
  )
}
