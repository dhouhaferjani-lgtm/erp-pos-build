import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

/**
 * Offline-authoritative POS payment policy (spec 2026-07-27 §4.2/§4.3).
 *
 * Money columns are TEXT and are carried as decimal STRINGS end-to-end: the
 * denomination is signed into the SALE_RECEIPT canonical bytes, so any hop
 * that turns '0.050' into 0.05 makes every rounded receipt quarantine.
 */
export interface PaymentPolicyCacheRow {
  company_id: string;
  cash_rounding_enabled: boolean;
  cash_rounding_denomination: string | null;
  tender_tolerance_enabled: boolean;
  tender_tolerance_percentage: string;
  tender_tolerance_max_amount: string;
  currency_code: string;
  currency_scale: number;
  refreshed_at?: string;
}

interface RawRow extends Omit<
  PaymentPolicyCacheRow,
  'cash_rounding_enabled' | 'tender_tolerance_enabled'
> {
  cash_rounding_enabled: number;
  tender_tolerance_enabled: number;
  refreshed_at: string;
}

export async function upsertPaymentPolicy(
  db: Database,
  row: PaymentPolicyCacheRow,
): Promise<void> {
  // `currency_scale` is ALWAYS bound explicitly: the column's `DEFAULT 2` is
  // wrong for a scale-3 currency (TND), so the default must stay unreachable.
  await execute(
    db,
    `INSERT INTO payment_policy_cache (
       company_id,
       cash_rounding_enabled, cash_rounding_denomination,
       tender_tolerance_enabled, tender_tolerance_percentage, tender_tolerance_max_amount,
       currency_code, currency_scale, refreshed_at
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, datetime('now'))
     ON CONFLICT(company_id) DO UPDATE SET
       cash_rounding_enabled        = excluded.cash_rounding_enabled,
       cash_rounding_denomination   = excluded.cash_rounding_denomination,
       tender_tolerance_enabled     = excluded.tender_tolerance_enabled,
       tender_tolerance_percentage  = excluded.tender_tolerance_percentage,
       tender_tolerance_max_amount  = excluded.tender_tolerance_max_amount,
       currency_code                = excluded.currency_code,
       currency_scale               = excluded.currency_scale,
       refreshed_at                 = datetime('now')`,
    [
      row.company_id,
      row.cash_rounding_enabled ? 1 : 0,
      row.cash_rounding_denomination,
      row.tender_tolerance_enabled ? 1 : 0,
      row.tender_tolerance_percentage,
      row.tender_tolerance_max_amount,
      row.currency_code,
      row.currency_scale,
    ],
  );
}

export async function getPaymentPolicy(
  db: Database,
  companyId: string,
): Promise<PaymentPolicyCacheRow | null> {
  const rows = await queryAll<RawRow>(
    db,
    `SELECT company_id,
            cash_rounding_enabled, cash_rounding_denomination,
            tender_tolerance_enabled, tender_tolerance_percentage, tender_tolerance_max_amount,
            currency_code, currency_scale, refreshed_at
     FROM payment_policy_cache
     WHERE company_id = $1`,
    [companyId],
  );
  if (rows.length === 0) {
    // The SQLite file is ALREADY per-company (`izipos-${companyId}.db`,
    // src/lib/db.ts:12), so a keyed miss while the table holds some OTHER
    // company_id means the caller's id disagrees with what was cached — the
    // policy then degrades to "rounding and tolerance disabled" with no
    // signal. Fail-closed still applies; this only makes the mismatch visible.
    const cached = await queryAll<{ company_id: string }>(
      db,
      'SELECT company_id FROM payment_policy_cache LIMIT 5',
    );
    if (cached.length > 0) {
      console.warn('[POS][paymentPolicyCache] no row for this company_id — cache holds another', {
        requested: companyId,
        cached: cached.map((r) => r.company_id),
      });
    }
    return null;
  }
  const r = rows[0]!;
  return {
    ...r,
    cash_rounding_enabled: r.cash_rounding_enabled === 1,
    tender_tolerance_enabled: r.tender_tolerance_enabled === 1,
  };
}
