import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Modal } from './Modal';
import { ClipboardList, RotateCcw, Trash2 } from 'lucide-react';
import type { HeldTransaction } from '@/stores/holdStore';

interface HeldTransactionsModalProps {
  isOpen: boolean;
  onClose: () => void;
  heldTransactions: HeldTransaction[];
  onRecall: (id: string) => void;
  onDiscard: (id: string) => void;
}

export function HeldTransactionsModal({
  isOpen,
  onClose,
  heldTransactions,
  onRecall,
  onDiscard,
}: HeldTransactionsModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('hold.title')} size="lg">
      {heldTransactions.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-12 text-center text-ink-muted">
          <ClipboardList className="mb-3 h-12 w-12" />
          <p className="text-base font-medium">{t('hold.empty')}</p>
        </div>
      ) : (
        <div className="space-y-3">
          {heldTransactions.map((tx) => {
            const heldTime = new Date(tx.heldAt).toLocaleTimeString();
            return (
              <div
                key={tx.id}
                className="flex items-center justify-between rounded-card border border-border-subtle p-4"
              >
                <div className="flex-1 min-w-0">
                  <p className="truncate text-sm font-semibold text-ink">
                    {tx.label}
                  </p>
                  <p className="mt-0.5 text-sm text-ink-muted">
                    {t('hold.itemCount', { count: tx.itemCount })} &middot;{' '}
                    {t('hold.heldAt', { time: heldTime })}
                  </p>
                  <p className="mt-1 text-sm font-bold text-ink">
                    {format(tx.total)}
                  </p>
                </div>

                <div className="ml-4 flex gap-2">
                  <button
                    onClick={() => onRecall(tx.id)}
                    className="flex min-h-[48px] items-center gap-1.5 rounded-ctl bg-action px-4 py-2 text-sm font-medium text-ink-inverse transition-colors hover:bg-action-hover"
                  >
                    <RotateCcw className="h-4 w-4" />
                    {t('hold.recall')}
                  </button>
                  <button
                    onClick={() => onDiscard(tx.id)}
                    className="flex min-h-[48px] items-center gap-1.5 rounded-ctl bg-danger-surface px-4 py-2 text-sm font-medium text-danger-strong transition-colors hover:bg-danger-surface"
                  >
                    <Trash2 className="h-4 w-4" />
                    {t('hold.discard')}
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </Modal>
  );
}
