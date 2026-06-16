import { textColors, colors } from '@/lib/designTokens'
import { Checkbox } from '@/components/atoms'

interface FieldComparisonRowProps {
  label: string
  userValue: string | null
  enrichedValue: string | null
  checked: boolean
  onToggle: () => void
  highlight?: boolean
}

export function FieldComparisonRow({
  label,
  userValue,
  enrichedValue,
  checked,
  onToggle,
  highlight = false,
}: FieldComparisonRowProps) {
  return (
    <div className="grid grid-cols-2 gap-0">
      <div className="border-r border-b px-3.5 py-3">
        <div className={`mb-1 text-xs ${textColors.secondary}`}>{label}</div>
        <div className={`text-sm ${textColors.primary}`}>
          {userValue ?? <span className={textColors.disabled}>&mdash;</span>}
        </div>
      </div>
      <div
        className={`border-b px-3.5 py-3 flex items-start gap-2 ${highlight ? colors.success[50] : ''}`}
      >
        <Checkbox
          checked={checked}
          onChange={onToggle}
          className="mt-1"
        />
        <div className="flex-1">
          <div className={`mb-1 text-xs ${textColors.secondary}`}>{label}</div>
          <div
            className={`text-sm ${checked ? `font-medium ${textColors.success}` : ''}`}
          >
            {enrichedValue ?? <span className={textColors.disabled}>&mdash;</span>}
          </div>
        </div>
      </div>
    </div>
  )
}
