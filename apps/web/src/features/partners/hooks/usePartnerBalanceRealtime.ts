import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useRealtimeChannel } from '../../../hooks/useRealtimeChannel'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import {
  partnerAccountBalanceInvalidationPredicate,
  partnersInvalidationPredicate,
} from '../_invalidation'

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
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const handleUpdate = useCallback(
    (data: PartnerBalanceUpdatePayload) => {
      void Promise.all([
        queryClient.invalidateQueries({
          predicate: partnersInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['partner', data.partnerId]) }),
        queryClient.invalidateQueries({
          predicate: partnerAccountBalanceInvalidationPredicate(tenantId, companyId),
        }),
      ])
    },
    [companyId, queryClient, tenantId]
  )

  const handleError = useCallback((error: Error) => {
    console.error('Partner balance WebSocket subscription error:', error)
    toast.error(t('common:errors.realtimeConnectionFailed'))
  }, [t])

  const shouldSubscribe = tenantId !== null && companyId !== null

  useRealtimeChannel<PartnerBalanceUpdatePayload>({
    channelName: shouldSubscribe
      ? `tenant.${tenantId}.company.${companyId}.partners`
      : '',
    eventName: 'partner.balance-updated',
    onEvent: shouldSubscribe ? handleUpdate : () => {},
    onError: handleError,
    isPrivate: true,
  })
}
