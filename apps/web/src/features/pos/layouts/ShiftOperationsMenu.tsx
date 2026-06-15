import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { FileText, Banknote, Wallet, Pause } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { generateXReport, type CurrentShift, type ShiftBalance } from '../api/shiftApi'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'

export interface ShiftOperationsMenuProps {
  shift: CurrentShift
  balance: ShiftBalance | null | undefined
  terminalCode: string
  isOpen: boolean
  onClose: () => void
  onOpenCashDeposit: () => void
  onOpenCashPayout: () => void
}

interface MenuButtonProps {
  icon: React.ReactNode
  label: string
  onClick: () => void
  disabled?: boolean
}

function MenuButton({ icon, label, onClick, disabled = false }: MenuButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm',
        'transition-colors duration-150',
        disabled
          ? cn('cursor-not-allowed', textColors.disabled)
          : cn(
              textColors.secondary,
              colors.hover.gray100,
              textColors.hoverPrimary
            )
      )}
    >
      <span className="flex items-center justify-center w-5 h-5">{icon}</span>
      <span className="font-medium">{label}</span>
    </button>
  )
}

/**
 * ShiftOperationsMenu - Dropdown menu for shift operations
 *
 * Provides access to shift management functions:
 * - Generate X-Report (mid-shift report)
 * - Cash Deposit (add cash to drawer)
 * - Cash Payout (remove cash from drawer)
 * - Pause Shift (coming soon)
 *
 * This component appears when the "Operations" button in POSLayout header is clicked.
 *
 * @example
 * ```tsx
 * <ShiftOperationsMenu
 *   shift={currentShift}
 *   balance={shiftBalance}
 *   terminalCode="POS01"
 *   isOpen={isMenuOpen}
 *   onClose={() => setIsMenuOpen(false)}
 *   onOpenCashDeposit={() => setActiveModal('deposit')}
 *   onOpenCashPayout={() => setActiveModal('payout')}
 * />
 * ```
 */
export function ShiftOperationsMenu({
  shift,
  balance,
  terminalCode,
  isOpen,
  onClose,
  onOpenCashDeposit,
  onOpenCashPayout,
}: ShiftOperationsMenuProps) {
  const { t } = useTranslation(['common'])
  const queryClient = useQueryClient()

  const generateXReportMutation = useMutation({
    mutationFn: () => generateXReport({ terminal_id: shift.terminal_id }),
    onSuccess: async () => {
      toast.success(t('common:pos.xReportGenerated'))
      onClose()
      // Invalidate shift data to reflect any changes
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['pos', 'shift', terminalCode]),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['pos', 'shift-balance', shift.id]),
        }),
      ])
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:pos.xReportError'))
    },
  })

  const handleGenerateXReport = () => {
    if (generateXReportMutation.isPending) return
    generateXReportMutation.mutate()
  }

  const handleCashDeposit = () => {
    onClose()
    onOpenCashDeposit()
  }

  const handleCashPayout = () => {
    onClose()
    onOpenCashPayout()
  }

  const handlePauseShift = () => {
    toast.info(t('common:pos.featureComingSoon'))
    onClose()
  }

  if (!isOpen) return null

  return (
    <>
      {/* Backdrop - closes menu when clicked */}
      <div
        className="fixed inset-0 z-40"
        onClick={onClose}
        aria-hidden="true"
      />

      {/* Dropdown Menu */}
      <div
        className={cn(
          'absolute top-14 right-4 z-50 w-64 rounded-lg shadow-xl border',
          colors.white,
          borderColors.light
        )}
      >
        <div className="p-2 space-y-1">
          <MenuButton
            icon={<FileText className="w-5 h-5" />}
            label={t('common:pos.generateXReport')}
            onClick={handleGenerateXReport}
            disabled={generateXReportMutation.isPending}
          />

          <MenuButton
            icon={<Banknote className="w-5 h-5" />}
            label={t('common:pos.cashDeposit')}
            onClick={handleCashDeposit}
          />

          <MenuButton
            icon={<Wallet className="w-5 h-5" />}
            label={t('common:pos.cashPayout')}
            onClick={handleCashPayout}
          />

          <div className={cn('border-t my-1', borderColors.light)} />

          <MenuButton
            icon={<Pause className="w-5 h-5" />}
            label={t('common:pos.pauseShift')}
            onClick={handlePauseShift}
            disabled={true}
          />
        </div>

        {/* Shift Info Footer */}
        {balance && (
          <div
            className={cn(
              'border-t px-3 py-2 rounded-b-lg',
              borderColors.light,
              colors.neutral[50]
            )}
          >
            <div className={cn('text-xs mb-1', textColors.tertiary)}>
              {t('common:pos.shiftInfo', { defaultValue: 'Shift Information' })}
            </div>
            <div className="space-y-1 text-xs">
              <div className="flex justify-between">
                <span className={textColors.tertiary}>
                  {t('common:pos.openingCash', { defaultValue: 'Opening' })}:
                </span>
                <span className={cn('font-medium', textColors.primary)}>{balance.opening_cash}</span>
              </div>
              <div className="flex justify-between">
                <span className={textColors.tertiary}>
                  {t('common:pos.totalSales', { defaultValue: 'Sales' })}:
                </span>
                <span className={cn('font-medium', textColors.success)}>{balance.total_sales}</span>
              </div>
              <div className="flex justify-between">
                <span className={textColors.tertiary}>
                  {t('common:pos.expectedCash', { defaultValue: 'Expected' })}:
                </span>
                <span className={cn('font-bold', textColors.brand)}>{balance.expected_cash}</span>
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  )
}
