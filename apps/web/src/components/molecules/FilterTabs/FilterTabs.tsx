import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
interface FilterTab<T extends string> {
  value: T
  label: string
  count?: number
}

interface FilterTabsProps<T extends string> {
  tabs: FilterTab<T>[]
  value: T
  onChange: (value: T) => void
  className?: string
}

export function FilterTabs<T extends string>({
  tabs,
  value,
  onChange,
  className = '',
}: FilterTabsProps<T>) {
  return (
    <div className={`flex gap-1 rounded-lg ${colorTokens.surface.muted} p-1 ${className}`}>
      {tabs.map((tab) => (
        <button
          key={tab.value}
          type="button"
          onClick={() => {
            onChange(tab.value)
          }}
          className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
            value === tab.value
              ? `${colorTokens.surface.base} ${colorTokens.text.primary} shadow-sm`
              : `${colorTokens.text.muted} ${colorTokens.variants.hoverTextGray900}`
          }`}
        >
          {tab.label}
          {tab.count !== undefined && (
            <span
              className={`ms-1.5 rounded-full px-1.5 py-0.5 text-xs ${
                value === tab.value ? `${colorTokens.surface.muted} ${colorTokens.text.muted}` : `${colorTokens.surface.subdued} ${colorTokens.text.subtle}`
              }`}
            >
              {tab.count}
            </span>
          )}
        </button>
      ))}
    </div>
  )
}
