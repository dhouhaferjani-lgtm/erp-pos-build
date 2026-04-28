import { apiGet, apiPatch, apiPost } from '../../../lib/api'
import type {
  FraudAlert,
  FraudAlertFilters,
  FraudAlertStatistics,
} from '../types/fraudAlerts'
import type { FraudSettings } from '../types/fraudSettings'

interface AdminUser {
  id: string
  name: string
  email: string
}

// Fraud Settings API
export const getFraudSettings = async (): Promise<{ data: FraudSettings }> => {
  return apiGet('/fraud-settings')
}

// The payload type is Partial<FraudSettings>, which includes all 7 cash-control
// fields added in the cash-counting remediation:
//   cash_variance_over_soft, cash_variance_over_hard,
//   cash_variance_under_soft, cash_variance_under_hard,
//   require_blind_cash_count, require_manager_pin_above_hard,
//   cash_variance_email_severity
// Any subset of these fields is sent as-is; no narrowing is needed here.
export const updateFraudSettings = async (
  settings: Partial<FraudSettings>
): Promise<{ data: FraudSettings; message: string }> => {
  return apiPatch('/fraud-settings', settings)
}

export const resetFraudSettings = async (): Promise<{
  data: FraudSettings
  message: string
}> => {
  return apiPost('/fraud-settings/reset', {})
}

// Fraud Alerts API
export const getFraudAlerts = async (
  filters?: FraudAlertFilters,
  page = 1
): Promise<{
  data: FraudAlert[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}> => {
  const params = new URLSearchParams({ page: page.toString() })

  if (filters) {
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params.append(key, value)
    })
  }

  return apiGet(`/fraud-alerts?${params.toString()}`)
}

export const getFraudAlert = async (
  alertId: string
): Promise<{ data: FraudAlert }> => {
  return apiGet(`/fraud-alerts/${alertId}`)
}

export const getFraudAlertStatistics =
  async (): Promise<{ data: FraudAlertStatistics }> => {
    return apiGet('/fraud-alerts/statistics')
  }

export const assignFraudAlert = async (
  alertId: string,
  assignedTo: string
): Promise<{ data: FraudAlert; message: string }> => {
  return apiPost(`/fraud-alerts/${alertId}/assign`, { assigned_to: assignedTo })
}

export const dismissFraudAlert = async (
  alertId: string,
  notes: string
): Promise<{ data: FraudAlert; message: string }> => {
  return apiPost(`/fraud-alerts/${alertId}/dismiss`, { notes })
}

export const resolveFraudAlert = async (
  alertId: string,
  notes: string
): Promise<{ data: FraudAlert; message: string }> => {
  return apiPost(`/fraud-alerts/${alertId}/resolve`, { notes })
}

// Admin Users API
export const getAdminUsers = async (): Promise<{ data: AdminUser[] }> => {
  return apiGet('/users?role=admin')
}

export type { AdminUser }
