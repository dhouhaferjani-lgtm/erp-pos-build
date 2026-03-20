import { useState, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { api } from '@/lib/api'
import { Input } from '@/components/atoms/Input/Input'

interface PartnerOption {
  id: string
  name: string
  type: string
}

interface PartnerSearchResponse {
  data: PartnerOption[]
}

interface PartnerSelectProps {
  value: string
  onChange: (value: string) => void
}

export function PartnerSelect({ value, onChange }: PartnerSelectProps) {
  const { t } = useTranslation(['crm'])
  const [search, setSearch] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [selectedName, setSelectedName] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)

  const { data: results } = useQuery({
    queryKey: ['partners', 'search', search],
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '10', is_active: 'true' })
      if (search) params.set('search', search)
      const response = await api.get<PartnerSearchResponse>(`/partners?${params.toString()}`)
      return response.data.data
    },
    enabled: isOpen && search.length > 0,
  })

  useEffect(() => {
    if (!value) {
      setSelectedName('')
      return
    }
    if (results) {
      const match = results.find((p) => p.id === value)
      if (match) setSelectedName(match.name)
    }
  }, [value, results])

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => { document.removeEventListener('mousedown', handleClickOutside); }
  }, [])

  if (value && selectedName) {
    return (
      <div className="flex items-center gap-2 rounded-md border border-gray-300 bg-gray-50 px-3 py-2">
        <span className="flex-1 text-sm text-gray-900">{selectedName}</span>
        <button
          type="button"
          onClick={() => {
            onChange('')
            setSelectedName('')
            setSearch('')
          }}
          className="text-gray-400 hover:text-gray-600"
        >
          <X className="h-4 w-4" />
        </button>
      </div>
    )
  }

  return (
    <div ref={containerRef} className="relative">
      <Input
        value={search}
        onChange={(e) => {
          setSearch(e.target.value)
          setIsOpen(true)
        }}
        onFocus={() => { setIsOpen(true); }}
        placeholder={t('crm:contacts.searchCompany')}
      />
      {isOpen && results && results.length > 0 && (
        <ul className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
          {results.map((partner) => (
            <li key={partner.id}>
              <button
                type="button"
                className="w-full px-3 py-2 text-left text-sm hover:bg-gray-100"
                onClick={() => {
                  onChange(partner.id)
                  setSelectedName(partner.name)
                  setSearch('')
                  setIsOpen(false)
                }}
              >
                {partner.name}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
