import { useState, useRef, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Search, X, ChevronDown, Plus, User } from 'lucide-react'
import { api } from '../../lib/api'

interface Partner {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
  email?: string
  phone?: string
}

interface PartnersResponse {
  data: Partner[]
}

interface PartnerSearchSelectProps {
  value: string
  onChange: (partnerId: string) => void
  partnerType?: 'customer' | 'supplier' | undefined
  placeholder?: string | undefined
  error?: string | undefined
  onAddNew?: (() => void) | undefined
  disabled?: boolean | undefined
}

export function PartnerSearchSelect({
  value,
  onChange,
  partnerType,
  placeholder,
  error,
  onAddNew,
  disabled = false,
}: PartnerSearchSelectProps) {
  const { t } = useTranslation()
  const [isOpen, setIsOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  // Fetch partners with search
  const { data: partnersData, isLoading } = useQuery({
    queryKey: ['partners-search', partnerType, searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (partnerType) {
        params.append('type', partnerType)
      }
      if (searchQuery) {
        params.append('search', searchQuery)
      }
      const query = params.toString()
      const response = await api.get<PartnersResponse>(`/partners${query ? `?${query}` : ''}`)
      return response.data
    },
    enabled: isOpen,
    staleTime: 30000,
  })

  // Fetch selected partner for display
  const { data: selectedPartnerData } = useQuery({
    queryKey: ['partner', value],
    queryFn: async () => {
      const response = await api.get<{ data: Partner }>(`/partners/${value}`)
      return response.data.data
    },
    enabled: Boolean(value) && !isOpen,
    staleTime: 60000,
  })

  const partners = partnersData?.data ?? []
  const selectedPartner = selectedPartnerData

  // Close dropdown when clicking outside
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
        setSearchQuery('')
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Focus input when dropdown opens
  useEffect(() => {
    if (isOpen && inputRef.current) {
      inputRef.current.focus()
    }
  }, [isOpen])

  const handleSelect = (partner: Partner) => {
    onChange(partner.id)
    setIsOpen(false)
    setSearchQuery('')
  }

  const handleClear = () => {
    onChange('')
    setSearchQuery('')
  }

  const getPartnerLabel = () => {
    if (partnerType === 'customer') return t('partners.customer', 'Customer')
    if (partnerType === 'supplier') return t('partners.supplier', 'Supplier')
    return t('partners.partner', 'Partner')
  }

  return (
    <div ref={containerRef} className="relative">
      {/* Selected value display / trigger */}
      <button
        type="button"
        onClick={() => !disabled && setIsOpen(!isOpen)}
        disabled={disabled}
        className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-start shadow-sm transition-colors ${
          error
            ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
        } ${disabled ? 'bg-gray-100 cursor-not-allowed' : 'bg-white hover:bg-gray-50'}`}
      >
        <span className={selectedPartner ? 'text-gray-900' : 'text-gray-500'}>
          {selectedPartner ? selectedPartner.name : (placeholder ?? `${t('actions.select', 'Select')} ${getPartnerLabel().toLowerCase()}`)}
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
          <ChevronDown className={`h-4 w-4 text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </div>
      </button>

      {/* Dropdown */}
      {isOpen && (
        <div className="absolute left-0 top-full z-50 mt-1 w-full min-w-[300px] rounded-lg border border-gray-200 bg-white shadow-lg">
          {/* Search input */}
          <div className="p-3 border-b border-gray-200">
            <div className="relative">
              <Search className="absolute inset-y-0 start-0 ms-3 h-full w-4 text-gray-400" />
              <input
                ref={inputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder={t('partners.searchPlaceholder', 'Search by name, email, or phone...')}
                className="w-full rounded-lg border border-gray-300 py-2 pe-10 ps-10 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => setSearchQuery('')}
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
                {t('status.loading', 'Loading...')}
              </div>
            ) : partners.length === 0 ? (
              <div className="p-4 text-center text-sm">
                <User className="mx-auto h-8 w-8 text-gray-300" />
                <p className="mt-2 text-gray-500">
                  {searchQuery
                    ? t('partners.noSearchResults', 'No partners found')
                    : t('partners.noPartners', 'No partners available')}
                </p>
              </div>
            ) : (
              <ul className="divide-y divide-gray-100">
                {partners.map((partner) => (
                  <li key={partner.id}>
                    <button
                      type="button"
                      onClick={() => handleSelect(partner)}
                      className={`flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 ${
                        partner.id === value ? 'bg-blue-50' : ''
                      }`}
                    >
                      <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100">
                        <User className="h-4 w-4 text-gray-500" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="text-sm font-medium text-gray-900 truncate">
                          {partner.name}
                        </div>
                        {(partner.email || partner.phone) && (
                          <div className="text-xs text-gray-500 truncate">
                            {partner.email ?? partner.phone}
                          </div>
                        )}
                      </div>
                      {partner.id === value && (
                        <div className="flex-shrink-0">
                          <div className="h-2 w-2 rounded-full bg-blue-600" />
                        </div>
                      )}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Add new button */}
          {onAddNew && (
            <div className="border-t border-gray-200 p-2">
              <button
                type="button"
                onClick={() => {
                  setIsOpen(false)
                  onAddNew()
                }}
                className="w-full flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50 transition-colors"
              >
                <Plus className="h-4 w-4" />
                {t('partners.addNew', 'Add new')} {getPartnerLabel().toLowerCase()}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
