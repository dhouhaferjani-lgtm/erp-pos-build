import { useEffect, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useLocationStore } from '../../stores/locationStore'
import { useCompanyStore } from '../../stores/companyStore'
import { fetchLocations, transformLocationResponse } from './api'

interface LocationProviderProps {
  children: ReactNode
}

/**
 * LocationProvider fetches locations for the current company
 *
 * Must be used inside CompanyProvider. Automatically refetches
 * when the current company changes.
 *
 * Skips fetching on admin routes since they use separate authentication.
 */
export function LocationProvider({ children }: LocationProviderProps) {
  const { t } = useTranslation()
  const routerLocation = useLocation()
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const setLocations = useLocationStore((state) => state.setLocations)
  const setLoading = useLocationStore((state) => state.setLoading)
  const resetForCompanyChange = useLocationStore((state) => state.resetForCompanyChange)

  // Skip fetching on admin routes - they use separate authentication
  const isAdminRoute = routerLocation.pathname === '/admin' || routerLocation.pathname.startsWith('/admin/')

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['locations', currentCompanyId],
    queryFn: async () => {
      const response = await fetchLocations()
      return response.map(transformLocationResponse)
    },
    retry: 1,
    staleTime: 1000 * 60 * 5, // 5 minutes
    enabled: Boolean(currentCompanyId) && !isAdminRoute,
  })

  // Reset location selection when company changes
  useEffect(() => {
    if (currentCompanyId) {
      resetForCompanyChange()
    }
  }, [currentCompanyId, resetForCompanyChange])

  // Update location store when data is fetched
  useEffect(() => {
    if (isLoading) {
      setLoading(true)
    } else if (data) {
      setLocations(data)
    } else if (isError) {
      console.error('Failed to fetch locations:', error)
      toast.error(t('common:errors.locationsFetchFailed'))
      setLoading(false)
    }
  }, [data, isLoading, isError, error, setLocations, setLoading])

  // Don't block rendering - locations are optional context
  return <>{children}</>
}

/**
 * Hook to invalidate locations query (call after location is created/deleted)
 */
export function useInvalidateLocations() {
  const queryClient = useQueryClient()
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  return () => queryClient.invalidateQueries({ queryKey: ['locations', currentCompanyId] })
}
