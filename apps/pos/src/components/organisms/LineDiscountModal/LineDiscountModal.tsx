import { useState, useCallback, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import {
  authorPosOverride,
  type PosOverrideContext,
  type PosOverrideEvidence,
} from '@/lib/operatorApproval/posOverrideAuthoring';
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';

type DiscountType = 'percentage' | 'fixed';

export interface LineDiscountApplyPayload {
  type: DiscountType;
  value: string;
  reason: string;
  approvalEvidence?: PosOverrideEvidence;
}

export interface LineDiscountModalProps {
  isOpen: boolean;
  onClose: () => void;
  onApply: (data: LineDiscountApplyPayload) => void;
  itemName: string;
  canDiscount: boolean;
  maxDiscountPercent: number;
  terminalMaxDiscountPercent: number;
  disabledReason?: string;
  approvalContext?: PosOverrideContext;
  lineReferenceId?: string | null;
}

const NUMPAD_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'C'];
const PIN_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'C'];

export function LineDiscountModal({
  isOpen,
  onClose,
  onApply,
  itemName,
  canDiscount,
  maxDiscountPercent,
  terminalMaxDiscountPercent: _terminalMaxDiscountPercent,
  disabledReason,
  approvalContext,
  lineReferenceId,
}: LineDiscountModalProps) {
  const { t } = useTranslation('pos');
  const dialogRef = useRef<HTMLDivElement>(null);
  useFocusTrap({ isActive: isOpen, containerRef: dialogRef });

  const [discountType, setDiscountType] = useState<DiscountType>('percentage');
  const [value, setValue] = useState('');
  const [reason, setReason] = useState('');

  // Manager approval state
  const [needsApproval, setNeedsApproval] = useState(false);
  const [managerPin, setManagerPin] = useState('');
  const [managerError, setManagerError] = useState<string | null>(null);
  const [verifyingPin, setVerifyingPin] = useState(false);
  const fallbackReferenceIdRef = useRef<string>(crypto.randomUUID());

  // Reset state when modal opens/closes
  useEffect(() => {
    if (isOpen) {
      setValue('');
      setReason('');
      setDiscountType('percentage');
      setNeedsApproval(false);
      setManagerPin('');
      setManagerError(null);
      fallbackReferenceIdRef.current = crypto.randomUUID();
    }
  }, [isOpen]);

  // Escape closes the view, but not while the manager PIN entry is active
  useEffect(() => {
    if (!isOpen || needsApproval) return;
    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleEscape);
    return () => window.removeEventListener('keydown', handleEscape);
  }, [isOpen, needsApproval, onClose]);

  const handleNumpadPress = useCallback((key: string) => {
    if (key === 'C') {
      setValue('');
      return;
    }
    setValue((prev) => {
      if (key === '.' && prev.includes('.')) return prev;
      if (prev === '0' && key !== '.') return key;
      return prev + key;
    });
  }, []);

  const handlePinPress = useCallback((key: string) => {
    if (key === '') return;
    if (key === 'C') {
      setManagerPin('');
      setManagerError(null);
      return;
    }
    setManagerPin((prev) => {
      if (prev.length >= 6) return prev;
      return prev + key;
    });
  }, []);

  const numericValue = parseFloat(value) || 0;
  const percentageExceeded =
    discountType === 'percentage' && numericValue > maxDiscountPercent;
  const isDiscountDisabled = disabledReason !== undefined;
  const needsManagerOverride = !isDiscountDisabled && (!canDiscount || percentageExceeded);
  const isValid = !isDiscountDisabled && numericValue > 0;

  const handleApply = useCallback(() => {
    if (!isValid) return;

    if (needsManagerOverride) {
      setNeedsApproval(true);
      setManagerPin('');
      setManagerError(null);
      return;
    }

    onApply({
      type: discountType,
      value,
      reason: reason.trim(),
    });

    setValue('');
    setReason('');
    onClose();
  }, [isValid, needsManagerOverride, discountType, value, reason, onApply, onClose]);

  const handleManagerPinSubmit = useCallback(async () => {
    if (managerPin.length < 4) return;
    setManagerError(null);
    setVerifyingPin(true);
    try {
      if (approvalContext === undefined) {
        setManagerError(t('discount.approvalEvidenceRequired'));
        return;
      }

      const targetReferenceId = lineReferenceId ?? fallbackReferenceIdRef.current;
      const reasonText = reason.trim();
      const manager = await verifyScopedManagerPin({
        pin: managerPin,
        context: approvalContext,
        approvalScope: 'discount_limit_override',
        targetEventType: 'LINE_DISCOUNT_LIMIT_OVERRIDE',
        targetReferenceId,
        reason: reasonText || 'Line discount limit override',
      });
      const approvalEvidence = await authorPosOverride({
        context: approvalContext,
        supervisor: {
          id: manager.id,
          name: manager.name,
          roles: manager.roles,
        },
        approvalScope: 'discount_limit_override',
        targetEventType: 'LINE_DISCOUNT_LIMIT_OVERRIDE',
        targetReferenceId,
        target: {
          discount_type: discountType,
          discount_value: value,
          item_name: itemName,
          reason: reasonText,
          target_reference_id: targetReferenceId,
        },
        policyVersion: 'pos-discount-policy-v1',
        reasonCode: reasonText === '' ? 'discount_limit_override' : 'manager_reason',
        reasonText: reasonText || null,
      });

      onApply({
        type: discountType,
        value,
        reason: reason.trim(),
        approvalEvidence,
      });
      setValue('');
      setReason('');
      setManagerPin('');
      setNeedsApproval(false);
      onClose();
    } catch {
      setManagerError(t('discount.invalidPin'));
    } finally {
      setVerifyingPin(false);
    }
  }, [managerPin, discountType, value, reason, approvalContext, lineReferenceId, itemName, onApply, onClose, t]);

  if (!isOpen) return null;

  return (
    <div
      ref={dialogRef}
      role="dialog"
      aria-modal="true"
      aria-labelledby="line-discount-modal-title"
      data-testid="line-discount-modal-dialog"
      className="fixed inset-0 z-50 flex flex-col bg-surface-canvas text-ink"
    >
      {/* Header */}
      <div className="flex shrink-0 items-center justify-between border-b border-border-subtle bg-surface-raised px-4 py-3">
        <button
          onClick={onClose}
          className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-ink-muted hover:bg-surface-sunken hover:text-ink"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('discount.cancel')}
        </button>
        <span id="line-discount-modal-title" className="text-lg font-bold text-ink">
          {t('cart.itemDiscount')}
        </span>
        <div className="w-20" />
      </div>

      <div className="flex min-h-0 flex-1 gap-4 p-4">
        {/* Left: Toggle + Value + Numpad OR Manager PIN */}
        <div className="flex flex-[2] flex-col">
          {needsApproval ? (
            <>
              {/* Manager approval mode */}
              <div className="mb-3 text-center">
                <h3 className="text-lg font-bold text-ink">
                  {t('discount.managerApproval')}
                </h3>
                <p className="mt-1 text-sm text-ink-muted">
                  {t('discount.enterManagerPin')}
                </p>
              </div>

              {/* PIN display */}
              <div className="mb-3 rounded-xl bg-surface-sunken px-4 py-4 text-center text-4xl font-bold text-ink">
                {'•'.repeat(managerPin.length) || '\u00A0'}
              </div>

              {/* PIN numpad */}
              <div className="grid flex-1 grid-cols-3 gap-2">
                {PIN_KEYS.map((key, idx) => (
                  <button
                    key={`pin-${String(idx)}`}
                    onClick={() => handlePinPress(key)}
                    disabled={key === ''}
                    className={cn(
                      'flex items-center justify-center rounded-xl text-xl font-semibold transition-colors',
                      key === ''
                        ? 'invisible'
                        : key === 'C'
                          ? 'bg-danger-surface text-danger-strong hover:bg-danger-surface'
                          : 'bg-surface-sunken text-ink hover:bg-surface-sunken active:bg-surface-sunken',
                    )}
                  >
                    {key}
                  </button>
                ))}
              </div>
            </>
          ) : (
            <>
              {/* Normal discount entry mode */}
              {/* Discount type toggle */}
              <div className="mb-3 flex rounded-lg bg-surface-sunken p-1">
                <button
                  onClick={() => setDiscountType('percentage')}
                  className={cn(
                    'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    discountType === 'percentage'
                      ? 'bg-surface-raised text-ink shadow-sm'
                      : 'text-ink-muted hover:text-ink',
                  )}
                >
                  {t('discount.percentage')}
                </button>
                <button
                  onClick={() => setDiscountType('fixed')}
                  className={cn(
                    'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    discountType === 'fixed'
                      ? 'bg-surface-raised text-ink shadow-sm'
                      : 'text-ink-muted hover:text-ink',
                  )}
                >
                  {t('discount.fixed')}
                </button>
              </div>

              {/* Value display */}
              <div className="mb-3 rounded-xl bg-surface-sunken px-4 py-4 text-center text-4xl font-bold text-ink">
                {value || '0'}
                {discountType === 'percentage' ? '%' : ''}
              </div>

              {/* Numpad */}
              <div className="grid flex-1 grid-cols-3 gap-2">
                {NUMPAD_KEYS.map((key) => (
                  <button
                    key={key}
                    onClick={() => handleNumpadPress(key)}
                    disabled={isDiscountDisabled}
                    className={cn(
                      'flex items-center justify-center rounded-xl text-xl font-semibold transition-colors',
                      isDiscountDisabled && 'cursor-not-allowed opacity-50',
                      key === 'C'
                        ? 'bg-danger-surface text-danger-strong hover:bg-danger-surface'
                        : 'bg-surface-sunken text-ink hover:bg-surface-sunken active:bg-surface-sunken',
                    )}
                  >
                    {key}
                  </button>
                ))}
              </div>
            </>
          )}
        </div>

        {/* Right: Item name + Summary + Reason + Apply/Authorize */}
        <div className="flex flex-[3] flex-col">
          {/* Item name — always visible */}
          <div className="mb-3 rounded-lg bg-surface-sunken px-3 py-2 text-center text-sm font-medium text-ink-muted">
            {itemName}
          </div>

          {needsApproval ? (
            <>
              {/* Show the discount that will be applied */}
              <div className="mb-3 rounded-lg bg-action-subtle border border-action-subtle p-3 text-center text-sm text-action">
                {discountType === 'percentage'
                  ? `${value}% ${t('cart.itemDiscount').toLowerCase()}`
                  : `${value} ${t('cart.itemDiscount').toLowerCase()}`}
              </div>

              {/* Manager error */}
              {managerError && (
                <div className="mb-3 rounded-lg border border-danger-subtle bg-danger-surface p-3 text-center text-sm text-danger-strong">
                  {managerError}
                </div>
              )}

              {/* Spacer */}
              <div className="flex-1" />

              {/* Authorize button */}
              <button
                onClick={() => void handleManagerPinSubmit()}
                disabled={managerPin.length < 4 || verifyingPin}
                className="mb-2 flex min-h-[56px] w-full items-center justify-center rounded-xl bg-action px-6 py-4 text-lg font-semibold text-ink-inverse transition-colors hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
              >
                {verifyingPin ? t('discount.verifyingPin') : t('discount.authorize')}
              </button>

              {/* Back button */}
              <button
                onClick={() => {
                  setNeedsApproval(false);
                  setManagerPin('');
                  setManagerError(null);
                }}
                className="flex min-h-[44px] w-full items-center justify-center rounded-xl border border-border-strong px-6 py-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken"
              >
                {t('cashPayment.back')}
              </button>
            </>
          ) : (
            <>
              {/* Max exceeded warning — shown as info since manager can override */}
              {disabledReason && (
                <div className="mb-3 rounded-lg border border-danger-subtle bg-danger-surface p-3 text-center text-sm text-danger-strong">
                  {disabledReason}
                </div>
              )}

              {needsManagerOverride && numericValue > 0 && (
                <div className="mb-3 rounded-lg border border-warning-subtle bg-warning-surface p-3 text-center text-sm text-warning-strong">
                  {!canDiscount
                    ? t('discount.managerApproval')
                    : t('discount.maxExceeded', { max: maxDiscountPercent })}
                </div>
              )}

              {/* Spacer to push content toward center */}
              <div className="flex-1" />

              {/* Reason */}
              <div className="mb-4">
                <label className="mb-1 block text-sm font-medium text-ink-muted">
                  {t('discount.reason')}
                </label>
                <input
                  type="text"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  className="w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm focus:border-accent focus:ring-2 focus:ring-accent focus:outline-none"
                />
              </div>

              {/* Apply button */}
              <button
                onClick={handleApply}
                disabled={!isValid}
                className="flex min-h-[56px] w-full items-center justify-center rounded-xl bg-action px-6 py-4 text-lg font-semibold text-ink-inverse transition-colors hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
              >
                {t('cart.applyDiscount')}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
