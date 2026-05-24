import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import { FiscalEventEngine } from '../FiscalEventEngine';
import { FiscalEventPayloadRegistry } from '../FiscalEventPayloadRegistry';
import { HashChainIntegrityProvider } from '../HashChainIntegrityProvider';
import {
  authorZSessionOpenWithOpeningFloatOnDb,
  buildOpeningFloatPayload,
  buildSessionOpenPayload,
  type AuthorZSessionOpenInput,
} from '../zSessionAuthoring';

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
const TERMINAL_ID = '11111111-1111-4111-8111-111111111111';
const OPERATOR_ID = '22222222-2222-4222-8222-222222222222';
const SHIFT_ID = '33333333-3333-4333-8333-333333333333';
const SESSION_ID = '44444444-4444-4444-8444-444444444444';
const MOVEMENT_ID = '55555555-5555-4555-8555-555555555555';
const GENESIS_SEED = 'a'.repeat(64);

async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const migration of migrations) {
    if (migration.version > maxVersion) continue;
    if (migration.run) {
      await migration.run(adapter);
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

async function seedTerminalState(adapter: SqliteTestAdapter): Promise<void> {
  await adapter.execute(
    `INSERT INTO terminal_state (
       terminal_id, terminal_code, genesis_seed, last_hash,
       fiscal_event_genesis_seed, z_chain_genesis_seed
     ) VALUES ($1, 'T01', 'legacy-seed', 'legacy-hash', $2, $2)`,
    [TERMINAL_ID, GENESIS_SEED],
  );
}

function input(overrides: Partial<AuthorZSessionOpenInput> = {}): AuthorZSessionOpenInput {
  return {
    tenantId: TENANT_ID,
    companyId: COMPANY_ID,
    terminalId: TERMINAL_ID,
    terminalLabel: 'T01',
    shiftId: SHIFT_ID,
    sessionId: SESSION_ID,
    businessDate: '2026-05-16',
    operatorId: OPERATOR_ID,
    operatorName: 'Alice',
    currencyCode: 'TND',
    currencyScale: 3,
    openingFloatAmount: '100',
    isTraining: false,
    openedAtDevice: new Date('2026-05-16T08:00:00.123Z'),
    openingFloatMovementId: MOVEMENT_ID,
    ...overrides,
  };
}

d('zSessionAuthoring', () => {
  let adapter: SqliteTestAdapter;
  let engine: FiscalEventEngine;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 37);
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

  it('builds canonical session-open and opening-float payloads', () => {
    const openedAtDevice = new Date('2026-05-16T08:00:00.123Z');

    expect(buildSessionOpenPayload(input(), openedAtDevice)).toEqual({
      business_date: '2026-05-16',
      currency_code: 'TND',
      currency_scale: 3,
      opened_at_device: '2026-05-16T08:00:00.123Z',
      opening_float_amount: '100.000',
      operator_id: OPERATOR_ID,
      operator_name: 'Alice',
      session_id: SESSION_ID,
      shift_id: SHIFT_ID,
      terminal_id: TERMINAL_ID,
      terminal_label: 'T01',
      training_flag: false,
    });

    expect(buildOpeningFloatPayload(input(), openedAtDevice, MOVEMENT_ID)).toEqual({
      amount: '100.000',
      approval: null,
      business_date: '2026-05-16',
      cash_drawer_operation_id: null,
      currency_code: 'TND',
      currency_scale: 3,
      event_time_device: '2026-05-16T08:00:00.123Z',
      movement_id: MOVEMENT_ID,
      movement_type: 'OPENING_FLOAT',
      operator_id: OPERATOR_ID,
      operator_name: 'Alice',
      reason_code: 'opening_float',
      reason_text: null,
      session_id: SESSION_ID,
      shift_id: SHIFT_ID,
      training_flag: false,
    });
  });

  it('authors SESSION_OPEN followed by OPENING_FLOAT on the z_session chain', async () => {
    const result = await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());

    expect(result.sessionOpenEvent.event_type).toBe('SESSION_OPEN');
    expect(result.sessionOpenEvent.chain_context).toBe('z_session');
    expect(result.sessionOpenEvent.sequence_number).toBe(1);
    expect(result.sessionOpenEvent.previous_hash).toBe(GENESIS_SEED);

    expect(result.openingFloatEvent.event_type).toBe('OPENING_FLOAT');
    expect(result.openingFloatEvent.chain_context).toBe('z_session');
    expect(result.openingFloatEvent.sequence_number).toBe(2);
    expect(result.openingFloatEvent.previous_hash).toBe(result.sessionOpenEvent.current_hash);
    expect(result.openingFloatEvent.reference_event_id).toBe(result.sessionOpenEvent.id);

    const rows = await adapter.select<Array<{ event_type: string; chain_context: string; sequence_number: number }>>(
      `SELECT event_type, chain_context, sequence_number
         FROM fiscal_events
        ORDER BY sequence_number ASC`,
    );
    expect(rows).toEqual([
      { event_type: 'SESSION_OPEN', chain_context: 'z_session', sequence_number: 1 },
      { event_type: 'OPENING_FLOAT', chain_context: 'z_session', sequence_number: 2 },
    ]);
  });
});
