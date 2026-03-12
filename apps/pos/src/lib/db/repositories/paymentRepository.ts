import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';

interface PaymentMethodRow {
  id: string;
  code: string;
  name: string;
  is_physical: number;
  has_maturity: number;
  requires_third_party: number;
  is_push: number;
  has_deducted_fees: number;
  is_restricted: number;
  fee_type: string | null;
  fee_fixed: string;
  fee_percent: string;
  restriction_type: string | null;
  is_active: number;
  position: number;
}

interface PaymentRepositoryRow {
  id: string;
  code: string;
  name: string;
  type: string;
  bank_name: string | null;
  account_number: string | null;
  iban: string | null;
  bic: string | null;
  balance: string;
  is_active: number;
}

function rowToMethod(row: PaymentMethodRow): PaymentMethod {
  return {
    id: row.id,
    code: row.code,
    name: row.name,
    is_physical: row.is_physical === 1,
    has_maturity: row.has_maturity === 1,
    requires_third_party: row.requires_third_party === 1,
    is_push: row.is_push === 1,
    has_deducted_fees: row.has_deducted_fees === 1,
    is_restricted: row.is_restricted === 1,
    fee_type: row.fee_type,
    fee_fixed: row.fee_fixed,
    fee_percent: row.fee_percent,
    restriction_type: row.restriction_type,
    is_active: row.is_active === 1,
    position: row.position,
  };
}

function rowToRepository(row: PaymentRepositoryRow): PaymentRepository {
  return {
    id: row.id,
    code: row.code,
    name: row.name,
    type: row.type as PaymentRepository['type'],
    bank_name: row.bank_name,
    account_number: row.account_number,
    iban: row.iban,
    bic: row.bic,
    balance: row.balance,
    is_active: row.is_active === 1,
  };
}

export async function getAllPaymentMethods(db: Database): Promise<PaymentMethod[]> {
  const rows = await queryAll<PaymentMethodRow>(
    db,
    'SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY position'
  );
  return rows.map(rowToMethod);
}

export async function getAllPaymentRepositories(db: Database): Promise<PaymentRepository[]> {
  const rows = await queryAll<PaymentRepositoryRow>(
    db,
    'SELECT * FROM payment_repositories WHERE is_active = 1 ORDER BY name'
  );
  return rows.map(rowToRepository);
}

export async function upsertPaymentMethods(db: Database, methods: PaymentMethod[]): Promise<void> {
  for (const m of methods) {
    await execute(
      db,
      `INSERT INTO payment_methods (id, code, name, is_physical, has_maturity, requires_third_party, is_push, has_deducted_fees, is_restricted, fee_type, fee_fixed, fee_percent, restriction_type, is_active, position, synced_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         code = excluded.code, name = excluded.name, is_physical = excluded.is_physical,
         has_maturity = excluded.has_maturity, requires_third_party = excluded.requires_third_party,
         is_push = excluded.is_push, has_deducted_fees = excluded.has_deducted_fees,
         is_restricted = excluded.is_restricted, fee_type = excluded.fee_type,
         fee_fixed = excluded.fee_fixed, fee_percent = excluded.fee_percent,
         restriction_type = excluded.restriction_type, is_active = excluded.is_active,
         position = excluded.position, synced_at = datetime('now')`,
      [
        m.id, m.code, m.name, m.is_physical ? 1 : 0, m.has_maturity ? 1 : 0,
        m.requires_third_party ? 1 : 0, m.is_push ? 1 : 0, m.has_deducted_fees ? 1 : 0,
        m.is_restricted ? 1 : 0, m.fee_type, m.fee_fixed, m.fee_percent,
        m.restriction_type, m.is_active ? 1 : 0, m.position,
      ]
    );
  }
}

export async function upsertPaymentRepositories(db: Database, repos: PaymentRepository[]): Promise<void> {
  for (const r of repos) {
    await execute(
      db,
      `INSERT INTO payment_repositories (id, code, name, type, bank_name, account_number, iban, bic, balance, is_active, synced_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         code = excluded.code, name = excluded.name, type = excluded.type,
         bank_name = excluded.bank_name, account_number = excluded.account_number,
         iban = excluded.iban, bic = excluded.bic, balance = excluded.balance,
         is_active = excluded.is_active, synced_at = datetime('now')`,
      [
        r.id, r.code, r.name, r.type, r.bank_name,
        r.account_number, r.iban, r.bic, r.balance, r.is_active ? 1 : 0,
      ]
    );
  }
}
