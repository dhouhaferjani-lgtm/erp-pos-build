import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
export interface BooleanFilterProps {
  label?: string
  value?: boolean | undefined
  onChange: (value: boolean | undefined) => void
  className?: string
}

export function BooleanFilter({
  label,
  value,
  onChange,
  className,
}: BooleanFilterProps) {
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    onChange(e.target.checked ? true : undefined)
  }

  return (
    <div className={className}>
      <label className="flex items-center gap-2 cursor-pointer">
        <input
          type="checkbox"
          checked={value ?? false}
          onChange={handleChange}
          className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
        />
        {label && <span className={`text-sm font-medium ${colorTokens.text.secondary}`}>{label}</span>}
      </label>
    </div>
  )
}
