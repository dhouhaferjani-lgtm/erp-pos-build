import { forwardRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Select } from '../../../components/atoms/Select/Select'
import { FormField } from '../../../components/atoms/FormField/FormField'
import { useUnits } from '../hooks/useUnits'

interface UnitDropdownProps {
  value?: string | undefined
  onChange?: ((value: string) => void) | undefined
  error?: boolean | undefined
  label?: string | undefined
  required?: boolean | undefined
  categoryId?: string | undefined
  disabled?: boolean | undefined
  name?: string | undefined
}

/**
 * Grouped dropdown for selecting a unit
 * Uses existing Select atom with optgroups
 */
export const UnitDropdown = forwardRef<HTMLSelectElement, UnitDropdownProps>(
  ({ value, onChange, error, label, required, categoryId, disabled, name, ...props }, ref) => {
    const { t } = useTranslation(['common', 'uom'])
    const { data: units, isLoading } = useUnits(categoryId)

    // Group units by category
    const groupedUnits = units?.reduce((acc, unit) => {
      const catName = unit.category?.name || 'Other'
      if (!acc[catName]) acc[catName] = []
      acc[catName].push(unit)
      return acc
    }, {} as Record<string, typeof units>)

    const content = (
      <Select
        ref={ref}
        name={name}
        value={value}
        onChange={(e) => onChange?.(e.target.value)}
        error={error}
        disabled={isLoading || disabled}
        {...props}
      >
        <option value="">{t('uom:selectUnit')}</option>
        {groupedUnits && Object.entries(groupedUnits).map(([categoryName, categoryUnits]) => (
          <optgroup key={categoryName} label={categoryName}>
            {categoryUnits.map((unit) => (
              <option key={unit.id} value={unit.id}>
                {unit.name} ({unit.symbol})
              </option>
            ))}
          </optgroup>
        ))}
      </Select>
    )

    if (label) {
      return (
        <FormField label={label} required={required} error={error ? t('common:required') : undefined}>
          {content}
        </FormField>
      )
    }

    return content
  }
)

UnitDropdown.displayName = 'UnitDropdown'
