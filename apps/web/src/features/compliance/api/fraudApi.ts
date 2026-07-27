import { apiGet, apiPatch, apiPost } from '../../../lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'
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
): Promise<OffsetPaginationMeta & {
  data: FraudAlert[]
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

// Tenant-scoped users-with-admin-role API. NOT to be confused with the
// super-admin getAdminUsers in features/admin/api/index.ts (which hits
// /admin/users via adminApiGet under the super-admin auth context).
// This call hits the tenant-scoped /users?role=admin endpoint via the
// per-tenant apiGet — used by FraudAlert assignment to populate the
// "assignee" dropdown with users in the current tenant who carry the
// admin role.
export const getUsersWithAdminRole = async (): Promise<{ data: AdminUser[] }> => {
  return apiGet('/users?role=admin')
}

export type { AdminUser }
