import { useEffect, useRef } from 'react';
import { useCustomerDisplayStore } from '@/stores/customerDisplayStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useAuthStore } from '@/stores/authStore';
import { sendCartUpdate, sendIdleScreen, sendThankYou } from '@/lib/customerDisplay';
import type { CartDisplayItem } from '@/lib/customerDisplay';

const THANK_YOU_DURATION_MS = 5_000;

/**
 * Hook that synchronizes cart state and checkout events to the customer display.
 * Should be mounted once in AppShell. Only active when the display is open.
 */
export function useCustomerDisplaySync(): void {
  const isOpen = useCustomerDisplayStore((s) => s.isOpen);
  const idleImagePath = useCustomerDisplayStore((s) => s.idleImagePath);
  const items = useCartStore((s) => s.items);
  const lastReceipt = usePaymentStore((s) => s.lastReceipt);
  const thankYouTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const showingThankYou = useRef(false);

  // Get currency from auth store
  const companies = useAuthStore((s) => s.companies);
  const companyId = useAuthStore((s) => s.companyId);
  const currency =
    companies.find((c) => c.id === companyId)?.currency ?? 'EUR';

  // Sync cart changes to CFD
  useEffect(() => {
    if (!isOpen || showingThankYou.current) return;

    const syncDisplay = async () => {
      try {
        if (items.length > 0) {
          const displayItems: CartDisplayItem[] = items.map((item) => ({
            name: item.product.name,
            quantity: item.quantity,
            line_total: item.line_total,
          }));
          const total = useCartStore.getState().total().toFixed(2);
          await sendCartUpdate(displayItems, total, currency);
        } else {
          await sendIdleScreen(idleImagePath);
        }
      } catch {
        // CFD communication failure is non-critical
      }
    };

    void syncDisplay();
  }, [isOpen, items, idleImagePath, currency]);

  // Handle checkout → thank-you screen
  useEffect(() => {
    if (!isOpen || !lastReceipt) return;

    showingThankYou.current = true;

    const showThankYou = async () => {
      try {
        await sendThankYou(
          lastReceipt.receipt_number,
          lastReceipt.total,
          currency,
        );
      } catch {
        // Non-critical
      }
    };

    void showThankYou();

    // Return to idle after delay
    thankYouTimerRef.current = setTimeout(() => {
      showingThankYou.current = false;
      void sendIdleScreen(idleImagePath).catch(() => {});
    }, THANK_YOU_DURATION_MS);

    return () => {
      if (thankYouTimerRef.current) {
        clearTimeout(thankYouTimerRef.current);
        thankYouTimerRef.current = null;
      }
      showingThankYou.current = false;
    };
  }, [isOpen, lastReceipt, idleImagePath, currency]);
}
