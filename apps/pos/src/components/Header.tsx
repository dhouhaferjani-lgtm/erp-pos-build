import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeftRight, BarChart3, Lock, LogOut, Settings } from 'lucide-react';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useProductStore } from '@/stores/productStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { CloseShiftModal } from '@/components/organisms/CloseShiftModal';
import { ReportsMenu } from '@/components/pos/ReportsMenu';
import { XReportModal } from '@/components/pos/XReportModal';
import { ZReportModal } from '@/components/pos/ZReportModal';
import { generateXReport, generateZReport } from '@/api/reportApi';
import type { XReportResponse, ZReportResponse } from '@/api/reportApi';
import { getErrorMessage } from '@/lib/api';
import { cn } from '@/lib/utils';
import { CashDrawerModal } from '@/components/organisms/CashDrawerModal';

export function Header() {
  const { t } = useTranslation('pos');
  const navigate = useNavigate();
  const logout = useAuthStore((s) => s.logout);
  const terminal = useTerminalStore((s) => s.terminal);
  const shift = useTerminalStore((s) => s.shift);

  const operator = useOperatorStore((s) => s.operator);
  const lockScreen = useOperatorStore((s) => s.lock);
  const clearOperator = useOperatorStore((s) => s.clearOperator);

  const companyId = useAuthStore((s) => s.companyId);

  const isOnline = useConnectivityStore((s) => s.isOnline);
  const pendingReceiptCount = useSyncStore((s) => s.pendingReceiptCount);
  const isSyncing = useSyncStore((s) => s.isSyncing);

  const [showCloseShift, setShowCloseShift] = useState(false);

  // Reports state
  const [showReportsMenu, setShowReportsMenu] = useState(false);
  const [showXReportModal, setShowXReportModal] = useState(false);
  const [showZReportModal, setShowZReportModal] = useState(false);
  const [xReport, setXReport] = useState<XReportResponse | null>(null);
  const [zReport, setZReport] = useState<ZReportResponse | null>(null);
  const [reportLoading, setReportLoading] = useState(false);
  const [reportError, setReportError] = useState<string | null>(null);
  const [showCashDrawerModal, setShowCashDrawerModal] = useState(false);

  const handleXReport = async () => {
    if (!terminal) return;
    setShowXReportModal(true);
    setReportLoading(true);
    setReportError(null);
    setXReport(null);
    try {
      const report = await generateXReport(terminal.id);
      setXReport(report);
    } catch (err) {
      setReportError(getErrorMessage(err));
    } finally {
      setReportLoading(false);
    }
  };

  const handleZReportOpen = () => {
    setZReport(null);
    setReportError(null);
    setShowZReportModal(true);
  };

  const handleZReportConfirm = async () => {
    if (!terminal || !shift || !companyId) return;
    setReportLoading(true);
    setReportError(null);
    try {
      const report = await generateZReport(
        terminal.id,
        companyId,
        shift.id,
        shift.opened_at,
        parseFloat(shift.opening_cash),
      );
      setZReport(report);
    } catch (err) {
      setReportError(getErrorMessage(err));
    } finally {
      setReportLoading(false);
    }
  };

  function handleSwitchOperator() {
    useCartStore.getState().clearCart();
    clearOperator();
  }

  function handleLogout() {
    useCartStore.getState().clearCart();
    usePaymentStore.getState().reset();
    useProductStore.getState().reset();
    useOperatorStore.getState().clearOperator();
    logout();
  }

  return (
    <>
      <header className="flex h-16 items-center justify-between bg-primary-900 px-4 text-white">
        <div className="flex items-center gap-3">
          <h1 className="text-xl font-bold text-white">{t('auth.title')}</h1>
          {terminal && (
            <span className="rounded-lg bg-primary-800 px-3 py-1 text-sm font-medium text-primary-200">
              {terminal.name}
            </span>
          )}
        </div>

        <div className="flex items-center gap-2">
          {/* Connectivity indicator */}
          <div className="flex items-center gap-1.5" title={isOnline ? t('sync.online') : t('sync.offline')}>
            <span
              className={cn(
                'inline-block h-3 w-3 rounded-full',
                isSyncing
                  ? 'animate-pulse bg-yellow-400'
                  : isOnline
                    ? 'bg-green-500'
                    : 'bg-red-500',
              )}
            />
            {!isOnline && (
              <span className="rounded bg-red-500/20 px-2 py-0.5 text-xs font-medium text-red-300">
                {t('sync.offline')}
              </span>
            )}
            {pendingReceiptCount > 0 && (
              <span className="rounded bg-orange-500/20 px-1.5 py-0.5 text-xs font-medium text-orange-300">
                {pendingReceiptCount}
              </span>
            )}
          </div>

          {/* Shift badge */}
          {shift ? (
            <button
              onClick={() => setShowCloseShift(true)}
              className="flex min-h-[44px] items-center rounded-lg bg-green-500/20 px-3 py-1.5 text-sm font-medium text-green-300 hover:bg-green-500/30"
            >
              {t('shift.number', { number: shift.shift_number })}
            </button>
          ) : (
            <span className="text-sm text-primary-300">{t('header.noShift')}</span>
          )}

          {/* Operator name */}
          {operator && (
            <span className="text-base text-primary-100">{operator.name}</span>
          )}

          {/* Switch operator */}
          <button
            onClick={handleSwitchOperator}
            className="flex min-h-[44px] items-center gap-2 rounded-lg bg-primary-800 px-4 py-2 text-sm font-medium text-primary-200 hover:bg-primary-700"
            title={t('header.switch')}
          >
            <ArrowLeftRight className="h-4 w-4" />
            {t('header.switch')}
          </button>

          {/* Lock */}
          <button
            onClick={lockScreen}
            className="flex min-h-[44px] items-center gap-2 rounded-lg bg-primary-800 px-4 py-2 text-sm font-medium text-primary-200 hover:bg-primary-700"
            title={t('header.lock')}
          >
            <Lock className="h-4 w-4" />
            {t('header.lock')}
          </button>

          {/* Reports */}
          {shift && (
            <button
              onClick={() => setShowReportsMenu(true)}
              className="flex min-h-[44px] items-center gap-2 rounded-lg bg-primary-800 px-3 py-2 text-sm font-medium text-primary-200 hover:bg-primary-700"
              title={t('quickActions.reports')}
            >
              <BarChart3 className="h-4 w-4" />
              {t('quickActions.reports')}
            </button>
          )}

          {/* Settings */}
          <button
            onClick={() => navigate('/settings')}
            className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary-800 text-primary-200 hover:bg-primary-700"
            title={t('header.settings')}
          >
            <Settings className="h-5 w-5" />
          </button>

          {/* Logout */}
          <button
            onClick={handleLogout}
            className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary-800 text-primary-200 hover:bg-primary-700"
            title={t('header.logout')}
          >
            <LogOut className="h-5 w-5" />
          </button>
        </div>
      </header>

      {/* Close Shift Modal */}
      {shift && (
        <CloseShiftModal
          isOpen={showCloseShift}
          onClose={() => setShowCloseShift(false)}
          shift={shift}
        />
      )}

      {/* Reports Menu */}
      <ReportsMenu
        isOpen={showReportsMenu}
        onClose={() => setShowReportsMenu(false)}
        onXReport={() => void handleXReport()}
        onZReport={handleZReportOpen}
        onTransactionHistory={() => { setShowReportsMenu(false); navigate('/sales'); }}
        onCashDrawerOps={() => { setShowReportsMenu(false); setShowCashDrawerModal(true); }}
        onTodaySales={() => { setShowReportsMenu(false); navigate('/sales'); }}
      />

      {/* X Report Modal */}
      <XReportModal
        isOpen={showXReportModal}
        onClose={() => setShowXReportModal(false)}
        report={xReport}
        isLoading={reportLoading}
        error={reportError}
      />

      {/* Z Report Modal */}
      <ZReportModal
        isOpen={showZReportModal}
        onClose={() => setShowZReportModal(false)}
        onConfirmGenerate={() => handleZReportConfirm()}
        report={zReport}
        isLoading={reportLoading}
        error={reportError}
      />

      {/* Cash Drawer Modal */}
      {shift && (
        <CashDrawerModal
          isOpen={showCashDrawerModal}
          onClose={() => setShowCashDrawerModal(false)}
          shiftId={shift.id}
        />
      )}
    </>
  );
}
