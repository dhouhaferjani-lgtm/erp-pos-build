import { useState } from 'react'
import { UserPicker } from '@/components/molecules/pickers/UserPicker'

interface UserSelectorProps {
  value: string | null
  onChange: (userId: string | null) => void
  label: string
  required?: boolean
  excludeUserIds?: string[]
  helperText?: string
  disabled?: boolean
}

export function UserSelector({
  value,
  onChange,
  label,
  required = false,
  excludeUserIds = [],
  helperText,
  disabled = false,
}: UserSelectorProps) {
  const [selectedLabel, setSelectedLabel] = useState<string | null>(null)

  return (
    <div className="space-y-1">
      <UserPicker
        value={value}
        selectedLabel={selectedLabel}
        onChange={(userId, name) => {
          setSelectedLabel(name ?? null)
          onChange(userId)
        }}
        label={required ? `${label} *` : label}
        excludeUserIds={excludeUserIds}
        disabled={disabled}
      />
      {helperText ? <p className="text-sm text-muted-foreground">{helperText}</p> : null}
    </div>
  )
}
