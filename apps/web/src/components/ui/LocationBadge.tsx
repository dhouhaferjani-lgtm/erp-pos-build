import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { MapPin } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface Location {
  id: string
  name: string
  code: string
  address?: string
  is_default: boolean
  is_active: boolean
}

interface LocationBadgeProps {
  locationId: string | null | undefined
  showIcon?: boolean
  showCode?: boolean
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

export function LocationBadge({
  locationId,
  showIcon = true,
  showCode = false,
  className = '',
  size = 'md',
}: LocationBadgeProps) {
  const { t } = useTranslation()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  // Fetch location details
  const { data: locationData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['location', locationId]),
    queryFn: async () => {
      if (!locationId) return null
      const response = await api.get<{ data: Location }>(`/locations/${locationId}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && Boolean(locationId),
    staleTime: 60000,
  })

  // If no location ID, show "No location" or nothing
  if (!locationId) {
    return (
      <span className={`inline-flex items-center gap-1.5 ${colorTokens.text.subtle} ${getSizeClasses(size)} ${className}`}>
        {showIcon && <MapPin className={getIconSize(size)} />}
        <span>{t('common.noLocation', 'No location')}</span>
      </span>
    )
  }

  if (isLoading) {
    return (
      <span className={`inline-flex items-center gap-1.5 ${colorTokens.text.disabled} ${getSizeClasses(size)} ${className}`}>
        {showIcon && <MapPin className={`${getIconSize(size)} animate-pulse`} />}
        <span className="animate-pulse">{t('status.loading', 'Loading...')}</span>
      </span>
    )
  }

  if (!locationData) {
    return null
  }

  return (
    <span className={`inline-flex items-center gap-1.5 ${getSizeClasses(size)} ${className}`}>
      {showIcon && <MapPin className={`${getIconSize(size)} ${colorTokens.text.disabled}`} />}
      <span className={`font-medium ${colorTokens.text.primary}`}>
        {locationData.name}
      </span>
      {showCode && locationData.code && (
        <span className={`font-mono ${colorTokens.text.subtle}`}>
          ({locationData.code})
        </span>
      )}
      {locationData.is_default && (
        <span className={`inline-flex items-center rounded-full ${colorTokens.intent.primary.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.primary.textStronger}`}>
          {t('common.default', 'Default')}
        </span>
      )}
    </span>
  )
}

function getSizeClasses(size: 'sm' | 'md' | 'lg'): string {
  switch (size) {
    case 'sm':
      return 'text-xs'
    case 'md':
      return 'text-sm'
    case 'lg':
      return 'text-base'
  }
}

function getIconSize(size: 'sm' | 'md' | 'lg'): string {
  switch (size) {
    case 'sm':
      return 'h-3 w-3'
    case 'md':
      return 'h-4 w-4'
    case 'lg':
      return 'h-5 w-5'
  }
}
