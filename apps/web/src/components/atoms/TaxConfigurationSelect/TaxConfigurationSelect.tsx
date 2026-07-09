import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { formatPercent } from '../../../lib/format'
import { useTaxConfigurations } from '../../../hooks/useTaxConfigurations'
import { TaxConfigFormModal } from '../../organisms/TaxConfigFormModal'
import type { TaxConfiguration } from '../../../features/settings/types/tax'

const ADD_NEW_VALUE = '__ADD_NEW__'

export interface TaxConfigurationSelectProps {
  value: string | null
  onChange: (configId: string | null, taxRate: string) => void
  documentType?: string
  disabled?: boolean
  size?: 'sm' | 'md'
  placeholder?: string
  error?: boolean
}

function getConfigDisplayLabel(config: TaxConfiguration): string {
  if (config.tax_type === 'PERCENTAGE' && config.percentage_rate) {
    return `${config.name} (${formatPercent(config.percentage_rate)})`
  }
  if (config.tax_type === 'FIXED_AMOUNT' && config.fixed_amount) {
    return `${config.name} (${config.fixed_amount})`
  }
  return config.name
}

function getConfigRate(config: TaxConfiguration): string {
  if (config.tax_type === 'PERCENTAGE') return config.percentage_rate ?? '0'
  return config.fixed_amount ?? '0'
}

export function TaxConfigurationSelect({
  value,
  onChange,
  documentType,
  disabled = false,
  size = 'md',
  placeholder,
  error = false,
}: TaxConfigurationSelectProps) {
  const { t } = useTranslation('common')
  const { data: configs, isLoading, isError } = useTaxConfigurations()
  const [isModalOpen, setIsModalOpen] = useState(false)

  const filteredConfigs = useMemo(() => {
    if (!configs) return []
    return configs.filter((config) => {
      if (!config.is_active) return false
      if (config.applies_to !== 'LINE_ITEMS') return false
      if (!documentType) return true
      if (config.applicable_document_types.length === 0) return true
      return config.applicable_document_types.includes(documentType)
    })
  }, [configs, documentType])

  const sizeClasses = size === 'sm' ? 'py-1 px-2 text-sm' : 'py-2 px-3'

  const isStaleValue = !!(value && configs && !configs.find((c) => c.id === value))

  if (isError) {
    return (
      <input
        type="number"
        min="0"
        max="100"
        disabled={disabled}
        className={cn(tokens.select.base, sizeClasses)}
        placeholder={t('tax.loadingError')}
        onChange={(e) => { onChange(null, e.target.value || '0') }}
      />
    )
  }

  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const selectedValue = e.target.value
    if (selectedValue === ADD_NEW_VALUE) {
      e.target.value = value ?? ''
      setIsModalOpen(true)
      return
    }
    if (!selectedValue) {
      onChange(null, '0')
      return
    }
    const config = filteredConfigs.find((c) => c.id === selectedValue)
    if (config) {
      onChange(config.id, getConfigRate(config))
    }
  }

  const handleTaxCreated = (newTax: TaxConfiguration) => {
    setIsModalOpen(false)
    onChange(newTax.id, getConfigRate(newTax))
  }

  return (
    <>
      {isStaleValue && (
        <p className="text-xs text-amber-600">{t('tax.staleTaxWarning')}</p>
      )}
      <select
        value={value ?? ''}
        onChange={handleChange}
        disabled={disabled || isLoading}
        className={cn(tokens.select.base, error && tokens.select.error, sizeClasses)}
      >
        <option value="">
          {isLoading ? t('loading', 'Loading...') : (placeholder ?? t('tax.selectPlaceholder'))}
        </option>
        {filteredConfigs.map((config) => (
          <option key={config.id} value={config.id}>
            {getConfigDisplayLabel(config)}
          </option>
        ))}
        {!isLoading && (
          <option value={ADD_NEW_VALUE}>
            {t('tax.addNew')}
          </option>
        )}
      </select>
      <TaxConfigFormModal
        isOpen={isModalOpen}
        onClose={() => { setIsModalOpen(false) }}
        onSaved={handleTaxCreated}
      />
    </>
  )
}
