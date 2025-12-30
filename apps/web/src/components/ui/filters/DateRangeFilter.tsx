import { Input } from '../../atoms'

export interface DateRangeFilterProps {
  label: string
  fromValue?: string | undefined
  toValue?: string | undefined
  onFromChange: (value: string | undefined) => void
  onToChange: (value: string | undefined) => void
  className?: string | undefined
}

export function DateRangeFilter({
  label,
  fromValue,
  toValue,
  onFromChange,
  onToChange,
  className,
}: DateRangeFilterProps) {
  const handleFromChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value
    onFromChange(value === '' ? undefined : value)
  }

  const handleToChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value
    onToChange(value === '' ? undefined : value)
  }

  return (
    <div className={className}>
      <label className="block text-sm font-medium text-gray-700 mb-1.5">{label}</label>
      <div className="grid grid-cols-2 gap-2">
        <Input
          type="date"
          placeholder="From"
          value={fromValue ?? ''}
          onChange={handleFromChange}
        />
        <Input
          type="date"
          placeholder="To"
          value={toValue ?? ''}
          onChange={handleToChange}
        />
      </div>
    </div>
  )
}
