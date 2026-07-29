import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import {
  getAllPaymentMethods,
  upsertPaymentMethods,
} from '@/lib/db/repositories/paymentRepository';
import { makePaymentMethod } from '@/test/helpers';

describe('paymentRepository — is_cash_tender wire', () => {
  let adapter: SqliteTestAdapter;
  let db: Database;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
    db = adapter.asDatabase() as unknown as Database;
  });

  afterEach(() => {
    adapter.close();
  });

  it('persists is_cash_tender = true and reads it back as a boolean', async () => {
    await upsertPaymentMethods(db, [
      makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true }),
      makePaymentMethod({ id: 'pm-card', code: 'CARD', is_cash_tender: false, is_physical: false }),
    ]);

    const methods = await getAllPaymentMethods(db);
    const byId = new Map(methods.map((m) => [m.id, m]));
    expect(byId.get('pm-cash')?.is_cash_tender).toBe(true);
    expect(byId.get('pm-card')?.is_cash_tender).toBe(false);
  });

  it('flips the flag on re-upsert (ON CONFLICT branch carries the column)', async () => {
    await upsertPaymentMethods(db, [
      makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true }),
    ]);
    await upsertPaymentMethods(db, [
      makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: false }),
    ]);

    const methods = await getAllPaymentMethods(db);
    expect(methods[0]?.is_cash_tender).toBe(false);
  });

  it('MEAL_VOUCHER is never cash even though it is physical and has no maturity', async () => {
    // The legacy quick-cash selection predicate (is_physical && !has_maturity)
    // misclassifies this seeded method; the flag is the only correct source.
    await upsertPaymentMethods(db, [
      makePaymentMethod({
        id: 'pm-meal',
        code: 'MEAL_VOUCHER',
        is_physical: true,
        has_maturity: false,
        is_cash_tender: false,
      }),
    ]);

    const methods = await getAllPaymentMethods(db);
    expect(methods[0]?.is_physical).toBe(true);
    expect(methods[0]?.has_maturity).toBe(false);
    expect(methods[0]?.is_cash_tender).toBe(false);
  });
});
