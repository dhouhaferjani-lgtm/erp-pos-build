import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/Modal';
import { Button, StatusPill } from '@/components/ui';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';
import { getDatabase } from '@/lib/db';
import { getOpenRequestForProduct } from '@/lib/db/repositories/openReplenishmentRepository';
import { enqueueReplenishmentRequest } from '@/lib/db/repositories/replenishmentOutboxRepository';
import { bccomp } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useTerminalStore } from '@/stores/terminalStore';
import type { POSProduct } from '@/types/product';

export interface RequestRefillSheetProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct;
  variantId?: string | null;
}

const QUANTITY_PATTERN = /^\d+(\.\d{1,4})?$/;
const REQUESTED_AT_FORMATTER = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'short',
  timeStyle: 'short',
});
const INITIAL_FORM = {
  quantity: '',
  note: '',
  quantityInvalid: false,
  lastRequestedAt: null as string | null,
};

export function RequestRefillSheet({
  isOpen,
  onClose,
  product,
  variantId = null,
}: RequestRefillSheetProps) {
  const { t } = useTranslation('pos');
  const [form, setForm] = useState(INITIAL_FORM);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!isOpen) return;

    setForm(INITIAL_FORM);

    const auth = useAuthStore.getState();
    const tenantId = auth.user?.tenantId;
    const companyId = auth.companyId;
    if (!tenantId || !companyId) return;

    let cancelled = false;
    void (async () => {
      const db = await getDatabase(companyId);
      const cached = await getOpenRequestForProduct(
        db,
        tenantId,
        companyId,
        product.id,
        variantId ?? '',
      );
      if (!cancelled) {
        setForm((current) => ({
          ...current,
          lastRequestedAt: cached?.last_requested_at ?? null,
        }));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [isOpen, product.id, variantId]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const normalizedQuantity = form.quantity.trim();
    const validQuantity =
      normalizedQuantity === '' ||
      (QUANTITY_PATTERN.test(normalizedQuantity) && bccomp(normalizedQuantity, '0') > 0);
    if (!validQuantity) {
      setForm((current) => ({ ...current, quantityInvalid: true }));
      return;
    }

    const auth = useAuthStore.getState();
    const tenantId = auth.user?.tenantId;
    const companyId = auth.companyId;
    const terminalId = useTerminalStore.getState().terminal?.id;
    if (!tenantId || !companyId || !terminalId) return;

    setSubmitting(true);
    try {
      const db = await getDatabase(companyId);
      const clientUuid = crypto.randomUUID();
      const requestedQty = normalizedQuantity === '' ? null : normalizedQuantity;
      await enqueueReplenishmentRequest(db, {
        client_request_uuid: clientUuid,
        tenant_id: tenantId,
        company_id: companyId,
        terminal_id: terminalId,
        product_id: product.id,
        variant_id: variantId,
        requested_qty: requestedQty,
        note: form.note.trim() === '' ? null : form.note.trim(),
      });
      void recordAuditEvent({
        type: 'pos.replenishment_requested',
        aggregateType: 'ReplenishmentRequest',
        aggregateId: clientUuid,
        payload: {
          product_id: product.id,
          variant_id: variantId,
          requested_qty: requestedQty,
        },
      }).catch(() => {});
      toast.success(
        t(
          useConnectivityStore.getState().isOnline
            ? 'replenishment.request_recorded'
            : 'replenishment.queued_offline',
        ),
      );
      onClose();
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      size="sm"
      title={t('replenishment.request_refill')}
      closable={!submitting}
    >
      <form className="space-y-4" noValidate onSubmit={(event) => void handleSubmit(event)}>
        <div>
          <div className="font-semibold text-ink">{product.name}</div>
          {form.lastRequestedAt && (
            <StatusPill
              className="mt-2"
              tone="warning"
              label={t('replenishment.already_requested', {
                date: formatRequestedAt(form.lastRequestedAt),
              })}
            />
          )}
        </div>

        <label className="block text-sm font-medium text-ink">
          <span>{t('replenishment.quantity_optional')}</span>
          <input
            className="mt-1 w-full rounded-ctl border border-border-subtle bg-surface-raised px-3 py-2 text-ink outline-none focus:border-action focus:ring-2 focus:ring-action"
            value={form.quantity}
            inputMode="decimal"
            pattern="^\d+(\.\d{1,4})?$"
            aria-invalid={form.quantityInvalid}
            onChange={(event) => {
              setForm((current) => ({
                ...current,
                quantity: event.target.value,
                quantityInvalid: false,
              }));
            }}
          />
        </label>

        <label className="block text-sm font-medium text-ink">
          <span>{t('replenishment.note')}</span>
          <textarea
            className="mt-1 min-h-20 w-full resize-y rounded-ctl border border-border-subtle bg-surface-raised px-3 py-2 text-ink outline-none focus:border-action focus:ring-2 focus:ring-action"
            value={form.note}
            maxLength={2000}
            onChange={(event) => {
              setForm((current) => ({ ...current, note: event.target.value }));
            }}
          />
        </label>

        <Button type="submit" variant="primary" fullWidth loading={submitting}>
          {t('replenishment.submit')}
        </Button>
      </form>
    </Modal>
  );
}

function formatRequestedAt(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return REQUESTED_AT_FORMATTER.format(date);
}
