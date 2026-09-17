import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeftRight, BarChart3, Lock, Minimize2, RotateCw, Settings } from 'lucide-react';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore, fiscalShiftIdForReceipt } from '@/stores/terminalStore';
import { useShiftActionsStore } from '@/stores/shiftActionsStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { applyFullscreen } from '@/lib/fullscreen';
import { useOperatorStore } from '@/stores/operatorStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { StockFreshness } from '@/components/atoms/StockFreshness/StockFreshness';
import { EndOfDayPreviewModal } from '@/components/pos/EndOfDayPreviewModal';
import { ReportsMenu } from '@/components/pos/ReportsMenu';
import { XReportModal } from '@/components/pos/XReportModal';
import { generateXReport, generateZReport, ReauthenticationRequiredError } from '@/api/reportApi';
import type { GenerateXReportOpts, GenerateZReportOpts, XReportResponse } from '@/api/reportApi';
import { getErrorMessage } from '@/lib/api';
import { Avatar, Badge, Divider, IconButton, StatusPill } from '@/components/ui';
import { cn } from '@/lib/utils';
import { tokens } from '@/lib/designTokens';
import { CashDrawerModal } from '@/components/organisms/CashDrawerModal';
import type { EndOfDayConfirmResult, CompanyFraudSettings, AuthorizedManager } from '@/components/pos/EndOfDayPreviewModal';
import type { CashCountCommitPayload } from '@/components/pos/EndOfDayPreviewModal';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import { printReceipt, getPrintSettingsFromStore, isTauriEnvironment, buildZReceiptData } from '@/lib/printing';
import type { ZReceiptCashCountRow } from '@/lib/printing';
import { buildReceiptLabels } from '@/lib/buildReceiptData';
import { dedupeVatNumber } from '@/lib/receiptTaxIdentity';
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
import {
  DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
  DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
  DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
} from '@/lib/refundFlow/refundExposureDefaults';
import { fetchAuthorizedManagers } from '@/api/managersApi';
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';
import { ApiRequestError } from '@/lib/api';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { getTerminalState, setManagerPinThrottle, setManagerPinFailedAttempts } from '@/lib/db/repositories/terminalStateRepository';
import { hasManagerAccess } from '@/lib/auth/roles';
import { useCashDisclosure } from '@/hooks/useCashDisclosure';
import { shouldConcealTakings } from '@/lib/offline/cashDisclosurePolicy';

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
  const triggerSync = useSyncStore((s) => s.triggerSync);

  const [showEndOfDay, setShowEndOfDay] = useState(false);

  // Cash-count fraud settings state (loaded when EOD modal opens)
  const [fraudSettingsValue, setFraudSettingsValue] = useState<CompanyFraudSettings | null>(null);
  const [fraudSettingsTerminal, setFraudSettingsTerminal] = useState<typeof terminal>(null);
  const [cashCountPolicyTerminal, setCashCountPolicyTerminal] = useState<typeof terminal>(null);
  const [authorizedManagersValue, setAuthorizedManagersValue] = useState<AuthorizedManager[]>([]);
  const [authorizedManagersTerminal, setAuthorizedManagersTerminal] = useState<typeof terminal>(null);
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
  /**
   * B-13 (iv): every manager gate in the POS reads the ACTIVE PIN OPERATOR,
   * never the back-office account the terminal is signed in with.
   */
  const isManager = hasManagerAccess(operator);
  // R1-2: the cash drawer has its own seeded manager permission.
  const canOperateCashDrawer = hasManagerAccess(operator, 'cash_drawer');

  /**
   * B-13 (ii)/(iii): the blind-cash-count policy, resolved AT MOUNT rather
   * than when the End-of-Day modal opens. Both surfaces this Header owns
   * disclose a term of the drawer expectation — the shift-badge tooltip
   * (opening float, on every route) and the X report (cash takings) — so the
   * answer has to be in hand before either is touched.
   */
  const cashDisclosure = useCashDisclosure(companyId);

  /**
   * B-13 (ii): while a shift is OPEN under blind counting, this shift's
   * physical-tender takings are the raw material for the drawer expectation
   * the counter must not see — the same predicate `/reports` applies
   * (`ReportsPage.tsx:164`, `concealCash`). With no open shift nothing is
   * being counted, so nothing is concealed.
   *
   * Gate r1 (F-1 / R1-5): this is a plain BOOLEAN, and WHICH rows it covers is
   * decided inside `XReportModal` from each row's own `is_physical`. The
   * earlier shape joined the report against `usePaymentStore.paymentMethods`,
   * which is `[]` until the payment config loads — a cold or offline boot then
   * produced an empty conceal set and disclosed cash under `conceal`. There is
   * no store to be empty any more.
   */
  const concealPhysicalTenders = shouldConcealTakings(cashDisclosure, shift !== null);

  /**
   * B-13 (iii): the opening float is the OTHER term of the drawer
   * expectation, and this tooltip rendered it on every route to every
   * operator. Concealed from non-managers while blind counting is on.
   */
  const concealOpeningFloat = cashDisclosure === 'conceal' && !isManager;

  const cashCountPolicyResolved =
    terminal !== null && cashCountPolicyTerminal === terminal;
  const fraudSettings = fraudSettingsTerminal === terminal ? fraudSettingsValue : null;
  const authorizedManagers =
    authorizedManagersTerminal === terminal ? authorizedManagersValue : [];

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

        setFraudSettingsValue({
          cash_variance_over_soft: settings.cashVarianceOverSoft,
          cash_variance_over_hard: settings.cashVarianceOverHard,
          cash_variance_under_soft: settings.cashVarianceUnderSoft,
          cash_variance_under_hard: settings.cashVarianceUnderHard,
          require_blind_cash_count: settings.requireBlindCashCount,
          require_manager_pin_above_hard: settings.requireManagerPinAboveHard,
        });
        setFraudSettingsTerminal(terminal);
        setAuthorizedManagersValue(managers);
        setAuthorizedManagersTerminal(terminal);

        // Load throttle state from local SQLite
        const { getDatabase } = await import('@/lib/db');
        const db = await getDatabase(companyId);

        // B6 (Codex F-2): refresh the durable fraud-settings cache on every
        // successful online EOD open, so a later OFFLINE close in the same
        // session reads fresh thresholds — not just the activation-time snapshot.
        try {
          const { upsertCompanyFraudSettings } = await import(
            '@/lib/db/repositories/companyFraudSettingsCacheRepository'
          );
          await upsertCompanyFraudSettings(db, {
            company_id: companyId,
            cash_variance_over_soft: settings.cashVarianceOverSoft,
            cash_variance_over_hard: settings.cashVarianceOverHard,
            cash_variance_under_soft: settings.cashVarianceUnderSoft,
            cash_variance_under_hard: settings.cashVarianceUnderHard,
            require_blind_cash_count: settings.requireBlindCashCount,
            require_manager_pin_above_hard: settings.requireManagerPinAboveHard,
            cash_variance_email_severity: settings.cashVarianceEmailSeverity,
            // Lane C M2/M3 — same seeded-equal fallback contract as
            // refreshFraudSettingsCache(): a server build without the
            // refund-exposure fields must not NULL out a NOT NULL column
            // and lose the whole cache write.
            offline_refund_count_ceiling:
              settings.offlineRefundCountCeiling ?? DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
            offline_refund_value_ceiling:
              settings.offlineRefundValueCeiling ?? DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
            online_required_refund_threshold:
              settings.onlineRequiredRefundThreshold ?? DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
          });
        } catch {
          // Non-fatal: a cache-write blip must not block the EOD flow.
        }

        const ts = await getTerminalState(db, terminal.id);
        if (!cancelled) {
          setManagerPinThrottleState({
            until: ts?.manager_pin_throttle_until ?? null,
            failedAttempts: ts?.manager_pin_failed_attempts ?? 0,
          });
        }
      } catch {
        // Offline (B6): the live fraud-settings + managers fetch failed.
        // Read the variance thresholds from the durable
        // company_fraud_settings_cache (eagerly populated at activation) so the
        // EOD close still computes severity offline, plus the throttle state.
        if (cancelled) return;
        try {
          const { getDatabase } = await import('@/lib/db');
          const db = await getDatabase(companyId);

          const { getCompanyFraudSettings } = await import(
            '@/lib/db/repositories/companyFraudSettingsCacheRepository'
          );
          const cachedFraud = await getCompanyFraudSettings(db, companyId);
          if (!cancelled && cachedFraud) {
            setFraudSettingsValue({
              cash_variance_over_soft: cachedFraud.cash_variance_over_soft,
              cash_variance_over_hard: cachedFraud.cash_variance_over_hard,
              cash_variance_under_soft: cachedFraud.cash_variance_under_soft,
              cash_variance_under_hard: cachedFraud.cash_variance_under_hard,
              require_blind_cash_count: cachedFraud.require_blind_cash_count,
              require_manager_pin_above_hard: cachedFraud.require_manager_pin_above_hard,
            });
            setFraudSettingsTerminal(terminal);
          }

          // F-3 (B7): the authorized-managers list for the offline above-hard
          // close comes from the operator_pins mirror — the managers holding the
          // close_shift_variance approval scope (their bcrypt hashes are already
          // mirrored there and verified locally by verifyScopedManagerPin).
          const { getAllOperators } = await import(
            '@/lib/db/repositories/operatorPinRepository'
          );
          const offlineManagers = (await getAllOperators(db))
            .filter((o) => o.approval_scopes?.includes('close_shift_variance'))
            .map((o) => ({ id: o.id, name: o.name }));
          if (!cancelled) {
            setAuthorizedManagersValue(offlineManagers);
            setAuthorizedManagersTerminal(terminal);
          }

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
      } finally {
        if (!cancelled) setCashCountPolicyTerminal(terminal);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [showEndOfDay, terminal, companyId]);

  const handleOpenEndOfDay = useCallback(() => {
    // Fail closed on every open. This prevents a first-load null or a stale
    // false policy from mounting a disclosure path while the refresh is in flight.
    setFraudSettingsValue(null);
    setFraudSettingsTerminal(null);
    setAuthorizedManagersValue([]);
    setAuthorizedManagersTerminal(null);
    setCashCountPolicyTerminal(null);
    setShowEndOfDay(true);
  }, []);

  // Other screens (e.g. the `/shift` service reading) ask for the ONE real
  // closure flow rather than standing up a second cash-count path. The request
  // is a monotonic id, so it routes through the same fail-closed
  // `handleOpenEndOfDay` above every time — including repeat requests.
  const endOfDayRequestId = useShiftActionsStore((s) => s.endOfDayRequestId);
  const lastEndOfDayRequestRef = useRef(endOfDayRequestId);
  useEffect(() => {
    if (endOfDayRequestId === lastEndOfDayRequestRef.current) return;
    // Consume the request ONLY once it is actually served. Advancing the ref
    // before the `shift` guard would swallow a request that arrived while the
    // shift was still loading — it would never replay once the shift landed.
    if (!shift) return;
    lastEndOfDayRequestRef.current = endOfDayRequestId;
    handleOpenEndOfDay();
  }, [endOfDayRequestId, shift, handleOpenEndOfDay]);

  // B7: above-hard-variance close manager approval is now OFFLINE-CAPABLE,
  // reusing the audited operator-approval verifier (online-first → anti-downgrade
  // → local bcrypt fallback against operator_pins for the close_shift_variance
  // scope). targetOperatorId pins the match to the manager the cashier selected.
  const onVerifyManagerPin = useCallback(
    async (managerUserId: string, pin: string) => {
      if (!approvalContext || !shift) return { valid: false };
      try {
        const approved = await verifyScopedManagerPin({
          pin,
          context: approvalContext,
          approvalScope: 'close_shift_variance',
          targetOperatorId: managerUserId,
          targetEventType: 'SESSION_CLOSE',
          targetReferenceId: shift.id,
          reason: 'above_hard_variance_close',
        });
        return { valid: true, user_id: approved.id, user_name: approved.name };
      } catch (error) {
        // A local no-match (wrong PIN / scope mismatch) or an explicit server
        // denial (4xx, not 408/429) is a COUNTABLE failed attempt → valid:false.
        // A fail-closed service-unavailable (503) or any unexpected error must
        // NOT be counted as a bad PIN — rethrow so the panel shows a generic
        // error without advancing the lockout (anti-downgrade discipline).
        if (error instanceof Error && error.message === 'manager_pin_scope_mismatch') {
          return { valid: false };
        }
        if (
          error instanceof ApiRequestError &&
          error.status < 500 &&
          error.status !== 408 &&
          error.status !== 429
        ) {
          return { valid: false };
        }
        throw error;
      }
    },
    [approvalContext, shift],
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

  /**
   * Gate r1 (R1-2). `ReportsMenu` FILTERS manager-only entries, but a filtered
   * menu is not a boundary — the same argument the X report already acts on.
   * Cash-drawer ops needed it MORE, not less: the server authorizes
   * deposit/payout on `pos.operate_terminal` (`CashDrawerController.php:42`,
   * `:111`), which CASHIERS HOLD (`RolesAndPermissionsSeeder.php:685`), so for
   * real cash movement the device gate is the ONLY gate. That server-side
   * permission gap is recorded in the LEDGER; it is out of this lane.
   */
  const handleCashDrawerOps = () => {
    setShowReportsMenu(false);
    if (!canOperateCashDrawer) {
      toast.error(t('reports.managerOnly'));
      return;
    }
    setShowCashDrawerModal(true);
  };

  /**
   * POLICY NOTE (gate r1 F-5) — why a cashier is refused the read-only X report
   * yet may still close the shift (End-of-Day → `handleEndOfDayConfirm` →
   * `handlePrintZReport`), which authors the far more consequential
   * once-per-shift `SESSION_CLOSE` / `Z_REPORT` events.
   *
   * This is DELIBERATE, not an inconsistency the gate missed. The cashier
   * closes their OWN drawer: counting it and signing the close is the job, and
   * the seeder agrees — `cashier` holds `pos.generate_z_report` but NOT
   * `pos.view_reports` (`RolesAndPermissionsSeeder.php:685`). The two surfaces
   * answer different questions. The Z is "here is what I counted", authored
   * under the blind-count regime with the expectation concealed. The X is
   * "here is what the drawer should hold right now" — the expectation itself,
   * mid-shift, which is exactly what the counter must not see.
   *
   * So the asymmetry runs the right way: authority to CLOSE is broad, authority
   * to READ THE EXPECTATION is narrow.
   */
  const handleXReport = async () => {
    if (!terminal) return;
    // B-13 (ii), defense in depth behind ReportsMenu's own filter: generating
    // an X report APPENDS an immutable `X_REPORT` fiscal event (rule 8 — never
    // correctable, only superseded) and discloses per-tender takings. Refuse
    // BEFORE the generator runs; hiding the rendered result would still have
    // authored the event.
    if (!isManager) {
      toast.error(t('reports.managerOnly'));
      return;
    }
    setShowXReportModal(true);
    setReportLoading(true);
    setReportError(null);
    setXReport(null);
    try {
      // Only device-authoritative (v3) terminals author fiscal events locally.
      // generateXReport treats a defined fiscalShiftId/fiscalSessionId as the
      // trigger to append an X_REPORT fiscal event; for a v2 server shift there
      // is no local SESSION_OPEN, so supplying ids would wrongly author (and
      // throw). Gate the opts on v3 — pre-one-id-sweep this was implicit because
      // v2 shifts had no fiscal_shift_id.
      const xOpts: GenerateXReportOpts = shift && tenantId && terminal.fiscal_schema_version === 3
        ? {
            tenantId,
            fiscalShiftId: fiscalShiftIdForReceipt(shift),
            fiscalSessionId: fiscalShiftIdForReceipt(shift),
            operatorId: shift.user.id,
            operatorName: shift.user.name,
            isTraining: terminal.is_training_mode === true,
          }
        : {};
      const report = await generateXReport(terminal.id, xOpts);
      setXReport(report);
    } catch (err) {
      // R2-4: a rotated/expired device token is not a permission problem —
      // say "sign in again", not "you may not".
      setReportError(
        err instanceof ReauthenticationRequiredError
          ? t(err.i18nKey)
          : getErrorMessage(err),
      );
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

    // F-1/F-2 (B7, fail-closed): a v3 device-authoritative close needs the
    // cash-count fraud policy to decide variance severity and whether manager
    // approval is required. If it could not be loaded — whether genuinely
    // offline with an empty cache, OR online-but-the-fraud-fetch-failed with no
    // cached fallback — BLOCK the close rather than silently degrading to a
    // preview-only close without the variance gate (Codex F-2: the guard must
    // NOT be limited to the offline branch). fraudSettings is only ever null
    // when the policy genuinely failed to load (the server always returns one),
    // so this never false-blocks a normal close.
    if (terminal.fiscal_schema_version === 3 && fraudSettings === null) {
      throw new Error(
        t('cash_count.policy_unavailable', {
          defaultValue:
            'Cannot close this shift: the cash-count policy has not been synced to this device. Connect to the network once, then retry the close.',
        }),
      );
    }

    // Build opts from cash-count payload when present.
    // Pass fraudSettings so generateZReport can compute variance_severity.
    // v3 only: device-authoritative close authors SESSION_CLOSE + Z_REPORT
    // locally. For a v2 server shift there is no local SESSION_OPEN, so the
    // fiscal ids must stay undefined — buildFiscalCloseInput keys on them and
    // would otherwise append a close with no matching open (pre-one-id-sweep
    // this was implicit because v2 shifts had no fiscal_shift_id).
    const isDeviceAuthoritative = terminal.fiscal_schema_version === 3;
    const fiscalZOpts: GenerateZReportOpts = {
      tenantId,
      fiscalShiftId: isDeviceAuthoritative ? fiscalShiftIdForReceipt(shift) : undefined,
      fiscalSessionId: isDeviceAuthoritative ? fiscalShiftIdForReceipt(shift) : undefined,
      terminalLabel: terminal.code,
      operatorId: shift.user.id,
      operatorName: shift.user.name,
      isTraining: terminal.is_training_mode === true,
      requireFiscalEvents: isDeviceAuthoritative,
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
  /**
   * Reachable by ANY PIN operator, including a cashier — see the F-5 policy
   * note on `handleXReport`. The cashier closing their own drawer is the
   * intended flow; the blind-count regime is enforced INSIDE the close
   * (`CashReconciliationSection` / `EndOfDayPreviewModal`), not by withholding
   * the close.
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
      // DEV-QA-092: display-only dedup at the call site too, so the Z header
      // input never carries a VAT number that merely repeats the tax id.
      vatNumber: dedupeVatNumber(
        zIdentity.taxNumber,
        zLocationComplete ? zLocation?.vat_number ?? null : null,
      ),
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

  return (
    <>
      <header
        className={cn(
          'flex h-[62px] items-center justify-between gap-3 border-b border-subtle px-4',
          tokens.section.header,
        )}
      >
        {/* LEFT zone — identity (largest). Wordmark (display face) + terminal
         * badge + at-a-glance online dot. The dot is decorative (aria-hidden);
         * the accessible connectivity/sync state lives in the B3 StatusPill.
         * Wordmark uses the inverse fg, not `text-brand` (accent): every
         * accent swatch measures well under the 7:1 AAA target against the
         * fixed navy chrome (max ~4.6:1 for orange) — see task-4-report.md. */}
        <div className="flex min-w-0 items-center gap-2.5">
          <h1 className="truncate font-display text-xl font-extrabold tracking-tight text-pay-navy-fg">
            {t('auth.title')}
          </h1>
          {terminal && <Badge tone="neutral">{terminal.name}</Badge>}
          <span
            aria-hidden
            title={isOnline ? t('sync.online') : t('sync.offline')}
            className={cn(
              'h-2 w-2 shrink-0 rounded-full',
              isOnline ? 'bg-success' : 'bg-danger',
            )}
          />
        </div>

        {/* CENTER zone — ONE session-status pill (connectivity + sync + stock age). */}
        <div className="flex min-w-0 items-center gap-2">
          {/* Status is a clean dot+label indicator (never a nested button). */}
          <StatusPill tone={statusTone} label={statusLabel} pulse={isSyncing} title={statusLabel} />
          {/* Manual sync trigger — a SEPARATE control beside the pill, not inside it. */}
          <IconButton
            variant="ghost"
            size="md"
            onClick={() => triggerSync()}
            disabled={isSyncing}
            title={t('sync.syncNow')}
            aria-label={t('sync.syncNow')}
            icon={<RotateCw className={isSyncing ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />}
          />
          {/* Stock staleness hint — own freshness/age signal beside the pill. */}
          <StockFreshness />
        </div>

        {/* RIGHT zone — shift · operator · ghost actions, split into clusters by
         * vertical Divider atoms (mock §5.0). */}
        <div className="flex min-w-0 items-center gap-1">
          {/* Shift badge — opens End of Day preview. */}
          {shift ? (
            <button
              type="button"
              onClick={handleOpenEndOfDay}
              className="flex min-h-12 items-center rounded-pill px-1 transition-colors hover:bg-surface-sunken"
              title={concealOpeningFloat
                ? t('shift.number', { number: shift.shift_number })
                : t('shift.opening', { amount: shift.opening_cash })}
            >
              <Badge tone="success">{t('shift.number', { number: shift.shift_number })}</Badge>
            </button>
          ) : (
            <span className={cn('text-sm', tokens.inverseOnNavy.muted)}>{t('header.noShift')}</span>
          )}

          {/* Operator cluster — divider + avatar + name + switch. The leading
           * divider is part of this cluster so it never orphans when there is
           * no operator. Avatar (tone="accent") owns its own tinted-disc
           * surface, so it's left as-is; the divider and name are bare
           * on navy and need the inverse treatment. */}
          {operator && (
            <>
              <Divider orientation="vertical" className={tokens.inverseOnNavy.divider} />
              <Avatar name={operator.name} size={36} tone="accent" />
              <span
                className={cn(
                  'mx-1 hidden max-w-[10rem] truncate text-sm font-medium sm:inline',
                  tokens.inverseOnNavy.muted,
                )}
              >
                {operator.name}
              </span>
            </>
          )}

          {/* Switch operator — icon-only */}
          <IconButton
            variant="ghost"
            size="md"
            onClick={handleSwitchOperator}
            title={t('header.switch')}
            aria-label={t('header.switch')}
            icon={<ArrowLeftRight className="h-4 w-4" />}
          />

          <Divider orientation="vertical" className={tokens.inverseOnNavy.divider} />

          {/* Lock — icon-only */}
          <IconButton
            variant="ghost"
            size="md"
            onClick={lockScreen}
            title={t('header.lock')}
            aria-label={t('header.lock')}
            icon={<Lock className="h-4 w-4" />}
          />

          {/* Reports — icon-only (behavior/routing unchanged) */}
          {shift && (
            <IconButton
              variant="ghost"
              size="md"
              onClick={() => setShowReportsMenu(true)}
              title={t('quickActions.reports')}
              aria-label={t('quickActions.reports')}
              icon={<BarChart3 className="h-4 w-4" />}
            />
          )}

          {/* Exit fullscreen — icon-only */}
          {fullscreen && (
            <IconButton
              variant="ghost"
              size="md"
              onClick={() => void handleExitFullscreen()}
              title={t('settings.exitFullscreen')}
              aria-label={t('settings.exitFullscreen')}
              icon={<Minimize2 className="h-4 w-4" />}
            />
          )}

          {/* Settings — icon-only */}
          <IconButton
            variant="ghost"
            size="md"
            onClick={() => navigate('/settings')}
            title={t('header.settings')}
            aria-label={t('header.settings')}
            icon={<Settings className="h-4 w-4" />}
          />
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
          cashCountPolicyResolved={cashCountPolicyResolved}
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
        onCashDrawerOps={handleCashDrawerOps}
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
        concealPhysicalTenders={concealPhysicalTenders}
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
