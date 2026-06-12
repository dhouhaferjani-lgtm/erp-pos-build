import axios from 'axios'
import { apiPost, ensureCsrfCookie } from '@/lib/api'
import { adminApiGet, adminApiGetPaginated, adminApiPost, adminApiPatch, adminApiPut } from '../lib/adminApi'
import type {
  AdminAuthResponse,
  AdminDashboardStats,
  TenantListItem,
  TenantDetailResponse,
  AdminAuditLog,
  BillingDashboardStats,
  PaymentProviderStatus,
  Plan,
  Subscription,
  Invoice,
  Payment,
  Refund,
  CreateInvoiceRequest,
  RecordPaymentRequest,
  RefundPaymentRequest,
  UpdateSubscriptionRequest,
  PlanSummary,
  AdminVerticalConfig,
  AdminVerticalsResponse,
  UpdateVerticalConfigRequest,
} from '../types'

// Authentication (uses regular API since not authenticated yet)
export async function loginSuperAdmin(
  email: string,
  password: string
): Promise<AdminAuthResponse> {
  // Ensure CSRF cookie is set before login (required for Sanctum SPA auth)
  await ensureCsrfCookie()
  const response = await apiPost<{ admin: AdminAuthResponse }>(
    '/admin/auth/login',
    { email, password }
  )
  // Cookie is set automatically by Sanctum - just return admin data
  return response.admin
}

export async function logoutSuperAdmin(): Promise<void> {
  await adminApiPost('/admin/auth/logout', {})
}

export async function getSuperAdminProfile(): Promise<AdminAuthResponse> {
  return adminApiGet<AdminAuthResponse>('/admin/auth/me')
}

// Dashboard
export async function getAdminDashboardStats(): Promise<AdminDashboardStats> {
  return adminApiGet<AdminDashboardStats>('/admin/dashboard')
}

// Tenants - API returns paginated data wrapped in { data: ... }
interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

// Backend wraps paginated responses in { data: paginated_result }
interface WrappedPaginatedResponse<T> {
  data: PaginatedResponse<T>
}

export async function getTenants(params?: {
  search?: string
  status?: string
}): Promise<{ data: TenantListItem[] }> {
  const queryParams = new URLSearchParams()
  if (params?.search) queryParams.append('search', params.search)
  if (params?.status) queryParams.append('status', params.status)

  const query = queryParams.toString()
  const response = await adminApiGetPaginated<WrappedPaginatedResponse<TenantListItem>>(
    `/admin/tenants${query ? `?${query}` : ''}`
  )
  return { data: response.data.data }
}

export async function getTenant(id: string): Promise<TenantDetailResponse> {
  return adminApiGet<TenantDetailResponse>(`/admin/tenants/${id}`)
}

export async function getTenantPlanUsage(id: string): Promise<PlanSummary> {
  return adminApiGet<PlanSummary>(`/admin/tenants/${id}/plan-usage`)
}

export async function extendTrial(
  tenantId: string,
  days: number
): Promise<void> {
  await adminApiPost(`/admin/tenants/${tenantId}/extend-trial`, { days })
}

export async function changePlan(
  tenantId: string,
  planId: string
): Promise<void> {
  await adminApiPost(`/admin/tenants/${tenantId}/change-plan`, { plan_id: planId })
}

export async function suspendTenant(
  tenantId: string,
  reason?: string
): Promise<void> {
  await adminApiPost(`/admin/tenants/${tenantId}/suspend`, { reason })
}

export async function activateTenant(tenantId: string): Promise<void> {
  await adminApiPost(`/admin/tenants/${tenantId}/activate`, {})
}

export async function updateTenantExtras(
  tenantId: string,
  enabledExtras: string[]
): Promise<void> {
  await adminApiPost(`/admin/tenants/${tenantId}/update-extras`, {
    enabled_extras: enabledExtras,
  })
}

// Audit Logs
export async function getAdminAuditLogs(params?: {
  tenant_id?: string
  action?: string
}): Promise<{ data: AdminAuditLog[] }> {
  const queryParams = new URLSearchParams()
  if (params?.tenant_id) queryParams.append('tenant_id', params.tenant_id)
  if (params?.action) queryParams.append('action', params.action)

  const query = queryParams.toString()
  const response = await adminApiGetPaginated<WrappedPaginatedResponse<AdminAuditLog>>(
    `/admin/audit-logs${query ? `?${query}` : ''}`
  )
  return { data: response.data.data }
}

// ============================================================================
// Vertical Configuration API (super-admin module assignment per vertical)
// ============================================================================

// The verticals index returns BOTH `data` and a top-level `available_modules`
// key, so we use the raw-response helper (adminApiGetPaginated returns
// response.data as-is) — adminApiGet would drop `available_modules`.
export async function getVerticals(): Promise<AdminVerticalsResponse> {
  return adminApiGetPaginated<AdminVerticalsResponse>('/admin/verticals')
}

export async function updateVerticalConfig(
  vertical: string,
  payload: UpdateVerticalConfigRequest
): Promise<AdminVerticalConfig> {
  return adminApiPut<AdminVerticalConfig>(`/admin/verticals/${vertical}`, payload)
}

/**
 * The verticals endpoints return validation failures as a flat
 * `{ error: string, valid_modules: string[] }` 422 body (NOT the standard
 * `{ error: { code, message } }` envelope), so getErrorMessage() cannot
 * extract it. This pulls the flat string out when present.
 */
export function getVerticalConfigErrorMessage(error: unknown): string | null {
  if (!axios.isAxiosError(error)) {
    return null
  }
  const data: unknown = error.response?.data
  if (typeof data !== 'object' || data === null || !('error' in data)) {
    return null
  }
  const { error: errorValue } = data
  return typeof errorValue === 'string' ? errorValue : null
}

// ============================================================================
// Billing API
// ============================================================================

// Billing Dashboard
export async function getBillingDashboardStats(): Promise<BillingDashboardStats> {
  return adminApiGet<BillingDashboardStats>('/admin/billing/dashboard')
}

export async function getPaymentProviders(): Promise<
  Record<string, PaymentProviderStatus>
> {
  return adminApiGet<Record<string, PaymentProviderStatus>>(
    '/admin/billing/providers'
  )
}

// Plans
export async function getPlans(): Promise<Plan[]> {
  return adminApiGet<Plan[]>('/admin/billing/plans')
}

// Subscriptions - Note: billing endpoints return paginated data directly (not wrapped in { data: ... })
export async function getSubscriptions(params?: {
  status?: string
  plan_id?: string
  per_page?: number
}): Promise<{ data: Subscription[]; total: number }> {
  const queryParams = new URLSearchParams()
  if (params?.status) queryParams.append('status', params.status)
  if (params?.plan_id) queryParams.append('plan_id', params.plan_id)
  if (params?.per_page) queryParams.append('per_page', String(params.per_page))

  const query = queryParams.toString()
  const response = await adminApiGetPaginated<PaginatedResponse<Subscription>>(
    `/admin/billing/subscriptions${query ? `?${query}` : ''}`
  )
  return { data: response.data, total: response.total }
}

export async function getSubscription(id: string): Promise<Subscription> {
  return adminApiGet<Subscription>(`/admin/billing/subscriptions/${id}`)
}

export async function updateSubscription(
  id: string,
  data: UpdateSubscriptionRequest
): Promise<Subscription> {
  return adminApiPatch<Subscription>(
    `/admin/billing/subscriptions/${id}`,
    data
  )
}

// Invoices - billing endpoints return paginated data directly
export async function getInvoices(params?: {
  status?: string
  tenant_id?: string
  per_page?: number
}): Promise<{ data: Invoice[]; total: number }> {
  const queryParams = new URLSearchParams()
  if (params?.status) queryParams.append('status', params.status)
  if (params?.tenant_id) queryParams.append('tenant_id', params.tenant_id)
  if (params?.per_page) queryParams.append('per_page', String(params.per_page))

  const query = queryParams.toString()
  const response = await adminApiGetPaginated<PaginatedResponse<Invoice>>(
    `/admin/billing/invoices${query ? `?${query}` : ''}`
  )
  return { data: response.data, total: response.total }
}

export async function getInvoice(id: string): Promise<Invoice> {
  return adminApiGet<Invoice>(`/admin/billing/invoices/${id}`)
}

export async function createInvoice(data: CreateInvoiceRequest): Promise<Invoice> {
  return adminApiPost<Invoice>('/admin/billing/invoices', data)
}

export function getInvoiceDownloadUrl(id: string): string {
  return `/api/v1/admin/billing/invoices/${id}/download`
}

// Payments - billing endpoints return paginated data directly
export async function getPayments(params?: {
  status?: string
  provider?: string
  tenant_id?: string
  per_page?: number
}): Promise<{ data: Payment[]; total: number }> {
  const queryParams = new URLSearchParams()
  if (params?.status) queryParams.append('status', params.status)
  if (params?.provider) queryParams.append('provider', params.provider)
  if (params?.tenant_id) queryParams.append('tenant_id', params.tenant_id)
  if (params?.per_page) queryParams.append('per_page', String(params.per_page))

  const query = queryParams.toString()
  const response = await adminApiGetPaginated<PaginatedResponse<Payment>>(
    `/admin/billing/payments${query ? `?${query}` : ''}`
  )
  return { data: response.data, total: response.total }
}

export async function getPayment(id: string): Promise<Payment> {
  return adminApiGet<Payment>(`/admin/billing/payments/${id}`)
}

export async function recordPayment(data: RecordPaymentRequest): Promise<Payment> {
  return adminApiPost<Payment>('/admin/billing/payments', data)
}

export async function refundPayment(
  id: string,
  data: RefundPaymentRequest
): Promise<Refund> {
  return adminApiPost<Refund>(`/admin/billing/payments/${id}/refund`, data)
}

// ============================================================================
// Monitoring API
// ============================================================================

export interface ServerMetrics {
  php_version: string
  laravel_version: string
  memory: {
    used: number
    peak: number
    limit: number
    usage_percent: number
  }
  load_average: {
    '1min': number
    '5min': number
    '15min': number
  }
  disk: {
    total: number
    free: number
    used: number
    usage_percent: number
    total_human: string
    free_human: string
    used_human: string
  }
  uptime: string
}

export interface DatabaseMetrics {
  status: string
  latency_ms: number
  connections: number
  max_connections: number
  size_bytes: number
  size_human: string
  error?: string
}

export interface CacheMetrics {
  status: string
  driver: string
  latency_ms: number
  info: Record<string, unknown>
  error?: string
}

export interface QueueMetrics {
  driver: string
  pending_jobs: number
  failed_jobs: number
  status: string
}

export interface ExternalServiceStatus {
  configured: boolean
  status: string
  driver?: string
}

export interface SystemHealth {
  status: 'healthy' | 'degraded' | 'critical'
  timestamp: string
  server: ServerMetrics
  database: DatabaseMetrics
  cache: CacheMetrics
  queue: QueueMetrics
  services: Record<string, ExternalServiceStatus>
}

export interface PerformanceMetrics {
  response_times: {
    average_ms: number
    p50_ms: number
    p95_ms: number
    p99_ms: number
    last_hour: number[]
  }
  throughput: {
    requests_per_minute: number
    requests_per_hour: number
    peak_rpm: number
    history: number[]
  }
  error_rates: {
    error_count_last_hour: number
    error_rate_percent: number
    status: string
  }
  database_performance: {
    slow_queries: number | string
    status: string
    note?: string
  }
}

export interface Alert {
  type: 'warning' | 'critical' | 'info'
  category: string
  message: string
  action: string
}

export interface CriticalEvent {
  type: string
  severity: string
  message: string
  tenant_id: string
  timestamp: string
}

export interface CriticalMetrics {
  billing: {
    revenue: {
      today: number
      this_month: number
      pending_invoices: number
      overdue_invoices: number
    }
    subscriptions: {
      active: number
      trial: number
      past_due: number
      churned_this_month: number
    }
    payments: {
      successful_today: number
      failed_today: number
    }
  }
  tenants: {
    total: number
    active: number
    trial: number
    new_this_month: number
    by_plan: Record<string, number>
  }
  alerts: Alert[]
  recent_events: CriticalEvent[]
}

export interface FailedJob {
  id: string | number
  queue: string
  failed_at: string
  exception: string
}

export interface QueueMonitoring {
  summary: QueueMetrics
  jobs_by_queue: Record<string, number>
  failed_jobs: FailedJob[]
  processing_rate: {
    jobs_per_minute: number
    jobs_per_hour: number
  }
}

export interface MonitoringDashboard {
  health: SystemHealth
  performance: PerformanceMetrics
  critical: CriticalMetrics
  queues: QueueMonitoring
}

// Monitoring API functions
export async function getMonitoringDashboard(): Promise<MonitoringDashboard> {
  return adminApiGet<MonitoringDashboard>('/admin/monitoring/dashboard')
}

export async function getSystemHealth(): Promise<SystemHealth> {
  return adminApiGet<SystemHealth>('/admin/monitoring/system')
}

export async function getPerformanceMetrics(): Promise<PerformanceMetrics> {
  return adminApiGet<PerformanceMetrics>('/admin/monitoring/performance')
}

export async function getCriticalMetrics(): Promise<CriticalMetrics> {
  return adminApiGet<CriticalMetrics>('/admin/monitoring/critical')
}

export async function getQueueMonitoring(): Promise<QueueMonitoring> {
  return adminApiGet<QueueMonitoring>('/admin/monitoring/queues')
}

export async function retryFailedJob(id: string | number): Promise<void> {
  await adminApiPost(`/admin/monitoring/failed-jobs/${id}/retry`, {})
}

export async function deleteFailedJob(id: string | number): Promise<void> {
  await adminApiPost(`/admin/monitoring/failed-jobs/${id}`, {})
}

export async function retryAllFailedJobs(): Promise<{ count: number }> {
  const response = await adminApiPost<{ count: number }>(
    '/admin/monitoring/failed-jobs/retry-all',
    {}
  )
  return response
}

export async function flushFailedJobs(): Promise<{ count: number }> {
  const response = await adminApiPost<{ count: number }>(
    '/admin/monitoring/failed-jobs/flush',
    {}
  )
  return response
}

// ============================================================================
// User Management API (for company owner email verification)
// ============================================================================

export interface AdminUser {
  id: string
  tenant_id: string
  name: string
  email: string
  status: string
  email_verified_at: string | null
  created_at: string
  tenant?: {
    id: string
    name: string
  }
}

export async function getAdminUsers(params?: {
  search?: string
  tenant_id?: string
  email_verified?: boolean
  status?: string
}): Promise<{ data: AdminUser[] }> {
  const queryParams = new URLSearchParams()
  if (params?.search) queryParams.append('search', params.search)
  if (params?.tenant_id) queryParams.append('tenant_id', params.tenant_id)
  if (params?.email_verified !== undefined) {
    queryParams.append('email_verified', String(params.email_verified))
  }
  if (params?.status) queryParams.append('status', params.status)

  const query = queryParams.toString()
  const response = await adminApiGetPaginated<WrappedPaginatedResponse<AdminUser>>(
    `/admin/users${query ? `?${query}` : ''}`
  )
  return { data: response.data.data }
}

export async function verifyUserEmail(userId: string, notes?: string): Promise<AdminUser> {
  return adminApiPost<AdminUser>(`/admin/users/${userId}/verify-email`, { notes })
}
