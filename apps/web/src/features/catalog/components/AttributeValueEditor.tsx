import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { toast } from 'sonner'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { getErrorMessage } from '@/lib/api'
import { useAttributeValues, useAddAttributeValue } from '../hooks/useVariants'
import type { ProductAttribute } from '../api/variantApi'
import { Button, Input } from '@/components/atoms'

interface AttributeValueEditorProps {
  attribute: ProductAttribute
  canEdit: boolean
}

/**
 * Lists the values belonging to an attribute and provides an inline form to add
 * new ones. For color attributes a hex swatch + picker is exposed.
 */
export function AttributeValueEditor({ attribute, canEdit }: AttributeValueEditorProps) {
  const { t } = useTranslation()
  const { data: values, isLoading } = useAttributeValues(attribute.id)
  const addValue = useAddAttributeValue(attribute.id)

  const [code, setCode] = useState('')
  const [label, setLabel] = useState('')
  const [hexColor, setHexColor] = useState('#000000')

  const isColor = attribute.data_type === 'color'

  const handleAdd = async () => {
    if (code.trim() === '' || label.trim() === '') {
      return
    }
    try {
      await addValue.mutateAsync({
        code: code.trim(),
        label: label.trim(),
        hex_color: isColor ? hexColor : null,
      })
      setCode('')
      setLabel('')
      setHexColor('#000000')
      toast.success(t('catalog:attributes.valueAdded'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  return (
    <div className="mt-3 space-y-3">
      <h4 className={`text-sm font-medium ${textColors.secondary}`}>
        {t('catalog:attributes.values')}
      </h4>

      {isLoading ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('common:loading')}</p>
      ) : (values ?? []).length === 0 ? (
        <p className={`text-sm ${textColors.tertiary}`}>
          {t('catalog:attributes.noValues')}
        </p>
      ) : (
        <ul className="flex flex-wrap gap-2">
          {(values ?? []).map((value) => (
            <li
              key={value.id}
              className={`inline-flex items-center gap-2 ${tokens.badge.base} ${tokens.badge.gray}`}
            >
              {value.hex_color !== null && value.hex_color !== '' ? (
                <span
                  className={`inline-block h-3 w-3 rounded-full border ${borderColors.default}`}
                  style={{ backgroundColor: value.hex_color }}
                  aria-hidden="true"
                />
              ) : null}
              <span>{value.label}</span>
              <span className={`font-mono ${textColors.tertiary}`}>{value.code}</span>
            </li>
          ))}
        </ul>
      )}

      {canEdit ? (
        <div className="flex flex-wrap items-end gap-2">
          <div>
            <label
              htmlFor={`value-code-${attribute.id}`}
              className={tokens.label.base}
            >
              {t('catalog:attributes.valueCode')}
            </label>
            <Input
              id={`value-code-${attribute.id}`}
              type="text"
              value={code}
              onChange={(e) => { setCode(e.target.value) }}
            />
          </div>
          <div>
            <label
              htmlFor={`value-label-${attribute.id}`}
              className={tokens.label.base}
            >
              {t('catalog:attributes.valueLabel')}
            </label>
            <Input
              id={`value-label-${attribute.id}`}
              type="text"
              value={label}
              onChange={(e) => { setLabel(e.target.value) }}
            />
          </div>
          {isColor ? (
            <div>
              <label
                htmlFor={`value-hex-${attribute.id}`}
                className={tokens.label.base}
              >
                {t('catalog:attributes.hexColor')}
              </label>
              <input
                id={`value-hex-${attribute.id}`}
                type="color"
                value={hexColor}
                onChange={(e) => { setHexColor(e.target.value) }}
                className={`mt-1 h-10 w-16 rounded-md border ${borderColors.default}`}
              />
            </div>
          ) : null}
          <Button variant="secondary"
            type="button"
            onClick={() => { void handleAdd() }}
            disabled={addValue.isPending}
          >
            <Plus className="mr-1 h-4 w-4" />
            {t('catalog:attributes.addValue')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
