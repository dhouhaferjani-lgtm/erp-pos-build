import type {
  Control,
  FieldErrors,
  UseFormRegister,
  UseFormSetValue,
  UseFormWatch,
} from 'react-hook-form'

import type { EnrichmentAttributeRow, UploadedPhoto } from '../enrichmentCaptureTypes'
import type { LookupState, SuggestedProduct } from '../productLookupTypes'

export type ProductSectionMode = 'view' | 'edit'

export interface ParapharmacySectionFormData {
  category: string
  dosage_form: string | null
  active_ingredients: { name: string; concentration: string }[]
  usage_instructions: string | null
  warnings: string | null
  contraindications: string | null
  minimum_age: number | null
  age_restriction: string | null
  requires_consultation: boolean
  regulatory_code: string | null
  storage_requirements: string | null
}

export interface ProductSectionFormData {
  name: string
  sku: string
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
  cross_references: { brand: string; reference: string }[]
  parapharmacy_metadata: ParapharmacySectionFormData
  requires_batch_tracking: boolean
  default_shelf_life_days: number | null
  units_per_pack: number | null
  shelf_location: string
  reorder_point: string
  reorder_quantity: string
  opening_qty: string
  opening_unit_cost: string
}

export type ProductSectionProduct = App.Modules.Product.Application.DTOs.ProductData

type ProductSectionCostField =
  | 'purchase_price'
  | 'cost_price'
  | 'last_purchase_cost'
  | 'effective_margins'

export type ProductSectionPublicProduct = Omit<ProductSectionProduct, ProductSectionCostField>

export interface ProductSectionCostPrices {
  purchasePrice: string | null
  costPrice: string | null
  lastPurchaseCost: string | null
  effectiveMargins: ProductSectionProduct['effective_margins']
}

export interface ProductPricingFieldController {
  value: string
  onFocus: () => void
  onChange: (value: string) => void
  onBlur: () => void
}

export interface ProductPricingEditController {
  costBasis: string
  cost: string
  margin: ProductPricingFieldController
  priceHt: ProductPricingFieldController
  priceTtc: ProductPricingFieldController
  commitCost: (value: string) => void
}

export interface DiscountPolicyVerdict {
  allowed: boolean
  blocksSale: boolean
  severity: 'info' | 'warn' | 'block'
  requiresPermission: string | null
  maxDiscountPercent: string
  discountPercent: string
  floorPriceNet: string | null
  floorBasis: string
  floorEnforcement: string
  mode: string
  overridable: boolean
  requiresReason: boolean
  policyVersion: string
  policyAsOf: string
  reasons: string[]
  meta: Record<string, unknown>
}

export type ProductHeroEnrichmentState =
  | 'never-submitted'
  | 'pending'
  | 'ready-for-review'
  | 'enriched'
  | 'unavailable'

export interface ProductHeroChip {
  id: string
  label: string
  enriched?: boolean
  href?: string
}

interface ProductSectionsBaseAdapter {
  canViewCostPrices: boolean
  costPrices: ProductSectionCostPrices | null
  currency: string
  locale: string
  moneyScale: number
  formatCurrency: (value: string | null) => string
  formatPercent: (value: string | null) => string
}

export interface ProductSectionsViewAdapter extends ProductSectionsBaseAdapter {
  mode: 'view'
  product: ProductSectionPublicProduct
  discountPolicyVerdict?: DiscountPolicyVerdict
}

export interface ProductSectionsEditAdapter extends ProductSectionsBaseAdapter {
  mode: 'edit'
  product: ProductSectionProduct | null
  productId: string | null
  isEditing: boolean
  form: {
    control: Control<ProductSectionFormData>
    register: UseFormRegister<ProductSectionFormData>
    watch: UseFormWatch<ProductSectionFormData>
    setValue: UseFormSetValue<ProductSectionFormData>
    errors: FieldErrors<ProductSectionFormData>
  }
  general: {
    prefilledFields: ReadonlySet<string>
    clearPrefilledField: (field: 'name' | 'description') => void
  }
  pricing: ProductPricingEditController
  inventory: {
    reorderDecimals: number
    showBatchTracking: boolean
    showOpeningSection: boolean
    canEnterOpening: boolean
    isOpeningLocked: boolean
    productStockQuantity: string | null
    canResetOpening: boolean
    showResetConfirm: boolean
    isResettingOpening: boolean
    requestOpeningReset: () => void
    cancelOpeningReset: () => void
    resetOpening: () => Promise<void>
  }
  media: {
    bufferedImages: File[]
    onBufferedImagesChange: (files: File[]) => void
  }
  hero: {
    enrichmentState: ProductHeroEnrichmentState
    chips: ProductHeroChip[]
    lookupState: LookupState
    onBarcodeChange: (value: string) => void
    onNameChange: (value: string) => void
    onProductData: (data: SuggestedProduct) => void
    onLookupStateChange: (state: LookupState) => void
    onManualRefresh: () => void
  }
  enrichmentCapture: {
    photos: UploadedPhoto[]
    onPhotosChange: (photos: UploadedPhoto[]) => void
    brand: string
    onBrandChange: (brand: string) => void
    attributes: EnrichmentAttributeRow[]
    onAttributesChange: (attributes: EnrichmentAttributeRow[]) => void
  }
}

export type ProductSectionsAdapter = ProductSectionsViewAdapter | ProductSectionsEditAdapter
