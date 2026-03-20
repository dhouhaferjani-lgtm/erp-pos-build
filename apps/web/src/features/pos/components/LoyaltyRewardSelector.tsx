import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Gift, Star, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { POSButton } from '../atoms/POSButton'
import { getRewards, redeemReward, type LoyaltyReward } from '../api/loyaltyApi'
import { toast } from 'sonner'

export interface LoyaltyRewardSelectorProps {
  enrollmentId: string
  onRewardRedeemed: (rewardValue: string, rewardName: string, rewardId: string) => void
  className?: string
}

/**
 * Lists available rewards for an enrollment and allows redemption.
 * Used in AdvancedPaymentsModal discount section.
 */
export function LoyaltyRewardSelector({
  enrollmentId,
  onRewardRedeemed,
  className,
}: LoyaltyRewardSelectorProps) {
  const { t } = useTranslation(['pos'])
  const [redeemingId, setRedeemingId] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['loyalty', 'rewards', enrollmentId],
    queryFn: () => getRewards(enrollmentId),
    enabled: !!enrollmentId,
  })

  const redeemMutation = useMutation({
    mutationFn: (rewardId: string) => redeemReward(enrollmentId, rewardId),
    onSuccess: (result, rewardId) => {
      const reward = data?.rewards.find((r) => r.id === rewardId)
      onRewardRedeemed(result.reward_value, reward?.name ?? t('pos:loyalty.rewards.title'), rewardId)
      toast.success(t('pos:loyalty.rewards.redeemSuccess'))
    },
    onError: () => {
      toast.error(t('pos:loyalty.rewards.redeemFailed'))
    },
    onSettled: () => {
      setRedeemingId(null)
    },
  })

  const handleRedeem = (reward: LoyaltyReward) => {
    setRedeemingId(reward.id)
    redeemMutation.mutate(reward.id)
  }

  if (isLoading) {
    return (
      <div className={cn('flex items-center justify-center py-4', className)}>
        <Loader2 className="w-5 h-5 animate-spin text-amber-500" />
      </div>
    )
  }

  if (!data?.rewards.length) {
    return (
      <div className={cn('text-sm text-gray-500 py-2', className)}>
        {t('pos:loyalty.rewards.noRewards')}
      </div>
    )
  }

  return (
    <div className={cn('space-y-3', className)}>
      <div className="flex items-center justify-between">
        <h4 className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
          <Gift className="w-4 h-4 text-amber-500" />
          {t('pos:loyalty.rewards.title')}
        </h4>
        <span className="text-xs text-amber-600 flex items-center gap-1">
          <Star className="w-3 h-3" />
          {data.current_balance} {t('pos:loyalty.memberBadge.points')}
        </span>
      </div>

      <div className="space-y-2">
        {data.rewards.map((reward) => {
          const canAfford = parseFloat(data.current_balance) >= parseFloat(reward.points_cost)
          const isRedeeming = redeemingId === reward.id

          return (
            <div
              key={reward.id}
              className={cn(
                'flex items-center justify-between p-3 rounded-lg border',
                canAfford ? 'bg-white border-amber-200' : 'bg-gray-50 border-gray-200 opacity-60',
              )}
            >
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium text-gray-900 truncate">
                  {reward.name}
                </div>
                {reward.description && (
                  <div className="text-xs text-gray-500 truncate">{reward.description}</div>
                )}
                <div className="text-xs text-amber-600 mt-0.5">
                  {reward.points_cost} {t('pos:loyalty.memberBadge.points')}
                </div>
              </div>
              <POSButton
                variant="primary"
                size="sm"
                onClick={() => { handleRedeem(reward); }}
                disabled={!canAfford || isRedeeming}
                className="ml-2 flex-shrink-0"
              >
                {isRedeeming ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  t('pos:loyalty.rewards.redeem')
                )}
              </POSButton>
            </div>
          )
        })}
      </div>
    </div>
  )
}
