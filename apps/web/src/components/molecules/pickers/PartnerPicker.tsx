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
import { AddPartnerModal } from '@/components/organisms/AddPartnerModal'

/**
 * Minimal partner shape a caller must hand back on `onChange`. This mirrors
 * the list row returned by `GET /api/v1/partners` but only the fields the
 * picker and its consumers actually need, to stay stable if the upstream
 * DTO grows.
 */
export interface PartnerPickerValue {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
  email?: string | null
  city?: string | null
}

export type PartnerTypeFilter = 'customer' | 'supplier' | 'all'

interface PartnerPickerProps {
  value: PartnerPickerValue | string | null
  onChange: (next: PartnerPickerValue | null) => void
  label?: string
  placeholder?: string
  disabled?: boolean
  required?: boolean
  /** Restrict results to `customer`, `supplier`, or `all` (default `customer`). */
  partnerType?: PartnerTypeFilter
  /** Optional data-testid override for automation. */
  testId?: string
  /** Include inactive partners in search results. Defaults to active-only. */
  includeInactive?: boolean
  /**
   * When true, the empty state shows an "Add new customer" affordance that
   * opens AddPartnerModal inline. Callers that cannot create from the current
   * surface should leave this off. Default: false.
   */
  allowNewInline?: boolean
  /** Optional caller-owned create flow, used when the caller needs custom prefill. */
  onAddNew?: () => void
}

interface PartnerListItem {
  id: string
  name: string
  type: string
  email: string | null
  city?: string | null
}

interface PartnerListResponse {
  data: PartnerListItem[]
}

function isPartnerType(value: string): value is PartnerPickerValue['type'] {
  return value === 'customer' || value === 'supplier' || value === 'both'
}

function toValue(item: PartnerListItem): PartnerPickerValue {
  const type: PartnerPickerValue['type'] = isPartnerType(item.type) ? item.type : 'customer'
  const value: PartnerPickerValue = {
    id: item.id,
    name: item.name,
    type,
  }
  if (item.email !== undefined && item.email !== null) {
    value.email = item.email
  }
  if (item.city !== undefined && item.city !== null) {
    value.city = item.city
  }
  return value
}

export function PartnerPicker({
  value,
  onChange,
  label,
  placeholder,
  disabled = false,
  required = false,
  partnerType = 'customer',
  testId,
  includeInactive = false,
  allowNewInline = false,
  onAddNew,
}: PartnerPickerProps) {
  const { t } = useTranslation('pickers')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const listboxId = useId()

  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [isAddModalOpen, setIsAddModalOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)
  const debouncedQuery = useDebouncedValue(query, 250)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const trimmedQuery = debouncedQuery.trim()
  const searchEnabled = isOpen
  const selectedPartnerId = typeof value === 'string'
    ? value.trim() === '' ? null : value
    : value?.id ?? null
  const queryKey = tenantScopedKey(['pickers', 'partner', partnerType, includeInactive, trimmedQuery] as const)

  const { data, isLoading, isError } = useQuery({
    queryKey,
    enabled: searchEnabled && !disabled && tenantId !== null && companyId !== null,
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '20' })
      if (!includeInactive) {
        params.set('is_active', 'true')
      }
      if (trimmedQuery !== '') {
        params.set('search', trimmedQuery)
      }
      if (partnerType !== 'all') {
        params.set('type', partnerType)
      }
      const response = await api.get<PartnerListResponse>(`/partners?${params.toString()}`)
      return response.data.data.map(toValue)
    },
  })

  const { data: selectedPartner } = useQuery({
    queryKey: tenantScopedKey(['partner', selectedPartnerId] as const),
    enabled: typeof value === 'string' && selectedPartnerId !== null && selectedPartnerId !== '' && tenantId !== null && companyId !== null,
    queryFn: async () => {
      const response = await api.get<{ data: PartnerListItem }>(`/partners/${selectedPartnerId}`)
      return toValue(response.data.data)
    },
    staleTime: 60000,
  })

  // Reset highlighted row when the result set changes — -1 = no
  // highlight; first ArrowDown moves to 0.
  useEffect(() => {
    setActiveIndex(-1)
  }, [data])

  // Close the listbox on outside clicks.
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

  const results = useMemo<PartnerPickerValue[]>(() => data ?? [], [data])

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

  const effectivePlaceholder = placeholder ?? t('partner.searchPlaceholder')
  const effectiveLabel = label ?? t('partner.label')
  const testIdAttr = testId ?? 'partner-picker'

  const selectedValue = typeof value === 'string' ? selectedPartner ?? null : value

  if (selectedValue !== null && selectedValue !== undefined) {
    return (
      <div
        ref={containerRef}
        className={`flex items-center gap-2 rounded-md border ${borderColors.default} bg-white px-3 py-2`}
        data-testid={testIdAttr}
      >
        <div className="min-w-0 flex-1">
          <div className={`truncate text-sm font-medium ${textColors.primary}`}>{selectedValue.name}</div>
          <div className={`flex items-center gap-1 truncate text-xs ${textColors.tertiary}`}>
            <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
              {t(`partner.typeChip.${selectedValue.type}`)}
            </span>
            {selectedValue.city !== undefined && selectedValue.city !== null ? <span>{selectedValue.city}</span> : null}
            {selectedValue.email !== undefined && selectedValue.email !== null ? <span>{selectedValue.email}</span> : null}
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
    <>
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
            className={`absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border ${borderColors.light} bg-white py-1 shadow-lg`}
          >
            {isLoading ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>
              {t('common.loading')}
            </div>
          ) : isError ? (
            <div className={`px-3 py-2 text-sm ${textColors.error}`}>{t('common.error')}</div>
          ) : results.length === 0 ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>
              {t('partner.empty')}
              {allowNewInline || onAddNew !== undefined ? (
                <button
                  type="button"
                  className={`ml-2 text-sm ${textColors.brand} hover:underline`}
                  onClick={() => {
                    setIsOpen(false)
                    if (onAddNew !== undefined) {
                      onAddNew()
                    } else {
                      setIsAddModalOpen(true)
                    }
                  }}
                >
                  {t('partner.addNew')}
                </button>
              ) : null}
            </div>
          ) : (
            results.map((partner, idx) => {
              const active = idx === activeIndex
              return (
                <button
                  key={partner.id}
                  type="button"
                  role="option"
                  aria-selected={active}
                  className={`flex w-full items-start justify-between gap-2 px-3 py-2 text-left ${
                    active ? colors.primary[50] : `${colors.white} ${colors.hover.gray50}`
                  }`}
                  onMouseEnter={() => {
                    setActiveIndex(idx)
                  }}
                  onClick={() => {
                    onChange(partner)
                    setQuery('')
                    setIsOpen(false)
                  }}
                >
                  <div className="min-w-0 flex-1">
                    <div className={`truncate text-sm font-medium ${textColors.primary}`}>
                      {partner.name}
                    </div>
                    <div className={`flex items-center gap-1 truncate text-xs ${textColors.tertiary}`}>
                      {partner.city !== undefined && partner.city !== null ? (
                        <span>{partner.city}</span>
                      ) : null}
                      {partner.email !== undefined && partner.email !== null ? (
                        <span className="truncate">{partner.email}</span>
                      ) : null}
                    </div>
                  </div>
                  <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
                    {t(`partner.typeChip.${partner.type}`)}
                  </span>
                </button>
              )
            })
          )}
          </div>
        ) : null}
      </div>
      {allowNewInline && onAddNew === undefined && isAddModalOpen ? (
        <AddPartnerModal
          isOpen={isAddModalOpen}
          onClose={() => { setIsAddModalOpen(false) }}
          partnerType={partnerType === 'all' ? undefined : partnerType}
          onSuccess={(partner) => {
            onChange(toValue(partner))
            setIsAddModalOpen(false)
          }}
        />
      ) : null}
    </>
  )
}
