import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { VerticalConfig } from '../config/verticals'

interface VerticalCardProps {
  vertical: VerticalConfig
  selected: boolean
  onSelect: () => void
}

export function VerticalCard({ vertical, selected, onSelect }: VerticalCardProps) {
  const { t } = useTranslation(['auth'])
  const Icon = vertical.icon

  return (
    <button
      type="button"
      role="option"
      aria-selected={selected}
      onClick={onSelect}
      className={cn(
        'flex items-center gap-3 rounded-xl border-2 p-4 text-start transition-all',
        'hover:border-blue-300 hover:bg-blue-50/50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
        selected
          ? 'border-blue-500 bg-blue-50'
          : 'border-gray-200 bg-white'
      )}
    >
      <div
        className={cn(
          'flex h-11 w-11 shrink-0 items-center justify-center rounded-[10px]',
          vertical.bgColor
        )}
      >
        <Icon className={cn('h-6 w-6', vertical.strokeColor)} strokeWidth={1.75} />
      </div>
      <div>
        <div className="text-sm font-semibold text-gray-900">
          {t(vertical.labelKey)}
        </div>
        <div className="text-xs text-gray-500">
          {t(vertical.descriptionKey)}
        </div>
      </div>
    </button>
  )
}
