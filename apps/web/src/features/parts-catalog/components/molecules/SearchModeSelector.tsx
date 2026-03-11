import { useTranslation } from 'react-i18next'
import { Car, Hash, Circle, FolderTree, CreditCard } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { SearchMode } from '../../types/catalog'

interface SearchModeSelectorProps {
  availableModes: SearchMode[]
  activeMode: SearchMode
  onModeChange: (mode: SearchMode) => void
  className?: string
}

const MODE_ICONS: Record<SearchMode, typeof Car> = {
  vehicle: Car,
  partNumber: Hash,
  tireSize: Circle,
  category: FolderTree,
  vinPlate: CreditCard,
}

export function SearchModeSelector({
  availableModes,
  activeMode,
  onModeChange,
  className,
}: SearchModeSelectorProps) {
  const { t } = useTranslation(['parts-catalog'])

  return (
    <nav className={cn('flex', className)} role="tablist" aria-label={t('parts-catalog:title')}>
      <div className="flex w-full rounded-lg bg-gray-100 p-1 gap-1">
        {availableModes.map((mode) => {
          const Icon = MODE_ICONS[mode]
          const isActive = mode === activeMode

          return (
            <button
              key={mode}
              role="tab"
              type="button"
              aria-selected={isActive}
              onClick={() => { onModeChange(mode) }}
              className={cn(
                'flex flex-1 items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium transition-all duration-200',
                isActive
                  ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200'
                  : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'
              )}
            >
              <Icon className="h-4 w-4" />
              <span className="hidden sm:inline">
                {t(`parts-catalog:searchModes.${mode}`)}
              </span>
            </button>
          )
        })}
      </div>
    </nav>
  )
}
