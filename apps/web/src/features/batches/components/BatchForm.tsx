import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { Calendar, Package, FileText } from 'lucide-react'
import { ProductLineSelect } from '@/components/molecules/line-items'
import type { Batch } from '../types'

const batchFormSchema = z.object({
  product_id: z.string().min(1, 'Product is required'),
  batch_number: z.string().min(1, 'Batch number is required').max(100),
  expiry_date: z.string().min(1, 'Expiry date is required'),
  manufacturing_date: z.string().optional(),
  notes: z.string().optional(),
})

export type BatchFormData = z.infer<typeof batchFormSchema>

interface BatchFormProps {
  batch?: Batch
  onSubmit: (data: BatchFormData) => void
  isSubmitting?: boolean
  submitLabel?: string
}

export function BatchForm({ batch, onSubmit, isSubmitting = false, submitLabel }: BatchFormProps) {
  const { t } = useTranslation(['batches', 'common'])

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<BatchFormData>({
    resolver: zodResolver(batchFormSchema),
    defaultValues: {
      product_id: batch?.product_id || '',
      batch_number: batch?.batch_number || '',
      expiry_date: batch?.expiry_date ? batch.expiry_date.split('T')[0] : '',
      manufacturing_date: batch?.manufacturing_date ? batch.manufacturing_date.split('T')[0] : '',
      notes: batch?.notes || '',
    },
  })

  const selectedProductId = watch('product_id')

  // Update form when batch prop changes (for edit mode)
  useEffect(() => {
    if (batch) {
      setValue('product_id', batch.product_id)
      setValue('batch_number', batch.batch_number)
      setValue('expiry_date', batch.expiry_date.split('T')[0])
      setValue('manufacturing_date', batch.manufacturing_date ? batch.manufacturing_date.split('T')[0] : '')
      setValue('notes', batch.notes || '')
    }
  }, [batch, setValue])

  const handleFormSubmit = handleSubmit((data) => {
    onSubmit(data)
  })

  // Get today's date for min date validation
  const today = new Date().toISOString().split('T')[0]

  return (
    <form onSubmit={handleFormSubmit} className="space-y-6">
      {/* Product Selection */}
      <div>
        <label className="mb-2 flex items-center gap-2 text-sm font-medium text-gray-700">
          <Package className="h-4 w-4" />
          {t('batches:fields.product')}
          <span className="text-red-500">*</span>
        </label>
        <ProductLineSelect
          value={selectedProductId}
          onChange={(productId) => { setValue('product_id', productId, { shouldValidate: true }) }}
          disabled={isSubmitting || !!batch}
          placeholder={t('batches:form.selectProduct')}
          className="w-full"
        />
        {errors.product_id && (
          <p className="mt-1 text-sm text-red-600">{errors.product_id.message}</p>
        )}
        {batch && (
          <p className="mt-1 text-sm text-gray-500">
            {t('batches:form.productCannotBeChanged')}
          </p>
        )}
      </div>

      {/* Batch Number */}
      <div>
        <label htmlFor="batch_number" className="mb-2 block text-sm font-medium text-gray-700">
          {t('batches:fields.batchNumber')}
          <span className="text-red-500">*</span>
        </label>
        <input
          id="batch_number"
          type="text"
          {...register('batch_number')}
          disabled={isSubmitting}
          className="block w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500"
          placeholder={t('batches:form.enterBatchNumber')}
        />
        {errors.batch_number && (
          <p className="mt-1 text-sm text-red-600">{errors.batch_number.message}</p>
        )}
      </div>

      {/* Dates Grid */}
      <div className="grid gap-6 sm:grid-cols-2">
        {/* Expiry Date */}
        <div>
          <label htmlFor="expiry_date" className="mb-2 flex items-center gap-2 text-sm font-medium text-gray-700">
            <Calendar className="h-4 w-4" />
            {t('batches:fields.expiryDate')}
            <span className="text-red-500">*</span>
          </label>
          <input
            id="expiry_date"
            type="date"
            {...register('expiry_date')}
            disabled={isSubmitting}
            min={today}
            className="block w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500"
          />
          {errors.expiry_date && (
            <p className="mt-1 text-sm text-red-600">{errors.expiry_date.message}</p>
          )}
        </div>

        {/* Manufacturing Date */}
        <div>
          <label htmlFor="manufacturing_date" className="mb-2 flex items-center gap-2 text-sm font-medium text-gray-700">
            <Calendar className="h-4 w-4" />
            {t('batches:fields.manufacturingDate')}
            <span className="text-gray-400">({t('common:optional')})</span>
          </label>
          <input
            id="manufacturing_date"
            type="date"
            {...register('manufacturing_date')}
            disabled={isSubmitting}
            max={today}
            className="block w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500"
          />
          {errors.manufacturing_date && (
            <p className="mt-1 text-sm text-red-600">{errors.manufacturing_date.message}</p>
          )}
        </div>
      </div>

      {/* Notes */}
      <div>
        <label htmlFor="notes" className="mb-2 flex items-center gap-2 text-sm font-medium text-gray-700">
          <FileText className="h-4 w-4" />
          {t('batches:fields.notes')}
          <span className="text-gray-400">({t('common:optional')})</span>
        </label>
        <textarea
          id="notes"
          {...register('notes')}
          disabled={isSubmitting}
          rows={4}
          className="block w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500"
          placeholder={t('batches:form.enterNotes')}
        />
        {errors.notes && (
          <p className="mt-1 text-sm text-red-600">{errors.notes.message}</p>
        )}
      </div>

      {/* Submit Button */}
      <div className="flex items-center justify-end gap-3 border-t border-gray-200 pt-6">
        <button
          type="submit"
          disabled={isSubmitting}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:bg-gray-300 disabled:text-gray-500"
        >
          {isSubmitting ? (
            <>
              <span className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
              {t('common:status.saving')}
            </>
          ) : (
            submitLabel || t('common:actions.save')
          )}
        </button>
      </div>
    </form>
  )
}
