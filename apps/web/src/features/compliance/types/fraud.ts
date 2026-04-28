export interface FraudSettings {
  id?: string
  company_id: string
  abandoned_draft_threshold: number
  time_window_days: number
  alert_emails: string[]
  alert_enabled: boolean
  auto_trigger_counting: boolean
  auto_restrict_access: boolean
  // Cash drawer variance thresholds
  cash_variance_over_soft: string
  cash_variance_over_hard: string
  cash_variance_under_soft: string
  cash_variance_under_hard: string
  require_blind_cash_count: boolean
  require_manager_pin_above_hard: boolean
  cash_variance_email_severity: 'none' | 'critical' | 'warning' | 'info'
  created_at?: string
  updated_at?: string
  is_configured?: boolean
}

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
