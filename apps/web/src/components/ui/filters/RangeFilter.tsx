import { Input } from '../../atoms'

export interface RangeFilterProps {
  label?: string
  min?: string | undefined
  max?: string | undefined
  onMinChange: (value: string | undefined) => void
  onMaxChange: (value: string | undefined) => void
  placeholder?: string
  className?: string
}

export function RangeFilter({
  label,
  min,
  max,
  onMinChange,
  onMaxChange,
  placeholder = '',
  className,
}: RangeFilterProps) {
  const handleMinChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value
    onMinChange(value === '' ? undefined : value)
  }

  const handleMaxChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value
    onMaxChange(value === '' ? undefined : value)
  }

  return (
    <div className={className}>
      {label && <label className="block text-sm font-medium text-gray-700 mb-1.5">{label}</label>}
      <div className="grid grid-cols-2 gap-2">
        <Input
          type="number"
          placeholder={`Min ${placeholder}`}
          value={min ?? ''}
          onChange={handleMinChange}
        />
        <Input
          type="number"
          placeholder={`Max ${placeholder}`}
          value={max ?? ''}
          onChange={handleMaxChange}
        />
      </div>
    </div>
  )
}
