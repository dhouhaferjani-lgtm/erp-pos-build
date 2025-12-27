import { useState, useRef, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Search, X, ChevronDown } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { api } from '../../lib/api'

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
  value?: T | null
  onChange: (document: T | null) => void
  config: DocumentSearchConfig<T>
  partnerId?: string
  required?: boolean
  disabled?: boolean
  className?: string
  label?: string
  error?: string
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
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const Icon = config.icon

  // Fetch documents with search
  const { data: documentsData, isLoading } = useQuery({
    queryKey: [config.queryKey, searchQuery, partnerId, config.additionalFilters],
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
    enabled: isOpen,
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

  const getDocumentNumber = (doc: T) => {
    return doc.document_number || doc.number || doc.id
  }

  const formatCurrency = (amount: number | string | undefined, currency: string = 'EUR') => {
    if (amount === undefined) return ''
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('fr-FR', {
      style: 'currency',
      currency: currency,
    }).format(num)
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
    const partner = value.partner?.name || t('common:unknown')
    const total = value.total ? formatCurrency(value.total, value.currency) : ''

    return `${docNumber} - ${partner}${total ? ` - ${total}` : ''}`
  }

  const renderDefaultItem = (document: T) => {
    const docNumber = getDocumentNumber(document)
    const partner = document.partner?.name || t('common:unknown')
    const total = document.total ? formatCurrency(document.total, document.currency) : null
    const date = new Date(document.document_date).toLocaleDateString()

    return (
      <>
        <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100">
          <Icon className="h-4 w-4 text-gray-500" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className="text-sm font-medium text-gray-900">{docNumber}</span>
            <span className="text-xs text-gray-500">{date}</span>
          </div>
          <div className="text-xs text-gray-500 truncate">{partner}</div>
          {total && (
            <div className="mt-1 text-xs text-gray-700">
              {t('common:total')}: {total}
            </div>
          )}
        </div>
        {document.id === value?.id && (
          <div className="flex-shrink-0">
            <div className="h-2 w-2 rounded-full bg-blue-600" />
          </div>
        )}
      </>
    )
  }

  return (
    <div ref={containerRef} className={`relative ${className}`}>
      {/* Label */}
      {label && (
        <label className="mb-1 block text-sm font-medium text-gray-700">
          {label}
          {required && <span className="ms-1 text-red-500">*</span>}
        </label>
      )}

      {/* Selected value display / trigger */}
      <button
        type="button"
        onClick={() => {
          if (!disabled) setIsOpen(!isOpen)
        }}
        disabled={disabled}
        className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-start shadow-sm transition-colors ${
          error
            ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
        } ${disabled ? 'bg-gray-100 cursor-not-allowed' : 'bg-white hover:bg-gray-50'}`}
      >
        <span className={value ? 'text-gray-900' : 'text-gray-500'}>
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
              className="rounded p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-600"
            >
              <X className="h-4 w-4" />
            </button>
          )}
          <ChevronDown
            className={`h-4 w-4 text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          />
        </div>
      </button>

      {/* Error message */}
      {error && <p className="mt-1 text-sm text-red-600">{error}</p>}

      {/* Dropdown */}
      {isOpen && (
        <div className="absolute left-0 top-full z-50 mt-1 w-full min-w-[400px] rounded-lg border border-gray-200 bg-white shadow-lg">
          {/* Search input */}
          <div className="border-b border-gray-200 p-3">
            <div className="relative">
              <Search className="absolute inset-y-0 start-0 ms-3 h-full w-4 text-gray-400" />
              <input
                ref={inputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value)
                }}
                placeholder={config.searchPlaceholder}
                className="w-full rounded-lg border border-gray-300 py-2 pe-10 ps-10 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => {
                    setSearchQuery('')
                  }}
                  className="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                >
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
          </div>

          {/* Results list */}
          <div className="max-h-60 overflow-y-auto">
            {isLoading ? (
              <div className="p-4 text-center text-sm text-gray-500">
                {t('common:status.loading')}
              </div>
            ) : filteredDocuments.length === 0 ? (
              <div className="p-4 text-center text-sm">
                <Icon className="mx-auto h-8 w-8 text-gray-300" />
                <p className="mt-2 text-gray-500">
                  {searchQuery ? config.noResultsMessage : config.noDataMessage}
                </p>
              </div>
            ) : (
              <ul className="divide-y divide-gray-100">
                {filteredDocuments.map((document) => (
                  <li key={document.id}>
                    <button
                      type="button"
                      onClick={() => {
                        handleSelect(document)
                      }}
                      className={`flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 ${
                        document.id === value?.id ? 'bg-blue-50' : ''
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
