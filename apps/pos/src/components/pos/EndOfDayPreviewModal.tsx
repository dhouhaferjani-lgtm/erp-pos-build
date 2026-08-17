import { useState, useEffect, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { CheckCircle, Loader2, AlertCircle, Printer } from 'lucide-react';
import { Modal } from './Modal';
import { useCurrency } from '@/lib/currency';
import { formatPercent } from '@/lib/format';
import {
  CashReconciliationSection,
  type CashCountCommitPayload,
  type CompanyFraudSettings,
  type AuthorizedManager,
} from './CashReconciliationSection';
import type { Shift } from '@/stores/terminalStore';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import { ToleranceDrillDown } from '@/components/pos/molecules/ToleranceDrillDown';
// The §8.1 cap, imported rather than written as `10`: paymentStore's gate and
// two test suites already bind to this constant, so a literal here would fork
// the displayed limit from the enforced one on the next tuning change.
import { TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT } from '@/lib/payment/cashRounding';

export interface EndOfDayConfirmResult {
  formattedZNumber: string;
  wasReused: boolean;
}

export type { CashCountCommitPayload, CompanyFraudSettings, AuthorizedManager };

export interface EndOfDayPreviewModalProps {
  isOpen: boolean;
  onClose: () => void;
  shift: Shift;
  terminalId: string;
  onConfirmAndClose: (
    preview: EndOfDayPreview,
    cashCountPayload: CashCountCommitPayload | null,
  ) => Promise<EndOfDayConfirmResult>;
  onPrintReceipt?: (result: EndOfDayConfirmResult) => void;
  /** When provided, the cash-reconciliation section is rendered. */
  fraudSettings?: CompanyFraudSettings | null;
  /** Set by the production caller once the online-or-cache policy lookup finishes. */
  cashCountPolicyResolved?: boolean;
  authorizedManagers?: AuthorizedManager[];
  cashierUserId?: string;
  onVerifyManagerPin?: (userId: string, pin: string) => Promise<{ valid: boolean }>;
  managerPinThrottle?: { until: string | null; failedAttempts: number };
  onManagerPinThrottleUpdate?: (next: {
    until: string | null;
    failedAttempts: number;
  }) => void;
}

type ModalPhase = 'loading' | 'preview' | 'confirming' | 'success' | 'error';

const NOOP_THROTTLE = { until: null, failedAttempts: 0 } as const;

export function EndOfDayPreviewModal({
  isOpen,
  onClose,
  shift,
  terminalId,
  onConfirmAndClose,
  onPrintReceipt,
  fraudSettings,
  cashCountPolicyResolved,
  authorizedManagers,
  cashierUserId,
  onVerifyManagerPin,
  managerPinThrottle,
  onManagerPinThrottleUpdate,
}: EndOfDayPreviewModalProps) {
  const { t } = useTranslation('pos');
  const { format, currency } = useCurrency();

  const [phase, setPhase] = useState<ModalPhase>('loading');
  const [preview, setPreview] = useState<EndOfDayPreview | null>(null);
  const [result, setResult] = useState<EndOfDayConfirmResult | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Cash-count flow state
  const [cashCountPayload, setCashCountPayload] = useState<CashCountCommitPayload | null>(null);
  const [cashCountReady, setCashCountReady] = useState<boolean>(false);
  const [cashCountsCommitted, setCashCountsCommitted] = useState<boolean>(false);

  // Guard: once confirmation begins, block backdrop dismiss
  const isConfirmingRef = useRef(false);

  const handleClose = useCallback(() => {
    if (isConfirmingRef.current) return;
    onClose();
  }, [onClose]);

  // Decide whether the cash-reconciliation section should render. All of the
  // optional props must be provided to enable it; otherwise we fall back to
  // legacy preview-only mode for backward compatibility with any caller that
  // hasn't been migrated yet.
  const cashCountEnabled =
    fraudSettings != null &&
    authorizedManagers !== undefined &&
    cashierUserId !== undefined &&
    onVerifyManagerPin !== undefined &&
    managerPinThrottle !== undefined &&
    onManagerPinThrottleUpdate !== undefined;
  const cashCountPolicyPending =
    cashCountPolicyResolved !== undefined && !cashCountPolicyResolved;
  const cashCountPolicyUnavailable =
    cashCountPolicyResolved === true && fraudSettings == null;
  // Physical non-cash totals are the exact expected count, and CASH plus the
  // visible opening float can reconstruct expected cash. Keep every payment
  // amount behind the same blind-count commit boundary as the tender table.
  const hideFinancialAmounts =
    cashCountEnabled &&
    fraudSettings?.require_blind_cash_count === true &&
    !cashCountsCommitted;

  // Load preview data when modal opens
  useEffect(() => {
    if (!isOpen) {
      // Reset state when modal closes
      setPhase('loading');
      setPreview(null);
      setResult(null);
      setErrorMessage(null);
      setCashCountPayload(null);
      setCashCountReady(false);
      setCashCountsCommitted(false);
      isConfirmingRef.current = false;
      return;
    }

    let cancelled = false;

    const loadPreview = async () => {
      setPhase('loading');
      try {
        const { getDatabase } = await import('@/lib/db');
        const { useAuthStore } = await import('@/stores/authStore');
        const companyId = useAuthStore.getState().companyId;
        if (!companyId) throw new Error('No company selected');
        const db = await getDatabase(companyId);
        const { buildEndOfDayPreview } = await import('@/lib/offline/endOfDayPreview');
        const data = await buildEndOfDayPreview(
          db,
          terminalId,
          shift.opened_at,
          shift.opening_cash,
          undefined, // currencyCode — fall back to company currency
          shift.id, // fold cash drawer deposits/payouts into expected_cash
        );
        if (!cancelled) {
          setPreview(data);
          setPhase('preview');
        }
      } catch (err) {
        if (!cancelled) {
          const msg = err instanceof Error ? err.message : String(err);
          setErrorMessage(msg);
          setPhase('error');
        }
      }
    };

    void loadPreview();
    return () => { cancelled = true; };
  }, [isOpen, terminalId, shift.opened_at, shift.opening_cash, shift.id]);

  const handleConfirm = useCallback(async () => {
    if (!preview) return;
    isConfirmingRef.current = true;
    setPhase('confirming');
    try {
      const confirmResult = await onConfirmAndClose(
        preview,
        cashCountEnabled ? cashCountPayload : null,
      );
      setResult(confirmResult);
      setPhase('success');
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      setErrorMessage(msg);
      setPhase('error');
      isConfirmingRef.current = false;
    }
  }, [preview, onConfirmAndClose, cashCountEnabled, cashCountPayload]);

  const title = phase === 'success'
    ? t('reports.endOfDay.successTitle')
    : t('reports.endOfDay.title');

  // Confirm gating:
  //   - legacy (cashCountEnabled = false): always enabled when not confirming.
  //   - cash-count (cashCountEnabled = true): requires `cashCountReady`.
  const confirmDisabled =
    phase === 'confirming' || (cashCountEnabled && !cashCountReady);

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={title} size="full">
      {phase === 'loading' && (
        <div className="flex flex-col items-center gap-3 py-12">
          <Loader2 className="h-8 w-8 animate-spin text-action" />
          <p className="text-sm text-ink-muted">{t('reports.loading')}</p>
        </div>
      )}

      {phase === 'preview' && cashCountPolicyPending && (
        <div className="flex flex-col items-center gap-3 py-12">
          <Loader2 className="h-8 w-8 animate-spin text-action" />
          <p className="text-sm text-ink-muted">{t('reports.loading')}</p>
        </div>
      )}

      {(phase === 'error' || (phase === 'preview' && cashCountPolicyUnavailable)) && (
        <div className="flex flex-col items-center gap-3 py-8">
          <AlertCircle className="h-10 w-10 text-danger" />
          <p className="text-center text-sm text-danger-strong">
            {cashCountPolicyUnavailable
              ? t('cash_count.policy_unavailable', {
                  defaultValue:
                    'Cannot close this shift: the cash-count policy has not been synced to this device. Connect to the network once, then retry the close.',
                })
              : errorMessage}
          </p>
          <button
            onClick={handleClose}
            className="mt-4 rounded-ctl border border-border-strong px-6 py-2.5 text-sm font-semibold text-ink-muted hover:bg-surface-sunken"
          >
            {t('reports.endOfDay.cancel')}
          </button>
        </div>
      )}

      {(phase === 'preview' || phase === 'confirming') &&
        preview !== null &&
        !cashCountPolicyPending &&
        !cashCountPolicyUnavailable && (
        <div className="space-y-5">
          {/* Cash Reconciliation (new) — appears at the top when enabled */}
          {cashCountEnabled && (
            <CashReconciliationSection
              preview={preview}
              fraudSettings={fraudSettings}
              authorizedManagers={authorizedManagers}
              cashierUserId={cashierUserId}
              currencyCode={currency}
              onVerifyManagerPin={onVerifyManagerPin}
              managerPinThrottle={managerPinThrottle ?? NOOP_THROTTLE}
              onManagerPinThrottleUpdate={onManagerPinThrottleUpdate}
              onChange={(payload, ready) => {
                setCashCountPayload(payload);
                setCashCountReady(ready);
              }}
              onCommit={() => setCashCountsCommitted(true)}
            />
          )}

          {/* Subtitle */}
          <p className="text-sm text-ink-muted">{t('reports.endOfDay.subtitle')}</p>

          {/* Shift info */}
          <div className="flex items-center justify-between text-xs text-ink-muted">
            <span>{t('shift.number', { number: shift.shift_number })}</span>
            <span>
              {new Date(shift.opened_at).toLocaleString()}
            </span>
          </div>

          {/* Totals */}
          <div className="grid grid-cols-4 gap-3">
            <SummaryCard label={t('reports.endOfDay.salesCount')} value={String(preview.sales_count)} />
            <SummaryCard
              label={t('reports.endOfDay.grossSales')}
              value={hideFinancialAmounts ? '—' : format(preview.gross_sales)}
            />
            <SummaryCard
              label={t('reports.endOfDay.netSales')}
              value={hideFinancialAmounts ? '—' : format(preview.net_sales)}
            />
            <SummaryCard
              label={t('reports.endOfDay.taxAmount')}
              value={hideFinancialAmounts ? '—' : format(preview.tax_amount)}
            />
          </div>

          {/* Cash reconciliation summary card (legacy parity). SECURITY: this
              card shows expected_cash unconditionally, which would DEFEAT the
              blind cash count (the operator must not see the expected total
              before entering physical counts). When the cash-count section is
              enabled it owns the blind-aware expected/variance reveal, so this
              redundant card MUST NOT render — otherwise it leaks the expected
              total. Only show it when there is no cash-count reconciliation. */}
          {!cashCountEnabled && (
            <div className="rounded-card border border-action-subtle bg-action-subtle p-5">
              <h4 className="mb-3 text-sm font-semibold text-action-strong">
                {t('reports.endOfDay.cashReconciliation')}
              </h4>
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <p className="text-xs text-action">{t('reports.endOfDay.openingCash')}</p>
                  <p className="mt-0.5 text-lg font-bold text-action-strong">{format(preview.opening_cash)}</p>
                </div>
                <div>
                  <p className="text-xs text-action">{t('reports.endOfDay.expectedCash')}</p>
                  <p className="mt-0.5 text-lg font-bold text-action-strong">{format(preview.expected_cash)}</p>
                </div>
              </div>
            </div>
          )}

          {/* VAT Breakdown + Payment Methods side by side */}
          <div className="grid grid-cols-2 gap-5">
            {preview.vat_breakdown.length > 0 && (
              <div>
                <h4 className="mb-2 text-sm font-semibold text-ink-muted">
                  {t('reports.endOfDay.vatBreakdown')}
                </h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border-subtle text-left text-xs text-ink-muted">
                      <th className="pb-2">{t('reports.vatRate')}</th>
                      <th className="pb-2 text-right">{t('reports.vatNet')}</th>
                      <th className="pb-2 text-right">{t('reports.vatVat')}</th>
                      <th className="pb-2 text-right">{t('reports.vatGross')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.vat_breakdown.map((row) => (
                      <tr key={row.tax_rate} className="border-b border-border-subtle">
                        <td className="py-2">{formatPercent(row.tax_rate)}</td>
                        <td className="py-2 text-right">
                          {hideFinancialAmounts ? '—' : format(row.net_amount)}
                        </td>
                        <td className="py-2 text-right">
                          {hideFinancialAmounts ? '—' : format(row.vat_amount)}
                        </td>
                        <td className="py-2 text-right">
                          {hideFinancialAmounts ? '—' : format(row.gross_amount)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {preview.payment_methods.length > 0 && (
              <div>
                <h4 className="mb-2 text-sm font-semibold text-ink-muted">
                  {t('reports.endOfDay.payments')}
                </h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border-subtle text-left text-xs text-ink-muted">
                      <th className="pb-2">{t('reports.paymentType')}</th>
                      <th className="pb-2 text-right">{t('reports.paymentCount')}</th>
                      <th className="pb-2 text-right">{t('reports.paymentAmount')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.payment_methods.map((row) => (
                      <tr key={row.payment_method_code} className="border-b border-border-subtle">
                        <td className="py-2">{row.payment_method_code}</td>
                        <td className="py-2 text-right">{row.transaction_count}</td>
                        <td className="py-2 text-right">
                          {hideFinancialAmounts ? '—' : format(row.total_amount)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {/* Tolerance write-off row — renders only when v2 session ships live data (writeoffCount > 0).
              Task 1 emits zero-shape (writeoffCount = 0), so this is intentionally hidden until
              payment-tolerance v2 goes live per coordination contract v1.1. */}
          {preview.tolerance_summary !== null &&
            preview.tolerance_summary.writeoffCount > 0 && (
              <ToleranceDrillDown
                shiftId={shift.id}
                totalAmount={preview.tolerance_summary.totalAmount}
                writeoffCount={preview.tolerance_summary.writeoffCount}
                currencyCode={preview.tolerance_summary.currencyCode}
              />
            )}

          {/* Auto-accept budget (spec §8.1) — spent against the per-shift
              limit. Always rendered: "0 / 10" is the useful reading, and
              hiding it would make the unknown case invisible too.

              A NULL count means the preview was built without a shift id, so
              there is no budget row to read. It is rendered as unknown, never
              as headroom: the gate treats a null shift as FULLY SPENT
              (paymentStore's fail-closed inversion), so a "0 / 10" here would
              promise budget the very next short tender is refused. */}
          <div
            className="flex items-center justify-between rounded-sm border border-border-subtle bg-surface-sunken p-3 text-sm"
            data-testid="tolerance-auto-accept-budget"
          >
            <span className="text-ink-muted">
              {t('reports.endOfDay.toleranceAutoAcceptsUsed')}
            </span>
            <span className="font-semibold text-ink">
              {preview.tolerance_auto_accept_count === null
                ? t('reports.endOfDay.toleranceAutoAcceptsUnknown', {
                    limit: TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT,
                  })
                : t('reports.endOfDay.toleranceAutoAcceptsValue', {
                    used: preview.tolerance_auto_accept_count,
                    limit: TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT,
                  })}
            </span>
          </div>

          {/* Net cash rounding (spec §4.3) — LOCAL observability. Absent, not
              zero, on a shift where nothing rounded: the builder returns null
              precisely so this row does not appear. */}
          {preview.cash_rounding_summary !== null && (
            <div
              className="flex items-center justify-between rounded-sm border border-border-subtle bg-surface-sunken p-3 text-sm"
              data-testid="cash-rounding-summary"
            >
              <span className="text-ink-muted">
                {t('reports.endOfDay.netCashRounding')}
              </span>
              <span className="font-semibold text-ink">
                {/* format() keeps the sign — the adjustment is signed and a
                    round-down must not read as a round-up. */}
                {format(preview.cash_rounding_summary.totalAdjustment)}
                {' '}
                <span className="text-ink-muted">
                  ({preview.cash_rounding_summary.receiptCount})
                </span>
              </span>
            </div>
          )}

          {/* Bottom bar */}
          <div className="flex gap-3 pt-2">
            <button
              onClick={handleClose}
              disabled={phase === 'confirming'}
              className="flex min-h-[48px] items-center justify-center flex-1 rounded-ctl border border-border-strong bg-surface-raised px-4 py-3 text-sm font-semibold text-ink-muted transition-colors hover:bg-surface-sunken disabled:cursor-not-allowed disabled:opacity-50"
            >
              {t('reports.endOfDay.cancel')}
            </button>
            <button
              onClick={() => void handleConfirm()}
              disabled={confirmDisabled}
              aria-label={t('reports.endOfDay.confirmLabel')}
              data-testid="end-of-day-confirm-button"
              className="flex min-h-[48px] items-center justify-center flex-1 rounded-ctl bg-danger px-4 py-3 text-sm font-semibold text-ink-inverse transition-colors hover:bg-danger-strong disabled:cursor-not-allowed disabled:opacity-75"
            >
              {phase === 'confirming' ? (
                <span className="flex items-center justify-center gap-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('reports.endOfDay.confirming')}
                </span>
              ) : (
                t('reports.endOfDay.confirmLabel')
              )}
            </button>
          </div>
        </div>
      )}

      {phase === 'success' && result !== null && (
        <div className="flex flex-col items-center gap-4 py-8">
          <CheckCircle className="h-16 w-16 text-success" />

          <div className="text-center">
            <p className="mt-2 text-sm text-ink-muted">
              {t('reports.endOfDay.successZNumber')}: {' '}
              <span className="rounded-sm bg-action-subtle px-2 py-0.5 font-mono font-bold text-action-strong">
                {result.formattedZNumber}
              </span>
            </p>
            {result.wasReused && (
              <p className="mt-2 text-xs text-warning-strong">
                {t('reports.endOfDay.successReused')}
              </p>
            )}
          </div>

          <div className="mt-4 flex gap-3">
            {onPrintReceipt && (
              <button
                onClick={() => onPrintReceipt(result)}
                className="flex items-center gap-2 rounded-ctl border border-border-strong bg-surface-raised px-6 py-2.5 text-sm font-semibold text-ink-muted hover:bg-surface-sunken"
              >
                <Printer className="h-4 w-4" />
                {t('reports.endOfDay.printReceipt')}
              </button>
            )}
            <button
              onClick={onClose}
              className="flex min-h-[48px] items-center justify-center rounded-ctl bg-action px-6 py-2.5 text-sm font-semibold text-ink-inverse hover:bg-action-hover"
            >
              {t('reports.endOfDay.done')}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-tile bg-surface-sunken px-4 py-3 text-center">
      <p className="text-xs text-ink-muted">{label}</p>
      <p className="mt-1 text-lg font-bold text-ink">{value}</p>
    </div>
  );
}
