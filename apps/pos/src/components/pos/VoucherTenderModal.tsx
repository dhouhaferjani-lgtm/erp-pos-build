/**
 * VoucherTenderModal
 *
 * New tender option in the payment screen alongside Cash/Card (spec §6.5).
 *
 * Flow:
 *   1. Cashier taps "Voucher / Bon" button (external to this component).
 *   2. This modal opens with a scan/type input.
 *   3. On submit, looks up the code in local SQLite via `findByCode(db, code)`.
 *      NO API CALL on the lookup path — offline-first.
 *   4. Shows balance + expiry + redemption_mode. Cashier confirms amount to apply
 *      (default = min(balance, remaining due)).
 *   5. Apply → calls `addVoucherPayment(code, amount)` on paymentStore.
 *   6. Multiple vouchers may be applied (stacking) until remaining due = 0.
 *
 * Restaurant-voucher rejection:
 *   The `vouchers` table stores `voucher_kind` as 'MPV' or 'SPV' (VoucherKind enum).
 *   Phase 1 only issues MPV (Multi-Purpose Vouchers — EU Directive 2016/1065).
 *   Restaurant meal vouchers (Ticket Restaurant, Sodexo, etc.) are tracked on
 *   `pos_receipt_payments.instrument_type = 'restaurant_voucher'`, NOT in the
 *   `vouchers` table. Therefore any voucher retrieved from local SQLite that has
 *   `voucher_kind !== 'MPV'` is either SPV (reserved) or an unknown kind.
 *   For safety, only 'MPV' is accepted; all other kinds are rejected with a clear
 *   message. Restaurant vouchers won't appear in this lookup at all in Phase 1 —
 *   but if a non-MPV row ever appears (e.g., SPV in Phase 2+), it is rejected here
 *   with a distinct Phase 2 notice rather than silently failing.
 *
 * Duplicate guard:
 *   The same voucher cannot be applied twice per transaction. The component checks
 *   `appliedVoucherCodes` from paymentStore before calling addVoucherPayment.
 *
 * Monetary precision:
 *   All monetary comparisons and formatting use bc-style string arithmetic
 *   (`bccomp`, `bcformat` from `@/lib/decimal`) — never IEEE 754 floats.
 *   This handles TND (scale 3) and other multi-decimal currencies correctly.
 */

import { useState, useCallback, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import type Database from '@tauri-apps/plugin-sql';
import { findByCode, type LocalVoucher } from '@/lib/offline/voucherRepository';
import { usePaymentStore } from '@/stores/paymentStore';
import { Modal } from '@/components/pos/Modal';
import { MoneyInput } from '@/components/atoms/MoneyInput';
import { bccomp, bcformat } from '@/lib/decimal';

// ─── Constants ────────────────────────────────────────────────────────────────

/** Only MPV (Multi-Purpose Voucher) is supported in Phase 1 (spec §6.5). */
const SUPPORTED_VOUCHER_KIND = 'MPV';

/**
 * Statuses that allow redemption.
 * 'Issued' and 'PartiallyRedeemed' are redeemable; all others are not.
 */
const REDEEMABLE_STATUSES = new Set<LocalVoucher['status']>(['Issued', 'PartiallyRedeemed']);

// ─── Props ────────────────────────────────────────────────────────────────────

/**
 * The payment-method tender code that opened this modal. Phase 1 only fully
 * wires `'store_voucher'` end-to-end — the canonical PaymentMethod for our
 * own merchant-issued store credit (refund-issued, exchange-surplus,
 * goodwill, loyalty-credit). Restaurant tickets and gift cards are Phase 2+
 * with distinct settlement / GL paths (spec §3.2.1).
 *
 * Why the prop exists in Phase 1 even though only one value is supported:
 *   - The discriminator must be correct end-to-end so the parent's
 *     AdvancedPaymentLine mapping (`instrument_type: 'store_voucher'`) is
 *     not hardcoded based on which screen mounted the modal.
 *   - Phase 2+ will accept restaurant_voucher and gift_card without a
 *     refactor — only the parent's tap-handler gating + the modal's
 *     internal kind matrix will change.
 *
 * Codex review B5-fix audit Minor 1 (2026-05-01).
 */
export type VoucherTenderMethodCode =
  | 'store_voucher'
  | 'restaurant_voucher'
  | 'gift_card';

export interface VoucherTenderModalProps {
  isOpen: boolean;
  onClose: () => void;

  /**
   * SQLite database handle. Must be the tenant-scoped DB from `getDatabase(companyId)`.
   * Injected by the parent so this component is testable without Tauri runtime.
   */
  db: Database;

  /**
   * Remaining amount due on the sale (before this voucher is applied).
   * Formatted decimal string at the currency's scale, e.g. "25.00".
   * Used to default the "amount to apply" input.
   */
  remainingDue: string;

  /** ISO 4217 currency code, e.g. "EUR". Used for display only. */
  currency: string;

  /** Called after a voucher is successfully applied. */
  onApplied: (code: string, amount: string) => void;

  /**
   * Discriminator: which tender tile opened this modal. Phase 1 only wires
   * `'store_voucher'`. Restaurant_voucher / gift_card values are accepted
   * for Phase 2+ readiness but the parent should NOT route those values
   * through here in Phase 1 (the gate at AdvancedPaymentsModal's
   * `handleSelectMethod` should surface a "not yet supported" message
   * BEFORE this modal opens).
   */
  methodCode: VoucherTenderMethodCode;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Infer the decimal scale from a stored monetary string (e.g. "25.123" → 3).
 * The voucher's `current_balance` is always stored at the correct currency scale
 * by the sync pipeline, so we can derive scale directly from the string rather
 * than depending on a currency-lookup import.
 */
function guessDecimals(decimalStr: string): number {
  const dotIdx = decimalStr.indexOf('.');
  return dotIdx < 0 ? 2 : decimalStr.length - dotIdx - 1;
}

// ─── Local types ──────────────────────────────────────────────────────────────

type Phase =
  | 'scan'    // Input code; nothing found yet
  | 'found';  // Voucher found — show details + amount input

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * String-safe min: returns the smaller of a and b (both decimal strings).
 * Falls back to '0' if either is not a valid decimal.
 */
function bcmin(a: string, b: string): string {
  return bccomp(a, b) <= 0 ? a : b;
}

/**
 * String-safe clamp: returns value clamped to [lo, hi] (all decimal strings).
 */
function bcclamp(value: string, lo: string, hi: string): string {
  if (bccomp(value, lo) < 0) return lo;
  if (bccomp(value, hi) > 0) return hi;
  return value;
}

// ─── Component ────────────────────────────────────────────────────────────────

export function VoucherTenderModal({
  isOpen,
  onClose,
  db,
  remainingDue,
  currency,
  onApplied,
  // Phase 1: only 'store_voucher' is fully wired. The parent gates other
  // values BEFORE opening the modal (Minor 1 fix in AdvancedPaymentsModal).
  // We accept the prop here so the discriminator is correct end-to-end and
  // the modal won't need refactoring when Phase 2 lands.
  methodCode: _methodCode,
}: VoucherTenderModalProps) {
  const { t } = useTranslation('pos');
  const { appliedVoucherCodes, addVoucherPayment } = usePaymentStore();

  const [phase, setPhase] = useState<Phase>('scan');
  const [code, setCode] = useState('');
  const [voucher, setVoucher] = useState<LocalVoucher | null>(null);
  const [amountInput, setAmountInput] = useState('');
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [isLooking, setIsLooking] = useState(false);

  const codeInputRef = useRef<HTMLInputElement>(null);

  const resetToScan = useCallback(() => {
    setPhase('scan');
    setCode('');
    setVoucher(null);
    setAmountInput('');
    setLookupError(null);
    setIsLooking(false);
  }, []);

  const handleClose = useCallback(() => {
    resetToScan();
    onClose();
  }, [resetToScan, onClose]);

  const handleLookup = useCallback(async () => {
    const trimmedCode = code.trim().toUpperCase();
    if (!trimmedCode) return;

    setIsLooking(true);
    setLookupError(null);

    let found: LocalVoucher | null;
    try {
      // NO API CALL — local SQLite only
      found = await findByCode(db, trimmedCode);
    } catch {
      setLookupError(
        t('voucherTender.lookupFailed', {
          defaultValue: 'Could not read local voucher database. Please try again.',
        }),
      );
      setIsLooking(false);
      return;
    }

    setIsLooking(false);

    if (!found) {
      setLookupError(
        t('voucherTender.notFound', { defaultValue: 'Voucher not found. Check the code and try again.' }),
      );
      return;
    }

    // Duplicate guard
    if (appliedVoucherCodes.has(found.code)) {
      setLookupError(
        t('voucherTender.alreadyApplied', {
          defaultValue: 'This voucher has already been applied to this sale.',
        }),
      );
      return;
    }

    // Restaurant-voucher rejection (Phase 1 gate)
    if (found.voucher_kind !== SUPPORTED_VOUCHER_KIND) {
      setLookupError(
        t('voucherTender.unsupportedKind', {
          defaultValue:
            'Restaurant vouchers are not yet supported — Phase 2 feature. Only store vouchers (MPV) can be used here.',
        }),
      );
      return;
    }

    // Status check
    if (!REDEEMABLE_STATUSES.has(found.status)) {
      setLookupError(
        t('voucherTender.notRedeemable', {
          defaultValue: 'This voucher cannot be redeemed (status: {{status}}).',
          status: found.status,
        }),
      );
      return;
    }

    // Default amount = min(balance, remaining due) — bc-safe, no float arithmetic
    // Scale is derived from the stored balance string (always at correct precision)
    const decimals = guessDecimals(found.current_balance);
    const balance = found.current_balance;
    // Clamp due to [0, balance] then take min with balance
    const due = bccomp(remainingDue, '0') > 0 ? remainingDue : '0';
    const defaultAmount = bcformat(bcmin(balance, due), decimals);

    setVoucher(found);
    setAmountInput(defaultAmount);
    setCode(found.code); // normalize to server-casing
    setPhase('found');
  }, [code, db, appliedVoucherCodes, remainingDue, currency, t]);

  const handleApply = useCallback(() => {
    if (voucher === null) return;

    const parsedAmount = amountInput;
    const balance = voucher.current_balance;
    const due = remainingDue;

    // Validate: must be a positive number
    const parsedFloat = parseFloat(parsedAmount);
    if (isNaN(parsedFloat) || parsedFloat <= 0 || parsedAmount.trim() === '') {
      setLookupError(t('voucherTender.invalidAmount', { defaultValue: 'Enter a valid amount greater than zero.' }));
      return;
    }

    // Validate: must not exceed balance (bc-safe comparison)
    if (bccomp(parsedAmount, balance) > 0) {
      setLookupError(
        t('voucherTender.amountExceedsBalance', {
          defaultValue: 'Amount cannot exceed the voucher balance ({{balance}} {{currency}}).',
          balance: voucher.current_balance,
          currency,
        }),
      );
      return;
    }

    // Validate: must not exceed remaining due (bc-safe comparison)
    if (bccomp(due, '0') > 0 && bccomp(parsedAmount, due) > 0) {
      setLookupError(
        t('voucherTender.amountExceedsDue', {
          defaultValue: 'Amount cannot exceed the remaining due ({{due}} {{currency}}).',
          due: remainingDue,
          currency,
        }),
      );
      return;
    }

    try {
      // Duplicate guard — addVoucherPayment also guards, but we check early for UX
      if (appliedVoucherCodes.has(voucher.code)) {
        setLookupError(
          t('voucherTender.alreadyApplied', {
            defaultValue: 'This voucher has already been applied to this sale.',
          }),
        );
        return;
      }

      const decimals = guessDecimals(voucher.current_balance);
      // Normalise to canonical decimal string at currency scale
      const finalAmount = bcclamp(
        bcformat(parsedAmount, decimals),
        '0',
        balance,
      );
      addVoucherPayment(voucher.code, finalAmount);
      onApplied(voucher.code, finalAmount);
      resetToScan();
    } catch (err: unknown) {
      setLookupError(
        err instanceof Error
          ? err.message
          : t('voucherTender.applyFailed', { defaultValue: 'Failed to apply voucher.' }),
      );
    }
  }, [voucher, amountInput, remainingDue, appliedVoucherCodes, currency, addVoucherPayment, onApplied, resetToScan, t]);

  const handleAmountChange = useCallback((value: string) => {
    // Allow decimals and digits only
    const raw = value.replace(/[^0-9.]/g, '');
    setAmountInput(raw);
    setLookupError(null);
  }, []);

  return (
    <Modal
      isOpen={isOpen}
      onClose={handleClose}
      title={t('voucherTender.title', { defaultValue: 'Apply voucher' })}
      size="sm"
    >
      <div className="space-y-4 p-4" data-testid="voucher-tender-modal">
        {phase === 'scan' ? (
          /* ── Scan / type phase ────────────────────────────────────────────── */
          <div className="space-y-3">
            <label htmlFor="voucher-code-input" className="text-sm font-medium text-gray-700">
              {t('voucherTender.codeLabel', { defaultValue: 'Voucher code' })}
            </label>
            <input
              ref={codeInputRef}
              id="voucher-code-input"
              type="text"
              value={code}
              onChange={(e) => {
                setCode(e.target.value);
                setLookupError(null);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') void handleLookup();
              }}
              disabled={isLooking}
              placeholder={t('voucherTender.codePlaceholder', { defaultValue: 'Scan or type code…' })}
              autoFocus
              data-testid="voucher-code-input"
              className="w-full rounded-md border border-gray-300 p-2 font-mono text-sm uppercase focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />

            {lookupError !== null && (
              <p data-testid="voucher-lookup-error" className="text-sm text-red-600">
                {lookupError}
              </p>
            )}

            <div className="flex gap-2 pt-1">
              <button
                type="button"
                onClick={handleClose}
                disabled={isLooking}
                data-testid="voucher-cancel"
                className="flex-1 rounded-md border border-gray-300 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              >
                {t('voucherTender.cancel', { defaultValue: 'Cancel' })}
              </button>
              <button
                type="button"
                onClick={() => void handleLookup()}
                disabled={isLooking || code.trim() === ''}
                data-testid="voucher-lookup-button"
                className="flex-1 rounded-md bg-blue-600 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {isLooking
                  ? t('voucherTender.looking', { defaultValue: 'Looking up…' })
                  : t('voucherTender.lookup', { defaultValue: 'Look up' })}
              </button>
            </div>
          </div>
        ) : (
          /* ── Found phase — details + amount ───────────────────────────────── */
          voucher !== null && (
            <div className="space-y-3" data-testid="voucher-found-section">
              {/* Voucher details */}
              <div className="rounded-md border border-green-200 bg-green-50 p-3 space-y-1 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-500">{t('voucherTender.balance', { defaultValue: 'Balance' })}</span>
                  <span className="font-semibold text-green-800" data-testid="voucher-balance">
                    {voucher.current_balance} {currency}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">{t('voucherTender.redemptionMode', { defaultValue: 'Mode' })}</span>
                  <span className="text-gray-800" data-testid="voucher-redemption-mode">
                    {voucher.redemption_mode === 'Bearer'
                      ? t('voucherTender.modeBearer', { defaultValue: 'Bearer (any holder)' })
                      : t('voucherTender.modeCustomerBound', { defaultValue: 'Customer-bound' })}
                  </span>
                </div>
                {voucher.expires_at !== null && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">{t('voucherTender.expires', { defaultValue: 'Expires' })}</span>
                    <span className="text-gray-800" data-testid="voucher-expiry">
                      {new Date(voucher.expires_at).toLocaleDateString()}
                    </span>
                  </div>
                )}
              </div>

              {/* Amount input */}
              <div className="space-y-1">
                <label htmlFor="voucher-amount-input" className="text-sm font-medium text-gray-700">
                  {t('voucherTender.amountLabel', { defaultValue: 'Amount to apply' })}
                </label>
                <div className="flex items-center gap-2">
                  <MoneyInput
                    id="voucher-amount-input"
                    currency={currency}
                    min="0"
                    value={amountInput}
                    onChange={handleAmountChange}
                    data-testid="voucher-amount-input"
                    className="w-full rounded-md border border-gray-300 p-2 text-right font-mono text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                  <span className="text-sm text-gray-500">{currency}</span>
                </div>
              </div>

              {lookupError !== null && (
                <p data-testid="voucher-apply-error" className="text-sm text-red-600">
                  {lookupError}
                </p>
              )}

              <div className="flex gap-2 pt-1">
                <button
                  type="button"
                  onClick={resetToScan}
                  data-testid="voucher-back"
                  className="flex-1 rounded-md border border-gray-300 py-2 text-sm text-gray-700 hover:bg-gray-50"
                >
                  {t('voucherTender.back', { defaultValue: 'Back' })}
                </button>
                <button
                  type="button"
                  onClick={handleApply}
                  data-testid="voucher-apply-button"
                  className="flex-1 rounded-md bg-green-600 py-2 text-sm font-semibold text-white hover:bg-green-700"
                >
                  {t('voucherTender.apply', { defaultValue: 'Apply' })}
                </button>
              </div>
            </div>
          )
        )}
      </div>
    </Modal>
  );
}
