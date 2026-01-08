import { Select } from '../../atoms'

export interface EnumFilterOption {
  value: string
  label: string
}

export interface EnumFilterProps {
  label?: string
  value?: string | undefined
  onChange: (value: string | undefined) => void
  options: EnumFilterOption[]
  placeholder?: string
  className?: string
}

export function EnumFilter({
  label,
  value,
  onChange,
  options,
  placeholder = 'Select...',
  className,
}: EnumFilterProps) {
  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const newValue = e.target.value
    onChange(newValue === '' ? undefined : newValue)
  }

  return (
    <div className={className}>
      {label && <label className="block text-sm font-medium text-gray-700 mb-1.5">{label}</label>}
      <Select value={value ?? ''} onChange={handleChange}>
        <option value="">{placeholder}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </div>
  )
}
