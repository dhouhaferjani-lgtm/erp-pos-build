/**
 * Parts Catalog TypeScript types
 *
 * Based on the enriched article response contract from doc 03-catalog-search.
 * These types represent the ERP-enriched responses, NOT raw platform data.
 */

// ─── Enums & Constants ─────────────────────────────────────────────

export type VehicleType = 'pc' | 'cv' | 'mtb'

export type SearchMode = 'vehicle' | 'partNumber' | 'tireSize' | 'category' | 'vinPlate'

export type CriteriaType = 'text' | 'number' | 'boolean' | 'range' | 'key_table'

export type CrossReferenceType = 'oe' | 'oem' | 'iam' | 'ean'

export type BrandQualityTier =
  | 'oe'
  | 'oes'
  | 'premium_aftermarket'
  | 'aftermarket'
  | 'economy'

export type TenantVertical =
  | 'mechanic'
  | 'body_shop'
  | 'parts_retailer'
  | 'tire_shop'
  | 'car_glass'
  | 'service_station'

// ─── Supplier ───────────────────────────────────────────────────────

export interface Supplier {
  id: string
  brand: string
  slug: string
}

// ─── Cross Reference ────────────────────────────────────────────────

export interface CrossReference {
  reference_type: CrossReferenceType
  reference_number: string
  manufacturer_name: string | null
}

// ─── Criteria ───────────────────────────────────────────────────────

export interface ArticleCriteria {
  criteria_id: string
  label: string
  value: string
  unit: string | null
  type: CriteriaType
}

export interface CriteriaMetadata {
  id: string
  tecdoc_id: number
  type: CriteriaType
  label: string
  unit: string | null
}

// ─── Vehicle Compatibility ──────────────────────────────────────────

export interface CompatibleVehicle {
  vehicle_type: VehicleType
  vehicle_id: string
  display: string
  fitment_confidence: number
  data_source: string
}

// ─── Local Inventory ────────────────────────────────────────────────

export interface LocalInventory {
  product_id: string | null
  in_stock: boolean
  total_quantity: number
  available_quantity: number
  sale_price: string | null
  purchase_price: string | null
}

// ─── Article Price ──────────────────────────────────────────────────

export interface ArticlePrice {
  price_type: string
  price: string
  currency_code: string
  valid_from: string
  valid_to: string | null
}

// ─── Enriched Article (main response type) ──────────────────────────

export interface EnrichedArticle {
  id: string
  article_number: string
  status: string
  supplier: Supplier
  cross_references: CrossReference[]
  criteria: ArticleCriteria[]
  compatible_vehicles: CompatibleVehicle[]
  local_inventory: LocalInventory
  prices: ArticlePrice[]
}

// ─── Vehicle Drill-Down ─────────────────────────────────────────────

export interface Manufacturer {
  id: string
  brand: string
  slug: string
}

export interface ModelSeries {
  id: string
  name: string
  manufacturer_id: string
  production_from: number | null
  production_to: number | null
}

export interface Vehicle {
  id: string
  display: string
  model_series_id: string
  vehicle_type: VehicleType
  power_kw: number | null
  power_hp: number | null
  engine_code: string | null
  production_from: string | null
  production_to: string | null
}

// ─── Selected Vehicle (enriched for display in sticky bar) ──────────

export interface SelectedVehicle extends Vehicle {
  manufacturerBrand: string
  manufacturerSlug: string
  modelSeriesName: string
  plate?: string
  vin?: string
  selectedAt: string // ISO timestamp for "last searched" display
}

// ─── Search Tree ────────────────────────────────────────────────────

export interface SearchTreeNode {
  id: string
  name: string
  parent_id: string | null
  has_children: boolean
  article_count: number | null
}

// ─── Multi-Search Response ──────────────────────────────────────────

export interface MultiSearchResponse {
  articles: EnrichedArticle[]
  detected_brand: string | null
  search_methods_used: string[]
}

// ─── Criteria Search Request ────────────────────────────────────────

export interface CriteriaFilter {
  criteria_id: string
  value: string
}

export interface CriteriaSearchRequest {
  criteria_filters: CriteriaFilter[]
  product_group_id?: string
  per_page?: number
  cursor?: string
}

// ─── Paginated Article Response ─────────────────────────────────────

export interface PaginatedArticles {
  data: EnrichedArticle[]
  meta: {
    cursor: string | null
    has_more: boolean
    per_page: number
  }
}

// ─── Add to Inventory Request ───────────────────────────────────────

export interface AddToInventoryRequest {
  name: string
  sku: string
  type: 'part'
  barcode?: string
  sale_price?: string
  purchase_price?: string
  tax_rate?: string
  is_active: true
  automotive_metadata: {
    platform_article_id: string
    platform_link_status: 'linked'
    article_number: string
    supplier_brand: string
    product_group_name: string
    brand_quality_tier: BrandQualityTier
    confidence_score: number
    data_source: 'manual'
    platform_original_data: Record<string, unknown>
    cross_references: CrossReference[]
    vehicles: CompatibleVehicle[]
    criteria: ArticleCriteria[]
  }
}

// ─── Slide-Over Panel Props ─────────────────────────────────────────

export interface PartsCatalogPanelProps {
  isOpen: boolean
  onClose: () => void
  onAddArticle: (article: EnrichedArticle, quantity: number) => void
  documentType: 'purchase_order' | 'sales_order' | 'quote'
  vehicleId?: string
  supplierId?: string
}

// ─── Search Mode Availability ───────────────────────────────────────

export const SEARCH_MODES_BY_VERTICAL: Record<TenantVertical, { default: SearchMode; available: SearchMode[] }> = {
  mechanic: { default: 'vehicle', available: ['vehicle', 'partNumber', 'category', 'vinPlate'] },
  body_shop: { default: 'vehicle', available: ['vehicle', 'partNumber', 'category', 'vinPlate'] },
  parts_retailer: { default: 'partNumber', available: ['vehicle', 'partNumber', 'category', 'vinPlate'] },
  tire_shop: { default: 'tireSize', available: ['tireSize', 'vehicle', 'partNumber', 'category', 'vinPlate'] },
  car_glass: { default: 'vehicle', available: ['vehicle', 'partNumber', 'category', 'vinPlate'] },
  service_station: { default: 'category', available: ['category', 'partNumber', 'vehicle', 'vinPlate'] },
}

// ─── Standard Tire Dimensions (ISO) ────────────────────────────────

export const TIRE_WIDTHS = [
  '145', '155', '165', '175', '185', '195', '205', '215',
  '225', '235', '245', '255', '265', '275', '285', '295',
  '305', '315', '325', '335', '345', '355',
] as const

export const TIRE_ASPECT_RATIOS = [
  '25', '30', '35', '40', '45', '50', '55', '60', '65', '70', '75', '80',
] as const

export const TIRE_RIM_DIAMETERS = [
  '13', '14', '15', '16', '17', '18', '19', '20', '21', '22',
] as const
