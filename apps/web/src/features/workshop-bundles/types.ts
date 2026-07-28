// TEMPORARY shadow — replace with re-export from
//   App.Modules.Workshop.Bundle.Application.DTOs.ServiceBundleData
// once feat/types-pipeline-overhaul lands.
// Tracked in docs/sessions/2026-04-19-types-pipeline-coordination.md

export type BundlePricingMode = 'standard' | 'fixed_bundle'

export type BundleComponentType = 'part' | 'labor' | 'nested_bundle'

export type VehicleTypeRef = 'pc' | 'cv' | 'mtb' | 'eng' | 'axl' | 'universal'

export interface ServiceBundleComponentData {
  id: string
  bundle_id: string
  component_type: BundleComponentType
  component_id: string
  component_display_name: string
  quantity: string
  quantity_decimals: number
  unit: string
  override_unit_price: string | null
  is_optional: boolean
  display_order: number
  notes: string | null
}

export interface ServiceBundleVehicleApplicabilityData {
  id: string
  bundle_id: string
  platform_vehicle_id: string | null
  vehicle_type: VehicleTypeRef | null
  vehicle_display: string | null
  year_from: number | null
  year_to: number | null
}

export interface ServiceBundleData {
  id: string
  tenant_id: string
  company_id: string
  code: string
  name: string
  description: string | null
  pricing_mode: BundlePricingMode
  base_price: string | null
  currency: string
  tax_rate: string | null
  estimated_labor_hours: string | null
  service_interval_km: number | null
  service_interval_months: number | null
  is_active: boolean
  components: ServiceBundleComponentData[]
  vehicle_applicabilities: ServiceBundleVehicleApplicabilityData[]
  created_at: string
  updated_at: string | null
}

export interface ApplicableBundleData {
  id: string
  code: string
  name: string
  description: string | null
  pricing_mode: BundlePricingMode
  base_price: string | null
  currency: string
  service_interval_km: number | null
  service_interval_months: number | null
  estimated_labor_hours: string | null
  component_count: number
}

export interface BundleExpansionLineData {
  component_type: BundleComponentType
  component_id: string | null
  display_name: string
  quantity: string
  quantity_decimals: number
  unit: string
  unit_price: string
  line_total: string
  is_optional: boolean
  is_from_fixed_bundle: boolean
}
