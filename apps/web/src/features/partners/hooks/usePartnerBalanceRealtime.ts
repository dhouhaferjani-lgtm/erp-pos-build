import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useRealtimeChannel } from '../../../hooks/useRealtimeChannel'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

interface PartnerBalanceUpdatePayload {
  partnerId: string
  receivableBalance: string
  creditBalance: string
  payableBalance: string
  netBalance: string
  timestamp: string
}

/**
 * Hook for subscribing to real-time partner balance updates.
 *
 * Subscribes to the company-level partners channel and invalidates
 * relevant queries when any partner's balance changes.
 */
export function usePartnerBalanceRealtime(): void {
  const queryClient = useQueryClient()
  const { user } = useAuthStore()
  const getCurrentCompany = useCompanyStore((state) => state.getCurrentCompany)
  const currentCompany = getCurrentCompany()

  const handleUpdate = useCallback(
    (data: PartnerBalanceUpdatePayload) => {
      void queryClient.invalidateQueries({ queryKey: ['partners'] })
      void queryClient.invalidateQueries({ queryKey: ['partner', data.partnerId] })
      void queryClient.invalidateQueries({ queryKey: ['partner-account-balance'] })
    },
    [queryClient]
  )

  const handleError = useCallback((error: Error) => {
    console.error('Partner balance WebSocket subscription error:', error)
  }, [])

  const shouldSubscribe = Boolean(user && currentCompany)

  useRealtimeChannel<PartnerBalanceUpdatePayload>({
    channelName: shouldSubscribe
      ? `tenant.${user!.tenant_id}.company.${currentCompany!.id}.partners`
      : '',
    eventName: 'partner.balance-updated',
    onEvent: shouldSubscribe ? handleUpdate : () => {},
    onError: handleError,
    isPrivate: true,
  })
}
