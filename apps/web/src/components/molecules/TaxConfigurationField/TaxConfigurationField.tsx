import { FormField } from '../../atoms/FormField'
import { TaxConfigurationSelect } from '../../atoms/TaxConfigurationSelect'
import type { TaxConfigurationSelectProps } from '../../atoms/TaxConfigurationSelect'

export interface TaxConfigurationFieldProps extends TaxConfigurationSelectProps {
  label?: string
  error?: string
  required?: boolean
  htmlFor?: string
  className?: string
}

export function TaxConfigurationField({
  label,
  error,
  required,
  htmlFor,
  className,
  ...selectProps
}: TaxConfigurationFieldProps) {
  return (
    <FormField label={label} error={error} required={required} htmlFor={htmlFor} className={className}>
      <TaxConfigurationSelect {...selectProps} error={!!error} />
    </FormField>
  )
}
