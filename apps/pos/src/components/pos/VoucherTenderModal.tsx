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
 */

import { useState, useCallback, useRef, type ChangeEvent } from 'react';
import { useTranslation } from 'react-i18next';
import type Database from '@tauri-apps/plugin-sql';
import { findByCode, type LocalVoucher } from '@/lib/offline/voucherRepository';
import { usePaymentStore } from '@/stores/paymentStore';
import { Modal } from '@/components/pos/Modal';

// ─── Constants ────────────────────────────────────────────────────────────────

/** Only MPV (Multi-Purpose Voucher) is supported in Phase 1 (spec §6.5). */
const SUPPORTED_VOUCHER_KIND = 'MPV';

/**
 * Statuses that allow redemption.
 * 'Issued' and 'PartiallyRedeemed' are redeemable; all others are not.
 */
const REDEEMABLE_STATUSES = new Set<LocalVoucher['status']>(['Issued', 'PartiallyRedeemed']);

// ─── Props ────────────────────────────────────────────────────────────────────

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
}

// ─── Local types ──────────────────────────────────────────────────────────────

type Phase =
  | 'scan'           // Input code; nothing found yet
  | 'found'          // Voucher found — show details + amount input
  | 'applying';      // In-flight (addVoucherPayment call)

// ─── Helpers ──────────────────────────────────────────────────────────────────

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value));
}

function formatDecimal(value: number, decimals: number): string {
  return value.toFixed(decimals);
}

function guessDecimals(decimalStr: string): number {
  const dotIdx = decimalStr.indexOf('.');
  return dotIdx < 0 ? 2 : decimalStr.length - dotIdx - 1;
}

// ─── Component ────────────────────────────────────────────────────────────────

export function VoucherTenderModal({
  isOpen,
  onClose,
  db,
  remainingDue,
  currency,
  onApplied,
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

    // NO API CALL — local SQLite only
    const found = await findByCode(db, trimmedCode);

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

    // Default amount = min(balance, remaining due)
    const decimals = guessDecimals(found.current_balance);
    const balance = parseFloat(found.current_balance);
    const due = parseFloat(remainingDue);
    const defaultAmount = formatDecimal(clamp(balance, 0, Math.max(0, due)), decimals);

    setVoucher(found);
    setAmountInput(defaultAmount);
    setCode(found.code); // normalize to server-casing
    setPhase('found');
  }, [code, db, appliedVoucherCodes, remainingDue, t]);

  const handleApply = useCallback(() => {
    if (voucher === null) return;

    const parsedAmount = parseFloat(amountInput);
    const balance = parseFloat(voucher.current_balance);
    const due = parseFloat(remainingDue);

    if (isNaN(parsedAmount) || parsedAmount <= 0) {
      setLookupError(t('voucherTender.invalidAmount', { defaultValue: 'Enter a valid amount greater than zero.' }));
      return;
    }
    if (parsedAmount > balance) {
      setLookupError(
        t('voucherTender.amountExceedsBalance', {
          defaultValue: 'Amount cannot exceed the voucher balance ({{balance}} {{currency}}).',
          balance: voucher.current_balance,
          currency,
        }),
      );
      return;
    }
    if (parsedAmount > due && due > 0) {
      setLookupError(
        t('voucherTender.amountExceedsDue', {
          defaultValue: 'Amount cannot exceed the remaining due ({{due}} {{currency}}).',
          due: remainingDue,
          currency,
        }),
      );
      return;
    }

    setPhase('applying');

    try {
      // Duplicate guard — addVoucherPayment also guards, but we check early for UX
      if (appliedVoucherCodes.has(voucher.code)) {
        setLookupError(
          t('voucherTender.alreadyApplied', {
            defaultValue: 'This voucher has already been applied to this sale.',
          }),
        );
        setPhase('found');
        return;
      }

      const decimals = guessDecimals(voucher.current_balance);
      const finalAmount = formatDecimal(parsedAmount, decimals);
      addVoucherPayment(voucher.code, finalAmount);
      onApplied(voucher.code, finalAmount);
      resetToScan();
    } catch (err: unknown) {
      setLookupError(
        err instanceof Error
          ? err.message
          : t('voucherTender.applyFailed', { defaultValue: 'Failed to apply voucher.' }),
      );
      setPhase('found');
    }
  }, [voucher, amountInput, remainingDue, appliedVoucherCodes, currency, addVoucherPayment, onApplied, resetToScan, t]);

  const handleAmountChange = useCallback((e: ChangeEvent<HTMLInputElement>) => {
    // Allow decimals and digits only
    const raw = e.target.value.replace(/[^0-9.]/g, '');
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
        {phase === 'scan' || phase === 'applying' ? (
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
              disabled={isLooking || phase === 'applying'}
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
                disabled={isLooking || phase === 'applying'}
                data-testid="voucher-cancel"
                className="flex-1 rounded-md border border-gray-300 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              >
                {t('voucherTender.cancel', { defaultValue: 'Cancel' })}
              </button>
              <button
                type="button"
                onClick={() => void handleLookup()}
                disabled={isLooking || code.trim() === '' || phase === 'applying'}
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
                  <input
                    id="voucher-amount-input"
                    type="number"
                    inputMode="decimal"
                    min="0.01"
                    step="0.01"
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
