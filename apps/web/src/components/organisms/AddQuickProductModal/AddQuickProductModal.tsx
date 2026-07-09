import { useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../Modal'
import { FormField } from '../../atoms/FormField'
import { Input } from '../../atoms/Input'
import { Button } from '../../atoms/Button'
import { apiPost } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { TaxConfigurationField } from '../../molecules/TaxConfigurationField'
import type { ProductPrefill } from '../../../features/products/productPrefill'

interface Product {
  id: string
  name: string
  sku: string | null
  is_physical: boolean
  sale_price: number
  cost_price: number
  tax_rate: number
  quantity_decimals?: number | null
}

interface QuickProductFormData {
  name: string
  sku: string
  sale_price: string
  tax_rate: string
  tax_configuration_id: string | null
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

  /**
   * Optional seed values applied every time the modal opens (create-only,
   * additive — absent prefill keeps the previous empty-form behavior).
   * `cost` is accepted for contract parity but not used: the quick form has
   * no cost field; costing comes from the purchase commit (WAC).
   */
  prefill?: ProductPrefill
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
  prefill,
}: AddQuickProductModalProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const queryClient = useQueryClient()

  // Form state with React Hook Form
  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors },
  } = useForm<QuickProductFormData>({
    defaultValues: {
      name: prefill?.name ?? '',
      sku: '',
      sale_price: prefill?.sale_price ?? '',
      tax_rate: prefill?.tax_rate ?? '',
      tax_configuration_id: null,
    },
  })

  // Reset form only on the closed→open transition (also re-seeds from
  // prefill on every open). `prefill` is intentionally NOT a trigger here:
  // callers may pass a referentially-new but value-identical prefill object
  // on unrelated parent re-renders (e.g. inline buildProductPrefill(line)),
  // and re-running reset() while the modal is open would silently wipe
  // whatever the user has typed so far.
  const wasOpenRef = useRef(false)
  useEffect(() => {
    if (isOpen && !wasOpenRef.current) {
      reset({
        name: prefill?.name ?? '',
        sku: '',
        sale_price: prefill?.sale_price ?? '',
        tax_rate: prefill?.tax_rate ?? '',
        tax_configuration_id: null,
      })
    }
    wasOpenRef.current = isOpen
  }, [isOpen, prefill, reset])

  // React Query mutation
  const mutation = useMutation({
    mutationFn: (data: QuickProductFormData) => {
      // Transform data for API
      const payload = {
        name: data.name,
        sku: data.sku || null,
        is_physical: true,
        sale_price: parseFloat(data.sale_price),
        cost_price: 0, // Default cost for quick creation
        tax_rate: parseFloat(data.tax_rate),
        is_active: true,
      }
      return apiPost<Product>('/products', payload)
    },
    onSuccess: async (product) => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['products']) })
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

            <TaxConfigurationField
              label={t('common:tax.selectPlaceholder')}
              required
              {...(errors.tax_rate?.message !== undefined && { error: errors.tax_rate.message })}
              value={watch('tax_configuration_id') ?? null}
              onChange={(configId, taxRate) => {
                setValue('tax_configuration_id', configId)
                setValue('tax_rate', taxRate)
              }}
            />
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
