import { useState, useMemo, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { CashCountTable } from './organisms/CashCountTable';
import { ManagerPinPanel } from './molecules/ManagerPinPanel';
import { bcsub, bccomp, bcformat } from '@/lib/decimal';
import { getCurrencyDecimals } from '@/lib/currency';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import { computeCashCountSeverity } from '@/lib/offline/cashCountValidation';

type VarianceStatus = 'info' | 'warning' | 'critical' | 'balanced';
type VarianceDirection = 'over' | 'under' | 'balanced';

export interface CompanyFraudSettings {
  cash_variance_over_soft: string;
  cash_variance_over_hard: string;
  cash_variance_under_soft: string;
  cash_variance_under_hard: string;
  require_blind_cash_count: boolean;
  require_manager_pin_above_hard: boolean;
}

export interface AuthorizedManager {
  id: string;
  name: string;
}

export interface CashCountCommitPayload {
  cashCounts: Array<{
    payment_method_id: string;
    currency_code: string;
    actual_amount: string;
  }>;
  varianceReason: string | null;
  managerUserId: string | null;
  blindCountUsed: boolean;
}

export interface CashReconciliationSectionProps {
  preview: EndOfDayPreview;
  fraudSettings: CompanyFraudSettings;
  authorizedManagers: AuthorizedManager[];
  cashierUserId: string;
  currencyCode: string;
  onVerifyManagerPin: (userId: string, pin: string) => Promise<{ valid: boolean }>;
  onChange: (payload: CashCountCommitPayload, isReady: boolean) => void;
  managerPinThrottle: { until: string | null; failedAttempts: number };
  onManagerPinThrottleUpdate: (next: { until: string | null; failedAttempts: number }) => void;
}

interface BuiltTender {
  payment_method_id: string;
  payment_method_code: string;
  payment_method_name: string;
  is_physical: boolean;
  expected_amount: string;
  transaction_count: number;
  currency_code: string;
}

function severityForVariance(
  absAmount: string,
  soft: string,
  hard: string,
): VarianceStatus {
  if (bccomp(absAmount, '0') === 0) return 'balanced';
  if (bccomp(absAmount, soft) <= 0) return 'info';
  if (bccomp(absAmount, hard) <= 0) return 'warning';
  return 'critical';
}


export function CashReconciliationSection({
  preview,
  fraudSettings,
  authorizedManagers,
  cashierUserId,
  currencyCode,
  onVerifyManagerPin,
  onChange,
  managerPinThrottle,
  onManagerPinThrottleUpdate,
}: CashReconciliationSectionProps) {
  const { t } = useTranslation('pos');
  const blindMode = fraudSettings.require_blind_cash_count;
  const scale = getCurrencyDecimals(currencyCode);

  const [committed, setCommitted] = useState<boolean>(!blindMode);
  const [actuals, setActuals] = useState<Record<string, string>>({});
  const [reason, setReason] = useState<string>('');
  const [verifiedManager, setVerifiedManager] = useState<{ id: string; name: string } | null>(null);

  // E3: Invalidate verifiedManager when actuals change after the manager verified.
  // Snapshot the actuals JSON at the moment of last verification; any subsequent
  // change must clear the badge so the operator must re-verify.
  const lastActualsSnapshotRef = useRef<string>('');
  useEffect(() => {
    const snapshot = JSON.stringify(actuals);
    if (
      verifiedManager !== null &&
      lastActualsSnapshotRef.current !== '' &&
      snapshot !== lastActualsSnapshotRef.current
    ) {
      setVerifiedManager(null);
    }
    lastActualsSnapshotRef.current = snapshot;
  }, [actuals, verifiedManager]);

  // Build tenders from preview's payment_methods, treating CASH expected as expected_cash
  const tenders: BuiltTender[] = useMemo(() => {
    return preview.payment_methods.map((p) => {
      const isCash = p.payment_method_code === 'CASH';
      const expected = isCash ? preview.expected_cash : p.total_amount;
      return {
        payment_method_id: p.payment_method_id,
        payment_method_code: p.payment_method_code,
        payment_method_name: p.payment_method_name ?? p.payment_method_code,
        is_physical: p.is_physical,
        expected_amount: bcformat(expected, scale),
        transaction_count: p.transaction_count,
        currency_code: currencyCode,
      };
    });
  }, [preview, currencyCode, scale]);

  // Compute per-tender variances (for physical tenders that have an actual entry)
  const variances = useMemo(() => {
    const result: Record<
      string,
      { amount: string; direction: VarianceDirection; status: VarianceStatus }
    > = {};

    for (const tender of tenders) {
      if (!tender.is_physical) continue;
      const raw = actuals[tender.payment_method_id];
      if (raw === undefined || raw === '' || raw === '.') continue;

      const diff = bcsub(raw, tender.expected_amount, scale);
      const cmp = bccomp(diff, '0');
      const direction: VarianceDirection =
        cmp === 0 ? 'balanced' : cmp < 0 ? 'under' : 'over';
      const absDiff = cmp < 0 ? bcsub('0', diff, scale) : diff;
      const isOver = direction === 'over';
      const soft = isOver
        ? fraudSettings.cash_variance_over_soft
        : fraudSettings.cash_variance_under_soft;
      const hard = isOver
        ? fraudSettings.cash_variance_over_hard
        : fraudSettings.cash_variance_under_hard;
      const status = severityForVariance(absDiff, soft, hard);
      result[tender.payment_method_id] = {
        amount: bcformat(absDiff, scale),
        direction,
        status,
      };
    }
    return result;
  }, [tenders, actuals, fraudSettings, scale]);

  const physicalTenders = useMemo(
    () => tenders.filter((tender) => tender.is_physical),
    [tenders],
  );

  // Aggregate severity — uses the signed-sum algorithm from cashCountValidation
  // (G12/D1 fix: sum all signed variances first, then classify the net deviation).
  const aggregateSeverity: VarianceStatus = useMemo(() => {
    const rows = physicalTenders
      .map((tender) => {
        const raw = actuals[tender.payment_method_id];
        if (raw === undefined || raw === '' || raw === '.') return null;
        const variance = bcsub(raw, tender.expected_amount, scale);
        return { variance_amount: variance };
      })
      .filter((r): r is { variance_amount: string } => r !== null);

    if (rows.length === 0) return 'balanced';

    return computeCashCountSeverity(
      rows,
      {
        cash_variance_over_soft: fraudSettings.cash_variance_over_soft,
        cash_variance_over_hard: fraudSettings.cash_variance_over_hard,
        cash_variance_under_soft: fraudSettings.cash_variance_under_soft,
        cash_variance_under_hard: fraudSettings.cash_variance_under_hard,
      },
      scale,
    ).severity;
  }, [physicalTenders, actuals, fraudSettings, scale]);
  const allPhysicalFilled = physicalTenders.every((tender) => {
    const raw = actuals[tender.payment_method_id];
    return raw !== undefined && raw !== '' && raw !== '.';
  });

  // Only treat severity as "needs reason" once user has actually entered values for all
  // physical tenders; otherwise the severity could be misleading mid-entry.
  const needsReason =
    allPhysicalFilled &&
    aggregateSeverity !== 'balanced' &&
    aggregateSeverity !== 'info';
  const needsManagerPin =
    allPhysicalFilled &&
    aggregateSeverity === 'critical' &&
    fraudSettings.require_manager_pin_above_hard;

  const reasonOk = !needsReason || reason.trim() !== '';
  const managerOk = !needsManagerPin || verifiedManager !== null;
  const isReady = allPhysicalFilled && committed && reasonOk && managerOk;

  // Notify parent. Use a ref-based stable callback reference to avoid effect storms.
  const onChangeRef = useRef(onChange);
  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);

  useEffect(() => {
    const cashCounts = physicalTenders
      .map((tender) => ({
        payment_method_id: tender.payment_method_id,
        currency_code: tender.currency_code,
        actual_amount: actuals[tender.payment_method_id] ?? '',
      }))
      .filter((c) => c.actual_amount !== '');

    onChangeRef.current(
      {
        cashCounts,
        varianceReason: needsReason ? reason : null,
        managerUserId: verifiedManager?.id ?? null,
        blindCountUsed: blindMode,
      },
      isReady,
    );
  }, [
    physicalTenders,
    actuals,
    reason,
    verifiedManager,
    needsReason,
    blindMode,
    isReady,
  ]);

  return (
    <div className="space-y-4" data-testid="cash-reconciliation-section">
      <h3 className="text-base font-semibold text-gray-900">
        {t('cash_count.section_title', { defaultValue: 'Cash Reconciliation' })}
      </h3>

      <CashCountTable
        tenders={tenders}
        actuals={actuals}
        onActualChange={(id, val) =>
          setActuals((prev) => ({ ...prev, [id]: val }))
        }
        blindMode={blindMode}
        committed={committed}
        variances={variances}
      />

      {blindMode && !committed && (
        <button
          type="button"
          onClick={() => setCommitted(true)}
          disabled={!allPhysicalFilled}
          data-testid="commit-counts-button"
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400"
        >
          {t('cash_count.commit_counts', { defaultValue: 'Commit Counts' })}
        </button>
      )}

      {committed && needsReason && (
        <div data-testid="variance-reason-section" className="space-y-1">
          <label
            htmlFor="variance-reason"
            className="block text-sm font-medium text-gray-700"
          >
            {t('cash_count.reason_label', {
              defaultValue: 'Variance Reason (required)',
            })}
          </label>
          <textarea
            id="variance-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            data-testid="variance-reason-input"
            maxLength={500}
            rows={3}
            className="w-full rounded-md border border-gray-300 p-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>
      )}

      {committed && needsManagerPin && verifiedManager === null && (
        <div data-testid="manager-pin-section" className="space-y-2">
          <h4 className="text-sm font-medium text-gray-700">
            {t('cash_count.manager_pin.section_title', {
              defaultValue: 'Manager Authorization Required',
            })}
          </h4>
          <ManagerPinPanel
            authorizedManagers={authorizedManagers}
            excludeUserId={cashierUserId}
            onVerify={onVerifyManagerPin}
            onSuccess={(id, name) => setVerifiedManager({ id, name })}
            throttle={managerPinThrottle}
            onThrottleUpdate={onManagerPinThrottleUpdate}
          />
        </div>
      )}

      {verifiedManager !== null && (
        <p
          data-testid="manager-verified"
          className="text-sm font-medium text-green-700"
        >
          {t('cash_count.manager_pin.verified', {
            defaultValue: 'Authorized by: {{name}}',
            name: verifiedManager.name,
          })}
        </p>
      )}
    </div>
  );
}
