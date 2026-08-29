import { useEffect, useRef, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useCompanyStore, type Company } from '../../stores/companyStore'
import { useAuthStore } from '../../stores/authStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface CompanyResponse {
  id: string
  name: string
  legal_name: string
  tax_id: string | null
  country_code: string
  currency: string
  locale: string
  timezone: string
  is_primary?: boolean
}

interface CompaniesApiResponse {
  data: CompanyResponse[]
}

interface CompanyProviderProps {
  children: ReactNode
}

/**
 * Maps API response to Company type
 */
function mapCompanyResponse(company: CompanyResponse): Company {
  return {
    id: company.id,
    name: company.name,
    legalName: company.legal_name,
    taxId: company.tax_id,
    countryCode: company.country_code,
    currency: company.currency,
    locale: company.locale,
    timezone: company.timezone,
    isPrimary: company.is_primary ?? false,
  }
}

function userCompaniesPredicate(q: { queryKey: readonly unknown[] }): boolean {
  const k = q.queryKey
  return k.length >= 2 && k[0] === 'user' && k[1] === 'companies'
}

/**
 * CompanyProvider fetches user's companies and sets company context
 *
 * Must be used inside AuthProvider and only renders children
 * when authenticated and company context is ready.
 *
 * Skips fetching on admin routes since they use separate authentication.
 */
export function CompanyProvider({ children }: CompanyProviderProps) {
  const { t } = useTranslation('settings')
  const routerLocation = useLocation()
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const setCompanies = useCompanyStore((state) => state.setCompanies)
  const setLoading = useCompanyStore((state) => state.setLoading)
  const reset = useCompanyStore((state) => state.reset)
  const companies = useCompanyStore((state) => state.companies)
  const queryClient = useQueryClient()
  const wasAuthenticated = useRef(false)

  // Skip fetching on admin routes - they use separate authentication
  const isAdminRoute = routerLocation.pathname === '/admin' || routerLocation.pathname.startsWith('/admin/')

  const { data, isLoading, isError, error } = useQuery({
    queryKey: tenantScopedKey(['user', 'companies']),
    queryFn: async () => {
      const response = await api.get<CompaniesApiResponse>('/user/companies')
      return response.data.data.map(mapCompanyResponse)
    },
    retry: 1,
    staleTime: 1000 * 60 * 10, // 10 minutes - companies don't change often
    enabled: isAuthenticated && tenantId !== null && !isAdminRoute,
  })

  // Update company store when data is fetched.
  //
  // Auto-selection is now centralized in the store's setCompanies via the
  // deterministic `resolveCompanySelection` rule (is_primary > persisted >
  // first). We no longer arbitrarily pick data[0] here — that two-step
  // (store nulls the selection, provider re-picks the first company) was a
  // source of the "scope switches on its own" behavior. setCompanies is only
  // called with a confirmed-successful response (React Query `data`), so a
  // transient/error state never clears the selection.
  useEffect(() => {
    if (isLoading) {
      setLoading(true)
    } else if (data) {
      setCompanies(data)
    } else if (isError) {
      // If we can't fetch companies, log error but don't break the app
      setLoading(false)
    }
  }, [data, isLoading, isError, error, setCompanies, setLoading])

  // Reset company state only after a real authenticated -> unauthenticated transition.
  // Authentication is intentionally false while a persisted session bootstraps.
  useEffect(() => {
    if (isAuthenticated) {
      wasAuthenticated.current = true
    } else if (wasAuthenticated.current) {
      reset()
      queryClient.removeQueries({ predicate: userCompaniesPredicate })
      wasAuthenticated.current = false
    }
  }, [isAuthenticated, reset, queryClient])

  // Show loading while fetching companies (only if authenticated and not on admin routes)
  if (isAuthenticated && !isAdminRoute && isLoading) {
    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page}`}>
        <div className="flex flex-col items-center gap-4">
          <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text}`} />
          <p className={`${colorTokens.text.subtle}`}>{t('company.modal.loading')}</p>
        </div>
      </div>
    )
  }

  // If authenticated but no companies (edge case), show error (skip on admin routes)
  if (isAuthenticated && !isAdminRoute && !isLoading && companies.length === 0 && !isError) {
    return (
      <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page}`}>
        <div className="text-center">
          <h2 className={`text-xl font-semibold ${colorTokens.text.primary}`}>{t('company.modal.noCompaniesTitle')}</h2>
          <p className={`mt-2 ${colorTokens.text.muted}`}>
            {t('company.modal.noCompaniesMessage')}
          </p>
        </div>
      </div>
    )
  }

  return <>{children}</>
}

/**
 * Hook to invalidate companies query (call after company is created/deleted)
 */
export function useInvalidateCompanies() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  // Deliberate per-tenant precision (pinned by CompanyProvider.tenantScope
  // test .107): only the ACTIVE tenant's companies entry is invalidated, so
  // the filter is the explicit FULL key — literal prefix + tenant/company in
  // the same suffix order tenantScopedKey stamps on the query key. Do NOT
  // wrap invalidation filters in tenantScopedKey(...): filters match as
  // positional prefixes, so the wrapper only works for exact-full-key
  // matches like this one and silently no-ops everywhere else.
  return () =>
    queryClient.invalidateQueries({ queryKey: ['user', 'companies', tenantId, companyId] })
}
