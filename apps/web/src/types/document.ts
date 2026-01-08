/**
 * Document types - using generated types from backend DTOs
 * Generated via: php artisan typescript:transform
 */

import type { App } from '@mecanospex/shared/types/generated'

// Re-export the generated DocumentData as Document for compatibility
export type Document = App.Modules.Document.Application.DTOs.DocumentData & {
  // Add computed/extended properties that may be added by frontend
  issue_date?: string  // Alias for document_date (some endpoints use this)
}

// Re-export VehicleContextData
export type VehicleContextData = App.Modules.Document.Application.DTOs.VehicleContextData

// DocumentLine type with delivery/receipt tracking fields
export interface DocumentLine {
  id: string
  document_id: string
  product_id: string | null
  line_number: number
  description: string
  quantity: string  // Formatted number string from backend
  unit_price: string  // Formatted number string from backend
  discount_percent: string | null
  discount_amount: string | null
  tax_rate: string | null  // Formatted number string from backend
  line_total: string  // Formatted number string from backend
  notes: string | null
  // Extended fields for delivery/receipt tracking (may be in payload)
  quantity_delivered?: string
  quantity_received?: string
}

// PaymentRecord from allocations
export interface PaymentRecord {
  id: string
  payment_id: string
  amount: string
  payment_date: string
  payment_reference: string | null
  payment_method: string | null
}
