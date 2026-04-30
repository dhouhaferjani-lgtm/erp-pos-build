import { useState, useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { RotateCcw } from 'lucide-react';
import { Modal } from './Modal';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import {
  findReceiptByNumber,
  findRecentReceiptsByPartner,
} from '@/lib/offline/voucherRepository';
import { getDatabase } from '@/lib/db';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

// ─── Types ────────────────────────────────────────────────────────────────────

type ActiveTab = 'scan' | 'customer';

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
    <div className="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold text-gray-900">{entry.receipt_number}</p>
        <p className="text-xs text-gray-500">{date}</p>
      </div>
      <div className="shrink-0 text-right">
        <p className="text-sm font-medium text-gray-800">
          {entry.total} {entry.currency}
        </p>
      </div>
      <button
        type="button"
        onClick={() => onRefund(entry)}
        className="shrink-0 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        {t('receiptLocator.refundThis')}
      </button>
    </div>
  );
}

// ─── Scan / Type tab ─────────────────────────────────────────────────────────

interface ScanTabProps {
  terminalId: string;
  onRefund: (entry: LocalReceiptQrIndexEntry) => void;
  companyId: string;
  t: (key: string, opts?: Record<string, unknown>) => string;
  locale: string;
}

function ScanTab({ terminalId, onRefund, companyId, t, locale }: ScanTabProps) {
  const [input, setInput] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  const [found, setFound] = useState<LocalReceiptQrIndexEntry | null | 'not-found' | 'wrong-terminal'>('not-found');
  const [hasSearched, setHasSearched] = useState(false);

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

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-gray-600">{t('receiptLocator.scanHint')}</p>

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
          className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
          aria-label={t('receiptLocator.numberPlaceholder')}
        />
        <button
          type="button"
          onClick={() => void handleSubmit()}
          disabled={isSearching || !input.trim()}
          className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          {isSearching ? t('receiptLocator.searching') : t('receiptLocator.search')}
        </button>
      </div>

      {hasSearched && (
        <div>
          {found === 'not-found' && (
            <p className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
              {t('receiptLocator.notFound')}
            </p>
          )}
          {found === 'wrong-terminal' && (
            <p className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700">
              {t('receiptLocator.wrongTerminal')}
            </p>
          )}
          {found !== 'not-found' && found !== 'wrong-terminal' && found !== null && (
            <div className="flex flex-col gap-3">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                {t('receiptLocator.found')}
              </p>
              <ReceiptRow
                entry={found}
                onRefund={onRefund}
                locale={locale}
                t={t}
              />
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Find by customer tab ────────────────────────────────────────────────────

interface CustomerTabProps {
  onRefund: (entry: LocalReceiptQrIndexEntry) => void;
  companyId: string;
  t: (key: string, opts?: Record<string, unknown>) => string;
  locale: string;
}

function CustomerTab({ onRefund, companyId, t, locale }: CustomerTabProps) {
  const [partnerInput, setPartnerInput] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  const [results, setResults] = useState<LocalReceiptQrIndexEntry[] | null>(null);
  const [hasSearched, setHasSearched] = useState(false);

  const handleSearch = useCallback(async () => {
    const trimmed = partnerInput.trim();
    if (!trimmed) return;

    setIsSearching(true);
    setHasSearched(false);
    try {
      const db = await getDatabase(companyId);
      const entries = await findRecentReceiptsByPartner(db, trimmed, 20);
      setResults(entries);
    } catch {
      setResults([]);
    } finally {
      setIsSearching(false);
      setHasSearched(true);
    }
  }, [partnerInput, companyId]);

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-gray-600">{t('receiptLocator.customerHint')}</p>

      <div className="flex gap-2">
        <input
          type="text"
          value={partnerInput}
          onChange={(e) => {
            setPartnerInput(e.target.value);
            setHasSearched(false);
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              void handleSearch();
            }
          }}
          placeholder={t('receiptLocator.customerPlaceholder')}
          className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
          aria-label={t('receiptLocator.customerPlaceholder')}
        />
        <button
          type="button"
          onClick={() => void handleSearch()}
          disabled={isSearching || !partnerInput.trim()}
          className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          {isSearching ? t('receiptLocator.searching') : t('receiptLocator.search')}
        </button>
      </div>

      {hasSearched && results !== null && results.length === 0 && (
        <p className="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500">
          {t('receiptLocator.noCustomerReceipts')}
        </p>
      )}

      {results !== null && results.length > 0 && (
        <div className="flex flex-col gap-2">
          <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
            {t('receiptLocator.customerResults', { count: results.length })}
          </p>
          {results.map((entry) => (
            <ReceiptRow
              key={entry.receipt_uuid}
              entry={entry}
              onRefund={onRefund}
              locale={locale}
              t={t}
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

/**
 * ReceiptLocatorScreen (Phase H Task 51, spec §6.1).
 *
 * Two-tab modal:
 *   1. "Scan or type ticket number" — always visible; looks up by receipt_number
 *      in local SQLite only. If the found entry belongs to a different terminal,
 *      shows a "not for this terminal" message without emitting any event.
 *   2. "Find by customer" — visible only when the operator holds the
 *      `pos.search_customer_recent_purchases` permission. Queries by partner_id
 *      in local SQLite only.
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
 * FIXED SIZE: the modal uses size="xl" (max-w-2xl) and the content area does
 * not grow when tabs switch — tab content has a fixed min-height so the modal
 * stays dimensionally stable.
 */
export function ReceiptLocatorScreen({ isOpen, onClose }: ReceiptLocatorScreenProps) {
  const { t, i18n } = useTranslation('pos');
  const operator = useOperatorStore((s) => s.operator);
  const terminal = useTerminalStore((s) => s.terminal);
  const companyId = useAuthStore((s) => s.companyId) ?? '';

  const canSearchByCustomer =
    operator?.permissions.includes('pos.search_customer_recent_purchases') ?? false;

  const [activeTab, setActiveTab] = useState<ActiveTab>('scan');

  const handleRefund = useCallback(
    (entry: LocalReceiptQrIndexEntry) => {
      useRefundFlowStore.getState().setPendingScanResult(entry);
      useRefundFlowStore.getState().acceptPendingScan();
      onClose();
    },
    [onClose],
  );

  const terminalId = terminal?.id ?? '';

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('receiptLocator.title')}
      size="xl"
    >
      {/* Tab bar — fixed height so the modal width never changes on switch */}
      <div className="mb-4 flex gap-1 rounded-lg bg-gray-100 p-1">
        <button
          type="button"
          role="tab"
          aria-selected={activeTab === 'scan'}
          onClick={() => setActiveTab('scan')}
          className={`flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
            activeTab === 'scan'
              ? 'bg-white text-gray-900 shadow-sm'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          {t('receiptLocator.tabScan')}
        </button>

        {canSearchByCustomer && (
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === 'customer'}
            onClick={() => setActiveTab('customer')}
            className={`flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
              activeTab === 'customer'
                ? 'bg-white text-gray-900 shadow-sm'
                : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            {t('receiptLocator.tabCustomer')}
          </button>
        )}
      </div>

      {/* Tab content — fixed min-height so modal doesn't resize between tabs */}
      <div className="min-h-[280px]">
        {activeTab === 'scan' && (
          <ScanTab
            terminalId={terminalId}
            onRefund={handleRefund}
            companyId={companyId}
            t={t}
            locale={i18n.language}
          />
        )}
        {activeTab === 'customer' && canSearchByCustomer && (
          <CustomerTab
            onRefund={handleRefund}
            companyId={companyId}
            t={t}
            locale={i18n.language}
          />
        )}
      </div>
    </Modal>
  );
}

// Re-export the RotateCcw icon so callers can use it for the entry button
// without importing from lucide-react directly.
export { RotateCcw as ReturnsIcon };
