import { X } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export interface FilterConfig {
  label: string
  type: 'enum' | 'boolean' | 'range' | 'text'
}

export interface ActiveFiltersProps {
  filters: Record<string, unknown>
  onRemove: (key: string) => void
  filterConfig: Record<string, FilterConfig>
}

export function ActiveFilters({ filters, onRemove, filterConfig }: ActiveFiltersProps) {
  const activeFilters = Object.entries(filters).filter(([_, value]) => value !== undefined && value !== '')

  if (activeFilters.length === 0) {
    return null
  }

  const formatValue = (value: unknown): string => {
    if (value === true) return 'Yes'
    if (value === false) return 'No'
    if (typeof value === 'string') return value
    if (typeof value === 'number') return value.toString()
    return String(value)
  }

  return (
    <div className="flex flex-wrap gap-2">
      {activeFilters.map(([key, value]) => {
        const config = filterConfig[key]
        if (!config) return null

        return (
          <span
            key={key}
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium ${colorTokens.intent.primary.bgSubtle} ${colorTokens.intent.primary.textStrong} border ${colorTokens.intent.primary.borderSubtle}`}
          >
            <span>
              {config.label}: {formatValue(value)}
            </span>
            <button
              onClick={() => { onRemove(key); }}
              className={`${colorTokens.variants.hoverTextBlue900} focus:outline-none`}
              aria-label={`Remove ${config.label} filter`}
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </span>
        )
      })}
    </div>
  )
}
