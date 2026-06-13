import { useTranslation } from 'react-i18next'
import { Search, X } from 'lucide-react'
import { Input } from '../../atoms'

export interface SearchFilterProps {
  label?: string
  value?: string | undefined
  onChange: (value: string | undefined) => void
  placeholder?: string
  className?: string
}

export function SearchFilter({
  label,
  value,
  onChange,
  placeholder,
  className,
}: SearchFilterProps) {
  const { t } = useTranslation('common')

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value
    onChange(newValue === '' ? undefined : newValue)
  }

  const handleClear = () => {
    onChange(undefined)
  }

  return (
    <div className={className}>
      {label && <label className="block text-sm font-medium text-gray-700 mb-1.5">{label}</label>}
      <div className="relative">
        <Search className="absolute start-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <Input
          type="text"
          placeholder={placeholder ?? t('actions.search')}
          value={value ?? ''}
          onChange={handleChange}
          className="ps-9 pe-9"
        />
        {value && (
          <button
            onClick={handleClear}
            className="absolute end-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
            aria-label={t('clearSearch')}
          >
            <X className="w-4 h-4" />
          </button>
        )}
      </div>
    </div>
  )
}
