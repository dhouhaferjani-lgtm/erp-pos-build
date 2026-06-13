import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeftRight, BarChart3, Lock, Minimize2, Settings } from 'lucide-react';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { applyFullscreen } from '@/lib/fullscreen';
import { useOperatorStore } from '@/stores/operatorStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { SyncButton } from '@/components/atoms/SyncButton/SyncButton';
import { StockFreshness } from '@/components/atoms/StockFreshness/StockFreshness';
import { EndOfDayPreviewModal } from '@/components/pos/EndOfDayPreviewModal';
import { ReportsMenu } from '@/components/pos/ReportsMenu';
import { XReportModal } from '@/components/pos/XReportModal';
import { generateXReport, generateZReport } from '@/api/reportApi';
import type { GenerateXReportOpts, GenerateZReportOpts, XReportResponse } from '@/api/reportApi';
import { getErrorMessage } from '@/lib/api';
import { cn } from '@/lib/utils';
import { CashDrawerModal } from '@/components/organisms/CashDrawerModal';
import type { EndOfDayConfirmResult, CompanyFraudSettings, AuthorizedManager } from '@/components/pos/EndOfDayPreviewModal';
import type { CashCountCommitPayload } from '@/components/pos/EndOfDayPreviewModal';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import { printReceipt, getPrintSettingsFromStore, isTauriEnvironment, buildZReceiptData } from '@/lib/printing';
import type { ZReceiptCashCountRow } from '@/lib/printing';
import { buildReceiptLabels } from '@/lib/buildReceiptData';
import {
  formatLegalIdentifierLines,
  resolveSellerIdentityWithSource,
} from '@/lib/fiscal/sellerIdentity';
import type { ZReportCountEntry } from '@/lib/offline/types';
import type { PaymentMethodItem } from '@/lib/offline/endOfDayPreview';
import { bcadd, bccomp, bcsub, bcformat } from '@/lib/decimal';
import { getCurrencyDecimals } from '@/lib/currency';
import { usePrinterStore } from '@/stores/printerStore';
import { toast } from 'sonner';
import { fetchFraudSettings } from '@/api/fraudSettingsApi';
import { fetchAuthorizedManagers } from '@/api/managersApi';
import { verifyManagerPin } from '@/api/managerPinApi';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { getTerminalState, setManagerPinThrottle, setManagerPinFailedAttempts } from '@/lib/db/repositories/terminalStateRepository';
import { tokens } from '@/lib/designTokens';

export function Header() {
  const { t } = useTranslation('pos');
  const navigate = useNavigate();
  const terminal = useTerminalStore((s) => s.terminal);
  const shift = useTerminalStore((s) => s.shift);
  const closeShift = useTerminalStore((s) => s.closeShift);

  const operator = useOperatorStore((s) => s.operator);
  const lockScreen = useOperatorStore((s) => s.lock);
  const clearOperator = useOperatorStore((s) => s.clearOperator);

  const companyId = useAuthStore((s) => s.companyId);
  const tenantId = useAuthStore((s) => s.user?.tenantId ?? null);
  const userId = useAuthStore((s) => s.user?.id ?? null);
  const fullscreen = useSettingsStore((s) => s.fullscreen);

  const isOnline = useConnectivityStore((s) => s.isOnline);
  const pendingReceiptCount = useSyncStore((s) => s.pendingReceiptCount);
  const isSyncing = useSyncStore((s) => s.isSyncing);

  const [showEndOfDay, setShowEndOfDay] = useState(false);

  // Cash-count fraud settings state (loaded when EOD modal opens)
  const [fraudSettings, setFraudSettings] = useState<CompanyFraudSettings | null>(null);
  const [authorizedManagers, setAuthorizedManagers] = useState<AuthorizedManager[]>([]);
  const [managerPinThrottle, setManagerPinThrottleState] = useState<{
    until: string | null;
    failedAttempts: number;
  }>({ until: null, failedAttempts: 0 });

  // Reports state
  const [showReportsMenu, setShowReportsMenu] = useState(false);
  const [showXReportModal, setShowXReportModal] = useState(false);
  const [xReport, setXReport] = useState<XReportResponse | null>(null);
  const [reportLoading, setReportLoading] = useState(false);
  const [reportError, setReportError] = useState<string | null>(null);
  const [showCashDrawerModal, setShowCashDrawerModal] = useState(false);
  // Refs for passing EOD data to handlePrintZReport after confirmation
  const lastCashCountPayloadRef = useRef<CashCountCommitPayload | null>(null);
  const lastZReportCashCountsRef = useRef<ZReportCountEntry[] | null>(null);
  const lastPreviewPaymentMethodsRef = useRef<PaymentMethodItem[] | null>(null);

  const approvalContext = useMemo(() => {
    if (!tenantId || !companyId || !terminal) return undefined;
    const cashierUserId = operator?.id ?? userId;
    if (!cashierUserId) return undefined;

    return {
      tenantId,
      companyId,
      terminalId: terminal.id,
      cashierUserId,
      businessDate: new Date().toISOString().slice(0, 10),
      isTraining: terminal.is_training_mode === true,
    };
  }, [tenantId, companyId, terminal, operator?.id, userId]);

  // Load fraud settings + authorized managers + local throttle when EOD modal opens
  useEffect(() => {
    if (!showEndOfDay || !terminal || !companyId) return;
    let cancelled = false;

    void (async () => {
      try {
        const [settings, managers] = await Promise.all([
          fetchFraudSettings(),
          fetchAuthorizedManagers(),
        ]);
        if (cancelled) return;

        setFraudSettings({
          cash_variance_over_soft: settings.cashVarianceOverSoft,
          cash_variance_over_hard: settings.cashVarianceOverHard,
          cash_variance_under_soft: settings.cashVarianceUnderSoft,
          cash_variance_under_hard: settings.cashVarianceUnderHard,
          require_blind_cash_count: settings.requireBlindCashCount,
          require_manager_pin_above_hard: settings.requireManagerPinAboveHard,
        });
        setAuthorizedManagers(managers);

        // Load throttle state from local SQLite
        const { getDatabase } = await import('@/lib/db');
        const db = await getDatabase(companyId);
        const ts = await getTerminalState(db, terminal.id);
        if (!cancelled) {
          setManagerPinThrottleState({
            until: ts?.manager_pin_throttle_until ?? null,
            failedAttempts: ts?.manager_pin_failed_attempts ?? 0,
          });
        }
      } catch {
        // Offline: keep existing local state from SQLite only
        if (cancelled) return;
        try {
          const { getDatabase } = await import('@/lib/db');
          const db = await getDatabase(companyId);
          const ts = await getTerminalState(db, terminal.id);
          if (!cancelled) {
            setManagerPinThrottleState({
              until: ts?.manager_pin_throttle_until ?? null,
              failedAttempts: ts?.manager_pin_failed_attempts ?? 0,
            });
          }
        } catch {
          // Silently ignore — throttle state resets to defaults
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [showEndOfDay, terminal, companyId]);

  const onVerifyManagerPin = useCallback(
    (userId: string, pin: string) => verifyManagerPin(userId, pin),
    [],
  );

  const onManagerPinThrottleUpdate = useCallback(
    async (next: { until: string | null; failedAttempts: number }) => {
      setManagerPinThrottleState(next);
      if (!terminal || !companyId) return;
      try {
        const { getDatabase } = await import('@/lib/db');
        const db = await getDatabase(companyId);
        await Promise.all([
          setManagerPinThrottle(db, terminal.id, next.until),
          setManagerPinFailedAttempts(db, terminal.id, next.failedAttempts),
        ]);
      } catch {
        // Best-effort persistence — throttle state is still held in React state
      }
    },
    [terminal, companyId],
  );

  const handleXReport = async () => {
    if (!terminal) return;
    setShowXReportModal(true);
    setReportLoading(true);
    setReportError(null);
    setXReport(null);
    try {
      const xOpts: GenerateXReportOpts = shift && tenantId
        ? {
            tenantId,
            fiscalShiftId: shift.fiscal_shift_id,
            fiscalSessionId: shift.fiscal_session_id,
            operatorId: shift.user.id,
            operatorName: shift.user.name,
            isTraining: terminal.is_training_mode === true,
          }
        : {};
      const report = await generateXReport(terminal.id, xOpts);
      setXReport(report);
    } catch (err) {
      setReportError(getErrorMessage(err));
    } finally {
      setReportLoading(false);
    }
  };

  /**
   * Called by EndOfDayPreviewModal when the operator confirms.
   * Atomically: generates Z (offline-first, with optional cash counts) →
   * closes shift → returns result.
   */
  const handleEndOfDayConfirm = async (
    preview: EndOfDayPreview,
    cashCountPayload: CashCountCommitPayload | null,
  ): Promise<EndOfDayConfirmResult> => {
    if (!terminal || !shift || !companyId || !tenantId) {
      throw new Error('Missing terminal, shift, company, or tenant context');
    }

    // Build opts from cash-count payload when present.
    // Pass fraudSettings so generateZReport can compute variance_severity.
    const fiscalZOpts: GenerateZReportOpts = {
      tenantId,
      fiscalShiftId: shift.fiscal_shift_id,
      fiscalSessionId: shift.fiscal_session_id,
      terminalLabel: terminal.code,
      operatorId: shift.user.id,
      operatorName: shift.user.name,
      isTraining: terminal.is_training_mode === true,
      requireFiscalEvents: terminal.fiscal_schema_version === 3,
    };
    const zOpts: GenerateZReportOpts = cashCountPayload != null
      ? {
          ...fiscalZOpts,
          cashCounts: cashCountPayload.cashCounts,
          varianceReason: cashCountPayload.varianceReason,
          managerUserId: cashCountPayload.managerUserId,
          blindCountUsed: cashCountPayload.blindCountUsed,
          fraudSettings: fraudSettings ?? null,
        }
      : fiscalZOpts;

    // Store refs so handlePrintZReport can access them after confirmation
    lastCashCountPayloadRef.current = cashCountPayload;
    lastPreviewPaymentMethodsRef.current = preview.payment_methods;

    // 1. Generate Z report (offline-first, idempotent)
    const zReport = await generateZReport(
      terminal.id,
      companyId,
      shift.id,
      shift.opened_at,
      shift.opening_cash,
      zOpts,
    );

    // Store the computed cash count entries (with expected/actual/variance amounts)
    lastZReportCashCountsRef.current = zReport.cash_counts ?? null;

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
   * Includes per-tender cash-count block when cash counts were captured.
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

    const payload = lastCashCountPayloadRef.current;
    const zCashCounts = lastZReportCashCountsRef.current;
    const previewMethods = lastPreviewPaymentMethodsRef.current;

    // Build a map of payment_method_id → { code, name } from the preview
    const methodById = new Map<string, { code: string; name: string }>();
    if (previewMethods) {
      for (const m of previewMethods) {
        methodById.set(m.payment_method_id, {
          code: m.payment_method_code,
          name: m.payment_method_name,
        });
      }
    }

    // Map zReport cash count entries to ZReceiptCashCountRow for printing
    const cashCountRows: ZReceiptCashCountRow[] | undefined =
      zCashCounts && zCashCounts.length > 0
        ? zCashCounts.map((entry) => {
            const method = methodById.get(entry.payment_method_id);
            return {
              code: method?.code ?? entry.payment_method_id,
              name: method?.name ?? method?.code ?? entry.payment_method_id,
              expected: entry.expected_amount,
              actual: entry.actual_amount,
              variance: entry.variance_amount,
              direction: entry.variance_direction,
            };
          })
        : undefined;

    // Aggregate total variance magnitude for display
    const currency = company?.currency ?? 'EUR';
    const scale = getCurrencyDecimals(currency);
    const aggregateVariance =
      cashCountRows && cashCountRows.length > 0
        ? cashCountRows.reduce((acc, row) => {
            const absVariance = bccomp(row.variance, '0') < 0
              ? bcsub('0', row.variance, scale)
              : bcformat(row.variance, scale);
            return bcadd(acc, absVariance, scale);
          }, bcformat('0', scale))
        : null;

    // Resolve manager name from authorizedManagers list
    const managerName =
      payload?.managerUserId
        ? (authorizedManagers.find((m) => m.id === payload.managerUserId)?.name ?? null)
        : null;

    // Atomic header identity (spec 2026-06-11 §4.6): the Z header prints the
    // terminal location's tax id (+ vat number / legal identifiers) when the
    // location is fiscally complete, otherwise the company tax id. The SIGNED
    // Z-report seller stays null — this is display-only.
    const zLocation = terminal?.location ?? null;
    // Single decision point (FU-3): the resolver reports whether it used the
    // location or the company, so the vat/legal-identifier display below can't
    // drift from the identity it actually resolved.
    const { identity: zIdentity, source: zSource } = resolveSellerIdentityWithSource(
      company,
      zLocation,
    );
    const zLocationComplete = zSource === 'location';

    const receiptData = buildZReceiptData({
      companyName: company?.name ?? '',
      taxId: zIdentity.taxNumber,
      vatNumber: zLocationComplete ? zLocation?.vat_number ?? null : null,
      legalIdentifierLines: zLocationComplete
        ? formatLegalIdentifierLines(zLocation?.legal_identifiers)
        : null,
      formattedZNumber: result.formattedZNumber,
      dateTime: new Date().toISOString(),
      terminalName: terminal?.name ?? '',
      operatorName: operator?.name ?? '',
      currencySymbol: '',
      wasReused: result.wasReused,
      cashCounts: cashCountRows,
      managerName,
      varianceReason: payload?.varianceReason ?? null,
      varianceSeverity: null,
      aggregateVariance,
      labels: buildReceiptLabels(),
    });

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
    useCartStore.getState().clearCart('operator_switch');
    useRefundFlowStore.getState().clearAll();
    useRefundDraftStore.getState().clearDraftState();
    usePaymentStore.getState().clearVoucherTenders();
    // T0.2: drop any in-flight idempotency key so the next operator's first
    // sale gets a fresh allocation. Without this, a stale key from a void/
    // failed attempt would leak across operator switches.
    usePaymentStore.getState().discardPendingSubmission();
    clearOperator();
  }

  // Single session-status signal (B3): one pill folds connectivity + sync +
  // pending-receipt backlog into one healthy/warning/danger state, driven by
  // the same stores the header already reads. The healthy "synced" signal now
  // lives HERE (the full-width "everything synced" banner is exception-only —
  // B4 returns null when healthy), so this pill must show the synced state.
  const statusTone: 'healthy' | 'warning' | 'danger' = !isOnline
    ? 'danger'
    : pendingReceiptCount > 0 || isSyncing
      ? 'warning'
      : 'healthy';

  const statusLabel = !isOnline
    ? t('sync.offline')
    : isSyncing
      ? t('sync.syncing')
      : pendingReceiptCount > 0
        ? t('sync.pendingCount', { count: pendingReceiptCount })
        : t('sync.online');

  const statusDotColor =
    statusTone === 'healthy' ? 'bg-success' : statusTone === 'warning' ? 'bg-warning' : 'bg-danger';

  return (
    <>
      <header className="flex h-12 items-center justify-between gap-4 border-b border-subtle bg-surface-raised px-4">
        {/* LEFT zone — identity (largest). Wordmark + terminal badge. */}
        <div className="flex min-w-0 items-center gap-3">
          <h1 className="truncate text-xl font-extrabold tracking-tight text-brand">{t('auth.title')}</h1>
          {terminal && <span className={tokens.badge.neutral}>{terminal.name}</span>}
        </div>

        {/* CENTER zone — ONE session-status pill (connectivity + sync + stock age). */}
        <div className="flex min-w-0 items-center gap-2">
          <div
            className={cn(tokens.statusPill.base, tokens.statusPill[statusTone])}
            title={statusLabel}
            aria-label={statusLabel}
          >
            <span
              className={cn(
                tokens.statusPill.dot,
                statusDotColor,
                isSyncing && 'animate-pulse',
              )}
            />
            <span className="truncate">{statusLabel}</span>
            <SyncButton />
          </div>
          {/* Stock staleness hint — own freshness/age signal beside the pill. */}
          <StockFreshness />
        </div>

        {/* RIGHT zone — operator + icon-only actions (smallest). */}
        <div className="flex min-w-0 items-center gap-1">
          {/* Shift badge — opens End of Day preview. */}
          {shift ? (
            <button
              onClick={() => setShowEndOfDay(true)}
              className={cn(tokens.badge.success, 'hover:bg-success-surface/80')}
              title={t('shift.opening', { amount: shift.opening_cash })}
            >
              <span>{t('shift.number', { number: shift.shift_number })}</span>
            </button>
          ) : (
            <span className="text-sm text-ink-faint">{t('header.noShift')}</span>
          )}

          {/* Operator name */}
          {operator && (
            <span className="mx-1 hidden truncate text-sm font-medium text-ink-muted sm:inline">
              {operator.name}
            </span>
          )}

          {/* Switch operator — icon-only */}
          <button
            onClick={handleSwitchOperator}
            className={cn(tokens.button.ghost, 'h-8 w-8 !px-0')}
            title={t('header.switch')}
            aria-label={t('header.switch')}
          >
            <ArrowLeftRight className="h-4 w-4" />
          </button>

          {/* Lock — icon-only */}
          <button
            onClick={lockScreen}
            className={cn(tokens.button.ghost, 'h-8 w-8 !px-0')}
            title={t('header.lock')}
            aria-label={t('header.lock')}
          >
            <Lock className="h-4 w-4" />
          </button>

          {/* Reports — icon-only (behavior/routing unchanged) */}
          {shift && (
            <button
              onClick={() => setShowReportsMenu(true)}
              className={cn(tokens.button.ghost, 'h-8 w-8 !px-0')}
              title={t('quickActions.reports')}
              aria-label={t('quickActions.reports')}
            >
              <BarChart3 className="h-4 w-4" />
            </button>
          )}

          {/* Exit fullscreen — icon-only */}
          {fullscreen && (
            <button
              onClick={() => void handleExitFullscreen()}
              className={cn(tokens.button.ghost, 'h-8 w-8 !px-0')}
              title={t('settings.exitFullscreen')}
              aria-label={t('settings.exitFullscreen')}
            >
              <Minimize2 className="h-4 w-4" />
            </button>
          )}

          {/* Settings — icon-only */}
          <button
            onClick={() => navigate('/settings')}
            className={cn(tokens.button.ghost, 'h-8 w-8 !px-0')}
            title={t('header.settings')}
            aria-label={t('header.settings')}
          >
            <Settings className="h-4 w-4" />
          </button>
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
          fraudSettings={fraudSettings}
          authorizedManagers={authorizedManagers}
          cashierUserId={operator?.id ?? ''}
          onVerifyManagerPin={onVerifyManagerPin}
          managerPinThrottle={managerPinThrottle}
          onManagerPinThrottleUpdate={(next) => { void onManagerPinThrottleUpdate(next); }}
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
          approvalContext={approvalContext}
        />
      )}

    </>
  );
}
