import { useState, useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { RotateCcw } from 'lucide-react';
import { Modal } from './Modal';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { findReceiptByNumber } from '@/lib/offline/voucherRepository';
import { getDatabase } from '@/lib/db';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

// ─── Types ────────────────────────────────────────────────────────────────────

interface ReceiptLocatorScreenProps {
  isOpen: boolean;
  onClose: () => void;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatDate(postedAt: string, locale: string): string {
  const ts = Date.parse(postedAt);
  if (Number.isNaN(ts)) return postedAt;
  try {
    return new Intl.DateTimeFormat(locale, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(ts));
  } catch {
    return postedAt;
  }
}

// ─── Sub-components ──────────────────────────────────────────────────────────

interface ReceiptRowProps {
  entry: LocalReceiptQrIndexEntry;
  onRefund: (entry: LocalReceiptQrIndexEntry) => void;
  locale: string;
  t: (key: string, opts?: Record<string, unknown>) => string;
}

function ReceiptRow({ entry, onRefund, locale, t }: ReceiptRowProps) {
  const date = useMemo(() => formatDate(entry.posted_at, locale), [entry.posted_at, locale]);

  return (
    <div className="flex items-center justify-between gap-4 rounded-tile border border-border-subtle bg-surface-sunken px-4 py-3">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold text-ink">{entry.receipt_number}</p>
        <p className="text-xs text-ink-muted">{date}</p>
      </div>
      <div className="shrink-0 text-right">
        <p className="text-sm font-medium text-ink">
          {entry.total} {entry.currency}
        </p>
      </div>
      <button
        type="button"
        onClick={() => onRefund(entry)}
        className="flex min-h-[48px] shrink-0 items-center justify-center rounded-ctl bg-action px-3 text-sm font-medium text-ink-inverse hover:bg-action-hover focus:outline-none focus:ring-2 focus:ring-accent"
      >
        {t('receiptLocator.refundThis')}
      </button>
    </div>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

/**
 * ReceiptLocatorScreen (Phase H Task 51, spec §6.1 — M2-UI fix).
 *
 * Single-search modal: "Scan or type ticket number" — looks up by
 * receipt_number in local SQLite only. If the found entry belongs to a
 * different terminal, shows a "not for this terminal" message without emitting
 * any event.
 *
 * The "Find by customer" tab has been removed (Codex review M2-UI). It was
 * wired to a partner_id UUID input that cashiers cannot produce at the till.
 * Phase 2 will rebuild customer search with proper phone/email/loyalty-card
 * identifier resolution once those are mirrored locally for offline use.
 * The `partner_id` column on `receipt_qr_index` stays populated (M2-backend)
 * so Phase 2 can query it immediately on reactivation.
 *
 * "Refund this" on any row calls:
 *   `useRefundFlowStore.getState().setPendingScanResult(entry)` then `acceptPendingScan()`
 * which emits the same `ReceiptTokenAccepted` event that Task 50's scan
 * dispatcher emits — Task 52 has a single consumer for both entry-points.
 *
 * CONTRACT: No API call is ever made from this component. Vitest mocks for
 * apiGet, apiPost, @tauri-apps/plugin-http.fetch, and globalThis.fetch must
 * remain un-called across all flows.
 *
 * FIXED SIZE: the modal uses size="xl" (max-w-2xl) and the content area has a
 * fixed min-height so the modal stays dimensionally stable regardless of search
 * results (feedback_modal_fixed_size.md invariant).
 */
export function ReceiptLocatorScreen({ isOpen, onClose }: ReceiptLocatorScreenProps) {
  const { t, i18n } = useTranslation('pos');
  const terminal = useTerminalStore((s) => s.terminal);
  const companyId = useAuthStore((s) => s.companyId) ?? '';

  const [input, setInput] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  // null = not yet searched; 'not-found' | 'wrong-terminal' = error state; entry = found
  const [found, setFound] = useState<LocalReceiptQrIndexEntry | null | 'not-found' | 'wrong-terminal'>(null);
  const [hasSearched, setHasSearched] = useState(false);

  const terminalId = terminal?.id ?? '';

  const handleSubmit = useCallback(async () => {
    const trimmed = input.trim();
    if (!trimmed) return;

    setIsSearching(true);
    setHasSearched(false);
    try {
      const db = await getDatabase(companyId);
      const entry = await findReceiptByNumber(db, trimmed);
      if (entry === null) {
        setFound('not-found');
      } else if (entry.terminal_id !== terminalId) {
        setFound('wrong-terminal');
      } else {
        setFound(entry);
      }
    } catch {
      setFound('not-found');
    } finally {
      setIsSearching(false);
      setHasSearched(true);
    }
  }, [input, companyId, terminalId]);

  const handleRefund = useCallback(
    (entry: LocalReceiptQrIndexEntry) => {
      useRefundFlowStore.getState().setPendingScanResult(entry);
      useRefundFlowStore.getState().acceptPendingScan();
      onClose();
    },
    [onClose],
  );

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('receiptLocator.title')}
      size="xl"
    >
      {/* Fixed min-height preserves modal dimensions regardless of result state */}
      <div data-testid="receipt-locator-body" className="flex min-h-[280px] flex-col gap-4">
        <p className="text-sm text-ink-muted">{t('receiptLocator.scanHint')}</p>

        <div className="flex gap-2">
          <input
            type="text"
            value={input}
            onChange={(e) => {
              setInput(e.target.value);
              setHasSearched(false);
            }}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                void handleSubmit();
              }
            }}
            placeholder={t('receiptLocator.numberPlaceholder')}
            className="flex-1 rounded-ctl border border-border-strong px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent"
            aria-label={t('receiptLocator.numberPlaceholder')}
          />
          <button
            type="button"
            onClick={() => void handleSubmit()}
            disabled={isSearching || !input.trim()}
            className="flex min-h-[48px] items-center justify-center rounded-ctl bg-action px-4 py-2 text-sm font-medium text-ink-inverse hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-accent"
          >
            {isSearching ? t('receiptLocator.searching') : t('receiptLocator.search')}
          </button>
        </div>

        {hasSearched && (
          <div>
            {found === 'not-found' && (
              <p role="alert" className="rounded-tile bg-danger-surface px-4 py-3 text-sm text-danger-strong">
                {t('receiptLocator.notFound')}
              </p>
            )}
            {found === 'wrong-terminal' && (
              <p role="alert" className="rounded-tile bg-warning-surface px-4 py-3 text-sm text-warning-strong">
                {t('receiptLocator.wrongTerminal')}
              </p>
            )}
            {found !== 'not-found' && found !== 'wrong-terminal' && found !== null && (
              <div className="flex flex-col gap-3">
                <p className="text-xs font-medium uppercase tracking-wide text-ink-muted">
                  {t('receiptLocator.found')}
                </p>
                <ReceiptRow
                  entry={found}
                  onRefund={handleRefund}
                  locale={i18n.language}
                  t={t}
                />
              </div>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

// Re-export the RotateCcw icon so callers can use it for the entry button
// without importing from lucide-react directly.
export { RotateCcw as ReturnsIcon };
