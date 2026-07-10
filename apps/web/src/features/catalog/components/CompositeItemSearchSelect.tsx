import { useState, useRef, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Search, X, ChevronDown, Layers } from 'lucide-react'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { Button, Input } from '@/components/atoms'
import { tokens, textColors, borderColors, colors , semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface CompositeItemOption {
  id: string
  name: string
  code: string
}

interface CompositeItemsResponse {
  data: CompositeItemOption[]
}

interface CompositeItemSearchSelectProps {
  value: string
  onChange: (id: string) => void
  placeholder?: string
  className?: string
  disabled?: boolean
}

export function CompositeItemSearchSelect({
  value,
  onChange,
  placeholder,
  className,
  disabled = false,
}: CompositeItemSearchSelectProps) {
  const { t } = useTranslation(['catalog', 'common'])
  const [isOpen, setIsOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const { data: itemsData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['composite-items-search', searchQuery]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) {
        params.append('search', searchQuery)
      }
      params.append('is_active', 'true')
      const response = await api.get<CompositeItemsResponse>(`/composite-items?${params.toString()}`)
      return response.data
    },
    enabled: isOpen && tenantId !== null && companyId !== null,
    staleTime: 30000,
  })

  const { data: selectedItem } = useQuery({
    queryKey: tenantScopedKey(['composite-item-selected', value]),
    queryFn: async () => {
      const response = await api.get<{ data: CompositeItemOption }>(`/composite-items/${value}`)
      return response.data.data
    },
    enabled: Boolean(value) && !isOpen && tenantId !== null && companyId !== null,
    staleTime: 60000,
  })

  const items = itemsData?.data ?? []

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
        setSearchQuery('')
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => { document.removeEventListener('mousedown', handleClickOutside) }
  }, [])

  useEffect(() => {
    if (isOpen && inputRef.current) {
      inputRef.current.focus()
    }
  }, [isOpen])

  const handleSelect = (item: CompositeItemOption) => {
    onChange(item.id)
    setIsOpen(false)
    setSearchQuery('')
  }

  const handleClear = () => {
    onChange('')
    setSearchQuery('')
  }

  return (
    <div ref={containerRef} className={`relative ${className ?? ''}`}>
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        onClick={() => !disabled && setIsOpen(!isOpen)}
        onKeyDown={(e) => {
          if ((e.key === 'Enter' || e.key === ' ') && !disabled) {
            e.preventDefault()
            setIsOpen(!isOpen)
          }
        }}
        aria-disabled={disabled}
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        className={`flex w-full items-center justify-between rounded-lg border ${borderColors.default} px-3 py-2 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:${colorTokens.intent.primary.ring} ${
          disabled ? `${colors.neutral[100]} cursor-not-allowed` : `${colors.white} ${colors.hover.gray50} cursor-pointer`
        }`}
      >
        <span className={selectedItem ? textColors.primary : textColors.tertiary}>
          {selectedItem
            ? `${selectedItem.name} (${selectedItem.code})`
            : (placeholder ?? t('common:actions.select'))}
        </span>
        <div className="flex items-center gap-1">
          {value && !disabled && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={(e) => {
                e.stopPropagation()
                handleClear()
              }}
              aria-label={t('common:actions.clear')}
              className={`!rounded !p-0.5 ${textColors.disabled} ${textColors.hoverSecondary}`}
            >
              <X className="h-4 w-4" />
            </Button>
          )}
          <ChevronDown className={`h-4 w-4 ${textColors.disabled} transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </div>
      </div>

      {isOpen && (
        <div className={`absolute left-0 top-full z-50 mt-1 w-full min-w-[300px] rounded-lg border ${borderColors.light} ${colors.white} shadow-lg`}>
          <div className={`border-b ${borderColors.light} p-3`}>
            <div className="relative">
              <Search className={`absolute inset-y-0 start-0 ms-3 h-full w-4 ${textColors.disabled}`} />
              <Input
                ref={inputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value) }}
                placeholder={placeholder ?? t('common:actions.search')}
                className="!mt-0 py-2 pe-10 ps-10 text-sm"
              />
              {searchQuery && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => { setSearchQuery('') }}
                  aria-label={t('common:actions.clear')}
                  className={`absolute inset-y-0 end-0 !rounded-none !bg-transparent pe-3 ${textColors.disabled} ${textColors.hoverSecondary}`}
                >
                  <X className="h-4 w-4" />
                </Button>
              )}
            </div>
          </div>

          <div className="max-h-60 overflow-y-auto">
            {isLoading ? (
              <div className={`p-4 text-center text-sm ${textColors.tertiary}`}>
                {t('common:status.loading', 'Loading...')}
              </div>
            ) : items.length === 0 ? (
              <div className="p-4 text-center text-sm">
                <Layers className={`mx-auto h-8 w-8 ${textColors.disabled}`} />
                <p className={`mt-2 ${textColors.tertiary}`}>
                  {searchQuery
                    ? t('catalog:noCompositeItemsFound')
                    : t('catalog:noCompositeItems')}
                </p>
              </div>
            ) : (
              <ul className={`divide-y ${borderColors.divideLight}`}>
                {items.map((item) => (
                  <li key={item.id}>
                    <Button
                      type="button"
                      variant="ghost"
                      onClick={() => { handleSelect(item) }}
                      className={`flex w-full !justify-start items-center gap-3 !rounded-none px-4 py-3 text-start ${colors.hover.gray50} ${
                        item.id === value ? colors.primary[50] : '!bg-transparent'
                      }`}
                    >
                      <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${tokens.badge.purple}`}>
                        <Layers className="h-4 w-4" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className={`truncate text-sm font-medium ${textColors.primary}`}>
                          {item.name}
                        </div>
                        <div className={`truncate text-xs ${textColors.tertiary}`}>
                          {item.code}
                        </div>
                      </div>
                      {item.id === value && (
                        <div className="flex-shrink-0">
                          <div className={`h-2 w-2 rounded-full ${colors.primary[600]}`} />
                        </div>
                      )}
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
