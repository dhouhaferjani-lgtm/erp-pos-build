export const PRODUCT_SECTION_KEYS = [
  'hero',
  'general',
  'pricing',
  'inventory',
  'suppliers',
  'media',
] as const

export type ProductSectionKey = (typeof PRODUCT_SECTION_KEYS)[number]

export interface ProductSectionDefinition {
  key: ProductSectionKey
  id: `section-${ProductSectionKey}`
  labelKey: string
}

export const PRODUCT_SECTION_DEFINITIONS = [
  { key: 'hero', id: 'section-hero', labelKey: 'catalog:editor.sectionLabels.hero' },
  { key: 'general', id: 'section-general', labelKey: 'catalog:editor.sectionLabels.general' },
  { key: 'pricing', id: 'section-pricing', labelKey: 'catalog:editor.sectionLabels.pricing' },
  { key: 'inventory', id: 'section-inventory', labelKey: 'catalog:editor.sectionLabels.inventory' },
  { key: 'suppliers', id: 'section-suppliers', labelKey: 'catalog:editor.sectionLabels.suppliers' },
  { key: 'media', id: 'section-media', labelKey: 'catalog:editor.sectionLabels.media' },
] as const satisfies readonly ProductSectionDefinition[]

