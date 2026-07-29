import { create } from 'zustand';
import type Database from '@tauri-apps/plugin-sql';
import { fetchPaymentPolicy } from '@/api/paymentPolicyApi';
import {
  getPaymentPolicy,
  upsertPaymentPolicy,
} from '@/lib/db/repositories/paymentPolicyCacheRepository';
import { toSqliteUtc } from '@/lib/db/sqliteTime';

/**
 * Device-side POS payment policy (spec 2026-07-27 §4.3).
 *
 * SQLite-FIRST: `hydratePaymentPolicyFromCache` runs at activation and gives
 * the cashier an offline-authoritative policy before any network call;
 * `refreshPaymentPolicy` then writes the fresh server value through to both
 * SQLite and this slice. A missing policy is NEVER replaced with a fabricated
 * default — `null` means "both mechanisms disabled", i.e. exact today-behavior.
 */
export interface PaymentPolicy {
  readonly cashRoundingEnabled: boolean;
  readonly cashRoundingDenomination: string | null;
  readonly tenderToleranceEnabled: boolean;
  readonly tenderTolerancePercentage: string;
  readonly tenderToleranceMaxAmount: string;
  readonly currencyCode: string;
  readonly currencyScale: number;
  /** `YYYY-MM-DD HH:MM:SS` UTC — the SQLite TEXT format, on BOTH paths. */
  readonly refreshedAt: string | null;
}

interface PaymentPolicyState {
  policy: PaymentPolicy | null;
}

interface PaymentPolicyActions {
  setPolicy: (policy: PaymentPolicy | null) => void;
  reset: () => void;
}

export const usePaymentPolicyStore = create<PaymentPolicyState & PaymentPolicyActions>()((set) => ({
  policy: null,
  setPolicy: (policy) => set({ policy }),
  reset: () => set({ policy: null }),
}));

/**
 * Synchronous, non-hook accessor. Safe from stores / library code (the
 * checkout snapshot builder calls it at tender time). Returns null when no
 * policy has been hydrated — callers MUST treat null as "disabled".
 */
export function getActivePaymentPolicy(): PaymentPolicy | null {
  return usePaymentPolicyStore.getState().policy;
}

/**
 * Load the cached policy for `companyId` into the slice.
 *
 * A missing row CLEARS the slice rather than leaving whatever was there. The
 * slice is a global in-memory singleton while the SQLite file is per-company
 * (`izipos-${companyId}.db`), so a company switch that finds no cached row for
 * the NEW company would otherwise keep deciding checkouts with the PREVIOUS
 * company's denomination and tolerance caps — values that then get signed into
 * the v3 payload. `refreshPaymentPolicy` is the only other writer, so offline
 * there is nothing else to correct it.
 */
export async function hydratePaymentPolicyFromCache(
  db: Database,
  companyId: string,
): Promise<void> {
  const row = await getPaymentPolicy(db, companyId);
  if (row === null) {
    usePaymentPolicyStore.getState().reset();
    return;
  }
  usePaymentPolicyStore.getState().setPolicy({
    cashRoundingEnabled: row.cash_rounding_enabled,
    cashRoundingDenomination: row.cash_rounding_denomination,
    tenderToleranceEnabled: row.tender_tolerance_enabled,
    tenderTolerancePercentage: row.tender_tolerance_percentage,
    tenderToleranceMaxAmount: row.tender_tolerance_max_amount,
    currencyCode: row.currency_code,
    currencyScale: row.currency_scale,
    refreshedAt: row.refreshed_at ?? null,
  });
}

export async function refreshPaymentPolicy(db: Database, companyId: string): Promise<void> {
  const response = await fetchPaymentPolicy();
  await upsertPaymentPolicy(db, {
    company_id: companyId,
    cash_rounding_enabled: response.cashRoundingEnabled,
    cash_rounding_denomination: response.cashRoundingDenomination,
    tender_tolerance_enabled: response.tenderToleranceEnabled,
    tender_tolerance_percentage: response.tenderTolerancePercentage,
    tender_tolerance_max_amount: response.tenderToleranceMaxAmount,
    currency_code: response.currencyCode,
    currency_scale: response.currencyScale,
  });
  usePaymentPolicyStore.getState().setPolicy({
    cashRoundingEnabled: response.cashRoundingEnabled,
    cashRoundingDenomination: response.cashRoundingDenomination,
    tenderToleranceEnabled: response.tenderToleranceEnabled,
    tenderTolerancePercentage: response.tenderTolerancePercentage,
    tenderToleranceMaxAmount: response.tenderToleranceMaxAmount,
    currencyCode: response.currencyCode,
    currencyScale: response.currencyScale,
    // ONE format on the device (rule 20): the upsert above writes the column
    // with datetime('now'), so the slice mirrors the same device clock in the
    // same `YYYY-MM-DD HH:MM:SS` UTC shape a later hydrate will read back.
    // The server's `response.refreshedAt` is deliberately NOT used here — it
    // is the server clock in ISO form, and mixing it in would make the value
    // disagree with the cached row across clock skew and separator.
    refreshedAt: toSqliteUtc(new Date().toISOString()),
  });
}
