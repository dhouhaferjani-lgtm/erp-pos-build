export {
  PRODUCT_SECTION_DEFINITIONS,
  PRODUCT_SECTION_KEYS,
  type ProductSectionDefinition,
  type ProductSectionKey,
} from './sectionRegistry'
export { ProductGeneralSection, type ProductGeneralAdapter } from './ProductGeneralSection'
export { ProductPricingSection, type ProductPricingAdapter } from './ProductPricingSection'
export { ProductInventorySection, type ProductInventoryAdapter } from './ProductInventorySection'
export { useProductPricingEditAdapter } from './useProductPricingEditAdapter'
export type {
  ParapharmacySectionFormData,
  DiscountPolicyVerdict,
  ProductHeroChip,
  ProductHeroEnrichmentState,
  ProductPricingEditController,
  ProductPricingFieldController,
  ProductSectionCostPrices,
  ProductSectionFormData,
  ProductSectionMode,
  ProductSectionProduct,
  ProductSectionPublicProduct,
  ProductSectionsAdapter,
  ProductSectionsEditAdapter,
  ProductSectionsViewAdapter,
} from './types'
