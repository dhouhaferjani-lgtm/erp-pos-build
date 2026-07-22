import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, ChevronDown, MapPin } from 'lucide-react'
import { useScopedLocations } from '@/features/locations/hooks/useScopedLocations'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface ViewScopePickerProps {
  className?: string
}

export function ViewScopePicker({ className = '' }: ViewScopePickerProps) {
  const { t } = useTranslation('locations')
  const locationQuery = useScopedLocations()
  const locations = Array.isArray(locationQuery.data) ? locationQuery.data : []
  const isLoading = locationQuery.isLoading
  const { effectiveLocationIds, isAll, setScope } = useViewScope()
  const [isOpen, setIsOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleOutside = (event: MouseEvent): void => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) setIsOpen(false)
    }
    document.addEventListener('mousedown', handleOutside)
    return () => { document.removeEventListener('mousedown', handleOutside) }
  }, [])

  const selectedIds: string[] = isAll ? locations.map((location) => location.id) : effectiveLocationIds
  const toggleLocation = (id: string): void => {
    const next = selectedIds.includes(id)
      ? selectedIds.filter((locationId) => locationId !== id)
      : [...selectedIds, id]
    if (next.length === 0) return
    setScope(next.length === locations.length ? 'all' : next)
  }

  if (isLoading && locations.length === 0) {
    return <div className={cn('relative', className)}><span className={`px-2 py-1 text-sm ${colorTokens.text.disabled}`}>{t('locations:viewScope.loading')}</span></div>
  }

  return (
    <div className={cn('relative', className)} ref={menuRef}>
      <button
        type="button"
        onClick={() => { setIsOpen((open) => !open) }}
        className={`flex items-center gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary}`}
        aria-label={t('locations:viewScope.open')}
        aria-expanded={isOpen}
        aria-haspopup="true"
      >
        <MapPin className={`h-4 w-4 ${colorTokens.text.subtle}`} />
        <span className="max-w-40 truncate">{isAll ? t('locations:viewScope.all') : t('locations:viewScope.selected', { count: selectedIds.length })}</span>
        <ChevronDown className={`h-4 w-4 ${colorTokens.text.disabled} ${isOpen ? 'rotate-180' : ''}`} />
      </button>
      {isOpen && (
        <div className={`absolute end-0 top-full z-50 mt-1 min-w-56 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1 shadow-lg`} role="menu">
          <div className={`px-3 py-2 text-xs font-semibold uppercase tracking-wider ${colorTokens.text.subtle}`}>{t('locations:viewScope.label')}</div>
          <label className={`flex cursor-pointer items-center justify-between px-3 py-2 text-sm ${colorTokens.variants.hoverBgGray50}`}>
            <span>{t('locations:viewScope.all')}</span>
            <input
              type="checkbox"
              aria-label={t('locations:viewScope.all')}
              checked={isAll}
              onChange={() => { setScope('all') }}
              className="rounded"
            />
          </label>
          <div className={`my-1 border-t ${colorTokens.border.hairline}`} />
          <div className="max-h-64 overflow-y-auto">
            {locations.map((location) => (
              <label key={location.id} className={`flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm ${colorTokens.variants.hoverBgGray50}`}>
                <span className="truncate">{location.name}</span>
                <span className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    aria-label={location.name}
                    checked={selectedIds.includes(location.id)}
                    onChange={() => { toggleLocation(location.id) }}
                    className="rounded"
                  />
                  {selectedIds.includes(location.id) && <Check aria-hidden="true" className={`h-4 w-4 ${colorTokens.intent.primary.text}`} />}
                </span>
              </label>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
