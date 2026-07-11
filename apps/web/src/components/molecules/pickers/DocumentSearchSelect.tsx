import { useState, useRef, useEffect, useId } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Search, X, ChevronDown } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * Generic Document Search Select Component
 * Reusable component for searching and selecting any document type
 */

interface BaseDocument {
  id: string
  document_number?: string
  number?: string
  document_date: string
  partner?: {
    id: string
    name: string
  } | null
  total?: string | number
  status: string
  currency?: string
}

interface DocumentSearchConfig<T extends BaseDocument> {
  endpoint: string // e.g., '/invoices', '/delivery-notes'
  queryKey: string // e.g., 'invoices-search', 'delivery-notes-search'
  statusFilter?: string // e.g., 'posted', 'confirmed'
  additionalFilters?: Record<string, string | boolean> // e.g., { has_balance: true }
  icon: LucideIcon
  searchPlaceholder: string
  noResultsMessage: string
  noDataMessage: string
  renderItem?: (document: T) => React.ReactNode
  getDisplayText?: (document: T) => string
  additionalLocalFilter?: (document: T, query: string) => boolean
}

interface DocumentSearchSelectProps<T extends BaseDocument> {
  value?: T | null | undefined
  onChange: (document: T | null) => void
  config: DocumentSearchConfig<T>
  partnerId?: string | undefined
  required?: boolean | undefined
  disabled?: boolean | undefined
  className?: string | undefined
  label?: string | undefined
  error?: string | undefined
}

const currencyFormatters = new Map<string, Intl.NumberFormat>()

function getCurrencyFormatter(currency: string): Intl.NumberFormat {
  const existing = currencyFormatters.get(currency)
  if (existing !== undefined) return existing

  const formatter = Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency,
  })
  currencyFormatters.set(currency, formatter)
  return formatter
}

function formatCurrency(amount: number | string | undefined, currency: string = 'EUR') {
  if (amount === undefined) return ''
  const num = typeof amount === 'string' ? parseFloat(amount) : amount
  return getCurrencyFormatter(currency).format(num)
}

function getDocumentNumber(doc: BaseDocument) {
  return doc.document_number || doc.number || doc.id
}

export function DocumentSearchSelect<T extends BaseDocument>({
  value,
  onChange,
  config,
  partnerId,
  required = false,
  disabled = false,
  className = '',
  label,
  error,
}: DocumentSearchSelectProps<T>) {
  const { t } = useTranslation()
  const [isOpen, setIsOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const labelId = useId()
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const Icon = config.icon

  // Fetch documents with search
  const { data: documentsData, isLoading } = useQuery({
    queryKey: tenantScopedKey([config.queryKey, searchQuery, partnerId, config.additionalFilters]),
    queryFn: async () => {
      const params = new URLSearchParams()

      // Add status filter if provided
      if (config.statusFilter) {
        params.append('status', config.statusFilter)
      }

      // Add pagination
      params.append('per_page', '10')

      // Add search query
      if (searchQuery) {
        params.append('search', searchQuery)
      }

      // Add partner filter
      if (partnerId) {
        params.append('partner_id', partnerId)
      }

      // Add additional filters
      if (config.additionalFilters) {
        Object.entries(config.additionalFilters).forEach(([key, val]) => {
          params.append(key, String(val))
        })
      }

      const response = await api.get<{ data: T[] }>(`${config.endpoint}?${params.toString()}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && isOpen,
    staleTime: 30000,
  })

  const documents = documentsData?.data ?? []

  // Apply additional local filtering if provided
  const filteredDocuments = config.additionalLocalFilter
    ? documents.filter((doc) => {
        if (config.additionalLocalFilter) {
          return config.additionalLocalFilter(doc, searchQuery)
        }
        return true
      })
    : documents

  // Close dropdown when clicking outside
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
        setSearchQuery('')
      }
    }
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => {
        document.removeEventListener('mousedown', handleClickOutside)
      }
    }
    return undefined
  }, [isOpen])

  // Focus input when dropdown opens
  useEffect(() => {
    if (isOpen && inputRef.current) {
      inputRef.current.focus()
    }
  }, [isOpen])

  const handleSelect = (document: T) => {
    onChange(document)
    setIsOpen(false)
    setSearchQuery('')
  }

  const handleClear = () => {
    onChange(null)
    setSearchQuery('')
  }

  const getDisplayText = () => {
    if (!value) {
      return t('common:actions.select') || 'Select'
    }

    // Use custom display text if provided
    if (config.getDisplayText) {
      return config.getDisplayText(value)
    }

    // Default display text
    const docNumber = getDocumentNumber(value)
    const partner = value.partner?.name || t('common:status.unknown')
    const total = value.total ? formatCurrency(value.total, value.currency) : ''

    return `${docNumber} - ${partner}${total ? ` - ${total}` : ''}`
  }

  const renderDefaultItem = (document: T) => {
    const docNumber = getDocumentNumber(document)
    const partner = document.partner?.name || t('common:status.unknown')
    const total = document.total ? formatCurrency(document.total, document.currency) : null
    const date = new Date(document.document_date).toLocaleDateString()

    return (
      <>
        <div className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full ${colorTokens.surface.muted}`}>
          <Icon className={`h-4 w-4 ${colorTokens.text.subtle}`} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={`text-sm font-medium ${colorTokens.text.primary}`}>{docNumber}</span>
            <span className={`text-xs ${colorTokens.text.subtle}`}>{date}</span>
          </div>
          <div className={`text-xs ${colorTokens.text.subtle} truncate`}>{partner}</div>
          {total !== null && (
            <div className={`mt-1 text-xs ${colorTokens.text.secondary}`}>
              {t('common:total')}: {total}
            </div>
          )}
        </div>
        {document.id === value?.id && (
          <div className="flex-shrink-0">
            <div className={`h-2 w-2 rounded-full ${colorTokens.intent.primary.bgStrong}`} />
          </div>
        )}
      </>
    )
  }

  return (
    <div ref={containerRef} className={`relative ${className}`}>
      {/* Label */}
      {label && (
        <span id={labelId} className={`mb-1 block text-sm font-medium ${colorTokens.text.secondary}`}>
          {label}
          {required && <span className={`ms-1 ${colorTokens.intent.danger.textSubtle}`}>*</span>}
        </span>
      )}

      {/* Selected value display / trigger */}
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        onClick={() => {
          if (!disabled) setIsOpen(!isOpen)
        }}
        onKeyDown={(e) => {
          if ((e.key === 'Enter' || e.key === ' ') && !disabled) {
            e.preventDefault()
            setIsOpen(!isOpen)
          }
        }}
        aria-disabled={disabled}
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        aria-labelledby={label ? labelId : undefined}
        className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 ${colorTokens.variants.focusVisibleRingBlue500} ${
          error
            ? `${colorTokens.intent.danger.border}`
            : `${colorTokens.border.default}`
        } ${disabled ? `${colorTokens.surface.muted} cursor-not-allowed` : `${colorTokens.surface.base} ${colorTokens.variants.hoverBgGray50} cursor-pointer`}`}
      >
        <span className={value ? `${colorTokens.text.primary}` : `${colorTokens.text.subtle}`}>
          {getDisplayText()}
        </span>
        <div className="flex items-center gap-1">
          {value && !disabled && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation()
                handleClear()
              }}
              aria-label={t('common:clearSearch')}
              className={`rounded p-0.5 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray200} ${colorTokens.variants.hoverTextGray600}`}
            >
              <X className="h-4 w-4" />
            </button>
          )}
          <ChevronDown
            className={`h-4 w-4 ${colorTokens.text.disabled} transition-transform ${isOpen ? 'rotate-180' : ''}`}
          />
        </div>
      </div>

      {/* Error message */}
      {error && <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{error}</p>}

      {/* Dropdown */}
      {isOpen && (
        <div className={`absolute left-0 top-full z-50 mt-1 w-full min-w-[400px] rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} shadow-lg`}>
          {/* Search input */}
          <div className={`border-b ${colorTokens.border.subtle} p-3`}>
            <div className="relative">
              <Search className={`absolute inset-y-0 start-0 ms-3 h-full w-4 ${colorTokens.text.disabled}`} />
              <input
                ref={inputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value)
                }}
                placeholder={config.searchPlaceholder}
                className={`w-full rounded-lg border ${colorTokens.border.default} py-2 pe-10 ps-10 text-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => {
                    setSearchQuery('')
                  }}
                  aria-label={t('common:clearSearch')}
                  className={`absolute inset-y-0 end-0 flex items-center pe-3 ${colorTokens.text.disabled} ${colorTokens.variants.hoverTextGray600}`}
                >
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
          </div>

          {/* Results list */}
          <div className="max-h-60 overflow-y-auto">
            {isLoading ? (
              <div className={`p-4 text-center text-sm ${colorTokens.text.subtle}`}>
                {t('common:status.loading')}
              </div>
            ) : filteredDocuments.length === 0 ? (
              <div className="p-4 text-center text-sm">
                <Icon className={`mx-auto h-8 w-8 ${colorTokens.text.faint}`} />
                <p className={`mt-2 ${colorTokens.text.subtle}`}>
                  {searchQuery ? config.noResultsMessage : config.noDataMessage}
                </p>
              </div>
            ) : (
              <ul className={`divide-y ${colorTokens.border.dividerSubtle}`}>
                {filteredDocuments.map((document) => (
                  <li key={document.id}>
                    <button
                      type="button"
                      onClick={() => {
                        handleSelect(document)
                      }}
                      className={`flex w-full items-center gap-3 px-4 py-3 text-start ${colorTokens.variants.hoverBgGray50} ${
                        document.id === value?.id ? `${colorTokens.intent.primary.bgSubtle}` : ''
                      }`}
                    >
                      {config.renderItem ? config.renderItem(document) : renderDefaultItem(document)}
                    </button>
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
