import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../lib/api'
import { tenantScopedKey } from '../lib/tenantScopedKey'
import { useAuthStore } from '../stores/authStore'
import { useCompanyStore } from '../stores/companyStore'

/**
 * Company configuration from backend
 */
export interface CompanyConfig {
  vertical: string
  default_modules: string[]
  enabled_extras: string[]
  all_enabled_modules: string[]
  currency: string
  locale: string
  country_code: string | null
  smart_prompts_enabled: boolean
  smart_prompts_variant: 'inline' | 'toast' | 'both' | 'off'
  line_designation_override_enabled: boolean
}

/**
 * Context value for company configuration
 */
interface CompanyConfigContextValue {
  config: CompanyConfig | null
  isLoading: boolean
  error: Error | null
  hasModule: (moduleName: string) => boolean
}

const CompanyConfigContext = createContext<CompanyConfigContextValue | undefined>(undefined)

interface CompanyConfigProviderProps {
  children: ReactNode
}

/**
 * Provider that fetches and provides company configuration
 *
 * The configuration includes:
 * - vertical: Business vertical (e.g., 'mechanic', 'pharmacy', 'restaurant')
 * - default_modules: Default modules for this vertical
 * - enabled_extras: Additional modules enabled for this tenant
 * - all_enabled_modules: Combined list of all available modules
 *
 * Configuration is cached for 1 hour as it rarely changes.
 */
export function CompanyConfigProvider({ children }: CompanyConfigProviderProps) {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['company-config']),
    queryFn: () => apiGet<CompanyConfig>('/company/config'),
    staleTime: 1000 * 60 * 60, // 1 hour - config doesn't change often
    retry: 1,
    enabled: isAuthenticated && tenantId !== null && companyId !== null,
  })

  const hasModule = useMemo(() => {
    return (moduleName: string): boolean => {
      if (!data) return false
      return data.all_enabled_modules.includes(moduleName)
    }
  }, [data])

  const value = useMemo<CompanyConfigContextValue>(
    () => ({
      config: data ?? null,
      isLoading,
      error: error,
      hasModule,
    }),
    [data, isLoading, error, hasModule]
  )

  return (
    <CompanyConfigContext.Provider value={value}>{children}</CompanyConfigContext.Provider>
  )
}

/**
 * Hook to access company configuration
 *
 * @example
 * ```tsx
 * function VehicleFeature() {
 *   const { config, hasModule } = useCompanyConfig()
 *
 *   if (!hasModule('Vehicle')) {
 *     return null // Hide if Vehicle module not enabled
 *   }
 *
 *   return <VehicleManagement />
 * }
 * ```
 */
export function useCompanyConfig(): CompanyConfigContextValue {
  const context = useContext(CompanyConfigContext)

  if (context === undefined) {
    throw new Error('useCompanyConfig must be used within a CompanyConfigProvider')
  }

  return context
}

/**
 * Safe variant of {@link useCompanyConfig} that returns `null` when rendered
 * outside a {@link CompanyConfigProvider} instead of throwing.
 *
 * Use this for components that may be mounted in contexts without the provider
 * (e.g. shared POS organisms). Treat a `null` return as "capability unknown" —
 * gate optimistically or pessimistically per the call site's needs.
 */
export function useCompanyConfigOptional(): CompanyConfigContextValue | null {
  return useContext(CompanyConfigContext) ?? null
}
