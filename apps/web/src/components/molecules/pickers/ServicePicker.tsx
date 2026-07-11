import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { api } from '@/lib/api'
import { useDebouncedValue } from '@/lib/hooks'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * Minimal service shape the picker hands back. Mirrors the Service DTO
 * columns the bundle authoring UI actually needs.
 */
export interface ServicePickerValue {
  id: string
  code: string
  name: string
  pricing_type?: string | null
  hourly_rate?: string | null
  base_price?: string | null
  tax_rate?: string | null
  currency?: string | null
}

interface ServicePickerProps {
  value: ServicePickerValue | null
  onChange: (next: ServicePickerValue | null) => void
  label?: string
  placeholder?: string
  disabled?: boolean
  required?: boolean
  testId?: string
}

interface ServiceListItem {
  id: string
  code: string
  name: string
  pricing_type?: string | null
  hourly_rate?: string | null
  base_price?: string | null
  tax_rate?: string | null
  currency?: string | null
}

interface ServiceListResponse {
  data: ServiceListItem[]
}

function toValue(item: ServiceListItem): ServicePickerValue {
  const value: ServicePickerValue = {
    id: item.id,
    code: item.code,
    name: item.name,
  }
  if (item.pricing_type !== undefined && item.pricing_type !== null) {
    value.pricing_type = item.pricing_type
  }
  if (item.hourly_rate !== undefined && item.hourly_rate !== null) {
    value.hourly_rate = item.hourly_rate
  }
  if (item.base_price !== undefined && item.base_price !== null) {
    value.base_price = item.base_price
  }
  if (item.tax_rate !== undefined && item.tax_rate !== null) {
    value.tax_rate = item.tax_rate
  }
  if (item.currency !== undefined && item.currency !== null) {
    value.currency = item.currency
  }
  return value
}

export function ServicePicker({
  value,
  onChange,
  label,
  placeholder,
  disabled = false,
  required = false,
  testId,
}: ServicePickerProps) {
  const { t } = useTranslation('pickers')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const listboxId = useId()

  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)
  const debouncedQuery = useDebouncedValue(query, 250)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const searchEnabled = isOpen && debouncedQuery.trim().length >= 2
  const queryKey = tenantScopedKey(['pickers', 'service', debouncedQuery] as const)

  const { data, isLoading, isError } = useQuery({
    queryKey,
    enabled: searchEnabled && !disabled && tenantId !== null && companyId !== null,
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '20', active: 'true' })
      params.set('search', debouncedQuery.trim())
      const response = await api.get<ServiceListResponse>(`/services?${params.toString()}`)
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

  const results = useMemo<ServicePickerValue[]>(() => data ?? [], [data])

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

  const effectivePlaceholder = placeholder ?? t('service.searchPlaceholder')
  const effectiveLabel = label ?? t('service.label')
  const testIdAttr = testId ?? 'service-picker'

  if (value !== null) {
    return (
      <div
        ref={containerRef}
        className={`flex items-center gap-2 rounded-md border ${borderColors.default} ${colorTokens.surface.base} px-3 py-2`}
        data-testid={testIdAttr}
      >
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={`${tokens.table.cellMonoBadge}`}>{value.code}</span>
            <span className={`truncate text-sm font-medium ${textColors.primary}`}>{value.name}</span>
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
      {isOpen ? (
        <div
          id={listboxId}
          role="listbox"
          className={`absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border ${borderColors.light} ${colorTokens.surface.base} py-1 shadow-lg`}
        >
          {!searchEnabled ? (
            <div className={`px-3 py-2 text-xs ${textColors.tertiary}`}>
              {t('common.minCharacters')}
            </div>
          ) : isLoading ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{t('common.loading')}</div>
          ) : isError ? (
            <div className={`px-3 py-2 text-sm ${textColors.error}`}>{t('common.error')}</div>
          ) : results.length === 0 ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{t('service.empty')}</div>
          ) : (
            results.map((service, idx) => {
              const active = idx === activeIndex
              return (
                <button
                  key={service.id}
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
                    onChange(service)
                    setQuery('')
                    setIsOpen(false)
                  }}
                >
                  <span className={tokens.table.cellMonoBadge}>{service.code}</span>
                  <div className="min-w-0 flex-1">
                    <div className={`truncate text-sm font-medium ${textColors.primary}`}>
                      {service.name}
                    </div>
                    {service.hourly_rate !== undefined && service.hourly_rate !== null ? (
                      <div className={`truncate text-xs ${textColors.tertiary}`}>
                        {service.hourly_rate} {service.currency ?? ''} / h
                      </div>
                    ) : service.base_price !== undefined && service.base_price !== null ? (
                      <div className={`truncate text-xs ${textColors.tertiary}`}>
                        {service.base_price} {service.currency ?? ''}
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
