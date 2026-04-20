// TEMPORARY shadow — replace with re-export from
//   App.Modules.Vehicle.Application.DTOs.VehicleWithCurrentOwnerData
// once feat/types-pipeline-overhaul lands.
// Tracked in docs/sessions/2026-04-19-types-pipeline-coordination.md

export type FuelType =
  | 'gasoline'
  | 'diesel'
  | 'electric'
  | 'hybrid'
  | 'plugin_hybrid'
  | 'lpg'
  | 'cng'
  | 'hydrogen'
  | 'other'

export type TransmissionType =
  | 'manual'
  | 'automatic'
  | 'semi_automatic'
  | 'cvt'
  | 'dual_clutch'
  | 'other'

export type BodyType =
  | 'sedan'
  | 'hatchback'
  | 'suv'
  | 'pickup'
  | 'van'
  | 'coupe'
  | 'convertible'
  | 'wagon'
  | 'truck'
  | 'motorcycle'
  | 'other'

export type OwnershipReason =
  | 'initial_registration'
  | 'purchase'
  | 'sale'
  | 'transfer'
  | 'trade_in'
  | 'fleet_assignment'
  | 'fleet_return'
  | 'other'

export type MileageSource =
  | 'service'
  | 'manual'
  | 'odometer_photo'
  | 'external_api'

export interface VehicleData {
  id: string
  tenant_id: string
  company_id: string
  license_plate: string
  brand: string
  model: string
  year: number | null
  color: string | null
  mileage: number | null
  vin: string | null
  engine_code: string | null
  fuel_type: FuelType | null
  transmission: TransmissionType | null
  body_type: BodyType | null
  notes: string | null
  current_owner_partner_id: string | null
  current_owner_display_name: string | null
  /** @deprecated kept for backward compat during the 2026-04-19 migration window */
  partner_id: string | null
  created_at: string
  updated_at: string | null
}

export interface VehicleOwnershipData {
  id: string
  vehicle_id: string
  owner_partner_id: string
  owner_display_name: string
  acquired_at: string
  released_at: string | null
  reason_code: OwnershipReason
  notes: string | null
  recorded_by_user_id: string | null
}

export interface VehicleMileageReadingData {
  id: string
  vehicle_id: string
  mileage: number
  recorded_at: string
  source: MileageSource
  context_document_id: string | null
  context_work_order_id: string | null
  notes: string | null
}
