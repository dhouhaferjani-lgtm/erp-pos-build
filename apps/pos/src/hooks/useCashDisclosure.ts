import { useEffect, useState } from 'react';
import { resolveCashDisclosure } from '@/lib/offline/cashDisclosurePolicy';
import type { CashDisclosure } from '@/lib/offline/cashDisclosurePolicy';

/**
 * The blind-cash-count policy, resolved AT MOUNT (B-13 (iii)).
 *
 * `resolveCashDisclosure` is a per-screen await everywhere else — `/shift` and
 * `/reports` both resolve it inside the effect that loads their data. The
 * Header cannot: it renders the shift-badge tooltip (the opening float) on
 * EVERY route and owns the X report entry point, so it needs the answer before
 * an operator touches either, not when the End-of-Day modal opens. Hence a
 * hook: one resolution per company, held for the life of the shell.
 *
 * Fails CLOSED in both directions:
 *   - 'conceal' is the initial value, so the pre-answer window discloses
 *     nothing (`resolveCashDisclosure` itself only discloses on a positive
 *     `require_blind_cash_count === false`);
 *   - a company switch re-arms to 'conceal' BEFORE the new read, so the
 *     previous company's answer never carries across the boundary.
 *
 * Returns 'conceal' while `companyId` is null — there is no company whose
 * policy could have said otherwise.
 */
export function useCashDisclosure(companyId: string | null): CashDisclosure {
  const [disclosure, setDisclosure] = useState<CashDisclosure>('conceal');

  useEffect(() => {
    setDisclosure('conceal');
    if (!companyId) return;
    let cancelled = false;

    void (async () => {
      try {
        const { getDatabase } = await import('@/lib/db');
        const db = await getDatabase(companyId);
        const policy = await resolveCashDisclosure(db, companyId);
        if (!cancelled) setDisclosure(policy);
      } catch {
        // resolveCashDisclosure already fails closed on every read failure it
        // can see; a database that will not open lands in the same place.
        if (!cancelled) setDisclosure('conceal');
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [companyId]);

  return disclosure;
}
