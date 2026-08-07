import { useState, useRef, useEffect, useCallback } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAfterSaveNavigation } from '@/hooks/useAfterSaveNavigation'
import { useUnsavedChangesGuard, confirmDiscard } from '@/hooks/useUnsavedChangesGuard'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, useFieldArray, type FieldErrors } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Plus, X, Layers } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, apiPatch, isApiError } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { focusFirstInvalidField } from '../../lib/formErrors'
import { cn } from '../../lib/utils'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { colors, tokens, textColors } from '../../lib/designTokens'
import { Button } from '../../components/atoms/Button/Button'
import { Checkbox } from '../../components/atoms/Checkbox/Checkbox'
import { Input } from '../../components/atoms/Input/Input'
import { CatalogBanner } from './components/CatalogBanner'
import { EnrichmentCapturePanel, type EnrichmentAttributeRow } from './components/EnrichmentCapturePanel'
import { EnrichmentReadyCard } from './components/EnrichmentReadyCard'
import { useEnrichmentRefresh, useProductSubmission } from './api/platformQueries'
import { useEnrichmentFastPath } from './hooks/useEnrichmentFastPath'
import type { SubmitForEnrichmentPayload } from './api/platformApi'
import type { LookupState, SuggestedProduct } from './types/platform'
import type { UploadedPhoto } from './api/enrichmentPhotos'
import { ParapharmacyMetadataFields } from '../products/components/ParapharmacyMetadataFields'
import { uploadProductImage } from '../products/api/productImages'
import { ProductVariantMatrixEditor } from '../catalog/components/ProductVariantMatrixEditor'
import { useVariantsForProduct } from '../catalog/hooks/useVariants'
import { useCategoryTree } from '../catalog/api/queries'
import type { CategoryTreeNode } from '../catalog/types'
import { useCompanyConfig } from '../../contexts/CompanyConfigContext'
import { useCurrency } from '../../hooks/useCurrency'
import { useProductConfig } from '../../contexts/ProductConfigContext'
import { usePermissions } from '../../hooks/usePermissions'
import { inventoryProductsInvalidationPredicate } from './_invalidation'
import { buildProductPayload } from './productPayload'
import { LoyaltyPointsDisplay } from './LoyaltyPointsDisplay'
import { SaveSplitButton } from '@/components/molecules/SaveSplitButton'
import { SectionNav } from '../products/editor/components/SectionNav'
import type { EditorSection } from '../products/editor/components/SectionNav'
import { EditorSectionCard } from '../products/editor/components/EditorSectionCard'
import { RelatedOperationsRail } from '../products/editor/components/RelatedOperationsRail'
import { BeforePublishChecklist } from '../products/editor/components/BeforePublishChecklist'
import type { ChecklistItem } from '../products/editor/components/BeforePublishChecklist'
import { LivePosTile } from '../products/editor/components/LivePosTile'
import { useScrollSpy } from '../products/editor/hooks/useScrollSpy'
import { formatCurrency } from '../../lib/formatCurrency'
import { useUnits } from '../uom/hooks/useUnits'
import { getQuantityDecimals } from '../../lib/quantityScale'
import type { ProductType } from '../products/types'
import type {
  ParapharmacySectionFormData,
  ProductHeroEnrichmentState,
  ProductSectionFormData,
} from '../products/sections/types'
import { ProductSectionStack } from '../products/sections/ProductSectionStack'
import {
  PRODUCT_SECTION_DEFINITIONS,
  type ProductSectionKey,
} from '../products/sections/sectionRegistry'
import { useProductPricingEditAdapter } from '../products/sections/useProductPricingEditAdapter'
import { ProductPlacementFields } from '../placement/components/ProductPlacementFields'

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
  primary_image_url?: string | null
  stock_quantity?: string | null
  enrichment_status?: string | null
  latest_enrichment_result?: { id?: string; status: string } | null
  brand?: { id?: string; name: string; source?: string | null } | null
  brand_source?: string | null
  category?: { id?: number | string; name: string } | null
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  parapharmacy_metadata: ParapharmacyMetadata | null
  requires_batch_tracking: boolean
  default_shelf_life_days: number | null
  units_per_pack: number | null
  shelf_location: string | null
  reorder_point: string | null
  reorder_quantity: string | null
  platform_product_id: string | null
  created_at: string
  updated_at: string | null
  // Generated type: App.Modules.Product.Application.DTOs.OpeningStateData
  opening: App.Modules.Product.Application.DTOs.OpeningStateData | null
}

interface ProductResponse {
  data: Product
}

type ParapharmacyMetadata = ParapharmacySectionFormData

type EditorExtensionKey = 'pharmacy' | 'loyalty' | 'automotive' | 'variants'

interface EditorGateCtx {
  isParapharmacy: boolean
  hasLoyalty: boolean
  isOtospex: boolean
  isEditing: boolean
}

interface EditorSectionDef {
  id: string
  labelKey: string
  component: ProductSectionKey | EditorExtensionKey
  when?: (ctx: EditorGateCtx) => boolean
  navVisible?: boolean
}

const INVENTORY_EXTENSIONS: EditorSectionDef[] = [
  { id: 'section-automotive', labelKey: 'inventory:products.sections.automotiveInfo', component: 'automotive', when: (ctx) => ctx.isOtospex, navVisible: false },
  { id: 'section-pharmacy', labelKey: 'catalog:editor.sectionLabels.pharmacy', component: 'pharmacy', when: (ctx) => ctx.isParapharmacy },
  { id: 'section-loyalty', labelKey: 'catalog:editor.sectionLabels.loyalty', component: 'loyalty', when: (ctx) => ctx.hasLoyalty },
]

const EDITOR_SECTIONS: EditorSectionDef[] = PRODUCT_SECTION_DEFINITIONS.flatMap((section) => [
  { id: section.id, labelKey: section.labelKey, component: section.key },
  ...(section.key === 'inventory' ? INVENTORY_EXTENSIONS : []),
  ...(section.key === 'media'
    ? [{ id: 'section-variants', labelKey: 'catalog:variants.title', component: 'variants' as const, when: (ctx: EditorGateCtx) => ctx.isEditing, navVisible: false }]
    : []),
])

export type ProductFormData = ProductSectionFormData

function findCategoryName(nodes: CategoryTreeNode[], id: number): string | null {
  for (const node of nodes) {
    if (node.id === id) return node.name
    const child = findCategoryName(node.children ?? [], id)
    if (child !== null) return child
  }
  return null
}

const PRODUCT_FORM_ID = 'product-editor-form'

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
  const { currency, locale, decimals } = useCurrency()

  const [showVariants, setShowVariants] = useState(false)
  const [oemInput, setOemInput] = useState('')
  const [lookupState, setLookupState] = useState<LookupState>('idle')
  const [showResetConfirm, setShowResetConfirm] = useState(false)
  const [isResettingOpening, setIsResettingOpening] = useState(false)
  const [enrichmentOptIn, setEnrichmentOptIn] = useState(true)
  const [capturePhotos, setCapturePhotos] = useState<UploadedPhoto[]>([])
  const [captureBrand, setCaptureBrand] = useState('')
  const [captureAttributes, setCaptureAttributes] = useState<EnrichmentAttributeRow[]>([])
  const suggestedProductRef = useRef<SuggestedProduct | null>(null)
  const [prefilledFields, setPrefilledFields] = useState<Set<string>>(new Set())
  // create-mode image buffer: files held client-side until product id is known
  const [bufferedImages, setBufferedImages] = useState<File[]>([])

  const { data: categoryTree } = useCategoryTree()
  const submissionMutation = useProductSubmission()
  const enrichmentRefreshMutation = useEnrichmentRefresh()

  const {
    register,
    handleSubmit,
    control,
    reset,
    watch,
    setValue,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<ProductFormData>({
    // BUG-003 / gate m2: react-hook-form's own `_focusError` runs AFTER
    // `onInvalid`, walks the `_fields` registry in REGISTRATION order and
    // focuses without `preventScroll`. Whenever registration order and DOM
    // order disagree it silently overrides the field `focusFirstInvalidField`
    // chose and scrolls somewhere else. Disabling it makes our DOM-order walk
    // the single, authoritative writer. Mechanism pinned in
    // `src/lib/formErrors.rhf.test.tsx`.
    shouldFocusError: false,
    defaultValues: {
      name: '',
      sku: '',
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

  // Close intent for Save & Close. Snapshot+reset at onSubmit entry (covers
  // mutation error) and reset via the form's onInvalid (covers validation
  // abort) so a stale intent can never make a later plain Save go to the list.
  const closeIntentRef = useRef(false)

  // BUG-003: a blocked submit used to be completely silent — no request, no
  // toast, no movement — because the failing required field could be below the
  // fold (the Parapharmacy "Product Category" on the reported tenant). Announce
  // the block and take the operator to the first invalid control. Field-
  // agnostic on purpose: any future required field is covered for free.
  const onInvalid = (fieldErrors: FieldErrors<ProductFormData>) => {
    closeIntentRef.current = false
    toast.error(t('inventory:products.validationBlocked'))
    const formElement = document.getElementById(PRODUCT_FORM_ID)
    focusFirstInvalidField(
      formElement instanceof HTMLFormElement ? formElement : null,
      fieldErrors,
    )
  }

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

  const clearPrefilledField = useCallback((field: 'name' | 'description') => {
    setPrefilledFields((current) => {
      if (!current.has(field)) return current
      const next = new Set(current)
      next.delete(field)
      return next
    })
  }, [])

  useEffect(() => {
    if (lookupState !== 'not_found') {
      setCapturePhotos([])
      setCaptureBrand('')
      setCaptureAttributes([])
    }
  }, [lookupState])

  const oemNumbers = watch('oem_numbers')
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
  const canViewCostPrices = hasPermission('pricing.view_cost_prices')
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
  const fastPathState = useEnrichmentFastPath({
    productId: id,
    enabled: Boolean(
      isEditing &&
      product?.enrichment_status === 'pending' &&
      hasPermission('enrichment.view'),
    ),
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
      // A stale FOUND suggestion can outlive a barcode edit: lookupState only idles
      // below 8 chars, and a cache-fresh lookup for a different barcode fires no
      // state transition, so the ref can still hold the previous product. Trust the
      // suggestion — platform product id AND server-resolved brand — only while its
      // barcode still matches the submitted form value. (No-op in the healthy flow:
      // handleProductData writes the suggested barcode into the form on a genuine found.)
      const suggestion = suggestedProductRef.current
      const suggestionIsCurrent =
        lookupState === 'found' && suggestion !== null && suggestion.barcode === data.barcode
      const suggestedBrandId = suggestionIsCurrent ? suggestion.brand_id ?? null : null
      const platformProductId = suggestionIsCurrent ? suggestion.platform_product_id : null
      const basePayload = {
        ...buildProductPayload(data, { isParapharmacy, suggestedBrandId }),
        ...(platformProductId ? { platform_product_id: platformProductId } : {}),
      }
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
        queryClient.invalidateQueries({ queryKey: ['product', id] }),
      ])
    },
  })

  const handleResetOpening = async () => {
    setIsResettingOpening(true)
    try {
      await apiPost(`/products/${id}/opening/reset`, {})
      await queryClient.invalidateQueries({ queryKey: ['product', id] })
      setShowResetConfirm(false)
      toast.success(t('inventory:opening.resetSuccess'))
    } catch {
      toast.error(t('inventory:opening.resetError'))
    } finally {
      setIsResettingOpening(false)
    }
  }

  const onSubmit = async (data: ProductFormData) => {
    const shouldClose = closeIntentRef.current
    closeIntentRef.current = false
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
      if (shouldClose) nav.goToList()
      else nav.goToRecord(id)
      return
    }

    try {
      const created = await createMutation.mutateAsync(data)

      if (lookupState === 'not_found' && enrichmentOptIn) {
        const payload: SubmitForEnrichmentPayload = {
          product_id: created.id,
          barcode: data.barcode || null,
          name: data.name,
          brand: captureBrand.trim() !== '' ? captureBrand.trim() : null,
          photo_ids: capturePhotos.map((photo) => photo.photoId),
        }
        if (data.description) {
          payload.description = data.description
        }
        // Optional category context for the platform: send the selected local
        // category's NAME (the platform maps free-text category hints).
        const categoryName = data.category_id !== null
          ? findCategoryName(categoryTree ?? [], data.category_id)
          : null
        if (categoryName) {
          payload.category = categoryName
        }
        const attributes = Object.fromEntries(
          captureAttributes
            .filter((row) => row.key.trim() !== '')
            .map((row) => [row.key.trim(), row.value]),
        )
        if (Object.keys(attributes).length > 0) {
          payload.attributes = attributes
        }
        submissionMutation.mutate(payload, {
          onError: (error: unknown) => {
            if (!isApiError(error)) {
              return
            }

            const code = error.response?.data.error.code
            if (code === 'invalid_barcode') {
              toast.error(t('inventory:barcodeLookup.invalidBarcode'))
              return
            }

            if (code === 'enrichment_tracking_conflict') {
              const details = error.response?.data.error.details ?? {}
              const holderName = typeof details['holder_product_name'] === 'string'
                ? details['holder_product_name']
                : t('inventory:products.singular')
              const holderId = typeof details['holder_product_id'] === 'string'
                ? details['holder_product_id']
                : null

              toast.error(t('inventory:barcodeLookup.trackingConflict', { name: holderName }), holderId
                ? {
                    action: {
                      label: t('actions.view'),
                      onClick: () => navigate(`/inventory/products/${holderId}`),
                    },
                  }
                : undefined)
            }
          },
        })
        toast.success(t('inventory:barcodeLookup.toastSavedWithEnrichment'))
      } else if (lookupState === 'found') {
        toast.success(t('inventory:barcodeLookup.toastSavedWithCatalog'))
      } else {
        toast.success(t('inventory:barcodeLookup.toastProductSaved'))
      }

      if (shouldClose) nav.goToList()
      else nav.goToRecord(created.id)
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

  // Operator-triggered manual re-fetch of the platform enrichment status.
  // Only meaningful once the product exists (edit mode) and has a submission;
  // the backend returns 422 when there is nothing to refresh.
  const handleManualRefresh = () => {
    if (!isEditing || enrichmentRefreshMutation.isPending) return
    enrichmentRefreshMutation.mutate(id, {
      onSuccess: () => {
        void Promise.all([
          queryClient.invalidateQueries({ queryKey: ['product', id] }),
          queryClient.invalidateQueries({
            predicate: inventoryProductsInvalidationPredicate(tenantId, companyId),
          }),
        ])
        toast.success(t('inventory:barcodeLookup.refreshSuccess'))
      },
      onError: (error) => {
        if (isApiError(error) && error.response?.status === 422) {
          toast.info(t('catalog:editor.hero.refreshNothing'))
          return
        }
        if (isApiError(error) && error.response?.status === 502) {
          toast.error(t('inventory:barcodeLookup.refreshError'))
          return
        }
        toast.error(t('inventory:barcodeLookup.refreshError'))
      },
    })
  }

  // --- Editor layout: sections, scroll-spy, completeness, checklist ---------
  // Watch the fields that drive the "before publish" checklist + completeness.
  const nameValue = watch('name')
  const skuValue = watch('sku')
  const salePriceValue = watch('sale_price')
  const taxConfigValue = watch('tax_configuration_id')
  const taxRateValue = watch('tax_rate')
  const barcodeValue = watch('barcode')

  const moneyScale = decimals ?? 3
  const pricing = useProductPricingEditAdapter({
    control,
    setValue,
    isOpeningLocked,
    productCostPrice: product?.cost_price ?? null,
    canEnterOpening,
    moneyScale,
  })

  const heroEnrichmentState: ProductHeroEnrichmentState = (() => {
    const latestStatus = product?.latest_enrichment_result?.status ?? null
    const status = product?.enrichment_status ?? null
    if (latestStatus === 'accepted' || product?.brand_source === 'enriched') return 'enriched'
    if (status === 'completed' && latestStatus === 'pending_review') return 'ready-for-review'
    if (status === 'pending' || status === 'enriching') return 'pending'
    if (status === 'failed' || status === 'rejected' || status === 'not_enrichable') return 'unavailable'
    return 'never-submitted'
  })()

  const heroChips = [
    ...(product?.brand !== null && product?.brand !== undefined
      ? [{
          id: 'brand',
          label: product.brand.name,
          enriched: product.brand_source === 'enriched' || product.brand.source === 'enriched',
        }]
      : []),
    ...(product?.category !== null && product?.category !== undefined
      ? [{ id: 'category', label: product.category.name }]
      : []),
  ]

  const hasTax = (taxConfigValue ?? '') !== '' || (taxRateValue ?? '') !== ''
  const checklistItems: ChecklistItem[] = [
    { key: 'name', satisfied: nameValue.trim().length > 0 },
    { key: 'sku', satisfied: skuValue.trim().length > 0 },
    { key: 'salePrice', satisfied: salePriceValue.trim().length > 0 },
    { key: 'tax', satisfied: hasTax },
  ]
  const satisfiedCount = checklistItems.filter((item) => item.satisfied).length
  const completenessPercent = Math.round((satisfiedCount / checklistItems.length) * 100)

  const editorCtx: EditorGateCtx = {
    isParapharmacy,
    hasLoyalty: hasModule('Loyalty'),
    isOtospex,
    isEditing,
  }
  const sectionDefs = EDITOR_SECTIONS.filter((section) => section.when?.(editorCtx) ?? true)
  const sectionIds = sectionDefs.filter((s) => s.navVisible !== false).map((s) => s.id)
  const activeSectionId = useScrollSpy(sectionIds)
  const sections: EditorSection[] = sectionDefs.filter((s) => s.navVisible !== false).map((s) => ({
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
              form={PRODUCT_FORM_ID}
              isPending={isSubmitting}
              onPrimarySave={() => { /* form= handles submission */ }}
              onSaveAndClose={() => {
                closeIntentRef.current = true
                const f = document.getElementById(PRODUCT_FORM_ID)
                if (f instanceof HTMLFormElement) f.requestSubmit()
              }}
            />
          </div>
        </div>
      </div>

      {isEditing ? (
        <EnrichmentReadyCard
          state={fastPathState}
          canReview={hasPermission('enrichment.review')}
          productId={id}
        />
      ) : null}

      {/* Enrichment opt-in — shown under the hero when barcode not found in
          catalog so the operator can submit for enrichment on save.
          CREATE MODE ONLY: the enrichment submission fires from the create
          path; in edit mode the panel would collect photos/brand and silently
          discard them on save. */}
      {!isEditing && lookupState === 'not_found' && (
        <div className="space-y-3">
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
          {enrichmentOptIn ? (
            <EnrichmentCapturePanel
              photos={capturePhotos}
              onPhotosChange={setCapturePhotos}
              brand={captureBrand}
              onBrandChange={setCaptureBrand}
              attributes={captureAttributes}
              onAttributesChange={setCaptureAttributes}
              disabled={isSubmitting}
            />
          ) : null}
        </div>
      )}

      {/* Form */}
      <form id={PRODUCT_FORM_ID} onSubmit={(e) => { void handleSubmit(onSubmit, onInvalid)(e) }} className="flex flex-1 flex-col gap-[18px]">
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
            <ProductSectionStack
              mode="edit"
              adapters={{
                hero: {
                  mode: 'edit',
                  barcode: barcodeValue,
                  name: nameValue,
                  productId: isEditing && id ? id : null,
                  primaryImageUrl: product?.primary_image_url ?? null,
                  media: { bufferedImages, onBufferedImagesChange: setBufferedImages },
                  hero: {
                    enrichmentState: heroEnrichmentState,
                    chips: heroChips,
                    onBarcodeChange: (value) => { setValue('barcode', value, { shouldDirty: true }) },
                    onNameChange: (value) => { setValue('name', value, { shouldDirty: true }) },
                    onProductData: handleProductData,
                    onLookupStateChange: handleLookupStateChange,
                    onManualRefresh: handleManualRefresh,
                  },
                },
                general: {
                  mode: 'edit',
                  form: { control, errors, register, setValue, watch },
                  general: { prefilledFields, clearPrefilledField },
                },
                pricing: {
                  mode: 'edit',
                  canViewCostPrices,
                  currency,
                  locale,
                  moneyScale,
                  form: { control, setValue, watch },
                  isEditing,
                  product: product === undefined ? null : { cost_price: product.cost_price },
                  pricing,
                },
                inventory: {
                  mode: 'edit',
                  form: { control, register, watch, setValue, errors },
                  inventory: {
                    reorderDecimals,
                    showBatchTracking,
                    showOpeningSection,
                    canEnterOpening,
                    isOpeningLocked,
                    productStockQuantity: product?.stock_quantity ?? null,
                    canResetOpening,
                    showResetConfirm,
                    isResettingOpening,
                    requestOpeningReset: () => { setShowResetConfirm(true) },
                    cancelOpeningReset: () => { setShowResetConfirm(false) },
                    resetOpening: handleResetOpening,
                  },
                },
                suppliers: { mode: 'edit' },
                media: {
                  mode: 'edit',
                  isEditing,
                  productId: id || null,
                  media: { bufferedImages, onBufferedImagesChange: setBufferedImages },
                },
              }}
              placement={hasModule('Inventory') && isEditing && watchIsPhysical ? (
                <ProductPlacementFields productId={id} canEdit={canAdjustInventory} />
              ) : undefined}
              automotive={isOtospex ? (
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
            ) : undefined}
              pharmacy={isParapharmacy ? (
                <div id="section-pharmacy" className="scroll-mt-24">
                  <ParapharmacyMetadataFields
                    control={control}
                    register={register}
                    errors={errors}
                  />
                </div>
              ) : undefined}
              loyalty={hasModule('Loyalty') ? (
                <EditorSectionCard
                  id="section-loyalty"
                  title={t('catalog:editor.sectionLabels.loyalty')}
                >
                  <LoyaltyPointsDisplay salePrice={salePriceValue} />
                </EditorSectionCard>
              ) : undefined}
              variants={isEditing && id ? (
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
              ) : undefined}
            />
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
