import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeftRight, BarChart3, Lock, LogOut, Minimize2, Settings } from 'lucide-react';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { applyFullscreen } from '@/lib/fullscreen';
import { useOperatorStore } from '@/stores/operatorStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useProductStore } from '@/stores/productStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { SyncButton } from '@/components/atoms/SyncButton/SyncButton';
import { EndOfDayPreviewModal } from '@/components/pos/EndOfDayPreviewModal';
import { ReportsMenu } from '@/components/pos/ReportsMenu';
import { XReportModal } from '@/components/pos/XReportModal';
import { generateXReport, generateZReport } from '@/api/reportApi';
import type { XReportResponse } from '@/api/reportApi';
import { getErrorMessage } from '@/lib/api';
import { cn } from '@/lib/utils';
import { CashDrawerModal } from '@/components/organisms/CashDrawerModal';
import type { EndOfDayConfirmResult } from '@/components/pos/EndOfDayPreviewModal';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import { printReceipt, getPrintSettingsFromStore, isTauriEnvironment } from '@/lib/printing';
import type { ReceiptData } from '@/lib/printing';
import { usePrinterStore } from '@/stores/printerStore';
import { toast } from 'sonner';

export function Header() {
  const { t } = useTranslation('pos');
  const navigate = useNavigate();
  const logout = useAuthStore((s) => s.logout);
  const terminal = useTerminalStore((s) => s.terminal);
  const shift = useTerminalStore((s) => s.shift);
  const closeShift = useTerminalStore((s) => s.closeShift);

  const operator = useOperatorStore((s) => s.operator);
  const lockScreen = useOperatorStore((s) => s.lock);
  const clearOperator = useOperatorStore((s) => s.clearOperator);

  const companyId = useAuthStore((s) => s.companyId);
  const fullscreen = useSettingsStore((s) => s.fullscreen);

  const isOnline = useConnectivityStore((s) => s.isOnline);
  const pendingReceiptCount = useSyncStore((s) => s.pendingReceiptCount);
  const isSyncing = useSyncStore((s) => s.isSyncing);

  const [showEndOfDay, setShowEndOfDay] = useState(false);

  // Reports state
  const [showReportsMenu, setShowReportsMenu] = useState(false);
  const [showXReportModal, setShowXReportModal] = useState(false);
  const [xReport, setXReport] = useState<XReportResponse | null>(null);
  const [reportLoading, setReportLoading] = useState(false);
  const [reportError, setReportError] = useState<string | null>(null);
  const [showCashDrawerModal, setShowCashDrawerModal] = useState(false);
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);

  const isManager = operator?.roles?.some((r) =>
    ['manager', 'admin', 'owner'].includes(r),
  ) ?? false;

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

  /**
   * Called by EndOfDayPreviewModal when the operator confirms.
   * Atomically: generates Z (offline-first) → closes shift → returns result.
   */
  const handleEndOfDayConfirm = async (preview: EndOfDayPreview): Promise<EndOfDayConfirmResult> => {
    if (!terminal || !shift || !companyId) {
      throw new Error('Missing terminal, shift, or company context');
    }

    // 1. Generate Z report (offline-first, idempotent)
    const zReport = await generateZReport(
      terminal.id,
      companyId,
      shift.id,
      shift.opened_at,
      parseFloat(shift.opening_cash),
    );

    // 2. Close the shift. In Option B, variance = 0: pass expected_cash as actualCash.
    await closeShift(preview.expected_cash);

    return {
      formattedZNumber: zReport.formatted_z_number,
      wasReused: zReport.was_reused ?? false,
    };
  };

  /**
   * Print a minimal Z-report summary receipt.
   * Only invoked in Tauri (thermal printer) environment.
   * Sets is_reprint=true when wasReused so a DUPLICATA banner is printed.
   */
  const handlePrintZReport = (result: EndOfDayConfirmResult) => {
    if (!isTauriEnvironment()) return;

    const { printerConfig } = usePrinterStore.getState();
    if (!printerConfig) {
      toast.error(t('settings.noPrinterConfigured'));
      return;
    }

    const { companies } = useAuthStore.getState();
    const company = companies.find((c) => c.id === companyId) ?? null;

    const receiptData: ReceiptData = {
      company: {
        name: company?.name ?? '',
        address_line1: '',
        address_line2: null,
        city: '',
        postal_code: '',
        country: '',
        tax_id: '',
        phone: null,
      },
      receipt_number: result.formattedZNumber,
      date_time: new Date().toISOString(),
      terminal_name: terminal?.name ?? '',
      operator_name: operator?.name ?? '',
      lines: [],
      subtotal: '0.00',
      discount_amount: '0.00',
      tax_amount: '0.00',
      total: '0.00',
      currency_symbol: '',
      vat_breakdown: [],
      payments: [],
      change_due: '0.00',
      fiscal_hash: null,
      fiscal_signature: null,
      customer_name: null,
      notes: null,
      show_vat_breakdown: false,
      show_fiscal_info: false,
      show_payment_details: false,
      show_customer: false,
      is_reprint: result.wasReused,
    };

    void printReceipt(receiptData, printerConfig, getPrintSettingsFromStore()).catch(
      (err: unknown) => {
        toast.error(err instanceof Error ? err.message : t('reports.endOfDay.printError', 'Failed to send receipt to printer.'));
      },
    );
  };

  const handleExitFullscreen = async () => {
    useSettingsStore.getState().setFullscreen(false);
    await applyFullscreen(false);
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
      <header className="flex h-12 items-center justify-between border-b border-gray-200 bg-white px-4">
        <div className="flex items-center gap-3">
          <h1 className="text-lg font-bold text-gray-900">{t('auth.title')}</h1>
          {terminal && (
            <span className="rounded-md bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
              {terminal.name}
            </span>
          )}
        </div>

        <div className="flex items-center gap-1.5">
          {/* Connectivity indicator */}
          <div className="flex items-center gap-1.5" title={isOnline ? t('sync.online') : t('sync.offline')}>
            <span
              className={cn(
                'inline-block h-2.5 w-2.5 rounded-full',
                isSyncing
                  ? 'animate-pulse bg-yellow-500'
                  : isOnline
                    ? 'bg-green-500'
                    : 'bg-red-500',
              )}
            />
            {!isOnline && (
              <span className="rounded bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
                {t('sync.offline')}
              </span>
            )}
            {pendingReceiptCount > 0 && (
              <span className="rounded bg-orange-50 px-1.5 py-0.5 text-xs font-medium text-orange-700">
                {pendingReceiptCount}
              </span>
            )}
          </div>

          {/* Manual sync button */}
          <SyncButton />

          {/* Shift badge — opens End of Day preview */}
          {shift ? (
            <button
              onClick={() => setShowEndOfDay(true)}
              className="flex items-center gap-2 rounded-md bg-green-50 px-2.5 py-1.5 text-xs font-medium text-green-700 hover:bg-green-100"
            >
              <span>{t('shift.number', { number: shift.shift_number })}</span>
              <span className="text-green-600">|</span>
              <span>{t('shift.opening', { amount: shift.opening_cash })}</span>
            </button>
          ) : (
            <span className="text-sm text-gray-500">{t('header.noShift')}</span>
          )}

          {/* Operator name */}
          {operator && (
            <span className="text-sm font-medium text-gray-700">{operator.name}</span>
          )}

          {/* Switch operator */}
          <button
            onClick={handleSwitchOperator}
            className="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
            title={t('header.switch')}
          >
            <ArrowLeftRight className="h-3.5 w-3.5" />
            {t('header.switch')}
          </button>

          {/* Lock */}
          <button
            onClick={lockScreen}
            className="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
            title={t('header.lock')}
          >
            <Lock className="h-3.5 w-3.5" />
            {t('header.lock')}
          </button>

          {/* Reports */}
          {shift && (
            <button
              onClick={() => setShowReportsMenu(true)}
              className="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
              title={t('quickActions.reports')}
            >
              <BarChart3 className="h-3.5 w-3.5" />
              {t('quickActions.reports')}
            </button>
          )}

          {/* Exit fullscreen */}
          {fullscreen && (
            <button
              onClick={() => void handleExitFullscreen()}
              className="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700"
              title={t('settings.exitFullscreen')}
            >
              <Minimize2 className="h-4 w-4" />
            </button>
          )}

          {/* Settings */}
          <button
            onClick={() => navigate('/settings')}
            className="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700"
            title={t('header.settings')}
          >
            <Settings className="h-4 w-4" />
          </button>

          {/* Logout — manager/admin only */}
          {isManager && (
            <button
              onClick={() => setShowLogoutConfirm(true)}
              className="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700"
              title={t('header.logout')}
            >
              <LogOut className="h-4 w-4" />
            </button>
          )}
        </div>
      </header>

      {/* End of Day Preview Modal (replaces CloseShiftModal) */}
      {shift && (
        <EndOfDayPreviewModal
          isOpen={showEndOfDay}
          onClose={() => setShowEndOfDay(false)}
          shift={shift}
          terminalId={terminal?.id ?? ''}
          onConfirmAndClose={handleEndOfDayConfirm}
          onPrintReceipt={isTauriEnvironment() ? handlePrintZReport : undefined}
        />
      )}

      {/* Reports Menu */}
      <ReportsMenu
        isOpen={showReportsMenu}
        onClose={() => setShowReportsMenu(false)}
        onXReport={() => void handleXReport()}
        onTransactionHistory={() => { setShowReportsMenu(false); navigate('/sales'); }}
        onCashDrawerOps={() => { setShowReportsMenu(false); setShowCashDrawerModal(true); }}
        onTodaySales={() => { setShowReportsMenu(false); navigate('/sales'); }}
        onZReportHistory={() => { setShowReportsMenu(false); navigate('/reports/z'); }}
      />

      {/* X Report Modal */}
      <XReportModal
        isOpen={showXReportModal}
        onClose={() => setShowXReportModal(false)}
        report={xReport}
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

      {/* Logout Confirmation */}
      {showLogoutConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="mx-4 w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-bold text-gray-900">
              {t('settings.signOutTerminal')}
            </h3>
            <p className="mt-2 text-sm text-gray-600">
              {t('settings.signOutConfirmMessage')}
            </p>
            <div className="mt-6 flex gap-3">
              <button
                onClick={() => setShowLogoutConfirm(false)}
                className="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('settings.cancel')}
              </button>
              <button
                onClick={() => {
                  setShowLogoutConfirm(false);
                  handleLogout();
                }}
                className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700"
              >
                {t('settings.signOut')}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
