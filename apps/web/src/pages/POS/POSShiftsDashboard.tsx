import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ShiftDashboardPage } from '@/features/pos'
import type { Terminal as ShiftTerminal } from '@/features/pos/pages/ShiftDashboardPage'
import { getOrCreateWebTerminal } from '@/features/pos/api/terminalApi'
import {
  getCurrentShift,
  openShift,
  closeShift,
  generateXReport,
  recordCashDeposit,
  recordCashPayout,
  getShiftBalance,
} from '@/features/pos/api/shiftApi'
import { useLocation } from '@/hooks/useLocation'
import { Loader2, MapPin } from 'lucide-react'
import { toast } from 'sonner'

export function POSShiftsDashboard() {
  const { t } = useTranslation(['pos', 'common'])
  const queryClient = useQueryClient()
  const { currentLocationId, isLoading: isLocationLoading } = useLocation()

  // Resolve web terminal for the active location
  const webTerminalMutation = useMutation({
    mutationFn: (locationId: string) => getOrCreateWebTerminal(locationId),
  })

  const webTerminal = webTerminalMutation.data

  useEffect(() => {
    if (currentLocationId && !isLocationLoading) {
      webTerminalMutation.mutate(currentLocationId)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentLocationId, isLocationLoading])

  const terminalCode = webTerminal?.code ?? null

  // Fetch current shift for this terminal
  const {
    data: shift,
    isLoading: isLoadingShift,
  } = useQuery({
    queryKey: ['pos', 'shift', terminalCode],
    queryFn: () => getCurrentShift(terminalCode!),
    refetchInterval: 30000,
    enabled: !!terminalCode,
  })

  // Fetch cash drawer balance when shift is open
  const { data: balance } = useQuery({
    queryKey: ['pos', 'shift-balance', shift?.id],
    queryFn: () => getShiftBalance(shift!.id),
    refetchInterval: 30000,
    enabled: !!shift?.id,
  })

  const invalidateShiftData = () => {
    void queryClient.invalidateQueries({ queryKey: ['pos', 'shift'] })
    void queryClient.invalidateQueries({ queryKey: ['pos', 'shift-balance'] })
  }

  const handleOpenShift = async (openingCash: string) => {
    if (!terminalCode) return
    try {
      await openShift({ terminal_code: terminalCode, opening_cash: openingCash })
      toast.success(t('pos:shifts.toasts.shiftOpened'))
      invalidateShiftData()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t('pos:shifts.toasts.openFailed'))
    }
  }

  const handleCloseShift = async (actualCash: string) => {
    if (!shift) return
    try {
      await closeShift(shift.id, actualCash)
      toast.success(t('pos:shifts.toasts.shiftClosed'))
      invalidateShiftData()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t('pos:shifts.toasts.closeFailed'))
    }
  }

  const handleCashDeposit = async (data: { amount: string; reason: string }) => {
    if (!shift) return
    try {
      await recordCashDeposit({ shift_id: shift.id, amount: data.amount, reason: data.reason })
      toast.success(t('pos:shifts.toasts.depositRecorded'))
      invalidateShiftData()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t('pos:shifts.toasts.depositFailed'))
    }
  }

  const handleCashPayout = async (data: { amount: string; reason: string }) => {
    if (!shift) return
    try {
      await recordCashPayout({ shift_id: shift.id, amount: data.amount, reason: data.reason })
      toast.success(t('pos:shifts.toasts.payoutRecorded'))
      invalidateShiftData()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t('pos:shifts.toasts.payoutFailed'))
    }
  }

  const handleGenerateXReport = async () => {
    if (!webTerminal) return
    try {
      await generateXReport({ terminal_id: webTerminal.id })
      toast.success(t('pos:shifts.toasts.xReportGenerated'))
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t('pos:shifts.toasts.xReportFailed'))
    }
  }

  // No location selected
  if (!isLocationLoading && !currentLocationId) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center max-w-md">
          <MapPin className="h-16 w-16 text-gray-300 mx-auto mb-4" />
          <h2 className="text-xl font-bold text-gray-900 mb-2">
            {t('pos:transactions.noLocation')}
          </h2>
          <p className="text-gray-500">
            {t('pos:transactions.noLocationDescription')}
          </p>
        </div>
      </div>
    )
  }

  // Resolving terminal
  if (isLocationLoading || webTerminalMutation.isPending || !webTerminal) {
    if (webTerminalMutation.isError) {
      return (
        <div className="flex items-center justify-center h-screen bg-gray-50">
          <div className="text-center max-w-md">
            <p className="text-red-600 mb-4">{t('pos:transactions.errors.terminalCreation')}</p>
            <button
              type="button"
              className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
              onClick={() => {
                if (currentLocationId) webTerminalMutation.mutate(currentLocationId)
              }}
            >
              {t('common:retry')}
            </button>
          </div>
        </div>
      )
    }

    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center">
          <Loader2 className="h-12 w-12 animate-spin text-blue-600 mx-auto mb-4" />
          <p className="text-gray-600">{t('pos:transactions.loading.terminal')}</p>
        </div>
      </div>
    )
  }

  // Map API terminal to ShiftDashboardPage's Terminal type
  const terminal: ShiftTerminal = {
    id: webTerminal.id,
    code: webTerminal.code,
    location_id: webTerminal.location_id,
    location_name: webTerminal.location?.name ?? '',
  }

  // Map API shift to ShiftDashboardPage's Shift type
  const currentShift = shift
    ? {
        id: shift.id,
        shift_number: shift.shift_number,
        terminal_id: shift.terminal_id,
        cashier_id: shift.user?.id ?? '',
        cashier_name: shift.user?.name ?? '',
        opening_cash: shift.opening_cash,
        expected_cash: balance?.expected_cash ?? shift.opening_cash,
        opened_at: shift.opened_at,
        status: shift.status as 'OPEN' | 'CLOSED',
      }
    : null

  return (
    <div className="h-screen bg-gray-50">
      <ShiftDashboardPage
        terminal={terminal}
        currentShift={currentShift}
        onOpenShift={handleOpenShift}
        onCloseShift={handleCloseShift}
        onCashDeposit={handleCashDeposit}
        onCashPayout={handleCashPayout}
        onGenerateXReport={handleGenerateXReport}
        isLoading={isLoadingShift}
        touchOptimized={false}
      />
    </div>
  )
}
