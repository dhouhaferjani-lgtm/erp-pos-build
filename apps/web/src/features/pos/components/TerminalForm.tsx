import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { tokens, borderColors } from '@/lib/designTokens'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Button } from '@/components/atoms/Button'
import type { Terminal, CreateTerminalInput, UpdateTerminalInput } from '../hooks/useTerminals'
import { Checkbox } from '@/components/atoms'

interface TerminalFormProps {
  terminal?: Terminal | undefined
  locations: Array<{ id: string; name: string; code: string }>
  isSubmitting?: boolean | undefined
  onSubmit: (data: CreateTerminalInput | UpdateTerminalInput) => void
  onCancel: () => void
}

/**
 * Form component for creating or editing terminals
 */
export function TerminalForm({
  terminal,
  locations,
  isSubmitting = false,
  onSubmit,
  onCancel,
}: TerminalFormProps) {
  const { t } = useTranslation(['common', 'pos', 'settings', 'validation'])
  const isEditMode = !!terminal

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<CreateTerminalInput | UpdateTerminalInput>({
    defaultValues: terminal
      ? {
          name: terminal.name,
          location_id: terminal.location_id,
          description: terminal.description || undefined,
          max_discount_percent: terminal.max_discount_percent != null ? Number(terminal.max_discount_percent) : 100,
          allow_line_discounts: terminal.allow_line_discounts ?? true,
          allow_transaction_discounts: terminal.allow_transaction_discounts ?? true,
        }
      : {
          name: '',
          code: '',
          location_id: '',
          description: '',
          max_discount_percent: 100,
          allow_line_discounts: true,
          allow_transaction_discounts: true,
        },
  })

  const handleFormSubmit = handleSubmit((data) => {
    onSubmit(data)
  })

  const codeError = 'code' in errors ? errors.code : undefined
  const maxDiscountError =
    'max_discount_percent' in errors ? errors.max_discount_percent : undefined

  return (
    <form onSubmit={handleFormSubmit} className="space-y-6">
      {/* Terminal Code (only for create) */}
      {!isEditMode && (
        <FormField
          label={t('pos:terminal.code')}
          htmlFor="code"
          helperText={t('common:common.leaveBlankForAutoGenerate')}
          error={codeError?.message}
        >
          <Input
            {...register('code', {
              pattern: {
                value: /^[A-Z0-9]+$/,
                message: t('common:validation.terminalCodeFormat'),
              },
            })}
            type="text"
            id="code"
            // eslint-disable-next-line local/no-untranslated-literal -- technical code example, not user-facing prose
            placeholder="POS01"
            error={!!codeError}
          />
        </FormField>
      )}

      {/* Terminal Name */}
      <FormField
        label={t('pos:terminal.name')}
        htmlFor="name"
        required
        error={errors.name?.message}
      >
        <Input
          {...register('name', {
            required: t('common:validation.required'),
            maxLength: {
              value: 100,
              message: t('common:validation.maxLength', { max: 100 }),
            },
          })}
          type="text"
          id="name"
          placeholder={t('pos:terminal.namePlaceholder')}
          error={!!errors.name}
        />
      </FormField>

      {/* Location */}
      <FormField
        label={t('pos:terminal.location')}
        htmlFor="location_id"
        required
        error={errors.location_id?.message}
      >
        <Select
          {...register('location_id', {
            required: t('common:validation.required'),
          })}
          id="location_id"
          error={!!errors.location_id}
        >
          <option value="">{t('common:common.selectOption')}</option>
          {locations.map((location) => (
            <option key={location.id} value={location.id}>
              {location.name} ({location.code})
            </option>
          ))}
        </Select>
      </FormField>

      {/* Description */}
      <FormField
        label={t('pos:terminal.description')}
        htmlFor="description"
        error={errors.description?.message}
      >
        <Textarea
          {...register('description', {
            maxLength: {
              value: 500,
              message: t('common:validation.maxLength', { max: 500 }),
            },
          })}
          id="description"
          rows={3}
          placeholder={t('pos:terminal.descriptionPlaceholder')}
        />
      </FormField>

      {/* Discount Settings */}
      <div className={cn('border-t pt-4', borderColors.light)}>
        <h4 className={cn(tokens.heading.section, 'text-sm mb-4')}>
          {t('settings:terminalDiscount.title')}
        </h4>

        {/* Max Discount Percent */}
        <div className="mb-4">
          <FormField
            label={t('settings:terminalDiscount.maxPercent')}
            htmlFor="max_discount_percent"
            helperText={t('settings:terminalDiscount.maxPercentHelp')}
            error={maxDiscountError?.message}
          >
            <Input
              {...register('max_discount_percent', {
                valueAsNumber: true,
                min: {
                  value: 0,
                  message: t('validation:min', { min: 0 }),
                },
                max: {
                  value: 100,
                  message: t('validation:max', { max: 100 }),
                },
              })}
              type="number"
              id="max_discount_percent"
              min={0}
              max={100}
              step={1}
              error={!!maxDiscountError}
            />
          </FormField>
        </div>

          {/* Allow Line Item Discounts */}
          <div className="mb-4 flex items-center gap-3">
            <Checkbox
              {...register('allow_line_discounts')}
              id="allow_line_discounts"
              className={tokens.checkbox.base}
            />
            <label htmlFor="allow_line_discounts" className={tokens.label.base}>
              {t('settings:terminalDiscount.allowLine')}
            </label>
          </div>

          {/* Allow Transaction Discounts */}
          <div className="mb-4 flex items-center gap-3">
            <Checkbox
              {...register('allow_transaction_discounts')}
              id="allow_transaction_discounts"
              className={tokens.checkbox.base}
            />
            <label htmlFor="allow_transaction_discounts" className={tokens.label.base}>
              {t('settings:terminalDiscount.allowTransaction')}
            </label>
          </div>
      </div>

      {/* Form Actions */}
      <div className={cn('flex justify-end gap-3 pt-4 border-t', borderColors.light)}>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={isSubmitting}>
          {t('common:actions.cancel')}
        </Button>
        <Button type="submit" variant="primary" disabled={isSubmitting}>
          {isSubmitting
            ? t('common:common.saving')
            : isEditMode
              ? t('common:common.update')
              : t('common:common.create')}
        </Button>
      </div>
    </form>
  )
}
