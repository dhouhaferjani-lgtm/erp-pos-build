import { useState, useRef, useEffect, useCallback } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAfterSaveNavigation } from '@/hooks/useAfterSaveNavigation'
import { useUnsavedChangesGuard, confirmDiscard } from '@/hooks/useUnsavedChangesGuard'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, useFieldArray, Controller } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Plus, X, Layers } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, apiPatch } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { colors, tokens, textColors } from '../../lib/designTokens'
import { CategorySelect } from '../../components/catalog/CategorySelect'
import { Button, Checkbox, FormField, Input, Select, Textarea, MoneyInput, Toggle, QuantityInput } from '../../components/atoms'
import { CatalogBanner } from './components/CatalogBanner'
import { useProductSubmission } from './api/platformQueries'
import type { LookupState, SuggestedProduct } from './types/platform'
import { ProductImageSection, ParapharmacyMetadataFields, CreateModeImageBuffer } from '../products/components'
import { uploadProductImage } from '../products/api/productImages'
import { ProductVariantMatrixEditor } from '../catalog/components/ProductVariantMatrixEditor'
import { useVariantsForProduct } from '../catalog/hooks/useVariants'
import { useCompanyConfig } from '../../contexts/CompanyConfigContext'
import { useCurrency } from '../../hooks/useCurrency'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { TaxConfigurationField } from '../../components/molecules/TaxConfigurationField'
import { usePermissions } from '../../hooks/usePermissions'
import { inventoryProductsInvalidationPredicate } from './_invalidation'
import { buildProductPayload } from './productPayload'
import { LoyaltyPointsDisplay } from './LoyaltyPointsDisplay'
import { SaveSplitButton } from '@/components/molecules/SaveSplitButton'
import { BarcodeHero } from '../products/editor/components/BarcodeHero'
import { SectionNav } from '../products/editor/components/SectionNav'
import type { EditorSection } from '../products/editor/components/SectionNav'
import { EditorSectionCard } from '../products/editor/components/EditorSectionCard'
import { RelatedOperationsRail } from '../products/editor/components/RelatedOperationsRail'
import { BeforePublishChecklist } from '../products/editor/components/BeforePublishChecklist'
import type { ChecklistItem } from '../products/editor/components/BeforePublishChecklist'
import { LivePosTile } from '../products/editor/components/LivePosTile'
import { useScrollSpy } from '../products/editor/hooks/useScrollSpy'
import { formatCurrency } from '../../lib/formatCurrency'
import { bcsub, bcdiv, bcmul } from '../../lib/decimal'
import { UnitDropdown } from '../uom/components/UnitDropdown'
import { useUnits } from '../uom/hooks/useUnits'
import { getQuantityDecimals } from '../../lib/quantityScale'
import type { ProductType } from '../products/types'

interface Product {
  id: string
  name: string
  sku: string
  type: ProductType | null
  is_physical: boolean
  is_active_for_ecommerce: boolean
  unit_id: string | null
  category_id: number | null
  description: string | null
  sale_price: string | null
  purchase_price: string | null
  cost_price: string | null
  tax_rate: string | null
  default_tax_configuration_id: string | null
  unit: string | null
  barcode: string | null
  is_active: boolean
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  parapharmacy_metadata: ParapharmacyMetadata | null
  requires_batch_tracking: boolean
  default_shelf_life_days: number | null
  units_per_pack: number | null
  shelf_location: string | null
  reorder_point: string | null
  reorder_quantity: string | null
  created_at: string
  updated_at: string | null
  // Generated type: App.Modules.Product.Application.DTOs.OpeningStateData
  opening: App.Modules.Product.Application.DTOs.OpeningStateData | null
}

interface ProductResponse {
  data: Product
}

interface ParapharmacyMetadata {
  category: string
  dosage_form: string | null
  active_ingredients: Array<{ name: string; concentration: string }>
  usage_instructions: string | null
  warnings: string | null
  contraindications: string | null
  minimum_age: number | null
  age_restriction: string | null
  requires_consultation: boolean
  regulatory_code: string | null
  storage_requirements: string | null
}

export interface ProductFormData {
  name: string
  sku: string
  type: ProductType | null
  is_physical: boolean
  is_active_for_ecommerce: boolean
  unit_id: string | null
  category_id: number | null
  description: string
  sale_price: string
  purchase_price: string
  tax_rate: string
  tax_configuration_id: string | null
  unit: string
  barcode: string
  is_active: boolean
  oem_numbers: string[]
  cross_references: Array<{ brand: string; reference: string }>
  parapharmacy_metadata: ParapharmacyMetadata
  requires_batch_tracking: boolean
  default_shelf_life_days: number | null
  units_per_pack: number | null
  shelf_location: string
  reorder_point: string
  reorder_quantity: string
  /** Opening balance quantity (decimal string). Submitted only when > 0. */
  opening_qty: string
  /** Opening balance unit cost (decimal string). Required when opening_qty > 0. */
  opening_unit_cost: string
}

export function ProductForm() {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const nav = useAfterSaveNavigation({
    recordPath: (rid) => `/inventory/products/${rid}`,
    listPath: '/inventory/products',
  })
  const queryClient = useQueryClient()
  const isEditing = id.length > 0
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { config, hasModule } = useCompanyConfig()
  const { hasPermission } = usePermissions()
  const isParapharmacy = config?.vertical === 'parapharmacy'
  const showBatchTracking = hasModule('BatchExpiry') || hasModule('Inventory')
  const { isOtospex } = useProductConfig()
  const { currency, locale } = useCurrency()

  const [showVariants, setShowVariants] = useState(false)
  const [oemInput, setOemInput] = useState('')
  const [lookupState, setLookupState] = useState<LookupState>('idle')
  const [showResetConfirm, setShowResetConfirm] = useState(false)
  const [isResettingOpening, setIsResettingOpening] = useState(false)
  const [enrichmentOptIn, setEnrichmentOptIn] = useState(true)
  const suggestedProductRef = useRef<SuggestedProduct | null>(null)
  const [prefilledFields, setPrefilledFields] = useState<Set<string>>(new Set())
  // create-mode image buffer: files held client-side until product id is known
  const [bufferedImages, setBufferedImages] = useState<File[]>([])

  const submissionMutation = useProductSubmission()

  const {
    register,
    handleSubmit,
    control,
    reset,
    watch,
    setValue,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<ProductFormData>({
    defaultValues: {
      name: '',
      sku: '',
      type: null,
      is_physical: true,
      is_active_for_ecommerce: false,
      unit_id: null,
      category_id: null,
      description: '',
      sale_price: '',
      purchase_price: '',
      tax_rate: '',
      tax_configuration_id: null,
      unit: 'pcs',
      barcode: '',
      is_active: true,
      oem_numbers: [],
      cross_references: [],
      parapharmacy_metadata: {
        category: '',
        dosage_form: null,
        active_ingredients: [],
        usage_instructions: null,
        warnings: null,
        contraindications: null,
        minimum_age: null,
        age_restriction: null,
        requires_consultation: false,
        regulatory_code: null,
        storage_requirements: null,
      },
      requires_batch_tracking: false,
      default_shelf_life_days: null,
      units_per_pack: null,
      shelf_location: '',
      reorder_point: '',
      reorder_quantity: '',
      opening_qty: '',
      opening_unit_cost: '',
    },
  })

  useUnsavedChangesGuard({ isDirty })

  const handleProductData = useCallback((data: SuggestedProduct) => {
    suggestedProductRef.current = data
    const filled = new Set<string>()
    if (data.name) {
      setValue('name', data.name, { shouldDirty: false })
      filled.add('name')
    }
    if (data.description) {
      setValue('description', data.description, { shouldDirty: false })
      filled.add('description')
    }
    if (data.barcode) {
      setValue('barcode', data.barcode, { shouldDirty: false })
    }
    setPrefilledFields(filled)
  }, [setValue])

  const handleLookupStateChange = useCallback((state: LookupState) => {
    setLookupState(state)
    if (state === 'idle') {
      suggestedProductRef.current = null
      setPrefilledFields(new Set())
    }
  }, [])

  const oemNumbers = watch('oem_numbers')
  const categoryId = watch('category_id')
  const requiresBatchTracking = watch('requires_batch_tracking')
  const watchIsPhysical = watch('is_physical')
  const openingQtyValue = watch('opening_qty')

  // Clear opening cost when qty is empty/zero so the cost is never submitted
  // without a corresponding quantity.
  useEffect(() => {
    const trimmed = openingQtyValue.trim()
    if (trimmed === '' || trimmed === '0') {
      setValue('opening_unit_cost', '', { shouldDirty: false })
    }
  }, [openingQtyValue, setValue])

  // Opening section gate: module + permission + physical product.
  // The per-product state derivations (canEnterOpening etc.) live AFTER
  // the useQuery that loads `product` — see below.
  const canAdjustInventory = hasPermission('inventory.adjust')
  const showOpeningSection =
    hasModule('Inventory') && canAdjustInventory && watchIsPhysical

  // Reorder quantities step by the product's unit precision (pieces → 1).
  // UnitDropdown is opaque, so resolve the selected unit's decimals here from
  // the units list. The /uom/units payload uses camelCase `decimalPlaces`.
  const watchedUnitId = watch('unit_id')
  const { data: unitOptions } = useUnits()
  const selectedUnit = unitOptions?.find((u) => u.id === watchedUnitId)
  const reorderDecimals = getQuantityDecimals({
    quantity_decimals: selectedUnit?.decimalPlaces ?? selectedUnit?.decimal_places ?? null,
  })

  const { fields: crossRefFields, append: appendCrossRef, remove: removeCrossRef } = useFieldArray({
    control,
    name: 'cross_references',
  })

  // Fetch product data when editing
  const { data: product, isLoading } = useQuery({
    queryKey: tenantScopedKey(['product', id]),
    queryFn: async () => {
      const response = await api.get<ProductResponse>(`/products/${id}`)
      return response.data.data
    },
    enabled: isEditing && !!tenantId && !!companyId,
  })

  // Opening state derivations — must follow useQuery so `product` is in scope.
  // Derive opening state from the loaded product (null = no opening recorded yet).
  const openingState = product?.opening ?? null
  // Can enter if: creating, OR product has no opening yet, OR backend says can enter.
  const canEnterOpening = !isEditing || openingState === null || openingState.can_enter_opening
  // Locked when editing and the opening cannot be re-entered.
  const isOpeningLocked = isEditing && openingState !== null && !openingState.can_enter_opening
  // Can reset if: locked, has an active opening, AND no downstream stock movements.
  const canResetOpening =
    isOpeningLocked &&
    openingState.has_active_opening &&
    !openingState.has_downstream_movements
  // Cost becomes required (and enabled) once qty has a non-zero value.
  const openingCostRequired =
    canEnterOpening &&
    openingQtyValue.trim() !== '' &&
    openingQtyValue.trim() !== '0'

  // Existing variants for this product (edit mode only). If any exist, default
  // the variants section open so the user lands on the matrix editor.
  const { data: existingVariants } = useVariantsForProduct(isEditing ? id : '')
  const hasExistingVariants = (existingVariants ?? []).length > 0

  const variantsToggleSeededRef = useRef(false)
  useEffect(() => {
    if (!variantsToggleSeededRef.current && hasExistingVariants) {
      variantsToggleSeededRef.current = true
      setShowVariants(true)
    }
  }, [hasExistingVariants])

  const hasPopulatedRef = useRef(false)

  // Populate form when product data loads
  useEffect(() => {
    if (product && !hasPopulatedRef.current) {
      hasPopulatedRef.current = true
      reset({
        name: product.name,
        sku: product.sku,
        type: product.type ?? null,
        is_physical: product.is_physical ?? true,
        is_active_for_ecommerce: product.is_active_for_ecommerce ?? false,
        unit_id: product.unit_id ?? null,
        category_id: product.category_id ?? null,
        description: product.description ?? '',
        sale_price: product.sale_price ?? '',
        purchase_price: product.purchase_price ?? '',
        tax_rate: product.tax_rate ?? '',
        tax_configuration_id: product.default_tax_configuration_id ?? null,
        unit: product.unit ?? 'pcs',
        barcode: product.barcode ?? '',
        is_active: product.is_active,
        oem_numbers: product.oem_numbers ?? [],
        cross_references: product.cross_references ?? [],
        parapharmacy_metadata: product.parapharmacy_metadata ?? {
          category: '',
          dosage_form: null,
          active_ingredients: [],
          usage_instructions: null,
          warnings: null,
          contraindications: null,
          minimum_age: null,
          age_restriction: null,
          requires_consultation: false,
          regulatory_code: null,
          storage_requirements: null,
        },
        requires_batch_tracking: product.requires_batch_tracking ?? false,
        default_shelf_life_days: product.default_shelf_life_days ?? null,
        units_per_pack: product.units_per_pack ?? null,
        shelf_location: product.shelf_location ?? '',
        reorder_point: product.reorder_point ?? '',
        reorder_quantity: product.reorder_quantity ?? '',
        // Opening fields are not stored on the product; reset to empty so
        // the section reflects a clean entry state on load.
        opening_qty: '',
        opening_unit_cost: '',
      })
    }
  }, [product, reset])

  const createMutation = useMutation({
    mutationFn: (data: ProductFormData) => {
      const basePayload = buildProductPayload(data, { isParapharmacy })
      const hasOpeningQty =
        data.opening_qty.trim() !== '' && data.opening_qty.trim() !== '0'
      if (hasOpeningQty) {
        return apiPost<Product>('/products', {
          ...basePayload,
          opening_qty: data.opening_qty,
          opening_unit_cost: data.opening_unit_cost,
        })
      }
      return apiPost<Product>('/products', basePayload)
    },
    onSuccess: async (newProduct: Product) => {
      await queryClient.invalidateQueries({
        predicate: inventoryProductsInvalidationPredicate(tenantId, companyId),
      })

      // Upload buffered images sequentially; preserve sort order.
      // The first upload becomes PRIMARY automatically on the backend.
      // Do NOT block navigation on failure — product already exists.
      if (bufferedImages.length > 0) {
        const failed: number[] = []
        for (let i = 0; i < bufferedImages.length; i++) {
          try {
            await uploadProductImage(newProduct.id, bufferedImages[i], i)
          } catch {
            failed.push(i + 1)
          }
        }
        if (failed.length > 0) {
          toast.error(
            t('products:media.uploadFailedPartial', { count: failed.length }),
          )
        }
      }
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: ProductFormData) =>
      apiPatch<Product>(`/products/${id}`, buildProductPayload(data, { isParapharmacy })),
    onSuccess: async () => {
      // Invalidate caches; navigation is handled in onSubmit so opening
      // submission can be sequenced before the redirect.
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: inventoryProductsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product', id]) }),
      ])
    },
  })

  const handleResetOpening = async () => {
    setIsResettingOpening(true)
    try {
      await apiPost(`/products/${id}/opening/reset`, {})
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product', id]) })
      setShowResetConfirm(false)
      toast.success(t('inventory:opening.resetSuccess'))
    } catch {
      toast.error(t('inventory:opening.resetError'))
    } finally {
      setIsResettingOpening(false)
    }
  }

  const onSubmit = async (data: ProductFormData) => {
    if (isEditing) {
      try {
        await updateMutation.mutateAsync(data)

        // After product update succeeds, submit opening balance if eligible and
        // a non-zero quantity was provided. Uses a separate endpoint so the
        // opening can be recorded independently of the product metadata PATCH.
        const openingState = product?.opening ?? null
        const canEnterOpeningNow = openingState === null || openingState.can_enter_opening
        const hasOpeningQty =
          data.opening_qty.trim() !== '' && data.opening_qty.trim() !== '0'

        if (canEnterOpeningNow && hasOpeningQty) {
          try {
            await apiPost(`/products/${id}/opening`, {
              opening_qty: data.opening_qty,
              opening_unit_cost: data.opening_unit_cost,
            })
          } catch {
            toast.error(t('inventory:opening.submitError'))
            // Stay on page so user can retry
            return
          }
        }
      } catch {
        // updateMutation error already displayed by react-query
        return
      }
      void navigate(`/inventory/products/${id}`)
      return
    }

    try {
      const created = await createMutation.mutateAsync(data)

      if (lookupState === 'not_found' && enrichmentOptIn) {
        const payload: Parameters<typeof submissionMutation.mutate>[0] = {
          barcode: data.barcode || null,
          name: data.name,
          brand: suggestedProductRef.current?.brand ?? '',
        }
        if (data.description) {
          payload.description = data.description
        }
        submissionMutation.mutate(payload)
        toast.success(t('inventory:barcodeLookup.toastSavedWithEnrichment'))
      } else if (lookupState === 'found') {
        toast.success(t('inventory:barcodeLookup.toastSavedWithCatalog'))
      } else {
        toast.success(t('inventory:barcodeLookup.toastProductSaved'))
      }

      nav.goToRecord(created.id)
    } catch {
      // Error handling via react-query
    }
  }

  const handleAddOem = () => {
    const trimmed = oemInput.trim()
    if (trimmed && !oemNumbers.includes(trimmed)) {
      setValue('oem_numbers', [...oemNumbers, trimmed])
      setOemInput('')
    }
  }

  const handleRemoveOem = (index: number) => {
    setValue('oem_numbers', oemNumbers.filter((_, i) => i !== index))
  }

  const handleOemKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      handleAddOem()
    }
  }

  // --- Editor layout: sections, scroll-spy, completeness, checklist ---------
  // Watch the fields that drive the "before publish" checklist + completeness.
  const nameValue = watch('name')
  const skuValue = watch('sku')
  const salePriceValue = watch('sale_price')
  const purchasePriceValue = watch('purchase_price')
  const taxConfigValue = watch('tax_configuration_id')
  const taxRateValue = watch('tax_rate')
  const barcodeValue = watch('barcode')

  // Compute indicative gross margin = (sale − purchase) / sale × 100
  // Only shown when both prices are non-empty and sale > 0.
  // NOTE: sale_price is TTC (tax-inclusive) while purchase_price is HT (ex-tax),
  // so this is an indicative margin only — a tax-exact net margin is a later refinement.
  // Precision rule 19: all arithmetic via big.js helpers, never parseFloat/Number.
  const indicativeMargin: string | null = (() => {
    const saleStr = salePriceValue.trim()
    const purchaseStr = purchasePriceValue.trim()
    if (saleStr === '' || purchaseStr === '') return null
    // bcdiv throws on division by zero — guard via the zero string check
    if (saleStr === '0') return null
    // Also guard via big comparison (handles '0.000', '0.00', etc.)
    try {
      const diff = bcsub(saleStr, purchaseStr, 4)
      const ratio = bcdiv(diff, saleStr, 6)
      const percent = bcmul(ratio, '100', 1)
      // Don't show if either side is effectively zero (purchase >= sale edge cases are allowed — negative margin is valid)
      return percent
    } catch {
      return null
    }
  })()

  const hasTax = (taxConfigValue ?? '') !== '' || (taxRateValue ?? '') !== ''
  const checklistItems: ChecklistItem[] = [
    { key: 'name', satisfied: nameValue.trim().length > 0 },
    { key: 'sku', satisfied: skuValue.trim().length > 0 },
    { key: 'salePrice', satisfied: salePriceValue.trim().length > 0 },
    { key: 'tax', satisfied: hasTax },
  ]
  const satisfiedCount = checklistItems.filter((item) => item.satisfied).length
  const completenessPercent = Math.round((satisfiedCount / checklistItems.length) * 100)

  // Section descriptors: General + Pricing + Inventory are always present; the
  // vertical-gated Pharmacy section and the always-present Suppliers + Media
  // sections follow. Markers are re-derived from order so they stay sequential.
  const sectionDefs: Array<{ id: string; labelKey: string }> = [
    { id: 'section-general', labelKey: 'catalog:editor.sectionLabels.general' },
    { id: 'section-pricing', labelKey: 'catalog:editor.sectionLabels.pricing' },
    { id: 'section-inventory', labelKey: 'catalog:editor.sectionLabels.inventory' },
    ...(isParapharmacy
      ? [{ id: 'section-pharmacy', labelKey: 'catalog:editor.sectionLabels.pharmacy' }]
      : []),
    ...(hasModule('Loyalty')
      ? [{ id: 'section-loyalty', labelKey: 'catalog:editor.sectionLabels.loyalty' }]
      : []),
    { id: 'section-suppliers', labelKey: 'catalog:editor.sectionLabels.suppliers' },
    { id: 'section-media', labelKey: 'catalog:editor.sectionLabels.media' },
  ]
  const sectionIds = sectionDefs.map((s) => s.id)
  const activeSectionId = useScrollSpy(sectionIds)
  const sections: EditorSection[] = sectionDefs.map((s) => ({
    id: s.id,
    label: t(s.labelKey),
  }))

  // Live POS preview price — formatted from the current sale_price for the
  // right-rail tile. Empty/zero until the operator enters a price.
  const livePosPrice = formatCurrency(salePriceValue.trim() === '' ? 0 : salePriceValue, currency, locale)

  const handleSectionSelect = (sectionId: string): void => {
    const el = document.getElementById(sectionId)
    if (el !== null) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }
  }

  if (isEditing && isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-[18px]">
      {/* Page header — breadcrumb, single <h1> (Montserrat 800 26px navy) +
          subtitle on the left, action buttons on the right. The buttons live
          OUTSIDE the <form> below but target it via `form=` so they submit.
          Save draft + Publish both submit for now (Publish = primary); the
          draft/publish split lands with the status workflow (stubbed). */}
      <div>
        <div className={cn('mb-4 flex items-center gap-2 text-sm', textColors.tertiary)}>
          <Link to="/inventory/products" className={textColors.hoverPrimary}>
            {t('actions.back')}
          </Link>
        </div>
        <div className="flex flex-wrap items-start gap-4">
          <div className="min-w-0 flex-1">
            <h1
              className={cn(
                'font-[family-name:var(--font-display)] text-[26px] font-extrabold tracking-[-0.02em]',
                textColors.primary,
              )}
            >
              {isEditing ? t('inventory:products.edit') : t('inventory:products.new')}
            </h1>
            <p className={cn('mt-1.5 text-sm', textColors.tertiary)}>{t('catalog:editor.subtitle')}</p>
          </div>
          <div className="flex shrink-0 items-center gap-2.5">
            <button
              type="button"
              onClick={() => {
                if (!isDirty || confirmDiscard(t('common:confirmation.unsavedChangesBody'))) {
                  void navigate('/inventory/products')
                }
              }}
              className={cn(
                'rounded-[var(--radius-button)] px-2.5 py-2 text-sm font-semibold',
                textColors.tertiary,
                textColors.hoverPrimary,
              )}
            >
              {t('actions.cancel')}
            </button>
            <SaveSplitButton
              primaryLabel={t('catalog:editor.actions.save')}
              form="product-editor-form"
              isPending={isSubmitting}
              onPrimarySave={() => { /* form= handles submission */ }}
            />
          </div>
        </div>
      </div>

      {/* Barcode-first hero: the barcode + name inputs are bound to the
          existing form fields (single source of truth for `barcode`).
          The hero drives the catalog lookup engine (debounce + scanner) —
          no separate BarcodeLookupInput in the General section. */}
      <BarcodeHero
        barcode={barcodeValue}
        onBarcodeChange={(value) => { setValue('barcode', value, { shouldDirty: true }) }}
        name={nameValue}
        onNameChange={(value) => { setValue('name', value, { shouldDirty: true }) }}
        onProductData={handleProductData}
        onLookupStateChange={handleLookupStateChange}
        onManualRefresh={() => { /* TODO(stage-4): trigger manual Synerivia re-fetch */ }}
      />

      {/* Enrichment opt-in — shown under the hero when barcode not found in
          catalog so the operator can submit for enrichment on save. */}
      {lookupState === 'not_found' && (
        <div className={cn('flex items-center gap-2.5 rounded-lg px-3.5 py-3', colors.neutral[100])}>
          <Checkbox
            id="enrichment-opt-in"
            checked={enrichmentOptIn}
            onChange={(e) => setEnrichmentOptIn(e.target.checked)}
          />
          <label htmlFor="enrichment-opt-in" className="text-sm">
            <span className="font-medium">{t('inventory:barcodeLookup.enrichmentCheckbox')}</span>
            <br />
            <span className="text-xs opacity-70">{t('inventory:barcodeLookup.enrichmentDescription')}</span>
          </label>
        </div>
      )}

      {/* Form */}
      <form id="product-editor-form" onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="flex flex-1 flex-col gap-[18px]">
        {/* Catalog Lookup Banner */}
        <CatalogBanner
          state={lookupState}
          confidenceTier={
            lookupState === 'found' && suggestedProductRef.current
              ? (suggestedProductRef.current.classification?.['enrichment_tier'] as string) ?? null
              : null
          }
        />

        {/* Three-column body (mock grid 188px / 1fr / 300px): sticky section
            nav card (left) + section cards (centre) + Live-on-POS / before-
            publish / related-operations rail (right). */}
        <div className="grid grid-cols-1 gap-[18px] lg:grid-cols-[188px_minmax(0,1fr)_300px] lg:items-start">
          {/* Left: sticky section navigator with scroll-spy + completeness.
              Sticky lives on the grid item (not the inner card) so its
              containing block is the full-height grid track and it has room
              to travel as the centre column scrolls. */}
          <div className="hidden lg:sticky lg:top-0 lg:block lg:self-start">
            <SectionNav
              sections={sections}
              activeId={activeSectionId}
              onSelect={handleSectionSelect}
              completenessPercent={completenessPercent}
            />
          </div>

          {/* Centre: stacked section cards */}
          <div className="flex min-w-0 flex-col gap-4">
            {/* General */}
            <EditorSectionCard
              id="section-general"
              title={t('catalog:editor.sectionLabels.general')}
            >
              <FormField
                label={`${t('inventory:products.name')} *`}
                htmlFor="name"
                error={errors.name?.message}
              >
                <Input
                  type="text"
                  id="name"
                  error={Boolean(errors.name)}
                  className={prefilledFields.has('name') ? colors.success[50] : ''}
                  {...register('name', {
                    required: t('inventory:products.nameRequired'),
                    onChange: () => {
                      setPrefilledFields((prev) => {
                        if (!prev.has('name')) return prev
                        const next = new Set(prev)
                        next.delete('name')
                        return next
                      })
                    },
                  })}
                />
              </FormField>

              <FormField
                label={`${t('inventory:products.sku')} *`}
                htmlFor="sku"
                error={errors.sku?.message}
              >
                <Input
                  type="text"
                  id="sku"
                  error={Boolean(errors.sku)}
                  {...register('sku', { required: t('inventory:products.skuRequired') })}
                />
              </FormField>

              {/* Product Type — drives is_physical automatically (service→false, else true).
                  The hidden is_physical value stays in the form to keep payload consistent
                  with the backend's own type↔is_physical derivation. */}
              <FormField label={t('inventory:products.type')} htmlFor="type">
                <Controller
                  name="type"
                  control={control}
                  render={({ field }) => (
                    <Select
                      id="type"
                      value={field.value ?? ''}
                      onChange={(e) => {
                        const raw = e.target.value
                        const val: ProductType | null =
                          raw === 'part' || raw === 'service' || raw === 'consumable' ? raw : null
                        field.onChange(val)
                        setValue('is_physical', val !== 'service')
                      }}
                    >
                      <option value="">{t('inventory:products.typePlaceholder')}</option>
                      <option value="part">{t('inventory:products.typeOptions.part')}</option>
                      <option value="service">{t('inventory:products.typeOptions.service')}</option>
                      <option value="consumable">{t('inventory:products.typeOptions.consumable')}</option>
                    </Select>
                  )}
                />
              </FormField>

              {/* Unit of measure — reuses the UnitDropdown atom from features/uom.
                  The legacy free-text `unit` Input in the Inventory section stays
                  untouched (a later Inventory task removes it; the backend mirrors
                  unit_id→unit server-side). */}
              <FormField label={t('inventory:products.unitOfMeasure')} htmlFor="unit_id">
                <UnitDropdown
                  id="unit_id"
                  value={watch('unit_id') ?? undefined}
                  onChange={(id) => { setValue('unit_id', id || null) }}
                />
              </FormField>

              <FormField label={t('catalog.products.category')} htmlFor="category">
                <CategorySelect
                  value={categoryId}
                  onChange={(id) => { setValue('category_id', id); }}
                  className="mt-1"
                />
              </FormField>

              <FormField
                className="sm:col-span-2"
                label={t('inventory:products.description')}
                htmlFor="description"
              >
                <Textarea
                  id="description"
                  rows={3}
                  className={prefilledFields.has('description') ? colors.success[50] : ''}
                  {...register('description', {
                    onChange: () => {
                      setPrefilledFields((prev) => {
                        if (!prev.has('description')) return prev
                        const next = new Set(prev)
                        next.delete('description')
                        return next
                      })
                    },
                  })}
                />
              </FormField>

              {/* Status toggles — grouped at the bottom of General so the section
                  reads: identity fields → type/unit/category → description → status. */}
              <div className="sm:col-span-2 flex flex-wrap items-center gap-6 pt-1">
                <Controller
                  name="is_active"
                  control={control}
                  render={({ field }) => (
                    <Toggle
                      aria-label={t('inventory:products.active')}
                      label={t('inventory:products.active')}
                      checked={!!field.value}
                      onChange={(e) => { field.onChange(e.target.checked) }}
                      ref={field.ref}
                    />
                  )}
                />
                <Controller
                  name="is_active_for_ecommerce"
                  control={control}
                  render={({ field }) => (
                    <Toggle
                      aria-label={t('inventory:products.isActiveForEcommerce')}
                      label={t('inventory:products.isActiveForEcommerce')}
                      checked={!!field.value}
                      onChange={(e) => { field.onChange(e.target.checked) }}
                      ref={field.ref}
                    />
                  )}
                />
              </div>
            </EditorSectionCard>

            {/* Pricing & Tax */}
            <EditorSectionCard
              id="section-pricing"
              title={t('catalog:editor.sectionLabels.pricing')}
            >
              {/* Purchase Price — ex-tax, from supplier */}
              <FormField
                label={t('inventory:products.purchasePrice')}
                htmlFor="purchase_price"
                helperText={t('inventory:products.purchasePriceHelper')}
              >
                <Controller
                  name="purchase_price"
                  control={control}
                  render={({ field }) => (
                    <MoneyInput
                      id="purchase_price"
                      currency={currency}
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      onBlur={field.onBlur}
                    />
                  )}
                />
              </FormField>

              <FormField label={t('inventory:products.salePrice')} htmlFor="sale_price">
                <Controller
                  name="sale_price"
                  control={control}
                  render={({ field }) => (
                    <MoneyInput
                      id="sale_price"
                      currency={currency}
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      onBlur={field.onBlur}
                    />
                  )}
                />
              </FormField>

              {/* Indicative Gross Margin — read-only, computed, not stored */}
              {indicativeMargin !== null && (
                <FormField
                  label={t('inventory:products.margin')}
                  htmlFor="indicative_margin"
                  helperText={t('inventory:products.marginIndicative')}
                >
                  <Input
                    id="indicative_margin"
                    type="text"
                    value={`${indicativeMargin}%`}
                    readOnly
                    className={cn(colors.neutral[50], textColors.tertiary, 'cursor-not-allowed')}
                  />
                </FormField>
              )}

              {/* Cost (WAC) - Read-only when editing */}
              {isEditing && product && (
                <FormField
                  label={t('inventory:products.costWac')}
                  htmlFor="cost_wac"
                  helperText={t('inventory:products.costWacHelper')}
                >
                  <Input
                    id="cost_wac"
                    type="text"
                    value={formatCurrency(product.cost_price ?? '0', currency, locale)}
                    readOnly
                    className={cn(colors.neutral[50], textColors.tertiary, 'cursor-not-allowed')}
                  />
                </FormField>
              )}

              <div className="sm:col-span-2">
                <TaxConfigurationField
                  label={t('inventory:products.fields.taxRate', 'Tax Rate')}
                  value={watch('tax_configuration_id')}
                  onChange={(configId, taxRate) => {
                    setValue('tax_configuration_id', configId)
                    setValue('tax_rate', taxRate)
                  }}
                />
              </div>
            </EditorSectionCard>

            {/* Inventory & Units */}
            <EditorSectionCard
              id="section-inventory"
              title={t('catalog:editor.sectionLabels.inventory')}
            >
              {/* units_per_pack — plain integer count, Number() coercion is fine (not money/qty) */}
              <FormField label={t('inventory:products.unitsPerPack')} htmlFor="units_per_pack">
                <Input
                  type="number"
                  id="units_per_pack"
                  min={1}
                  placeholder={t('inventory:products.unitsPerPackPlaceholder')}
                  {...register('units_per_pack', {
                    setValueAs: (value: string): number | null =>
                      value === '' || value === null ? null : Number(value),
                  })}
                />
              </FormField>

              {/* shelf_location — free-text string */}
              <FormField label={t('inventory:products.shelfLocation')} htmlFor="shelf_location">
                <Input
                  type="text"
                  id="shelf_location"
                  placeholder={t('inventory:products.shelfLocationPlaceholder')}
                  {...register('shelf_location')}
                />
              </FormField>

              {/* reorder_point — decimal quantity string, precision rule 19 */}
              <FormField label={t('inventory:products.reorderPoint')} htmlFor="reorder_point">
                <Controller
                  name="reorder_point"
                  control={control}
                  render={({ field }) => (
                    <QuantityInput
                      id="reorder_point"
                      decimalPlaces={reorderDecimals}
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      onBlur={field.onBlur}
                    />
                  )}
                />
              </FormField>

              {/* reorder_quantity — decimal quantity string, precision rule 19 */}
              <FormField label={t('inventory:products.reorderQuantity')} htmlFor="reorder_quantity">
                <Controller
                  name="reorder_quantity"
                  control={control}
                  render={({ field }) => (
                    <QuantityInput
                      id="reorder_quantity"
                      decimalPlaces={reorderDecimals}
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      onBlur={field.onBlur}
                    />
                  )}
                />
              </FormField>

              {showBatchTracking && (
                <div data-testid="batch-tracking-section">
                  <div className="flex items-center gap-2 mt-6">
                    <Controller
                      name="requires_batch_tracking"
                      control={control}
                      render={({ field }) => (
                        <Toggle
                          aria-label={t('inventory:products.requiresBatchTracking')}
                          label={t('inventory:products.requiresBatchTracking')}
                          checked={!!field.value}
                          onChange={(e) => { field.onChange(e.target.checked) }}
                          ref={field.ref}
                        />
                      )}
                    />
                  </div>
                  <p className={tokens.helperText.base}>
                    {t('inventory:products.requiresBatchTrackingHelper')}
                  </p>

                  {requiresBatchTracking && (
                    <FormField
                      label={t('inventory:products.defaultShelfLifeDays')}
                      htmlFor="default_shelf_life_days"
                      className="mt-3"
                    >
                      <Input
                        type="number"
                        id="default_shelf_life_days"
                        min={0}
                        placeholder={t('inventory:products.defaultShelfLifeDaysPlaceholder')}
                        {...register('default_shelf_life_days', {
                          setValueAs: (value: string): number | null =>
                            value === '' || value === null ? null : Number(value),
                        })}
                      />
                    </FormField>
                  )}
                </div>
              )}
            </EditorSectionCard>

            {/* Opening Stock — shown when Inventory module is enabled, the user
                has inventory.adjust permission, and the product is physical.
                Section is absent for services; operators without adjust rights
                do not see it. On create it allows entering initial qty + cost
                (WAC seed). On edit it reflects the backend opening state:
                editable if can_enter_opening, locked otherwise. */}
            {showOpeningSection && (
              <div data-testid="opening-section">
                <EditorSectionCard
                  id="section-opening"
                  title={t('inventory:opening.title')}
                >
                  {/* Qty input — disabled in locked mode */}
                  <FormField
                    label={t('inventory:opening.qty_label')}
                    htmlFor="opening_qty"
                  >
                    <Controller
                      name="opening_qty"
                      control={control}
                      render={({ field }) => (
                        <QuantityInput
                          id="opening_qty"
                          data-testid="opening-qty-input"
                          decimalPlaces={4}
                          value={field.value ?? ''}
                          onChange={field.onChange}
                          onBlur={field.onBlur}
                          disabled={!canEnterOpening}
                        />
                      )}
                    />
                  </FormField>

                  {/* Cost input — required when qty > 0, disabled when locked or qty empty */}
                  <FormField
                    label={`${t('inventory:opening.cost_label')}${openingCostRequired ? ' *' : ''}`}
                    htmlFor="opening_unit_cost"
                    helperText={t('inventory:opening.cost_help')}
                  >
                    <Controller
                      name="opening_unit_cost"
                      control={control}
                      render={({ field }) => (
                        <MoneyInput
                          id="opening_unit_cost"
                          data-testid="opening-cost-input"
                          currency={currency}
                          value={field.value ?? ''}
                          onChange={field.onChange}
                          onBlur={field.onBlur}
                          disabled={!canEnterOpening || !openingCostRequired}
                        />
                      )}
                    />
                  </FormField>

                  {/* Lock / reset controls — only shown when can_enter_opening is false */}
                  {isOpeningLocked && (
                    <div className="sm:col-span-2">
                      {canResetOpening ? (
                        <>
                          <p className={cn('mb-2 text-sm', textColors.tertiary)}>
                            {t('inventory:opening.locked_helper')}
                          </p>
                          {!showResetConfirm ? (
                            <button
                              type="button"
                              data-testid="opening-reset-btn"
                              onClick={() => { setShowResetConfirm(true) }}
                              className={cn('text-sm font-medium', textColors.brand)}
                            >
                              {t('inventory:opening.reset_label')}
                            </button>
                          ) : (
                            <div className={cn('rounded-lg p-4', colors.neutral[100])}>
                              <p className={cn('mb-3 text-sm font-medium', textColors.secondary)}>
                                {t('inventory:opening.reset_confirm_body')}
                              </p>
                              <div className="flex gap-2">
                                <Button
                                  type="button"
                                  variant="secondary"
                                  size="sm"
                                  onClick={() => { setShowResetConfirm(false) }}
                                >
                                  {t('inventory:opening.reset_confirm_cancel')}
                                </Button>
                                <Button
                                  type="button"
                                  variant="danger"
                                  size="sm"
                                  disabled={isResettingOpening}
                                  onClick={() => { void handleResetOpening() }}
                                >
                                  {isResettingOpening
                                    ? t('status.saving')
                                    : t('inventory:opening.reset_confirm_proceed')}
                                </Button>
                              </div>
                            </div>
                          )}
                        </>
                      ) : (
                        <>
                          <p className={cn('mb-1 text-sm', textColors.tertiary)}>
                            {t('inventory:opening.locked_with_downstream')}
                          </p>
                          <Link
                            to="/inventory/stock"
                            className={cn('text-sm', textColors.brand)}
                          >
                            {t('inventory:opening.view_stock_link')}
                          </Link>
                        </>
                      )}
                    </div>
                  )}
                </EditorSectionCard>
              </div>
            )}

            {/* Automotive Information - Otospex only (no section nav entry; it
                is a vertical-exclusive block layered between inventory and the
                vertical-gated pharmacy/suppliers sections). */}
            {isOtospex && (
              <div className={tokens.card.base}>
                <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('inventory:products.sections.automotiveInfo')}</h2>

                {/* OEM Numbers */}
                <div className="mb-6">
                  <label className={cn(tokens.label.base, 'mb-2')}>
                    {t('inventory:products.oemNumbers')}
                  </label>
                  <div className="flex gap-2">
                    <Input
                      type="text"
                      className="mt-0 flex-1"
                      value={oemInput}
                      onChange={(e) => { setOemInput(e.target.value) }}
                      onKeyDown={handleOemKeyDown}
                      placeholder={t('inventory:products.oemPlaceholder')}
                    />
                    <Button
                      type="button"
                      variant="secondary"
                      className="gap-1"
                      onClick={handleAddOem}
                    >
                      <Plus className="h-4 w-4" />
                      {t('actions.add')}
                    </Button>
                  </div>
                  {oemNumbers.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-2">
                      {oemNumbers.map((oem, index) => (
                        <span
                          key={index}
                          className={cn('inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-sm font-mono', colors.neutral[100], textColors.secondary)}
                        >
                          {oem}
                          <button
                            type="button"
                            onClick={() => { handleRemoveOem(index) }}
                            className={cn(textColors.disabled, textColors.hoverSecondary)}
                          >
                            <X className="h-3.5 w-3.5" />
                          </button>
                        </span>
                      ))}
                    </div>
                  )}
                </div>

                {/* Cross References */}
                <div>
                  <label className={cn(tokens.label.base, 'mb-2')}>
                    {t('inventory:products.crossReferences')}
                  </label>
                  <div className="space-y-2">
                    {crossRefFields.map((field, index) => (
                      <div key={field.id} className="flex gap-2">
                        <Input
                          type="text"
                          className="mt-0 flex-1"
                          // eslint-disable-next-line @typescript-eslint/restrict-template-expressions
                          {...register(`cross_references.${index}.brand` as const)}
                          placeholder={t('inventory:products.brand')}
                        />
                        <Input
                          type="text"
                          className="mt-0 flex-1"
                          // eslint-disable-next-line @typescript-eslint/restrict-template-expressions
                          {...register(`cross_references.${index}.reference` as const)}
                          placeholder={t('inventory:products.reference')}
                        />
                        <Button
                          type="button"
                          variant="secondary"
                          className="p-2"
                          onClick={() => { removeCrossRef(index) }}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                  </div>
                  <button
                    type="button"
                    onClick={() => { appendCrossRef({ brand: '', reference: '' }) }}
                    className={cn('mt-2 inline-flex items-center gap-1 text-sm', textColors.brand)}
                  >
                    <Plus className="h-4 w-4" />
                    {t('inventory:products.addCrossReference')}
                  </button>
                </div>
              </div>
            )}

            {/* Pharmacy — parapharmacy vertical only. ParapharmacyMetadataFields
                renders its own card chrome + (translated) section header. */}
            {isParapharmacy && (
              <div id="section-pharmacy" className="scroll-mt-24">
                <ParapharmacyMetadataFields
                  control={control}
                  register={register}
                  errors={errors}
                />
              </div>
            )}

            {/* Loyalty — module-gated read-only indicative points display */}
            {hasModule('Loyalty') && (
              <EditorSectionCard
                id="section-loyalty"
                title={t('catalog:editor.sectionLabels.loyalty')}
              >
                <LoyaltyPointsDisplay salePrice={salePriceValue} />
              </EditorSectionCard>
            )}

            {/* Suppliers — no product-level supplier fields exist on this form;
                suppliers are managed in Purchases. Render an informational card
                with a link to the suppliers route (no new data fields). */}
            <EditorSectionCard
              id="section-suppliers"
              title={t('catalog:editor.sectionLabels.suppliers')}
              contentClassName="sm:grid-cols-1"
            >
              <p className={cn('text-sm', textColors.tertiary)}>
                {t('catalog:editor.suppliers.managedHint')}
              </p>
              <Link
                to="/purchases/suppliers"
                className={cn('inline-flex items-center gap-1 text-sm', textColors.brand)}
              >
                {t('catalog:editor.suppliers.manageLink')}
              </Link>
            </EditorSectionCard>

            {/* Media & Files — always rendered so the nav entry + scroll-spy
                anchor exists in both create and edit mode. In create mode,
                files are buffered client-side (CreateModeImageBuffer) and
                uploaded after the product is created. In edit mode,
                ProductImageSection takes over. */}
            <EditorSectionCard
              id="section-media"
              title={t('catalog:editor.sectionLabels.media')}
              contentClassName="sm:grid-cols-1"
            >
              {isEditing && id ? (
                <ProductImageSection productId={id} />
              ) : (
                <CreateModeImageBuffer
                  bufferedFiles={bufferedImages}
                  onFilesChange={setBufferedImages}
                />
              )}
            </EditorSectionCard>

            {/* Variants Section - Only when editing (matrix generation needs a persisted product id) */}
            {isEditing && id && (
              <div className={tokens.card.base}>
                <div className="mb-4 flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Layers className={cn('h-5 w-5', textColors.disabled)} />
                    <h2 className={tokens.heading.section}>
                      {t('catalog:variants.title')}
                    </h2>
                  </div>
                  <label className="inline-flex items-center gap-2">
                    <Checkbox
                      checked={showVariants}
                      onChange={(e) => { setShowVariants(e.target.checked) }}
                    />
                    <span className={cn('text-sm', textColors.secondary)}>
                      {t('catalog:variants.hasVariants')}
                    </span>
                  </label>
                </div>
                {showVariants && <ProductVariantMatrixEditor productId={id} />}
              </div>
            )}
          </div>

          {/* Right rail (mock order): Live on POS preview, before-publish
              checklist, then related operations. */}
          <aside className="flex flex-col gap-4">
            <LivePosTile name={nameValue} price={livePosPrice} />
            <BeforePublishChecklist items={checklistItems} />
            <RelatedOperationsRail disabled={!isEditing} />
          </aside>
        </div>
      </form>
    </div>
  )
}
