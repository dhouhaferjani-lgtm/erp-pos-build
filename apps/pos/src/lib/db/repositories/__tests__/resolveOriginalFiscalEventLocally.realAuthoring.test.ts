/**
 * v3-refund-chain-integration wave-2 review fix (FISCAL CRITICAL) —
 * `resolveOriginalFiscalEventLocally()` parsed `offline_receipts.canonical_bytes`
 * as if it WERE the signed fiscal payload. It is the chain ENVELOPE
 * (`FiscalEventEngine.ts`'s `canonicalPayload`, :620-637) — the actual
 * signed payload is nested one level down at `envelope.payload`. Reading
 * `training_flag`/`transaction_discount_amount`/`line_items` off the
 * envelope's top level silently missed every one of them and fell back to
 * a PERMISSIVE default (`false`/`'0'`/`[]`), meaning the §3.5/§3.7 refusals
 * could never fire — the exact silent over-refund class the orchestrator's
 * ruling exists to eliminate.
 *
 * This test proves the fix against a REAL sealed original — no mocks on
 * the producer: a real `FiscalEventEngine` (SqliteTestAdapter harness,
 * same pattern as `FiscalEventEngine.test.ts`) seals a genuine v3
 * SALE_RECEIPT, the resulting `canonical_bytes` is written into a real
 * `offline_receipts` row via the real `insertOfflineReceipt()`, and the
 * real `resolveOriginalFiscalEventLocally()` is called against that
 * adapter. Then `assertOriginalRefundable()` — the EXACT function
 * `refundCheckoutStore.ts`'s `beginV4()` calls immediately after resolving
 * the original, with no logic in between — is called on the real resolved
 * view and asserted to throw, proving the full real chain: seal → resolve
 * → refuse.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import {
  FiscalEventEngine,
  type FiscalEventAppendRequest,
} from '@/lib/fiscal/FiscalEventEngine';
import { FiscalEventCanonicalEncoder } from '@/lib/fiscal/FiscalEventCanonicalEncoder';
import { HashChainIntegrityProvider } from '@/lib/fiscal/HashChainIntegrityProvider';
import { FiscalEventPayloadRegistry } from '@/lib/fiscal/FiscalEventPayloadRegistry';
import { insertOfflineReceipt, type OfflineReceipt } from '../offlineReceiptRepository';
import { resolveOriginalFiscalEventLocally } from '../fiscalEventRepository';
import {
  assertOriginalRefundable,
  TrainingOriginalRefundRefusedError,
  WholeReceiptDiscountRefundRefusedError,
} from '@/lib/fiscal/payloads/RefundReceiptV4Payload';

const nodeSqliteAvailable = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';
// Chain routing (request.terminal_id / terminal_state.terminal_id) uses the
// device's plain local id; the SIGNED PAYLOAD's own `terminal_id` field is
// a separate, independently-validated UUID (FiscalEventEngine.ts's
// assertUuid on the payload, distinct from the request-level field).
const TERMINAL_ID = 'terminal-1';
const SR_TERMINAL_UUID = '11111111-1111-1111-1111-111111111111';
const CASHIER_UUID = '22222222-2222-2222-2222-222222222222';
const SHIFT_UUID = '33333333-3333-3333-3333-333333333333';
const GENESIS_SEED = 'a'.repeat(64);

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

async function seedTerminalState(adapter: SqliteTestAdapter): Promise<void> {
  // Seeds BOTH the operational AND training_operational chain heads — the
  // training-original test case appends under `chain_context:
  // 'training_operational'` (required whenever `payload.training_flag ===
  // true`, FiscalEventEngine.ts:814-818), which reads its genesis seed
  // from separate `training_fiscal_event_*` columns.
  await adapter.execute(
    `INSERT INTO terminal_state (
       terminal_id, terminal_code, genesis_seed, last_hash,
       fiscal_event_genesis_seed, fiscal_event_last_hash, fiscal_event_sequence,
       training_fiscal_event_genesis_seed, training_fiscal_event_last_hash, training_fiscal_event_sequence
     ) VALUES ($1, 'T01', 'legacy-seed', 'legacy-hash', $2, '', 0, $2, '', 0)`,
    [TERMINAL_ID, GENESIS_SEED],
  );
}

/** A structurally-valid v3 SALE_RECEIPT payload — same shape proven valid
 *  in FiscalEventEngine.test.ts's own `validSaleReceiptPayload()`. */
function saleReceiptV3Payload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    approval_references: [],
    business_date: '2026-08-01',
    buyer: null,
    cash_rounding_adjustment: '0.000',
    cash_rounding_denomination: '0.000',
    cashier_id: CASHIER_UUID,
    cashier_name: 'Alice',
    consumption_mode: null,
    currency_code: 'TND',
    currency_scale: 3,
    event_time_device: '2026-08-01T10:00:00.000Z',
    invoice_type_code: 'SALE',
    line_items: [
      {
        gtin: null,
        line_discount_amount: '0.000',
        line_discount_reason: null,
        line_subtotal: '10.000',
        line_vat: '2.000',
        name: 'Espresso',
        non_collected_subtype: null,
        product_id: 'p-1',
        quantity: '1.000',
        sku: 'A',
        tax_category_code: '',
        unit_price: '10.000',
        variant_id: null,
        variant_name: null,
        variant_sku: null,
        vat_rate: '20.00',
      },
    ],
    lottery_code: null,
    notes: null,
    original_receipt_reference: null,
    payments: [
      {
        amount: '12.000',
        foreign_currency_amount: null,
        foreign_currency_code: null,
        instrument_serial: null,
        instrument_type: null,
        method_code: 'cash',
      },
    ],
    receipt_uuid: '44444444-4444-4444-4444-444444444444',
    seller: {
      address: {
        city: 'Tunis',
        country_code: 'TN',
        postal_code: '1000',
        street: '1 Rue de la Liberte',
      },
      name: 'Cafe Tunis',
      tax_jurisdiction_country_code: 'TN',
      tax_number: '1234567AM000',
    },
    shift_id: SHIFT_UUID,
    subtotal: '10.000',
    table_id: null,
    terminal_id: SR_TERMINAL_UUID,
    total: '12.000',
    training_flag: false,
    transaction_discount_amount: '0.000',
    transaction_discount_reason: null,
    vat_breakdown: [
      {
        gross_amount: '12.000',
        net_amount: '10.000',
        rate: '20.00',
        tax_category_code: '',
        vat_amount: '2.000',
      },
    ],
    vat_total: '2.000',
    vouchers_redeemed: [],
    ...overrides,
  };
}

d('resolveOriginalFiscalEventLocally — real sealed original (wave-2 fix)', () => {
  let adapter: SqliteTestAdapter;
  let engine: FiscalEventEngine;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
    await seedTerminalState(adapter);
    engine = new FiscalEventEngine(
      adapter,
      new FiscalEventCanonicalEncoder(),
      new HashChainIntegrityProvider(),
      new FiscalEventPayloadRegistry(),
    );
  });

  afterEach(() => {
    adapter.close();
  });

  /** Seals `payload` as a real SALE_RECEIPT and writes the matching
   *  offline_receipts row exactly as receiptService.ts does: same
   *  receiptId, canonical_bytes = the engine's own envelope bytes,
   *  source_event_class/source_event_id linking the two tables. */
  async function sealOriginal(receiptId: string, payload: Record<string, unknown>): Promise<void> {
    // A training_flag=true payload MUST append under the training_*
    // chain_context (FiscalEventEngine.ts:814-818) -- exactly why
    // seedTerminalState above seeds both chain heads.
    const isTraining = payload['training_flag'] === true;
    const request: FiscalEventAppendRequest = {
      event_type: 'SALE_RECEIPT',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      terminal_id: TERMINAL_ID,
      operator_id: CASHIER_UUID,
      event_time_device: '2026-08-01T10:00:00Z',
      business_date: '2026-08-01',
      chain_context: isTraining ? 'training_operational' : 'operational',
      payload: { ...payload, receipt_uuid: receiptId },
      source_event_class: 'offline_receipts',
      source_event_id: receiptId,
    };
    const appended = await engine.append(adapter, request);

    const total = (payload['total'] as string) ?? '12.000';
    const offlineReceipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'> = {
      id: receiptId,
      idempotency_key: receiptId,
      receipt_number: 'MAIN-T01-2026-00000001',
      terminal_id: TERMINAL_ID,
      terminal_code: 'T01',
      operator_id: CASHIER_UUID,
      operator_name: 'Alice',
      lines: JSON.stringify([]),
      subtotal: (payload['subtotal'] as string) ?? '10.000',
      tax_amount: (payload['vat_total'] as string) ?? '2.000',
      discount_amount: '0.000',
      total,
      currency: 'TND',
      fiscal_hash: appended.current_hash,
      previous_hash: appended.previous_hash,
      hash_sequence: appended.sequence_number,
      transaction_discount_amount: (payload['transaction_discount_amount'] as string) ?? '0.000',
      transaction_discount_reason: null,
      tendered_amount: total,
      change_due: '0.000',
      payment_method_id: 'pm-cash',
      payment_repository_id: 'repo-1',
      status: 'pending',
      payments_json: JSON.stringify([
        { payment_method_id: 'pm-cash', amount: total, method_code: 'CASH' },
      ]),
      consumption_mode: null,
      table_id: null,
      fiscal_schema_version: 3,
      is_training: payload['training_flag'] === true ? 1 : 0,
      canonical_bytes: appended.canonical_bytes,
      cash_rounding_adjustment: '0.000',
      cash_rounding_denomination: '0.000',
      tolerance_shortfall: null,
      receipt_kind: 'sale',
    };
    await insertOfflineReceipt(adapter.asDatabase(), offlineReceipt);
  }

  it('§3.7 — a REAL sealed training original resolves trainingFlag: true (not the old silent false)', async () => {
    const receiptId = '55555555-5555-4555-8555-555555555555';
    await sealOriginal(receiptId, saleReceiptV3Payload({ training_flag: true, invoice_type_code: 'TRAINING' }));

    const view = await resolveOriginalFiscalEventLocally(adapter.asDatabase(), receiptId);

    expect(view).not.toBeNull();
    expect(view?.trainingFlag).toBe(true);
    expect(view?.lineItems).toHaveLength(1);
  });

  it('§3.7 — assertOriginalRefundable (the EXACT check begin() runs) throws TrainingOriginalRefundRefusedError on the real resolved view', async () => {
    const receiptId = '66666666-6666-4666-8666-666666666666';
    await sealOriginal(receiptId, saleReceiptV3Payload({ training_flag: true, invoice_type_code: 'TRAINING' }));

    const view = await resolveOriginalFiscalEventLocally(adapter.asDatabase(), receiptId);
    expect(view).not.toBeNull();

    expect(() => assertOriginalRefundable(view!, receiptId)).toThrow(
      TrainingOriginalRefundRefusedError,
    );
  });

  it('§3.5 — a REAL sealed discounted original resolves the true non-zero transaction_discount_amount (not the old silent \'0\')', async () => {
    const receiptId = '77777777-7777-4777-8777-777777777777';
    await sealOriginal(
      receiptId,
      saleReceiptV3Payload({
        total: '10.000',
        transaction_discount_amount: '2.000',
        transaction_discount_reason: 'loyalty',
      }),
    );

    const view = await resolveOriginalFiscalEventLocally(adapter.asDatabase(), receiptId);

    expect(view).not.toBeNull();
    expect(view?.transactionDiscountAmount).toBe('2.000');
  });

  it('§3.5 — assertOriginalRefundable throws WholeReceiptDiscountRefundRefusedError on the real resolved view', async () => {
    const receiptId = '88888888-8888-4888-8888-888888888888';
    await sealOriginal(
      receiptId,
      saleReceiptV3Payload({
        total: '10.000',
        transaction_discount_amount: '2.000',
        transaction_discount_reason: 'loyalty',
      }),
    );

    const view = await resolveOriginalFiscalEventLocally(adapter.asDatabase(), receiptId);
    expect(view).not.toBeNull();

    expect(() => assertOriginalRefundable(view!, receiptId)).toThrow(
      WholeReceiptDiscountRefundRefusedError,
    );
  });

  it('a clean (non-training, zero-discount) real original resolves normally and passes assertOriginalRefundable', async () => {
    const receiptId = '99999999-9999-4999-8999-999999999999';
    await sealOriginal(receiptId, saleReceiptV3Payload());

    const view = await resolveOriginalFiscalEventLocally(adapter.asDatabase(), receiptId);

    expect(view).not.toBeNull();
    expect(view?.trainingFlag).toBe(false);
    expect(view?.transactionDiscountAmount).toBe('0.000');
    expect(() => assertOriginalRefundable(view!, receiptId)).not.toThrow();
  });

  it('fail-closed: an unreadable envelope (malformed JSON) returns null, never a permissive default', async () => {
    const receiptId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    await adapter.execute(
      `INSERT INTO fiscal_events (
         id, tenant_id, company_id, terminal_id, operator_id,
         event_type, event_version, signature_version, sequence_number,
         event_time_device, business_date, canonical_bytes,
         previous_hash, current_hash, sync_status, created_at,
         source_event_class, source_event_id
       ) VALUES ($1,'tenant-1','company-1','terminal-1','operator-1','SALE_RECEIPT',3,'v1',1,
                 '2026-08-01T10:00:00Z','2026-08-01','not-json',
                 '${'0'.repeat(64)}','${'1'.repeat(64)}','pending','2026-08-01T10:00:00Z',
                 'offline_receipts',$2)`,
      [`${receiptId}-fe`, receiptId],
    );
    await insertOfflineReceipt(adapter.asDatabase(), {
      id: receiptId,
      idempotency_key: receiptId,
      receipt_number: 'MAIN-T01-2026-00000002',
      terminal_id: TERMINAL_ID,
      terminal_code: 'T01',
      operator_id: CASHIER_UUID,
      operator_name: 'Alice',
      lines: '[]',
      subtotal: '10.000',
      tax_amount: '2.000',
      discount_amount: '0.000',
      total: '12.000',
      currency: 'TND',
      fiscal_hash: '1'.repeat(64),
      previous_hash: '0'.repeat(64),
      hash_sequence: 1,
      transaction_discount_amount: '0.000',
      transaction_discount_reason: null,
      tendered_amount: '12.000',
      change_due: '0.000',
      payment_method_id: 'pm-cash',
      payment_repository_id: 'repo-1',
      status: 'pending',
      payments_json: '[]',
      consumption_mode: null,
      table_id: null,
      fiscal_schema_version: 3,
      is_training: 0,
      canonical_bytes: 'not-json',
      cash_rounding_adjustment: '0.000',
      cash_rounding_denomination: '0.000',
      tolerance_shortfall: null,
      receipt_kind: 'sale',
    });

    const view = await resolveOriginalFiscalEventLocally(adapter.asDatabase(), receiptId);

    expect(view).toBeNull();
  });
});
