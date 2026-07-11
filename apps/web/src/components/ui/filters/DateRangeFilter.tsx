import { useTranslation } from 'react-i18next'
import { Input } from '../../atoms/Input'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
  const { t } = useTranslation('common')

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
      <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1.5`}>{label}</label>
      <div className="grid grid-cols-2 gap-2">
        <Input
          type="date"
          placeholder={t('dateFrom')}
          value={fromValue ?? ''}
          onChange={handleFromChange}
        />
        <Input
          type="date"
          placeholder={t('dateTo')}
          value={toValue ?? ''}
          onChange={handleToChange}
        />
      </div>
    </div>
  )
}
