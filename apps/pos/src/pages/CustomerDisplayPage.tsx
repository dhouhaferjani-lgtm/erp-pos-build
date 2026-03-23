import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { listen } from '@tauri-apps/api/event';
import type { CartDisplayItem } from '@/lib/customerDisplay';

type DisplayMode =
  | { type: 'idle'; image_url: string }
  | { type: 'cart'; items: CartDisplayItem[]; total: string; currency: string }
  | { type: 'thank_you'; receipt_number: string; total: string; currency: string };

function formatAmount(amount: string, currency: string): string {
  try {
    const num = parseFloat(amount);
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
    }).format(num);
  } catch {
    return `${amount} ${currency}`;
  }
}

function IdleScreen({ imageUrl }: { imageUrl: string }) {
  const { t } = useTranslation('pos');

  return (
    <div className="flex h-full w-full items-center justify-center bg-gray-950">
      {imageUrl ? (
        <img
          src={imageUrl}
          alt=""
          className="max-h-[60vh] max-w-[80vw] object-contain"
        />
      ) : (
        <div className="text-center">
          <div className="text-6xl font-bold tracking-tight text-white/90">
            {t('customerDisplay.brandName')}
          </div>
          <div className="mt-4 text-xl text-white/40">
            {t('customerDisplay.welcome')}
          </div>
        </div>
      )}
    </div>
  );
}

function CartScreen({
  items,
  total,
  currency,
}: {
  items: CartDisplayItem[];
  total: string;
  currency: string;
}) {
  const { t } = useTranslation('pos');

  return (
    <div className="flex h-full w-full flex-col bg-gray-950 text-white">
      {/* Header */}
      <div className="border-b border-white/10 px-8 py-6">
        <h1 className="text-2xl font-bold text-white/90">{t('customerDisplay.yourOrder')}</h1>
      </div>

      {/* Items list */}
      <div className="flex-1 overflow-y-auto px-8 py-4">
        <div className="space-y-3">
          {items.map((item, index) => (
            <div
              key={index}
              className="flex items-center justify-between border-b border-white/5 pb-3"
            >
              <div className="flex items-baseline gap-3">
                <span className="min-w-[2rem] text-right text-lg font-medium text-white/60">
                  {item.quantity}x
                </span>
                <span className="text-xl text-white/90">{item.name}</span>
              </div>
              <span className="text-xl font-medium text-white/80">
                {formatAmount(item.line_total, currency)}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* Total */}
      <div className="border-t border-white/20 bg-white/5 px-8 py-6">
        <div className="flex items-center justify-between">
          <span className="text-2xl font-bold text-white/70">{t('customerDisplay.total')}</span>
          <span className="text-4xl font-bold text-white">
            {formatAmount(total, currency)}
          </span>
        </div>
      </div>
    </div>
  );
}

function ThankYouScreen({
  receiptNumber,
  total,
  currency,
}: {
  receiptNumber: string;
  total: string;
  currency: string;
}) {
  const { t } = useTranslation('pos');

  return (
    <div className="flex h-full w-full items-center justify-center bg-gray-950">
      <div className="text-center">
        {/* Checkmark */}
        <div className="mx-auto mb-8 flex h-28 w-28 items-center justify-center rounded-full bg-green-500/20">
          <svg
            className="h-16 w-16 text-green-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2.5}
              d="M5 13l4 4L19 7"
            />
          </svg>
        </div>

        <h1 className="text-5xl font-bold text-white/90">{t('customerDisplay.thankYou')}</h1>

        <div className="mt-6 text-3xl font-semibold text-white/60">
          {formatAmount(total, currency)}
        </div>

        <div className="mt-4 text-lg text-white/40">
          {t('customerDisplay.receiptLabel', { number: receiptNumber })}
        </div>
      </div>
    </div>
  );
}

/**
 * Customer-Facing Display page.
 * Rendered in the secondary window. Listens for Tauri events from the main window.
 * No interactive elements — display only.
 */
export function CustomerDisplayPage() {
  const [mode, setMode] = useState<DisplayMode>({ type: 'idle', image_url: '' });

  useEffect(() => {
    const unlisten = listen<DisplayMode>('customer-display-update', (event) => {
      setMode(event.payload);
    });

    return () => {
      void unlisten.then((fn) => fn());
    };
  }, []);

  // Allow Escape key to close the customer display window (safety hatch)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        void (async () => {
          try {
            const { invoke } = await import('@tauri-apps/api/core');
            await invoke('close_customer_display');
          } catch {
            try {
              const { getCurrentWindow } = await import('@tauri-apps/api/window');
              await getCurrentWindow().close();
            } catch {
              // Last resort — ignore
            }
          }
        })();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  // Prevent any interaction on this window
  useEffect(() => {
    const preventInteraction = (e: Event) => {
      e.preventDefault();
      e.stopPropagation();
    };

    window.addEventListener('contextmenu', preventInteraction);
    return () => {
      window.removeEventListener('contextmenu', preventInteraction);
    };
  }, []);

  return (
    <div className="h-screen w-screen select-none overflow-hidden" style={{ cursor: 'none' }}>
      {mode.type === 'idle' && <IdleScreen imageUrl={mode.image_url} />}
      {mode.type === 'cart' && (
        <CartScreen items={mode.items} total={mode.total} currency={mode.currency} />
      )}
      {mode.type === 'thank_you' && (
        <ThankYouScreen
          receiptNumber={mode.receipt_number}
          total={mode.total}
          currency={mode.currency}
        />
      )}
    </div>
  );
}
