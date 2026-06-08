import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import type { Terminal, CreateTerminalInput, UpdateTerminalInput } from '../hooks/useTerminals'

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
  const { t } = useTranslation()
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

  return (
    <form onSubmit={handleFormSubmit} className="space-y-6">
      {/* Terminal Code (only for create) */}
      {!isEditMode && (
        <div>
          <label htmlFor="code" className="block text-sm font-medium text-gray-700">
            {t('pos.terminal.code')}
          </label>
          <p className="mt-1 text-sm text-gray-500">
            {t('common.leaveBlankForAutoGenerate')}
          </p>
          <input
            {...register('code', {
              pattern: {
                value: /^[A-Z0-9]+$/,
                message: t('validation.terminalCodeFormat'),
              },
            })}
            type="text"
            id="code"
            placeholder="POS01"
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
          />
          {'code' in errors && errors.code && (
            <p className="mt-1 text-sm text-red-600">{errors.code.message}</p>
          )}
        </div>
      )}

      {/* Terminal Name */}
      <div>
        <label htmlFor="name" className="block text-sm font-medium text-gray-700">
          {t('pos.terminal.name')} <span className="text-red-500">*</span>
        </label>
        <input
          {...register('name', {
            required: t('validation.required'),
            maxLength: {
              value: 100,
              message: t('validation.maxLength', { max: 100 }),
            },
          })}
          type="text"
          id="name"
          placeholder={t('pos.terminal.namePlaceholder')}
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
        />
        {errors.name && (
          <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>
        )}
      </div>

      {/* Location */}
      <div>
        <label htmlFor="location_id" className="block text-sm font-medium text-gray-700">
          {t('pos.terminal.location')} <span className="text-red-500">*</span>
        </label>
        <select
          {...register('location_id', {
            required: t('validation.required'),
          })}
          id="location_id"
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
        >
          <option value="">{t('common.selectOption')}</option>
          {locations.map((location) => (
            <option key={location.id} value={location.id}>
              {location.name} ({location.code})
            </option>
          ))}
        </select>
        {errors.location_id && (
          <p className="mt-1 text-sm text-red-600">{errors.location_id.message}</p>
        )}
      </div>

      {/* Description */}
      <div>
        <label htmlFor="description" className="block text-sm font-medium text-gray-700">
          {t('pos.terminal.description')}
        </label>
        <textarea
          {...register('description', {
            maxLength: {
              value: 500,
              message: t('validation.maxLength', { max: 500 }),
            },
          })}
          id="description"
          rows={3}
          placeholder={t('pos.terminal.descriptionPlaceholder')}
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
        />
        {errors.description && (
          <p className="mt-1 text-sm text-red-600">{errors.description.message}</p>
        )}
      </div>

      {/* Discount Settings */}
      <div className="border-t border-gray-200 pt-4">
        <h4 className="text-sm font-medium text-gray-900 mb-4">
          {t('settings:terminalDiscount.title')}
        </h4>

        {/* Max Discount Percent */}
        <div className="mb-4">
          <label htmlFor="max_discount_percent" className="block text-sm font-medium text-gray-700">
            {t('settings:terminalDiscount.maxPercent')}
          </label>
          <p className="mt-1 text-sm text-gray-500">
            {t('settings:terminalDiscount.maxPercentHelp')}
          </p>
          <input
            {...register('max_discount_percent', {
              valueAsNumber: true,
              min: {
                value: 0,
                message: t('validation.min', { min: 0 }),
              },
              max: {
                value: 100,
                message: t('validation.max', { max: 100 }),
              },
            })}
            type="number"
            id="max_discount_percent"
            min={0}
            max={100}
            step={1}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
          />
          {'max_discount_percent' in errors && errors.max_discount_percent && (
            <p className="mt-1 text-sm text-red-600">{errors.max_discount_percent.message}</p>
          )}
        </div>

        {/* Allow Line Item Discounts */}
        <div className="mb-4 flex items-center gap-3">
          <input
            {...register('allow_line_discounts')}
            type="checkbox"
            id="allow_line_discounts"
            className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <label htmlFor="allow_line_discounts" className="text-sm font-medium text-gray-700">
            {t('settings:terminalDiscount.allowLine')}
          </label>
        </div>

        {/* Allow Transaction Discounts */}
        <div className="mb-4 flex items-center gap-3">
          <input
            {...register('allow_transaction_discounts')}
            type="checkbox"
            id="allow_transaction_discounts"
            className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <label htmlFor="allow_transaction_discounts" className="text-sm font-medium text-gray-700">
            {t('settings:terminalDiscount.allowTransaction')}
          </label>
        </div>
      </div>

      {/* Form Actions */}
      <div className="flex justify-end gap-3 pt-4 border-t border-gray-200">
        <button
          type="button"
          onClick={onCancel}
          disabled={isSubmitting}
          className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {t('common.cancel')}
        </button>
        <button
          type="submit"
          disabled={isSubmitting}
          className="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {isSubmitting
            ? t('common.saving')
            : isEditMode
              ? t('common.update')
              : t('common.create')}
        </button>
      </div>
    </form>
  )
}
