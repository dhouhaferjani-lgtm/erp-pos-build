import { useTaxConfigurations } from './useTaxConfigurations'
import { formatPercent } from '@/lib/format'

export function useTaxConfigName(configId: string | null | undefined): string | null {
  const { data: configs } = useTaxConfigurations()
  if (!configId || !configs) return null
  const config = configs.find((c) => c.id === configId)
  if (!config) return null
  if (config.tax_type === 'PERCENTAGE' && config.percentage_rate) {
    return `${config.name} (${formatPercent(config.percentage_rate)})`
  }
  if (config.tax_type === 'FIXED_AMOUNT' && config.fixed_amount) {
    return `${config.name} (${config.fixed_amount})`
  }
  return config.name
}
