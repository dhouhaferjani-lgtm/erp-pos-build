import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { api } from '@/lib/api'
import { useDebouncedValue } from '@/lib/hooks'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'

/**
 * Minimal shape returned by `/vehicles`. Consumers that need the full
 * `VehicleData` DTO (mileage, fuel type, etc.) should fetch it separately
 * once they hold the id — the picker intentionally stays narrow.
 */
export interface VehiclePickerValue {
  id: string
  license_plate: string
  brand: string
  model: string
  year: number | null
}

interface VehiclePickerProps {
  value: VehiclePickerValue | null
  onChange: (next: VehiclePickerValue | null) => void
  label?: string
  placeholder?: string
  disabled?: boolean
  required?: boolean
  /** When set, results are scoped to that partner's vehicles. */
  partnerId?: string
  testId?: string
  /** Show an "Add new vehicle" CTA when empty. */
  allowNewInline?: boolean
}

interface VehicleListItem {
  id: string
  license_plate: string
  brand: string
  model: string
  year: number | null
}

interface VehicleListResponse {
  data: VehicleListItem[]
}

function toValue(item: VehicleListItem): VehiclePickerValue {
  return {
    id: item.id,
    license_plate: item.license_plate,
    brand: item.brand,
    model: item.model,
    year: item.year,
  }
}

export function VehiclePicker({
  value,
  onChange,
  label,
  placeholder,
  disabled = false,
  required = false,
  partnerId,
  testId,
  allowNewInline = false,
}: VehiclePickerProps) {
  const { t } = useTranslation('pickers')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const listboxId = useId()

  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)
  const debouncedQuery = useDebouncedValue(query, 250)

  // When the scope changes (different partner selected), drop any previously
  // chosen vehicle: the new owner's vehicle list is a different search space.
  const lastPartnerRef = useRef<string | undefined>(partnerId)
  useEffect(() => {
    if (lastPartnerRef.current !== partnerId) {
      lastPartnerRef.current = partnerId
      if (value !== null) {
        onChange(null)
      }
    }
    // Intentionally only depend on partnerId — we want onChange fired only on
    // partner changes, not on every onChange/value identity churn.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [partnerId])

  const searchEnabled = isOpen && debouncedQuery.trim().length >= 2
  const queryKey = ['pickers', 'vehicle', partnerId ?? null, debouncedQuery] as const

  const { data, isLoading, isError } = useQuery({
    queryKey,
    enabled: searchEnabled && !disabled,
    queryFn: async () => {
      const params = new URLSearchParams()
      params.set('search', debouncedQuery.trim())
      if (partnerId !== undefined && partnerId !== '') {
        params.set('partner_id', partnerId)
      }
      const response = await api.get<VehicleListResponse>(`/vehicles?${params.toString()}`)
      return response.data.data.map(toValue)
    },
  })

  useEffect(() => {
    setActiveIndex(-1)
  }, [data])

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (containerRef.current !== null && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', onClick)
    return () => {
      document.removeEventListener('mousedown', onClick)
    }
  }, [])

  const results = useMemo<VehiclePickerValue[]>(() => data ?? [], [data])

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>): void => {
    if (!isOpen) {
      if (e.key === 'ArrowDown' || e.key === 'Enter') {
        setIsOpen(true)
      }
      return
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, Math.max(results.length - 1, 0)))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      const choice = results[activeIndex]
      if (choice !== undefined) {
        onChange(choice)
        setQuery('')
        setIsOpen(false)
      }
    } else if (e.key === 'Escape') {
      e.preventDefault()
      setIsOpen(false)
    }
  }

  const openAddNewTab = (): void => {
    const href = partnerId !== undefined && partnerId !== ''
      ? `/vehicles/new?partner_id=${encodeURIComponent(partnerId)}`
      : '/vehicles/new'
    window.open(href, '_blank', 'noopener,noreferrer')
  }

  const effectiveLabel = label ?? t('vehicle.label')
  const effectivePlaceholder = placeholder ?? t('vehicle.searchPlaceholder')
  const testIdAttr = testId ?? 'vehicle-picker'

  if (value !== null) {
    return (
      <div
        ref={containerRef}
        className={`flex items-center gap-2 rounded-md border ${borderColors.default} bg-white px-3 py-2`}
        data-testid={testIdAttr}
      >
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={`${tokens.table.cellMonoBadge}`}>{value.license_plate}</span>
            <span className={`truncate text-sm font-medium ${textColors.primary}`}>
              {value.brand} {value.model}
              {value.year !== null ? ` · ${String(value.year)}` : ''}
            </span>
          </div>
        </div>
        <button
          type="button"
          className={`${textColors.tertiary} ${textColors.hoverPrimary}`}
          aria-label={t('common.clear')}
          disabled={disabled}
          onClick={() => {
            onChange(null)
            setQuery('')
          }}
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>
    )
  }

  return (
    <div ref={containerRef} className="relative" data-testid={testIdAttr}>
      {effectiveLabel !== '' ? (
        <label className={tokens.label.base}>
          {effectiveLabel}
          {required ? <span className={tokens.label.required}> *</span> : null}
        </label>
      ) : null}
      <input
        ref={inputRef}
        type="text"
        role="combobox"
        aria-expanded={isOpen}
        aria-controls={listboxId}
        aria-autocomplete="list"
        className={tokens.input.base}
        placeholder={effectivePlaceholder}
        value={query}
        disabled={disabled}
        onChange={(e) => {
          setQuery(e.target.value)
          setIsOpen(true)
        }}
        onFocus={() => {
          setIsOpen(true)
        }}
        onKeyDown={handleKeyDown}
      />
      {disabled ? (
        <p className={`mt-1 text-xs ${textColors.tertiary}`}>{t('vehicle.disabledHint')}</p>
      ) : null}
      {isOpen && !disabled ? (
        <div
          id={listboxId}
          role="listbox"
          className={`absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border ${borderColors.light} bg-white py-1 shadow-lg`}
        >
          {!searchEnabled ? (
            <div className={`px-3 py-2 text-xs ${textColors.tertiary}`}>
              {t('common.minCharacters')}
            </div>
          ) : isLoading ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>
              {t('common.loading')}
            </div>
          ) : isError ? (
            <div className={`px-3 py-2 text-sm ${textColors.error}`}>{t('common.error')}</div>
          ) : results.length === 0 ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>
              {partnerId !== undefined && partnerId !== ''
                ? t('vehicle.emptyForPartner')
                : t('vehicle.empty')}
              {allowNewInline ? (
                <button
                  type="button"
                  className={`ml-2 text-sm ${textColors.brand} hover:underline`}
                  onClick={openAddNewTab}
                >
                  {t('vehicle.addNew')}
                </button>
              ) : null}
            </div>
          ) : (
            results.map((vehicle, idx) => {
              const active = idx === activeIndex
              return (
                <button
                  key={vehicle.id}
                  type="button"
                  role="option"
                  aria-selected={active}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-left ${
                    active ? colors.primary[50] : `${colors.white} ${colors.hover.gray50}`
                  }`}
                  onMouseEnter={() => {
                    setActiveIndex(idx)
                  }}
                  onClick={() => {
                    onChange(vehicle)
                    setQuery('')
                    setIsOpen(false)
                  }}
                >
                  <span className={tokens.table.cellMonoBadge}>{vehicle.license_plate}</span>
                  <div className="min-w-0 flex-1">
                    <div className={`truncate text-sm font-medium ${textColors.primary}`}>
                      {vehicle.brand} {vehicle.model}
                    </div>
                    {vehicle.year !== null ? (
                      <div className={`truncate text-xs ${textColors.tertiary}`}>
                        {String(vehicle.year)}
                      </div>
                    ) : null}
                  </div>
                </button>
              )
            })
          )}
        </div>
      ) : null}
    </div>
  )
}
