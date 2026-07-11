import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Pause } from 'lucide-react'
import { toast } from 'sonner'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { useHoldOrder } from '../hooks/useHeldOrders'
import type { CartSnapshot } from '../api/heldOrderApi'
import { Button, Input } from '@/components/atoms'

export interface HoldOrderButtonProps {
  terminalId: string
  shiftId: string
  cartSnapshot: CartSnapshot | null
  disabled?: boolean
  onSuccess?: () => void
}

/**
 * HoldOrderButton - Button to hold the current cart.
 *
 * Opens a dialog asking for an optional label before holding the order.
 * The button is disabled when the cart is empty or not ready.
 */
export function HoldOrderButton({
  terminalId,
  shiftId,
  cartSnapshot,
  disabled = false,
  onSuccess,
}: HoldOrderButtonProps) {
  const { t } = useTranslation(['pos', 'common'])
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [label, setLabel] = useState('')

  const holdMutation = useHoldOrder()

  const hasItems = cartSnapshot !== null && cartSnapshot.lines.length > 0
  const isButtonDisabled = disabled || !hasItems || holdMutation.isPending

  const handleOpenDialog = () => {
    setLabel('')
    setIsDialogOpen(true)
  }

  const handleHold = () => {
    if (!cartSnapshot || cartSnapshot.lines.length === 0) {
      return
    }

    holdMutation.mutate(
      {
        terminal_id: terminalId,
        shift_id: shiftId,
        label: label.trim() || null,
        cart_snapshot: cartSnapshot,
      },
      {
        onSuccess: () => {
          toast.success(t('pos:heldOrders.holdSuccess'))
          setIsDialogOpen(false)
          setLabel('')
          onSuccess?.()
        },
        onError: () => {
          toast.error(t('pos:heldOrders.holdFailed'))
        },
      },
    )
  }

  return (
    <>
      <Button
        type="button"
        onClick={handleOpenDialog}
        disabled={isButtonDisabled}
        className={cn(
          'flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50',
          borderColors.default,
          textColors.secondary,
          colors.hover.gray50,
        )}
      >
        <Pause className="h-4 w-4" />
        {t('pos:heldOrders.holdOrder')}
      </Button>

      <Modal isOpen={isDialogOpen} onClose={() => { setIsDialogOpen(false); }}>
        <ModalHeader title={t('pos:heldOrders.holdOrder')} onClose={() => { setIsDialogOpen(false); }} />
        <ModalContent>
          <div className="space-y-4">
            <div>
              <label
                htmlFor="hold-order-label"
                className={cn('mb-1 block', tokens.label.base)}
              >
                {t('pos:heldOrders.label')}
              </label>
              <Input
                id="hold-order-label"
                type="text"
                value={label}
                onChange={(e) => { setLabel(e.target.value); }}
                placeholder={t('pos:heldOrders.labelPlaceholder')}
                maxLength={255}
                className={cn('block w-full text-sm')}
                autoFocus
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    handleHold()
                  }
                }}
              />
            </div>
          </div>
        </ModalContent>
        <ModalFooter>
          <Button variant="secondary"
            type="button"
            onClick={() => { setIsDialogOpen(false); }}
          >
            {t('common:cancel')}
          </Button>
          <Button
            type="button"
            onClick={handleHold}
            disabled={holdMutation.isPending}
          >
            {holdMutation.isPending ? '...' : t('pos:heldOrders.holdOrder')}
          </Button>
        </ModalFooter>
      </Modal>
    </>
  )
}
