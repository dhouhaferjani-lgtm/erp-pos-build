import { useEffect, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '../../lib/api'
import { useCompanyStore, type Company } from '../../stores/companyStore'
import { useAuthStore } from '../../stores/authStore'

interface CompanyResponse {
  id: string
  name: string
  legal_name: string
  tax_id: string | null
  country_code: string
  currency: string
  locale: string
  timezone: string
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
  }
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
  const routerLocation = useLocation()
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const setCompanies = useCompanyStore((state) => state.setCompanies)
  const setLoading = useCompanyStore((state) => state.setLoading)
  const reset = useCompanyStore((state) => state.reset)
  const companies = useCompanyStore((state) => state.companies)
  const queryClient = useQueryClient()

  // Skip fetching on admin routes - they use separate authentication
  const isAdminRoute = routerLocation.pathname === '/admin' || routerLocation.pathname.startsWith('/admin/')

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['user', 'companies'],
    queryFn: async () => {
      const response = await api.get<CompaniesApiResponse>('/user/companies')
      return response.data.data.map(mapCompanyResponse)
    },
    retry: 1,
    staleTime: 1000 * 60 * 10, // 10 minutes - companies don't change often
    enabled: isAuthenticated && !isAdminRoute,
  })

  // Update company store when data is fetched
  useEffect(() => {
    if (isLoading) {
      setLoading(true)
    } else if (data) {
      setCompanies(data)
    } else if (isError) {
      // If we can't fetch companies, log error but don't break the app
      console.error('Failed to fetch companies:', error)
      setLoading(false)
    }
  }, [data, isLoading, isError, error, setCompanies, setLoading])

  // Reset company store on logout
  useEffect(() => {
    if (!isAuthenticated) {
      reset()
      queryClient.removeQueries({ queryKey: ['user', 'companies'] })
    }
  }, [isAuthenticated, reset, queryClient])

  // Show loading while fetching companies (only if authenticated and not on admin routes)
  if (isAuthenticated && !isAdminRoute && isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
          <p className="text-gray-500">Loading companies...</p>
        </div>
      </div>
    )
  }

  // If authenticated but no companies (edge case), show error (skip on admin routes)
  if (isAuthenticated && !isAdminRoute && !isLoading && companies.length === 0 && !isError) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="text-center">
          <h2 className="text-xl font-semibold text-gray-900">No Companies Found</h2>
          <p className="mt-2 text-gray-600">
            You don't have access to any companies. Please contact your administrator.
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
  return () => queryClient.invalidateQueries({ queryKey: ['user', 'companies'] })
}
