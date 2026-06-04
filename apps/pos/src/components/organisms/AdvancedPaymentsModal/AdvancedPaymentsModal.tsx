import { useState, useMemo, useCallback, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import type Database from '@tauri-apps/plugin-sql';
import {
  Banknote,
  CreditCard,
  FileText,
  Building2,
  Wallet,
  Trash2,
  AlertTriangle,
  ArrowLeft,
  Ticket,
  UserRound,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useCurrency } from '@/lib/currency';
import { bcformat } from '@/lib/decimal';
import { NumPad } from '@/components/molecules/NumPad';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import { requiresInstrumentForMethodCode } from '@/lib/payment/paymentMethodKind';
import { VoucherTenderModal } from '@/components/pos/VoucherTenderModal';
import { AccountChargeConfirmation } from '@/components/customers/AccountChargeConfirmation';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import { usePaymentStore, type AdvancedPaymentLine } from '@/stores/paymentStore';
import { useAuthStore } from '@/stores/authStore';
import type { AccountChargeOverrideApprovalInput } from '@/lib/accountCharge/accountChargeService';

const METHOD_ICONS: Record<string, typeof Banknote> = {
  CASH: Banknote,
  ESPECES: Banknote,
  CARD: CreditCard,
  CARTE: CreditCard,
  CB: CreditCard,
  CHECK: FileText,
  CHEQUE: FileText,
  TRANSFER: Building2,
  VIREMENT: Building2,
  MOBILE: Wallet,
};

function getMethodIcon(method: PaymentMethod) {
  const code = method.code?.toUpperCase() ?? '';
  return METHOD_ICONS[code] ?? Wallet;
}

function getCompatibleRepositoryTypes(
  method: PaymentMethod,
): PaymentRepository['type'][] {
  if (method.is_physical && !method.has_maturity) {
    return ['cash_register', 'safe'];
  }
  if (method.requires_third_party) {
    return ['bank_account', 'virtual'];
  }
  if (method.has_maturity) {
    return ['safe', 'bank_account'];
  }
  return ['cash_register', 'safe', 'bank_account', 'virtual'];
}

interface PaymentLineItem {
  id: string;
  methodId: string;
  methodName: string;
  amount: number;
  repositoryId: string;
  repositoryName: string;
  reference: string;
  cardLastFour: string;
}

export interface AdvancedPaymentsModalProps {
  isOpen: boolean;
  onClose: () => void;
  total: number;
  paymentMethods: PaymentMethod[];
  paymentRepositories: PaymentRepository[];
  onComplete: (
    payments: AdvancedPaymentLine[],
    options?: { tenderTolerancePin?: string },
  ) => Promise<void>;
  isProcessing: boolean;
  error: string | null;
  /**
   * Codex review B5 (2026-05-01): SQLite handle for VoucherTenderModal's
   * local-first lookup. When the cashier taps a `store_voucher` /
   * `restaurant_voucher` / `gift_card` tile, this modal opens
   * VoucherTenderModal which calls `findByCode(db, code)` against the
   * tenant-scoped DB. When omitted (e.g. tests that don't exercise the
   * voucher mount path, or pre-shift terminals where the DB isn't open
   * yet), the modal falls back to the B4 dead-end message rather than
   * silently no-op'ing — that preserves the B4 contract that ANY user
   * feedback is required on the tap. Mount path: HomePage passes
   * `getDatabase(companyId)` once `companyId` is non-null.
   */
  voucherDb?: Database | null;
  /**
   * Task 5 (2026-06-04): whole-cart charge-to-account handler. When supplied
   * AND an eligible customer (charge_account_enabled) is attached with a
   * positive total, the modal renders a synthetic "On Account" tile. Tapping
   * it switches the modal into account-charge mode, replacing the split-tender
   * working area with the credit-decision confirmation. A charge-to-account
   * collects NO payment and is mutually exclusive with tenders, so it never
   * mixes with the `onComplete` tender path. The optional override approval is
   * captured by AccountChargeConfirmation (manager-PIN gated) and forwarded to
   * the store's `processAccountCharge` action by the parent.
   */
  onChargeToAccount?: (
    overrideApproval?: AccountChargeOverrideApprovalInput | null,
  ) => Promise<void>;
}

export function AdvancedPaymentsModal({
  isOpen,
  onClose,
  total,
  paymentMethods,
  paymentRepositories,
  onComplete,
  isProcessing,
  error,
  voucherDb = null,
  onChargeToAccount,
}: AdvancedPaymentsModalProps) {
  const { t } = useTranslation('pos');
  const { format, decimals, currency } = useCurrency();
  const dialogRef = useRef<HTMLDivElement>(null);
  useFocusTrap({ isActive: isOpen, containerRef: dialogRef });

  // Codex review B5 (2026-05-01): tracks whether the dedicated
  // VoucherTenderModal scan/lookup overlay is open. Open only when the
  // cashier taps an instrument-bearing tile (store_voucher, etc.) AND the
  // parent supplied a DB handle. Re-opening is idempotent.
  const [isVoucherTenderModalOpen, setIsVoucherTenderModalOpen] = useState(false);
  const [paymentLines, setPaymentLines] = useState<PaymentLineItem[]>([]);
  const [selectedMethodId, setSelectedMethodId] = useState<string | null>(null);
  const [amount, setAmount] = useState('');
  const [repositoryId, setRepositoryId] = useState('');
  const [reference, setReference] = useState('');
  const [cardLastFour, setCardLastFour] = useState('');
  const [tenderTolerancePin, setTenderTolerancePin] = useState('');
  const [validationError, setValidationError] = useState<string | null>(null);

  // B3-followup audit (Finding 1, 2026-05-01): voucher tender rows live in
  // paymentStore (added by VoucherTenderModal). They MUST surface in this
  // modal's payments list AND merge into the AdvancedPaymentLine[] we hand
  // to `onComplete`, otherwise B3's writer plumbing (instrument_type +
  // instrument_serial all the way to the v3 fiscal hash) has no live UI
  // consumer and a voucher-bearing sale would seal a v3 receipt with a null
  // serial in the hash — exactly the original B3 production bug.
  const voucherTenders = usePaymentStore((s) => s.voucherTenders);
  const removeVoucherPayment = usePaymentStore((s) => s.removeVoucherPayment);

  // Task 5 (2026-06-04): charge-to-account is whole-cart and collects no
  // payment, so it cannot be mixed with tenders. The "On Account" tile is only
  // offered when the parent wired `onChargeToAccount`, an eligible customer is
  // attached (charge_account_enabled), and the total is positive. Tapping it
  // flips `accountChargeMode`, which swaps the tender working area for the
  // credit-decision confirmation while keeping the dialog shell unchanged.
  const selectedCustomer = usePaymentStore((s) => s.selectedCustomer);
  const [accountChargeMode, setAccountChargeMode] = useState(false);
  const chargeEligible =
    !!onChargeToAccount
    && selectedCustomer != null
    && (selectedCustomer.charge_account_enabled === true
      || selectedCustomer.charge_account_enabled === 1)
    && total > 0;
  const cashierUserId = useAuthStore.getState().user?.id ?? '';

  const activeMethods = useMemo(
    () => paymentMethods.filter((m) => m.is_active),
    [paymentMethods],
  );

  // B3-followup audit (Finding 1, 2026-05-01): the store_voucher PaymentMethod
  // is the contractual sink for voucher tenders — see spec §6.5 and the
  // canonical hash fixture at `08-store-voucher-binding-eur.json`. Match by
  // immutable code (lowercase or uppercase). Returns null if the tenant's
  // payment-method seed has not yet shipped store_voucher; in that case we
  // surface a validation error on Complete rather than silently drop the row.
  const storeVoucherMethod = useMemo(
    () => activeMethods.find((m) => m.code.toLowerCase() === 'store_voucher') ?? null,
    [activeMethods],
  );

  // Voucher tenders need a payment_repository_id at the API boundary
  // (`payments.*.repository_id` is required uuid). Vouchers are virtual money
  // — no physical till, no bank account — so a `virtual` repository is
  // required. No silent fallback to bank_account: routing a voucher tender
  // through a bank-account repository ID would post the voucher liability
  // redemption against the bank-account GL journal, causing reconciliation
  // drift (fiscal hash remains correct, but bookkeeping does not).
  // B3-followup audit (Minor 2, 2026-05-01): bank_account fallback removed.
  // If no virtual repo is configured the `voucherRepositoryMissing` error
  // fires at handleComplete() time — clear cashier message, no silent GL drift.
  const voucherRepository = useMemo(
    () =>
      paymentRepositories.find((r) => r.is_active && r.type === 'virtual') ?? null,
    [paymentRepositories],
  );

  const selectedMethod = useMemo(
    () => activeMethods.find((m) => m.id === selectedMethodId) ?? null,
    [activeMethods, selectedMethodId],
  );

  const compatibleRepositories = useMemo(() => {
    if (!selectedMethod) return [];
    const types = getCompatibleRepositoryTypes(selectedMethod);
    return paymentRepositories.filter(
      (r) => r.is_active && types.includes(r.type),
    );
  }, [selectedMethod, paymentRepositories]);

  // Auto-select repository when only one compatible
  const effectiveRepositoryId = useMemo(() => {
    if (repositoryId) return repositoryId;
    if (compatibleRepositories.length === 1) return compatibleRepositories[0]!.id;
    return '';
  }, [repositoryId, compatibleRepositories]);

  // B3-followup audit (Finding 1, 2026-05-01): voucher tender amounts must
  // count toward `totalPaid` so the modal's running balance, "Pay Remaining"
  // pill, change-due indicator, and "fully paid" gate all reflect the true
  // tender state. Stored as decimal strings — convert to float for the same
  // arithmetic the rest of the modal uses.
  const voucherTotal = useMemo(
    () =>
      voucherTenders.reduce(
        (sum, v) => sum + (Number.parseFloat(v.amount) || 0),
        0,
      ),
    [voucherTenders],
  );

  const totalPaid = useMemo(
    () => paymentLines.reduce((sum, l) => sum + l.amount, 0) + voucherTotal,
    [paymentLines, voucherTotal],
  );

  const remaining = Math.max(0, total - totalPaid);
  const overpayment = Math.max(0, totalPaid - total);
  const isFullyPaid = totalPaid >= total;
  const canSubmitWithTenderTolerance = remaining > 0 && totalPaid > 0 && tenderTolerancePin.trim() !== '';
  const canComplete = isFullyPaid || canSubmitWithTenderTolerance;

  // Codex review B4 (2026-04-30) UI half: tapping an instrument-bearing
  // payment method tile (store_voucher / restaurant_voucher / gift_card per
  // the PaymentInstrumentKind enum on the server, mirrored in
  // `lib/payment/paymentMethodKind.ts`) MUST NOT enter the free-form
  // PaymentLineItem flow. A free-form line carries no instrument fields and
  // the server's StoreReceiptPaymentsRequest validator would reject the
  // submission with 422 — but more importantly, it would let a v3 receipt
  // bind `method_code = store_voucher, instrument_serial = null` if the
  // server enforcement ever drifted. Routing here through the dedicated
  // voucher tender flow means voucher tenders ALWAYS land via
  // `paymentStore.voucherTenders` with the voucher code as instrument_serial.
  //
  // Codex review B5 (2026-05-01): wire the dedicated VoucherTenderModal
  // mount. When the cashier taps a `store_voucher` tile AND the parent
  // supplied a `voucherDb` handle, open VoucherTenderModal so the cashier
  // can scan/type the voucher code, see balance + expiry, and apply against
  // the remaining due. The modal calls `addVoucherPayment(code, amount)` on
  // the store (handled inside VoucherTenderModal's onApply path), so a
  // tender row appears in this modal's payment list as soon as the voucher
  // modal closes.
  //
  // B5-fix audit Minor 1 (2026-05-01): Phase 1 only fully wires
  // `store_voucher` end-to-end. Restaurant_voucher and gift_card tiles are
  // also instrument-bearing per `requiresInstrumentForMethodCode` (so the
  // tap MUST NOT enter the free-form payment-line flow), but their
  // settlement / GL paths are Phase 2+. We surface a clear "not yet
  // supported in Phase 1" message at the tap handler instead of opening
  // the half-broken VoucherTenderModal (which hardcodes voucher_kind = MPV
  // — restaurant tickets and gift cards are not in the local `vouchers`
  // table). This matches the audit recommendation.
  //
  // Fallback: when `voucherDb` is null (rare — pre-shift, no companyId, or
  // a test that doesn't exercise this path), keep the B4 dead-end message
  // so the cashier sees actionable feedback rather than a silent no-op.
  const [voucherTenderMethodCode, setVoucherTenderMethodCode] = useState<
    'store_voucher' | 'restaurant_voucher' | 'gift_card'
  >('store_voucher');

  const handleSelectMethod = useCallback(
    (methodId: string) => {
      const tappedMethod = activeMethods.find((m) => m.id === methodId);
      if (
        tappedMethod !== undefined
        && requiresInstrumentForMethodCode(tappedMethod.code ?? '')
      ) {
        // Clear any in-progress free-form selection state so a previously
        // selected cash/card row's config UI disappears the moment the user
        // pivots to a voucher tile.
        setSelectedMethodId(null);
        setAmount('');
        setRepositoryId('');
        setReference('');
        setCardLastFour('');

        const tappedCode = (tappedMethod.code ?? '').toLowerCase();

        // B5-fix audit Minor 1: Phase 1 boundary — only store_voucher is
        // fully wired. Restaurant tickets and gift cards surface a clear
        // "not yet supported" message rather than opening a half-broken
        // modal that would always show "Voucher not found" because those
        // instruments don't enter the local vouchers table.
        if (tappedCode !== 'store_voucher') {
          setValidationError(t('advancedPayments.voucherKindNotSupportedInPhase1'));
          return;
        }

        if (voucherDb !== null) {
          setValidationError(null);
          setVoucherTenderMethodCode('store_voucher');
          setIsVoucherTenderModalOpen(true);
        } else {
          setValidationError(t('advancedPayments.voucherTenderFlowRequired'));
        }

        return;
      }

      setSelectedMethodId(methodId);
      setAmount(remaining > 0 ? remaining.toFixed(decimals) : '');
      setRepositoryId('');
      setReference('');
      setCardLastFour('');
      setValidationError(null);
    },
    [activeMethods, remaining, decimals, t, voucherDb],
  );

  const handlePayRemaining = useCallback(() => {
    if (remaining > 0) {
      setAmount(remaining.toFixed(decimals));
    }
  }, [remaining, decimals]);

  const handleAddPayment = useCallback(() => {
    if (!selectedMethod) {
      setValidationError(t('advancedPayments.methodRequired'));
      return;
    }

    const parsedAmount = parseFloat(amount);
    if (!amount || isNaN(parsedAmount) || parsedAmount <= 0) {
      setValidationError(t('advancedPayments.amountRequired'));
      return;
    }

    if (!effectiveRepositoryId) {
      setValidationError(t('advancedPayments.repositoryRequired'));
      return;
    }

    const repo = paymentRepositories.find((r) => r.id === effectiveRepositoryId);

    const line: PaymentLineItem = {
      id: crypto.randomUUID(),
      methodId: selectedMethod.id,
      methodName: selectedMethod.name,
      amount: parsedAmount,
      repositoryId: effectiveRepositoryId,
      repositoryName: repo?.name ?? '',
      reference,
      cardLastFour,
    };

    setPaymentLines((prev) => [...prev, line]);
    setSelectedMethodId(null);
    setAmount('');
    setRepositoryId('');
    setReference('');
    setCardLastFour('');
    setValidationError(null);
  }, [
    selectedMethod,
    amount,
    effectiveRepositoryId,
    paymentRepositories,
    reference,
    cardLastFour,
    t,
  ]);

  const handleRemoveLine = useCallback((lineId: string) => {
    setPaymentLines((prev) => prev.filter((l) => l.id !== lineId));
  }, []);

  const handleComplete = useCallback(async () => {
    if (!isFullyPaid && !canSubmitWithTenderTolerance) {
      setValidationError(t('advancedPayments.insufficientPayment'));
      return;
    }

    // B3-followup audit (Finding 1, 2026-05-01): if any voucher tenders are
    // present, the tenant MUST have a `store_voucher` PaymentMethod and a
    // virtual / bank-account repository for the tender to land at the wire
    // boundary. Surface a clear error rather than emitting a malformed
    // AdvancedPaymentLine that the server would 422 on.
    if (voucherTenders.length > 0) {
      if (!storeVoucherMethod) {
        setValidationError(t('advancedPayments.voucherMethodMissing'));
        return;
      }
      if (!voucherRepository) {
        setValidationError(t('advancedPayments.voucherRepositoryMissing'));
        return;
      }
    }

    const cashAndCardPayments: AdvancedPaymentLine[] = paymentLines.map((l) => ({
      payment_method_id: l.methodId,
      // Canonicalize the display-math float to a currency-scale string at the
      // wire boundary so the amount enters the fiscal hash without IEEE-754
      // jitter (F-FRONTEND-VOUCHER / precision remediation Phase 10.1).
      amount: bcformat(String(l.amount), decimals),
      repository_id: l.repositoryId,
      ...(l.cardLastFour ? { card_last_four: l.cardLastFour } : {}),
      ...(l.reference ? { transaction_reference: l.reference } : {}),
    }));

    // B3-followup audit (Finding 1, 2026-05-01): convert each VoucherTenderRow
    // into an AdvancedPaymentLine with `instrument_type: 'store_voucher'` and
    // `instrument_serial: <voucher.code>`. Without this branch, B3's writer
    // plumbing has no live UI consumer and the v3 fiscal hash binds a null
    // serial — the original B3 production bug at the entry point. Spec §6.5.
    const voucherPayments: AdvancedPaymentLine[] = storeVoucherMethod && voucherRepository
      ? voucherTenders.map((v) => ({
        payment_method_id: storeVoucherMethod.id,
        // F-FRONTEND-VOUCHER: forward the canonical string verbatim — the
        // voucher amount is bound to the fiscal-event hash, so a parseFloat
        // round-trip here would inject IEEE-754 jitter into the hash input.
        amount: v.amount,
        repository_id: voucherRepository.id,
        instrument_type: 'store_voucher',
        instrument_serial: v.code,
      }))
      : [];

    await onComplete(
      [...cashAndCardPayments, ...voucherPayments],
      canSubmitWithTenderTolerance
        ? { tenderTolerancePin: tenderTolerancePin.trim() }
        : undefined,
    );
  }, [
    isFullyPaid,
    canSubmitWithTenderTolerance,
    tenderTolerancePin,
    paymentLines,
    onComplete,
    t,
    voucherTenders,
    storeVoucherMethod,
    voucherRepository,
    decimals,
  ]);

  const handleClose = useCallback(() => {
    if (isProcessing) return;
    setPaymentLines([]);
    setSelectedMethodId(null);
    setAmount('');
    setRepositoryId('');
    setReference('');
    setCardLastFour('');
    setTenderTolerancePin('');
    setValidationError(null);
    setIsVoucherTenderModalOpen(false);
    // Task 5: leave account-charge mode so a reopen starts in split-tender mode.
    setAccountChargeMode(false);
    onClose();
  }, [isProcessing, onClose]);

  // Codex review B5 (2026-05-01): VoucherTenderModal calls onApplied AFTER it
  // has invoked `addVoucherPayment(code, amount)` on the paymentStore. The
  // tender row already lives in `paymentStore.voucherTenders` by that point;
  // this callback only needs to dismiss the voucher overlay so the cashier
  // sees the row appear in this modal's payment list and can either scan
  // another voucher (open it again), pay the rest with cash/card, or hit
  // Complete.
  const handleVoucherApplied = useCallback(() => {
    setIsVoucherTenderModalOpen(false);
  }, []);

  const handleVoucherTenderModalClose = useCallback(() => {
    setIsVoucherTenderModalOpen(false);
  }, []);

  const showReference = selectedMethod?.has_maturity || selectedMethod?.requires_third_party;
  const showCardLastFour = selectedMethod?.requires_third_party;

  if (!isOpen) return null;

  return (
    <div
      ref={dialogRef}
      role="dialog"
      aria-modal="true"
      aria-labelledby="advanced-payments-title"
      data-testid="advanced-payments-dialog"
      className="fixed inset-0 z-50 flex flex-col bg-gray-50 text-gray-900"
    >
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
        <button
          onClick={handleClose}
          className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('advancedPayments.back')}
        </button>
        <div id="advanced-payments-title" className="flex items-center gap-2 text-lg font-bold">
          <Wallet className="h-5 w-5" />
          {t('advancedPayments.title')}
        </div>
        <div className="w-20" />
      </div>

      {/* Main 3-column layout */}
      <div className="flex min-h-0 flex-1 overflow-hidden">
        {/* LEFT COLUMN (30%): Method selection + config */}
        <div className="flex w-[30%] flex-col border-r border-gray-200 bg-white p-4">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
            {t('advancedPayments.selectMethod')}
          </p>
          <div className="flex flex-col gap-2">
            {activeMethods.map((method) => {
              const Icon = getMethodIcon(method);
              const isSelected = selectedMethodId === method.id;
              // Codex review B4 (2026-04-30): mark instrument-bearing tiles
              // (store_voucher / restaurant_voucher / gift_card) so the
              // cashier sees at a glance that tapping them routes through
              // the voucher tender flow rather than the free-form line UI.
              const isInstrumentBearing = requiresInstrumentForMethodCode(
                method.code ?? '',
              );
              return (
                <button
                  key={method.id}
                  onClick={() => handleSelectMethod(method.id)}
                  className={cn(
                    'flex min-h-[52px] items-center gap-3 rounded-xl border-2 px-4 py-3 text-left transition-colors',
                    isSelected
                      ? 'border-primary-500 bg-primary-50 text-primary-700'
                      : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50',
                  )}
                >
                  {isInstrumentBearing ? (
                    <Ticket className="h-5 w-5 shrink-0 text-purple-600" />
                  ) : (
                    <Icon className="h-5 w-5 shrink-0" />
                  )}
                  <span className="text-sm font-medium">{method.name}</span>
                  {isInstrumentBearing && (
                    <span className="ml-auto rounded-full bg-purple-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-wider text-purple-700">
                      {t('advancedPayments.voucherBadge')}
                    </span>
                  )}
                </button>
              );
            })}

            {/*
              Task 5 (2026-06-04): synthetic "On Account" tile, rendered after
              the real payment-method tiles and only when an eligible customer
              is attached with a positive total. It is not a PaymentMethod — a
              charge-to-account collects no tender — so tapping it switches the
              modal into account-charge mode rather than the free-form line flow.
            */}
            {chargeEligible && (
              <button
                type="button"
                data-testid="on-account-tile"
                onClick={() => setAccountChargeMode(true)}
                className={cn(
                  'flex min-h-[52px] items-center gap-3 rounded-xl border-2 px-4 py-3 text-left transition-colors',
                  accountChargeMode
                    ? 'border-primary-500 bg-primary-50 text-primary-700'
                    : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50',
                )}
              >
                <UserRound className="h-5 w-5 shrink-0 text-blue-600" />
                <span className="text-sm font-medium">
                  {t('account_charge.tile', { defaultValue: 'On Account' })}
                </span>
              </button>
            )}
          </div>

          {/* Config fields — shown when method selected */}
          {selectedMethod && (
            <div className="mt-4 space-y-3 border-t border-gray-200 pt-4">
              {/* Repository dropdown */}
              {compatibleRepositories.length > 1 && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-500">
                    {t('advancedPayments.repository')}
                  </label>
                  <select
                    value={effectiveRepositoryId}
                    onChange={(e) => {
                      setRepositoryId(e.target.value);
                      setValidationError(null);
                    }}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                  >
                    <option value="">
                      {t('advancedPayments.selectRepository')}
                    </option>
                    {compatibleRepositories.map((r) => (
                      <option key={r.id} value={r.id}>
                        {r.name}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              {/* Reference field */}
              {showReference && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-500">
                    {t('advancedPayments.reference')}
                  </label>
                  <input
                    type="text"
                    value={reference}
                    onChange={(e) => setReference(e.target.value)}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                  />
                </div>
              )}

              {/* Card last 4 */}
              {showCardLastFour && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-500">
                    {t('advancedPayments.cardLastFour')}
                  </label>
                  <input
                    type="text"
                    maxLength={4}
                    value={cardLastFour}
                    onChange={(e) =>
                      setCardLastFour(e.target.value.replace(/\D/g, ''))
                    }
                    className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                  />
                </div>
              )}
            </div>
          )}

          {/* Add Payment button — pinned to bottom */}
          {selectedMethod && (
            <button
              onClick={handleAddPayment}
              className="mt-auto rounded-xl bg-primary-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-primary-700 active:bg-primary-800"
            >
              {t('advancedPayments.addPayment')}
            </button>
          )}
        </div>

        {/*
          Task 5 (2026-06-04): in account-charge mode the tender working area
          (center NumPad + right balance/Complete columns) is replaced by the
          credit-decision confirmation. The dialog shell and its fixed layout
          are untouched — only this inner region swaps, per the modal-sizing
          rule. A charge-to-account collects no tender, so none of the
          split-tender controls apply while it is active.
        */}
        {accountChargeMode ? (
          <div className="flex flex-1 items-center justify-center bg-gray-50 p-4">
            <AccountChargeConfirmation
              total={String(total)}
              currency={currency}
              cashierUserId={cashierUserId}
              isProcessing={isProcessing}
              onCancel={() => setAccountChargeMode(false)}
              onConfirm={async (o) => {
                await onChargeToAccount!(o);
              }}
            />
          </div>
        ) : (
          <>
        {/* CENTER COLUMN (35%): Amount + NumPad */}
        <div className="flex w-[35%] flex-col bg-gray-50 p-4">
          {/* Amount display */}
          <div className="mb-3 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-gray-500">
              {t('advancedPayments.amount')}
            </p>
            <p className="mt-1 text-3xl font-bold text-gray-900">
              {amount ? format(parseFloat(amount)) : format(0)}
            </p>
          </div>

          {/* Pay Remaining pill */}
          {remaining > 0 && (
            <div className="mb-3 text-center">
              <button
                onClick={handlePayRemaining}
                className="inline-flex rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-700"
              >
                {t('advancedPayments.payRemaining')}: {format(remaining)}
              </button>
            </div>
          )}

          {/* NumPad */}
          <div className="flex-1">
            <NumPad
              value={amount}
              onChange={(val) => {
                setAmount(val);
                setValidationError(null);
              }}
            />
          </div>
        </div>

        {/* RIGHT COLUMN (35%): Balance + Payments list + Complete */}
        <div className="flex w-[35%] flex-col border-l border-gray-200 bg-white p-4">
          {/* Total due card */}
          <div className="mb-4 rounded-xl bg-primary-600 p-4 text-white">
            <p className="text-xs font-medium uppercase tracking-wider opacity-80">
              {t('advancedPayments.totalDue')}
            </p>
            <p className="mt-1 text-3xl font-bold">{format(total)}</p>
          </div>

          {/* Payment lines — scrollable */}
          <div className="mb-3 flex-1 overflow-y-auto">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
              {t('advancedPayments.addedPayments')}
            </p>
            {paymentLines.length === 0 && voucherTenders.length === 0 ? (
              <p className="py-6 text-center text-sm text-gray-500">
                {t('advancedPayments.noPayments')}
              </p>
            ) : (
              <div className="space-y-2">
                {/*
                  B3-followup audit (Finding 1, 2026-05-01): voucher tender
                  rows render alongside cash/card lines so the cashier sees a
                  single tender list. Distinguished by the Ticket icon and
                  the voucher code as the secondary line. Removing a voucher
                  here also calls `removeVoucherPayment` on the store so the
                  duplicate-guard set stays in sync.
                */}
                {voucherTenders.map((v) => (
                  <div
                    key={`voucher-${v.code}`}
                    data-testid={`voucher-tender-row-${v.code}`}
                    className="flex items-center justify-between rounded-lg border border-purple-200 bg-purple-50 px-3 py-2.5"
                  >
                    <div className="flex items-center gap-2">
                      <Ticket className="h-4 w-4 text-purple-700" />
                      <div>
                        <p className="text-sm font-medium text-gray-900">
                          {t('advancedPayments.voucherTenderLabel')}
                        </p>
                        <p className="text-xs text-gray-500">{v.code}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">
                        {format(Number.parseFloat(v.amount) || 0)}
                      </span>
                      <button
                        onClick={() => removeVoucherPayment(v.code)}
                        className="rounded-lg p-1.5 text-red-500 hover:bg-red-50"
                        aria-label={t('advancedPayments.delete')}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
                {paymentLines.map((line) => (
                  <div
                    key={line.id}
                    className="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5"
                  >
                    <div>
                      <p className="text-sm font-medium text-gray-900">
                        {line.methodName}
                      </p>
                      <p className="text-xs text-gray-500">
                        {line.repositoryName}
                        {line.cardLastFour && ` · *${line.cardLastFour}`}
                        {line.reference && ` · ${line.reference}`}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">
                        {format(line.amount)}
                      </span>
                      <button
                        onClick={() => handleRemoveLine(line.id)}
                        className="rounded-lg p-1.5 text-red-500 hover:bg-red-50"
                        aria-label={t('advancedPayments.delete')}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Footer — balance + error + complete */}
          <div className="border-t border-gray-200 pt-3">
            <div className="mb-2 space-y-1 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-500">
                  {t('advancedPayments.totalPaid')}
                </span>
                <span className="font-medium text-gray-900">
                  {format(totalPaid)}
                </span>
              </div>
              {remaining > 0 && (
                <div className="flex justify-between text-orange-600">
                  <span>{t('advancedPayments.remaining')}</span>
                  <span className="font-medium">{format(remaining)}</span>
                </div>
              )}
              {overpayment > 0 && (
                <div className="flex justify-between text-green-600">
                  <span>{t('advancedPayments.changeDue')}</span>
                  <span className="font-medium">{format(overpayment)}</span>
                </div>
              )}
            </div>

            {(validationError || error) && (
              <div className="mb-2 flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                {validationError || error}
              </div>
            )}

            {remaining > 0 && (
              <label className="mb-2 block text-sm">
                <span className="mb-1 block font-medium text-gray-700">
                  {t('advancedPayments.tenderTolerancePinLabel')}
                </span>
                <input
                  type="password"
                  inputMode="numeric"
                  value={tenderTolerancePin}
                  onChange={(event) => {
                    setTenderTolerancePin(event.target.value);
                    setValidationError(null);
                  }}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                />
              </label>
            )}

            <button
              onClick={() => void handleComplete()}
              disabled={!canComplete || isProcessing}
              className="w-full rounded-xl bg-green-600 px-6 py-3.5 text-lg font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isProcessing
                ? t('advancedPayments.processing')
                : t('advancedPayments.completeTransaction')}
            </button>
          </div>
        </div>
          </>
        )}
      </div>

      {/*
        Codex review B5 (2026-05-01): VoucherTenderModal mount. Open only when
        the cashier tapped a `store_voucher` / `restaurant_voucher` /
        `gift_card` tile AND the parent supplied a voucherDb handle. The
        modal scans/types the code, looks it up in local SQLite, validates
        against expiry/balance/customer/duplicate guards, and on apply pushes
        the tender into `paymentStore.voucherTenders`. We pass the modal a
        decimal-string `remainingDue` at the currency's scale so the bcmath
        comparisons inside the modal work correctly (TND scale 3, EUR scale 2).
      */}
      {voucherDb !== null && (
        <VoucherTenderModal
          isOpen={isVoucherTenderModalOpen}
          onClose={handleVoucherTenderModalClose}
          db={voucherDb}
          remainingDue={remaining.toFixed(decimals)}
          currency={currency}
          onApplied={handleVoucherApplied}
          methodCode={voucherTenderMethodCode}
        />
      )}
    </div>
  );
}
