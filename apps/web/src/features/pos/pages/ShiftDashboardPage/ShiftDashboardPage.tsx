import { useState, useMemo } from 'react'
import { cn } from '@/lib/utils'
import { POSButton, MoneyInput } from '../../atoms'
import {
  Clock,
  DollarSign,
  FileText,
  LogOut,
  TrendingDown,
  TrendingUp,
  Loader2,
} from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'

export interface Shift {
  id: string
  terminal_id: string
  shift_number: number
  cashier_id: string
  cashier_name: string
  opening_cash: string
  expected_cash: string
  opened_at: string
  status: 'OPEN' | 'CLOSED'
}

export interface Terminal {
  id: string
  code: string
  location_id: string
  location_name: string
}

export interface ShiftDashboardPageProps {
  currentShift: Shift | null
  terminal: Terminal
  onOpenShift: (openingBalance: string) => void
  onCloseShift: (actualCash: string) => void
  onGenerateXReport: () => void
  onCashDeposit: (data: { amount: string; reason: string }) => void
  onCashPayout: (data: { amount: string; reason: string }) => void
  isLoading?: boolean
  touchOptimized?: boolean
  className?: string
}

type ModalType = 'open' | 'close' | 'deposit' | 'payout' | null

export function ShiftDashboardPage({
  currentShift,
  terminal,
  onOpenShift,
  onCloseShift,
  onGenerateXReport,
  onCashDeposit,
  onCashPayout,
  isLoading = false,
  touchOptimized = false,
  className,
}: ShiftDashboardPageProps) {
  const [activeModal, setActiveModal] = useState<ModalType>(null)
  const [openingBalance, setOpeningBalance] = useState('')
  const [actualCash, setActualCash] = useState('')
  const [operationAmount, setOperationAmount] = useState('')
  const [operationReason, setOperationReason] = useState('')

  // Calculate shift duration
  const shiftDuration = useMemo(() => {
    if (!currentShift) return null
    return formatDistanceToNow(new Date(currentShift.opened_at), {
      addSuffix: false,
    })
  }, [currentShift])

  // Calculate variance for close shift
  const cashVariance = useMemo(() => {
    if (!currentShift || !actualCash) return null
    const expected = parseFloat(currentShift.expected_cash)
    const actual = parseFloat(actualCash)
    return (actual - expected).toFixed(3)
  }, [currentShift, actualCash])

  const handleOpenShift = () => {
    onOpenShift(openingBalance)
    setActiveModal(null)
    setOpeningBalance('')
  }

  const handleCloseShift = () => {
    onCloseShift(actualCash)
    setActiveModal(null)
    setActualCash('')
  }

  const handleCashOperation = (type: 'deposit' | 'payout') => {
    const handler = type === 'deposit' ? onCashDeposit : onCashPayout
    handler({
      amount: operationAmount,
      reason: operationReason,
    })
    setActiveModal(null)
    setOperationAmount('')
    setOperationReason('')
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="text-center">
          <Loader2 className="w-12 h-12 animate-spin text-blue-600 mx-auto mb-4" />
          <p className="text-gray-600">Loading...</p>
        </div>
      </div>
    )
  }

  return (
    <div
      className={cn(
        'min-h-screen bg-gray-50',
        touchOptimized ? 'p-6' : 'p-4',
        className
      )}
    >
      {/* Header */}
      <div className="bg-white rounded-lg shadow-sm p-6 mb-6">
        <div className="flex items-center justify-between">
          <div>
            <h1
              className={cn(
                'font-bold text-gray-900',
                touchOptimized ? 'text-3xl' : 'text-2xl'
              )}
            >
              {terminal.code}
            </h1>
            <p className="text-gray-600 mt-1">{terminal.location_name}</p>
          </div>

          {currentShift && shiftDuration && (
            <div className="flex items-center gap-2 text-gray-600">
              <Clock className="w-5 h-5" />
              <span>{shiftDuration}</span>
            </div>
          )}
        </div>
      </div>

      {/* Current Shift Status */}
      {currentShift ? (
        <div className="space-y-6">
          {/* Shift Info Card */}
          <div className="bg-white rounded-lg shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
              <h2
                className={cn(
                  'font-semibold text-gray-900',
                  touchOptimized ? 'text-2xl' : 'text-xl'
                )}
              >
                Shift #{currentShift.shift_number}
              </h2>
              <span className="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                OPEN
              </span>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-sm text-gray-600">Cashier</p>
                <p className="font-medium text-gray-900">
                  {currentShift.cashier_name}
                </p>
              </div>
              <div>
                <p className="text-sm text-gray-600">Opening Balance</p>
                <p className="font-medium text-gray-900">
                  {currentShift.opening_cash} TND
                </p>
              </div>
              <div>
                <p className="text-sm text-gray-600">Expected Cash</p>
                <p className="font-medium text-gray-900">
                  {currentShift.expected_cash} TND
                </p>
              </div>
              <div>
                <p className="text-sm text-gray-600">Duration</p>
                <p className="font-medium text-gray-900">{shiftDuration}</p>
              </div>
            </div>
          </div>

          {/* Actions Grid */}
          <div className="grid grid-cols-2 gap-4">
            <POSButton
              variant="secondary"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={() => setActiveModal('deposit')}
              icon={<TrendingDown className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              Cash Deposit
            </POSButton>

            <POSButton
              variant="secondary"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={() => setActiveModal('payout')}
              icon={<TrendingUp className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              Cash Payout
            </POSButton>

            <POSButton
              variant="primary"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={onGenerateXReport}
              icon={<FileText className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              X Report
            </POSButton>

            <POSButton
              variant="danger"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={() => setActiveModal('close')}
              icon={<LogOut className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              Close Shift
            </POSButton>
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm p-12 text-center">
          <DollarSign className="w-16 h-16 text-gray-300 mx-auto mb-4" />
          <h2 className="text-xl font-semibold text-gray-900 mb-2">
            No Active Shift
          </h2>
          <p className="text-gray-600 mb-6">
            Start a new shift to begin operations
          </p>
          <POSButton
            variant="primary"
            size={touchOptimized ? 'lg' : 'md'}
            onClick={() => setActiveModal('open')}
            touchOptimized={touchOptimized}
          >
            Open Shift
          </POSButton>
        </div>
      )}

      {/* Modals */}
      {activeModal === 'open' && (
        <Modal
          title="Open Shift"
          onClose={() => setActiveModal(null)}
          touchOptimized={touchOptimized}
        >
          <MoneyInput
            label="Opening Balance"
            value={openingBalance}
            onChange={setOpeningBalance}
            placeholder="Opening balance"
            touchOptimized={touchOptimized}
            autoFocus
          />
          <div className="flex gap-3 mt-6">
            <POSButton
              variant="secondary"
              onClick={() => setActiveModal(null)}
              fullWidth
            >
              Cancel
            </POSButton>
            <POSButton
              variant="primary"
              onClick={handleOpenShift}
              fullWidth
              disabled={!openingBalance}
            >
              Confirm
            </POSButton>
          </div>
        </Modal>
      )}

      {activeModal === 'close' && currentShift && (
        <Modal
          title="Close Shift"
          onClose={() => setActiveModal(null)}
          touchOptimized={touchOptimized}
        >
          <div className="space-y-4">
            <div className="bg-gray-50 rounded-lg p-4">
              <p className="text-sm text-gray-600">Expected Cash</p>
              <p className="text-xl font-bold text-gray-900">
                {currentShift.expected_cash} TND
              </p>
            </div>

            <MoneyInput
              label="Actual Cash Count"
              value={actualCash}
              onChange={setActualCash}
              placeholder="Actual cash"
              touchOptimized={touchOptimized}
              autoFocus
            />

            {cashVariance && (
              <div
                className={cn(
                  'rounded-lg p-4',
                  parseFloat(cashVariance) === 0 && 'bg-green-50',
                  parseFloat(cashVariance) > 0 && 'bg-blue-50',
                  parseFloat(cashVariance) < 0 && 'bg-red-50'
                )}
              >
                <p className="text-sm text-gray-600">Variance</p>
                <p
                  className={cn(
                    'text-xl font-bold',
                    parseFloat(cashVariance) === 0 && 'text-green-600',
                    parseFloat(cashVariance) > 0 && 'text-blue-600',
                    parseFloat(cashVariance) < 0 && 'text-red-600'
                  )}
                >
                  {cashVariance} TND
                </p>
              </div>
            )}
          </div>

          <div className="flex gap-3 mt-6">
            <POSButton
              variant="secondary"
              onClick={() => setActiveModal(null)}
              fullWidth
            >
              Cancel
            </POSButton>
            <POSButton
              variant="danger"
              onClick={handleCloseShift}
              fullWidth
              disabled={!actualCash}
            >
              Confirm
            </POSButton>
          </div>
        </Modal>
      )}

      {(activeModal === 'deposit' || activeModal === 'payout') && (
        <Modal
          title={activeModal === 'deposit' ? 'Cash Deposit' : 'Cash Payout'}
          onClose={() => setActiveModal(null)}
          touchOptimized={touchOptimized}
        >
          <div className="space-y-4">
            <MoneyInput
              label="Amount"
              value={operationAmount}
              onChange={setOperationAmount}
              placeholder="Amount"
              touchOptimized={touchOptimized}
              autoFocus
            />

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Reason
              </label>
              <input
                type="text"
                value={operationReason}
                onChange={(e) => setOperationReason(e.target.value)}
                placeholder="Reason"
                className="w-full px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <div className="flex gap-3 mt-6">
            <POSButton
              variant="secondary"
              onClick={() => setActiveModal(null)}
              fullWidth
            >
              Cancel
            </POSButton>
            <POSButton
              variant="primary"
              onClick={() => handleCashOperation(activeModal)}
              fullWidth
              disabled={!operationAmount || !operationReason}
            >
              Confirm
            </POSButton>
          </div>
        </Modal>
      )}
    </div>
  )
}

// Modal Component
function Modal({
  title,
  children,
  onClose,
  touchOptimized = false,
}: {
  title: string
  children: React.ReactNode
  onClose: () => void
  touchOptimized?: boolean
}) {
  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
      <div
        className={cn(
          'bg-white rounded-lg shadow-xl w-full max-w-md',
          touchOptimized ? 'p-6' : 'p-4'
        )}
      >
        <h3
          className={cn(
            'font-bold text-gray-900 mb-4',
            touchOptimized ? 'text-2xl' : 'text-xl'
          )}
        >
          {title}
        </h3>
        {children}
      </div>
    </div>
  )
}
