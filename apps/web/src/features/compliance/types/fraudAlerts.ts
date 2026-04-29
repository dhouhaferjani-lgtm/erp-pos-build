/**
 * FE-only types for the fraud-alerts area.
 *
 * These shadow the unwrapped Eloquent payload returned by FraudAlertController
 * and the FE filter UI; there is no backend Spatie Data DTO for FraudAlert at
 * the moment, so they remain hand-written here. When a Compliance/Domain DTO
 * is introduced, migrate consumers per Rule #7 (types flow from backend) and
 * delete this file.
 *
 * The canonical FraudSettings shape lives in the generated namespace as
 * `App.Modules.Compliance.Application.DTOs.CompanyFraudSettingsData`
 * (see ./fraudSettings.ts re-export) — do NOT add it here.
 */

export interface FraudAlert {
  id: string
  tenant_id: string
  company_id: string
  user_id: string
  alert_type: 'high_abandonment' | 'suspicious_items' | 'rapid_cycle'
  severity: 'info' | 'warning' | 'critical'
  description: string
  detected_at: string
  flagged_products: FlaggedProduct[] | null
  metadata: Record<string, unknown> | null
  status: 'open' | 'investigating' | 'dismissed' | 'resolved'
  assigned_to: string | null
  resolved_at: string | null
  resolution_notes: string | null
  created_at: string
  updated_at: string
  user?: {
    id: string
    name: string
    email: string
  }
  assigned_user?: {
    id: string
    name: string
    email: string
  }
  company?: {
    id: string
    name: string
  }
}

export interface FlaggedProduct {
  product_id: string
  product_name: string
  count: number
}

export interface FraudAlertStatistics {
  total_alerts: number
  open_alerts: number
  investigating: number
  dismissed: number
  resolved: number
  by_severity: {
    critical: number
    warning: number
    info: number
  }
  recent_alerts: number
}

export interface FraudAlertFilters {
  status?: string
  severity?: string
  alert_type?: string
  detected_after?: string
  detected_before?: string
}
