import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Modal, ModalContent, ModalFooter } from '../../../components/organisms/Modal/Modal'
import { Button } from '../../../components/atoms/Button'
import { Input } from '../../../components/atoms/Input'
import { Select } from '../../../components/atoms/Select'
import { FormField } from '../../../components/atoms/FormField'
import { useCategories, useCreateUnit, useUpdateUnit } from '../hooks/useUnits'
import type { Unit } from '../api/uomApi'

/**
 * Validation schema for unit form
 */
const unitFormSchema = z.object({
  categoryId: z.string().min(1, 'Category is required'),
  code: z
    .string()
    .min(1, 'Code is required')
    .max(20, 'Code must be 20 characters or less')
    .regex(/^[a-z0-9_-]+$/, 'Code must contain only lowercase letters, numbers, hyphens, and underscores'),
  name: z.string().min(1, 'Name is required').max(100, 'Name must be 100 characters or less'),
  symbol: z.string().min(1, 'Symbol is required').max(10, 'Symbol must be 10 characters or less'),
  conversionFactor: z
    .string()
    .min(1, 'Conversion factor is required')
    .refine((val) => !isNaN(parseFloat(val)) && parseFloat(val) > 0, {
      message: 'Conversion factor must be a positive number',
    }),
  decimalPlaces: z
    .number()
    .int()
    .min(0, 'Decimal places must be 0 or greater')
    .max(10, 'Decimal places must be 10 or less')
    .optional()
    .default(2),
  roundingMethod: z.enum(['half_up', 'floor', 'ceil']).optional().default('half_up'),
})

export type UnitFormData = z.infer<typeof unitFormSchema>

interface AddUnitModalProps {
  /**
   * Controls modal visibility
   */
  isOpen: boolean

  /**
   * Callback when modal closes
   */
  onClose: () => void

  /**
   * Unit to edit (if editing existing unit)
   */
  unit?: Unit

  /**
   * Pre-select category (for "Add Unit" from category view)
   */
  categoryId?: string
}

/**
 * AddUnitModal - Modal for creating or editing custom units
 *
 * This organism component provides a complete form for creating or editing
 * custom units of measure. It uses Zod validation and React Hook Form.
 *
 * System units cannot be edited (modal will show error if attempted).
 *
 * @example
 * ```tsx
 * const [isOpen, setIsOpen] = useState(false)
 *
 * <Button onClick={() => setIsOpen(true)}>Add Unit</Button>
 * <AddUnitModal
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   categoryId={selectedCategoryId} // Optional pre-selection
 * />
 * ```
 */
export function AddUnitModal({ isOpen, onClose, unit, categoryId }: AddUnitModalProps) {
  const { t } = useTranslation(['uom', 'common'])
  const { data: categories, isLoading: loadingCategories } = useCategories()
  const createMutation = useCreateUnit()
  const updateMutation = useUpdateUnit()

  const isEditMode = !!unit
  const isSystemUnit = unit?.isSystem

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<UnitFormData>({
    resolver: zodResolver(unitFormSchema),
    defaultValues: {
      categoryId: categoryId || unit?.categoryId || '',
      code: unit?.code || '',
      name: unit?.name || '',
      symbol: unit?.symbol || '',
      conversionFactor: unit?.conversionFactor || '1',
      decimalPlaces: unit?.decimalPlaces ?? 2,
      roundingMethod: (unit?.roundingMethod as 'half_up' | 'floor' | 'ceil') || 'half_up',
    },
  })

  // Reset form when modal opens/closes or unit changes
  useEffect(() => {
    if (isOpen) {
      reset({
        categoryId: categoryId || unit?.categoryId || '',
        code: unit?.code || '',
        name: unit?.name || '',
        symbol: unit?.symbol || '',
        conversionFactor: unit?.conversionFactor || '1',
        decimalPlaces: unit?.decimalPlaces ?? 2,
        roundingMethod: (unit?.roundingMethod as 'half_up' | 'floor' | 'ceil') || 'half_up',
      })
    }
  }, [isOpen, unit, categoryId, reset])

  const onSubmit = async (data: UnitFormData) => {
    // Prevent editing system units
    if (isEditMode && isSystemUnit) {
      toast.error(t('uom:errors.systemUnit'))
      return
    }

    try {
      if (isEditMode && unit) {
        await updateMutation.mutateAsync({
          id: unit.id,
          input: {
            code: data.code,
            name: data.name,
            symbol: data.symbol,
            conversionFactor: data.conversionFactor,
            decimalPlaces: data.decimalPlaces,
            roundingMethod: data.roundingMethod,
          },
        })
        toast.success(t('uom:unitUpdated'))
      } else {
        await createMutation.mutateAsync({
          categoryId: data.categoryId,
          code: data.code,
          name: data.name,
          symbol: data.symbol,
          conversionFactor: data.conversionFactor,
          decimalPlaces: data.decimalPlaces,
          roundingMethod: data.roundingMethod,
        })
        toast.success(t('uom:unitCreated'))
      }

      reset()
      onClose()
    } catch (error: unknown) {
      const errorMessage = error instanceof Error ? error.message : t('common:error')
      toast.error(errorMessage)
    }
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={isEditMode ? t('uom:editUnit') : t('uom:addUnit')} size="md">
      <form onSubmit={handleSubmit(onSubmit)}>
        <ModalContent>
          {/* System unit warning */}
          {isSystemUnit && (
            <div className="rounded-md bg-yellow-50 p-4">
              <p className="text-sm text-yellow-800">{t('uom:errors.systemUnit')}</p>
            </div>
          )}

          {/* Category */}
          <FormField
            label={t('uom:category')}
            htmlFor="categoryId"
            required
            error={errors.categoryId?.message}
          >
            <Select
              id="categoryId"
              {...register('categoryId')}
              disabled={isEditMode || loadingCategories}
              error={!!errors.categoryId}
            >
              <option value="">{t('common:select')}</option>
              {categories?.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </FormField>

          {/* Code */}
          <FormField
            label={t('uom:code')}
            htmlFor="code"
            required
            helperText={t('uom:codeHelp')}
            error={errors.code?.message}
          >
            <Input
              id="code"
              {...register('code')}
              disabled={isSystemUnit}
              error={!!errors.code}
              placeholder="tbsp"
            />
          </FormField>

          {/* Name */}
          <FormField label={t('uom:name')} htmlFor="name" required error={errors.name?.message}>
            <Input
              id="name"
              {...register('name')}
              disabled={isSystemUnit}
              error={!!errors.name}
              placeholder="Tablespoon"
            />
          </FormField>

          {/* Symbol */}
          <FormField
            label={t('uom:symbol')}
            htmlFor="symbol"
            required
            helperText={t('uom:symbolHelp')}
            error={errors.symbol?.message}
          >
            <Input
              id="symbol"
              {...register('symbol')}
              disabled={isSystemUnit}
              error={!!errors.symbol}
              placeholder="tbsp"
            />
          </FormField>

          {/* Conversion Factor */}
          <FormField
            label={t('uom:conversionFactor')}
            htmlFor="conversionFactor"
            required
            helperText={t('uom:conversionFactorHelp')}
            error={errors.conversionFactor?.message}
          >
            <Input
              id="conversionFactor"
              type="text"
              {...register('conversionFactor')}
              disabled={isSystemUnit}
              error={!!errors.conversionFactor}
              placeholder="14.787"
            />
          </FormField>

          {/* Decimal Places */}
          <FormField
            label={t('uom:decimalPlaces')}
            htmlFor="decimalPlaces"
            error={errors.decimalPlaces?.message}
          >
            <Input
              id="decimalPlaces"
              type="number"
              {...register('decimalPlaces', { valueAsNumber: true })}
              disabled={isSystemUnit}
              error={!!errors.decimalPlaces}
              min={0}
              max={10}
            />
          </FormField>

          {/* Rounding Method */}
          <FormField label={t('uom:roundingMethod')} htmlFor="roundingMethod">
            <Select id="roundingMethod" {...register('roundingMethod')} disabled={isSystemUnit}>
              <option value="half_up">{t('uom:roundingMethods.half_up')}</option>
              <option value="floor">{t('uom:roundingMethods.floor')}</option>
              <option value="ceil">{t('uom:roundingMethods.ceil')}</option>
            </Select>
          </FormField>
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={handleClose} disabled={isSubmitting}>
            {t('common:actions.cancel')}
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || isSystemUnit || createMutation.isPending || updateMutation.isPending}
          >
            {isSubmitting || createMutation.isPending || updateMutation.isPending
              ? t('common:common.saving')
              : t('common:actions.save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
