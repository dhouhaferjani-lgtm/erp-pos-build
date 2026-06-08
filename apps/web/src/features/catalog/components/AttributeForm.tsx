import type { FormEvent } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { tokens, textColors } from '@/lib/designTokens'
import { getErrorMessage } from '@/lib/api'
import { useCreateAttribute } from '../hooks/useVariants'
import type { AttributeDataType, CreateAttributePayload } from '../api/variantApi'

interface AttributeFormProps {
  onCreated: () => void
  onCancel: () => void
}

interface AttributeFormValues {
  code: string
  name: string
  data_type: AttributeDataType
  is_variant_axis: boolean
}

const DATA_TYPES: AttributeDataType[] = [
  'selection',
  'color',
  'text',
  'numeric',
  'boolean',
  'date',
  'image',
]

/** Inline form to create a new product attribute. */
export function AttributeForm({ onCreated, onCancel }: AttributeFormProps) {
  const { t } = useTranslation()
  const createAttribute = useCreateAttribute()

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<AttributeFormValues>({
    defaultValues: {
      code: '',
      name: '',
      data_type: 'selection',
      is_variant_axis: true,
    },
  })

  const onSubmit = handleSubmit(async (values) => {
    const payload: CreateAttributePayload = {
      code: values.code.trim(),
      name: values.name.trim(),
      data_type: values.data_type,
      is_variant_axis: values.is_variant_axis,
    }
    try {
      await createAttribute.mutateAsync(payload)
      toast.success(t('catalog:attributes.created'))
      onCreated()
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  })

  const handleFormSubmit = (e: FormEvent<HTMLFormElement>) => {
    // Stop the native form submission unconditionally so a successful save
    // never triggers a full-page navigation. react-hook-form's handleSubmit
    // also calls preventDefault, but guarding here keeps the no-navigation
    // contract independent of library internals and async timing.
    e.preventDefault()
    void onSubmit(e)
  }

  return (
    <form onSubmit={handleFormSubmit} className={`${tokens.card.base} space-y-4`}>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label htmlFor="attr-code" className={tokens.label.base}>
            {t('catalog:attributes.code')}
            <span className={tokens.label.required}> *</span>
          </label>
          <input
            id="attr-code"
            type="text"
            className={tokens.input.base}
            {...register('code', { required: true, maxLength: 100 })}
          />
          {errors.code ? (
            <p className={tokens.helperText.error}>
              {t('catalog:attributes.codeRequired')}
            </p>
          ) : null}
        </div>

        <div>
          <label htmlFor="attr-name" className={tokens.label.base}>
            {t('catalog:attributes.name')}
            <span className={tokens.label.required}> *</span>
          </label>
          <input
            id="attr-name"
            type="text"
            className={tokens.input.base}
            {...register('name', { required: true, maxLength: 255 })}
          />
          {errors.name ? (
            <p className={tokens.helperText.error}>
              {t('catalog:attributes.nameRequired')}
            </p>
          ) : null}
        </div>

        <div>
          <label htmlFor="attr-data-type" className={tokens.label.base}>
            {t('catalog:attributes.dataType')}
          </label>
          <select
            id="attr-data-type"
            className={tokens.select.base}
            {...register('data_type')}
          >
            {DATA_TYPES.map((type) => (
              <option key={type} value={type}>
                {t(`catalog:attributes.dataTypes.${type}`)}
              </option>
            ))}
          </select>
        </div>

        <div className="flex items-center">
          <label className="mt-6 inline-flex items-center gap-2">
            <input
              type="checkbox"
              className={tokens.checkbox.base}
              {...register('is_variant_axis')}
            />
            <span className={`text-sm ${textColors.secondary}`}>
              {t('catalog:attributes.isVariantAxis')}
            </span>
          </label>
        </div>
      </div>

      <div className="flex justify-end gap-3">
        <button
          type="button"
          onClick={onCancel}
          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
        >
          {t('catalog:attributes.cancel')}
        </button>
        <button
          type="submit"
          disabled={createAttribute.isPending}
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
        >
          {t('catalog:attributes.save')}
        </button>
      </div>
    </form>
  )
}
