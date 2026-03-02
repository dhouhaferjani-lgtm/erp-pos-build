import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../Modal'
import { FormField } from '../../atoms/FormField'
import { Input } from '../../atoms/Input'
import { Select } from '../../atoms/Select'
import { Button } from '../../atoms/Button'
import { apiPost } from '../../../lib/api'

type ProductType = 'service' | 'consumable' | 'storable'

interface Product {
  id: string
  name: string
  sku: string | null
  type: ProductType
  sale_price: number
  cost_price: number
  tax_rate: number
}

interface QuickProductFormData {
  name: string
  sku: string
  type: ProductType | ''
  sale_price: string
  tax_rate: string
}

export interface AddQuickProductModalProps {
  /**
   * Controls modal visibility
   */
  isOpen: boolean

  /**
   * Callback when modal should close
   */
  onClose: () => void

  /**
   * Callback after successful product creation
   * Receives the newly created product
   */
  onSuccess?: (product: Product) => void
}

/**
 * AddQuickProductModal - Simplified product creation for in-flow usage
 *
 * Creates basic products quickly while building documents.
 * Only includes essential fields - full product editor stays as separate page.
 *
 * @example
 * ```tsx
 * <AddQuickProductModal
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   onSuccess={(product) => {
 *     // Add product to document line
 *     addLine(product)
 *   }}
 * />
 * ```
 */
export function AddQuickProductModal({
  isOpen,
  onClose,
  onSuccess,
}: AddQuickProductModalProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const queryClient = useQueryClient()

  // Form state with React Hook Form
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<QuickProductFormData>({
    defaultValues: {
      name: '',
      sku: '',
      type: '',
      sale_price: '',
      tax_rate: '19', // Default VAT rate (can be adjusted per country)
    },
  })

  // Reset form when modal opens
  useEffect(() => {
    if (isOpen) {
      reset({
        name: '',
        sku: '',
        type: '',
        sale_price: '',
        tax_rate: '19',
      })
    }
  }, [isOpen, reset])

  // React Query mutation
  const mutation = useMutation({
    mutationFn: (data: QuickProductFormData) => {
      // Transform data for API
      const payload = {
        name: data.name,
        sku: data.sku || null,
        type: data.type,
        sale_price: parseFloat(data.sale_price),
        cost_price: 0, // Default cost for quick creation
        tax_rate: parseFloat(data.tax_rate),
        is_active: true,
      }
      return apiPost<Product>('/products', payload)
    },
    onSuccess: (product) => {
      void queryClient.invalidateQueries({ queryKey: ['products'] })
      onSuccess?.(product)
      onClose()
    },
  })

  const onSubmit = (data: QuickProductFormData) => {
    mutation.mutate(data)
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose}>
      <ModalHeader title={t('inventory:products.addQuick')} onClose={onClose} />

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }}>
        <ModalContent>
          {/* Name */}
          <FormField
            label={t('inventory:products.name')}
            htmlFor="product-name"
            required
            error={errors.name?.message}
          >
            <Input
              id="product-name"
              {...register('name', { required: t('common:validation.required') })}
              placeholder={t('inventory:products.namePlaceholder')}
            />
          </FormField>

          {/* SKU */}
          <FormField
            label={t('inventory:products.sku')}
            htmlFor="product-sku"
            helperText={t('inventory:products.skuHelper')}
          >
            <Input
              id="product-sku"
              {...register('sku')}
              placeholder={t('inventory:products.skuPlaceholder')}
            />
          </FormField>

          {/* Type */}
          <FormField
            label={t('inventory:products.type')}
            htmlFor="product-type"
            required
            error={errors.type?.message}
          >
            <Select
              id="product-type"
              {...register('type', { required: t('common:validation.required') })}
              error={!!errors.type}
            >
              <option value="">{t('common:actions.select')}</option>
              <option value="service">{t('inventory:products.types.service')}</option>
              <option value="consumable">{t('inventory:products.types.consumable')}</option>
              <option value="storable">{t('inventory:products.types.storable')}</option>
            </Select>
          </FormField>

          {/* Sale Price and Tax Rate */}
          <div className="grid grid-cols-2 gap-4">
            <FormField
              label={t('inventory:products.salePrice')}
              htmlFor="product-sale-price"
              required
              error={errors.sale_price?.message}
            >
              <Input
                id="product-sale-price"
                type="number"
                step="0.01"
                min="0"
                {...register('sale_price', {
                  required: t('common:validation.required'),
                  min: { value: 0, message: t('inventory:products.priceMin') },
                })}
                placeholder="0.00"
              />
            </FormField>

            <FormField
              label={t('inventory:products.taxRate')}
              htmlFor="product-tax-rate"
              required
              error={errors.tax_rate?.message}
            >
              <Input
                id="product-tax-rate"
                type="number"
                step="0.01"
                min="0"
                max="100"
                {...register('tax_rate', {
                  required: t('common:validation.required'),
                  min: { value: 0, message: 'Minimum 0%' },
                  max: { value: 100, message: 'Maximum 100%' },
                })}
                placeholder="19"
              />
            </FormField>
          </div>

          {/* Info message */}
          <div className="rounded-lg bg-blue-50 p-3 text-sm text-blue-700">
            {t('inventory:products.quickCreateNote')}
          </div>

          {/* Error message */}
          {mutation.isError && (
            <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
              {mutation.error instanceof Error
                ? mutation.error.message
                : t('common:errorMessages.generic')}
            </div>
          )}
        </ModalContent>

        <ModalFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={onClose}
            disabled={mutation.isPending}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={mutation.isPending}
          >
            {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            {t('common:actions.create')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
