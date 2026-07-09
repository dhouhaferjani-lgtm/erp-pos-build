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
import { bcformat, bcadd, bcsub, bccomp, bcsum } from '@/lib/decimal';
import { NumPad } from '@/components/molecules/NumPad';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import { requiresInstrumentForMethodCode } from '@/lib/payment/paymentMethodKind';
import { VoucherTenderModal } from '@/components/pos/VoucherTenderModal';
import { AccountChargeConfirmation } from '@/components/customers/AccountChargeConfirmation';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import { usePaymentStore, type AdvancedPaymentLine } from '@/stores/paymentStore';
import { useAuthStore } from '@/stores/authStore';
import type { AccountChargeOverrideApprovalInput } from '@/lib/accountCharge/accountChargeService';
import type { PosOverrideContext } from '@/lib/operatorApproval/posOverrideAuthoring';

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
  /** Decimal string at the tenant's currency scale, e.g. "50.00". Set via bcformat. */
  amount: string;
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
  /**
   * Scoped-approval context forwarded to AccountChargeConfirmation so the
   * credit-limit / account-status manager override verifies through the
   * canonical scoped (audited) path. Built once by HomePage.
   */
  approvalContext?: PosOverrideContext;
}

/**
 * Pure helper extracted for unit-testability (D0-1, 2026-07-01).
 * Computes tender state using decimal-string bcmath (big.js) — no IEEE-754 float.
 * PaymentLineItem.amount is a decimal string (S4, 2026-07-01); amounts are passed
 * directly to bcsum with no String() bridge.
 */
export function computeTenderState(
  paymentLines: readonly { amount: string }[],
  voucherTenders: readonly { amount: string }[],
  total: number,
  decimals: number,
): { totalPaid: string; remaining: string; overpayment: string; isFullyPaid: boolean } {
  const totalStr = String(total);
  const voucherTotal = bcsum(voucherTenders.map((v) => v.amount), decimals);
  const totalPaid = bcadd(
    bcsum(paymentLines.map((l) => l.amount), decimals),
    voucherTotal,
    decimals,
  );
  const remaining =
    bccomp(totalPaid, totalStr) < 0
      ? bcsub(totalStr, totalPaid, decimals)
      : bcformat('0', decimals);
  const overpayment =
    bccomp(totalPaid, totalStr) > 0
      ? bcsub(totalPaid, totalStr, decimals)
      : bcformat('0', decimals);
  const isFullyPaid = bccomp(totalPaid, totalStr) >= 0;
  return { totalPaid, remaining, overpayment, isFullyPaid };
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
  approvalContext,
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

  // D0-1 (2026-07-01): tender arithmetic via decimal-string bcmath (big.js).
  // computeTenderState is a pure exported function — unit-tested independently.
  const { totalPaid, remaining, overpayment, isFullyPaid } = useMemo(
    () => computeTenderState(paymentLines, voucherTenders, total, decimals),
    [paymentLines, voucherTenders, total, decimals],
  );
  const canSubmitWithTenderTolerance =
    bccomp(remaining, '0') > 0 &&
    bccomp(totalPaid, '0') > 0 &&
    tenderTolerancePin.trim() !== '';
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
      setAmount(bccomp(remaining, '0') > 0 ? remaining : '');
      setRepositoryId('');
      setReference('');
      setCardLastFour('');
      setValidationError(null);
    },
    [activeMethods, remaining, t, voucherDb],
  );

  const handlePayRemaining = useCallback(() => {
    if (bccomp(remaining, '0') > 0) {
      setAmount(remaining);
    }
  }, [remaining]);

  const handleAddPayment = useCallback(() => {
    if (!selectedMethod) {
      setValidationError(t('advancedPayments.methodRequired'));
      return;
    }

    // S4 (2026-07-01): validate without float ingress. Reject empty, non-numeric,
    // or non-positive amounts using a decimal regex + bccomp.
    const trimmedAmount = amount.trim();
    if (!/^\d+(\.\d+)?$/.test(trimmedAmount) || bccomp(trimmedAmount, '0') <= 0) {
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
      // S4: store as a decimal string at currency scale — no parseFloat ingress.
      amount: bcformat(trimmedAmount, decimals),
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
    decimals,
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
      // S4 (2026-07-01): l.amount is already a currency-scale decimal string
      // (set via bcformat in handleAddPayment) — forward directly, no String() bridge.
      amount: l.amount,
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
      className="fixed inset-0 z-50 flex flex-col bg-surface-sunken text-ink"
    >
      {/* Header */}
      <div className="flex items-center justify-between border-b border-border-subtle bg-surface-raised px-4 py-3">
        <button
          onClick={handleClose}
          className="flex items-center gap-2 rounded-ctl px-3 py-2 text-sm text-ink-muted hover:bg-surface-sunken hover:text-ink"
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
        <div className="flex w-[30%] flex-col border-r border-border-subtle bg-surface-raised p-4">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-ink-muted">
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
                    'flex min-h-[52px] items-center gap-3 rounded-card border-2 px-4 py-3 text-left transition-colors',
                    isSelected
                      ? 'border-accent bg-accent-tint text-accent-strong'
                      : 'border-border-subtle bg-surface-raised text-ink-muted hover:border-border-strong hover:bg-surface-sunken',
                  )}
                >
                  {isInstrumentBearing ? (
                    <Ticket className="h-5 w-5 shrink-0 text-accent-strong" />
                  ) : (
                    <Icon className="h-5 w-5 shrink-0" />
                  )}
                  <span className="text-sm font-medium">{method.name}</span>
                  {isInstrumentBearing && (
                    <span className="ml-auto rounded-pill bg-accent-tint px-2 py-0.5 text-xs font-semibold uppercase tracking-wider text-accent-strong">
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
                  'flex min-h-[52px] items-center gap-3 rounded-card border-2 px-4 py-3 text-left transition-colors',
                  accountChargeMode
                    ? 'border-accent bg-accent-tint text-accent-strong'
                    : 'border-border-subtle bg-surface-raised text-ink-muted hover:border-border-strong hover:bg-surface-sunken',
                )}
              >
                <UserRound className="h-5 w-5 shrink-0 text-action" />
                <span className="text-sm font-medium">
                  {t('account_charge.tile', { defaultValue: 'On Account' })}
                </span>
              </button>
            )}
          </div>

          {/* Config fields — shown when method selected */}
          {selectedMethod && (
            <div className="mt-4 space-y-3 border-t border-border-subtle pt-4">
              {/* Repository dropdown */}
              {compatibleRepositories.length > 1 && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-ink-muted">
                    {t('advancedPayments.repository')}
                  </label>
                  <select
                    value={effectiveRepositoryId}
                    onChange={(e) => {
                      setRepositoryId(e.target.value);
                      setValidationError(null);
                    }}
                    className="w-full rounded-ctl border border-border-strong px-3 py-2.5 text-sm focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
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
                  <label className="mb-1 block text-xs font-medium text-ink-muted">
                    {t('advancedPayments.reference')}
                  </label>
                  <input
                    type="text"
                    value={reference}
                    onChange={(e) => setReference(e.target.value)}
                    className="w-full rounded-ctl border border-border-strong px-3 py-2.5 text-sm focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                  />
                </div>
              )}

              {/* Card last 4 */}
              {showCardLastFour && (
                <div>
                  <label className="mb-1 block text-xs font-medium text-ink-muted">
                    {t('advancedPayments.cardLastFour')}
                  </label>
                  <input
                    type="text"
                    maxLength={4}
                    value={cardLastFour}
                    onChange={(e) =>
                      setCardLastFour(e.target.value.replace(/\D/g, ''))
                    }
                    className="w-full rounded-ctl border border-border-strong px-3 py-2.5 text-sm focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                  />
                </div>
              )}
            </div>
          )}

          {/* Add Payment button — pinned to bottom */}
          {selectedMethod && (
            <button
              onClick={handleAddPayment}
              className="mt-auto rounded-ctl bg-action px-4 py-3 text-sm font-semibold text-ink-inverse transition-colors hover:bg-action-hover active:bg-action-strong"
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
          <div className="flex flex-1 items-center justify-center bg-surface-sunken p-4">
            <AccountChargeConfirmation
              total={bcformat(String(total), decimals)}
              currency={currency}
              cashierUserId={cashierUserId}
              approvalContext={approvalContext}
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
        <div className="flex w-[35%] flex-col bg-surface-sunken p-4">
          {/* Amount display */}
          <div className="mb-3 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-ink-muted">
              {t('advancedPayments.amount')}
            </p>
            <p className="mt-1 font-mono text-3xl font-bold tabular-nums text-ink">
              {format(amount || '0')}
            </p>
          </div>

          {/* Pay Remaining pill */}
          {bccomp(remaining, '0') > 0 && (
            <div className="mb-3 text-center">
              <button
                onClick={handlePayRemaining}
                className="inline-flex rounded-ctl bg-action px-4 py-2 text-sm font-semibold text-ink-inverse transition-colors hover:bg-action-hover"
              >
                {t('advancedPayments.payRemaining')}: {format(remaining)}
              </button>
            </div>
          )}

          {/* NumPad — fills the column so there is no dead gap (consistent with
           * the cash screen). */}
          <div className="min-h-0 flex-1">
            <NumPad
              value={amount}
              onChange={(val) => {
                setAmount(val);
                setValidationError(null);
              }}
              className="h-full auto-rows-fr"
            />
          </div>
        </div>

        {/* RIGHT COLUMN (35%): Balance + Payments list + Complete */}
        <div className="flex w-[35%] flex-col border-l border-border-subtle bg-surface-raised p-4">
          {/* Total due card */}
          <div className="mb-4 rounded-card bg-action p-4 text-ink-inverse">
            <p className="text-xs font-medium uppercase tracking-wider opacity-80">
              {t('advancedPayments.totalDue')}
            </p>
            <p className="mt-1 text-3xl font-bold">{format(total)}</p>
          </div>

          {/* Payment lines — scrollable */}
          <div className="mb-3 flex-1 overflow-y-auto">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-muted">
              {t('advancedPayments.addedPayments')}
            </p>
            {paymentLines.length === 0 && voucherTenders.length === 0 ? (
              <p className="py-6 text-center text-sm text-ink-muted">
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
                    className="flex items-center justify-between rounded-tile border border-accent-tint bg-accent-tint px-3 py-2.5"
                  >
                    <div className="flex items-center gap-2">
                      <Ticket className="h-4 w-4 text-accent-strong" />
                      <div>
                        <p className="text-sm font-medium text-ink">
                          {t('advancedPayments.voucherTenderLabel')}
                        </p>
                        <p className="text-xs text-ink-muted">{v.code}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-ink">
                        {format(v.amount)}
                      </span>
                      <button
                        onClick={() => removeVoucherPayment(v.code)}
                        className="rounded-ctl p-1.5 text-danger hover:bg-danger-surface"
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
                    className="flex items-center justify-between rounded-tile border border-border-subtle bg-surface-sunken px-3 py-2.5"
                  >
                    <div>
                      <p className="text-sm font-medium text-ink">
                        {line.methodName}
                      </p>
                      <p className="text-xs text-ink-muted">
                        {line.repositoryName}
                        {line.cardLastFour && ` · *${line.cardLastFour}`}
                        {line.reference && ` · ${line.reference}`}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-ink">
                        {format(line.amount)}
                      </span>
                      <button
                        onClick={() => handleRemoveLine(line.id)}
                        className="rounded-ctl p-1.5 text-danger hover:bg-danger-surface"
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
          <div className="border-t border-border-subtle pt-3">
            <div className="mb-2 space-y-1 text-sm">
              <div className="flex justify-between">
                <span className="text-ink-muted">
                  {t('advancedPayments.totalPaid')}
                </span>
                <span className="font-medium text-ink">
                  {format(totalPaid)}
                </span>
              </div>
              {bccomp(remaining, '0') > 0 && (
                <div className="flex justify-between text-warning-strong">
                  <span>{t('advancedPayments.remaining')}</span>
                  <span className="font-medium">{format(remaining)}</span>
                </div>
              )}
              {bccomp(overpayment, '0') > 0 && (
                <div className="flex justify-between text-success-strong">
                  <span>{t('advancedPayments.changeDue')}</span>
                  <span className="font-medium">{format(overpayment)}</span>
                </div>
              )}
            </div>

            {(validationError || error) && (
              <div className="mb-2 flex items-center gap-2 rounded-tile bg-danger-surface px-3 py-2 text-sm text-danger-strong">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                {validationError || error}
              </div>
            )}

            {bccomp(remaining, '0') > 0 && (
              <label className="mb-2 block text-sm">
                <span className="mb-1 block font-medium text-ink-muted">
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
                  className="w-full rounded-ctl border border-border-strong px-3 py-2.5 text-sm focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                />
              </label>
            )}

            <button
              onClick={() => void handleComplete()}
              disabled={!canComplete || isProcessing}
              className="w-full rounded-ctl bg-success px-6 py-3.5 text-lg font-semibold text-ink-inverse transition-colors hover:bg-success-hover active:bg-success-hover disabled:cursor-not-allowed disabled:opacity-50"
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
          remainingDue={remaining}
          currency={currency}
          onApplied={handleVoucherApplied}
          methodCode={voucherTenderMethodCode}
        />
      )}
    </div>
  );
}
