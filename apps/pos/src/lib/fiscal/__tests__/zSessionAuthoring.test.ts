import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

import { migrations } from '@/lib/db/migrations';
import { setShiftNumberSeed } from '@/lib/db/repositories/terminalStateRepository';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import { FiscalEventEngine } from '../FiscalEventEngine';
import { FiscalEventPayloadRegistry } from '../FiscalEventPayloadRegistry';
import { HashChainIntegrityProvider } from '../HashChainIntegrityProvider';
import {
  appendXReport,
  appendZCashDrawerMovement,
  appendZSessionCloseAndZReport,
  authorZSessionOpenWithOpeningFloatOnDb,
  buildXReportPayload,
  buildZCashDrawerMovementPayload,
  buildZReportPayload,
  buildOpeningFloatPayload,
  buildSessionOpenPayload,
  buildSessionClosePayload,
  ZSessionLifecycleError,
  type AuthorXReportInput,
  type AuthorZCashDrawerMovementInput,
  type AuthorZSessionCloseInput,
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
const SECOND_SESSION_ID = '12121212-1212-4212-8212-121212121212';
const SECOND_SHIFT_ID = '23232323-2323-4323-8323-232323232323';

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

function closeInput(overrides: Partial<AuthorZSessionCloseInput> = {}): AuthorZSessionCloseInput {
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
    periodStart: '2026-05-16T08:00:00.000Z',
    periodEnd: '2026-05-16T18:00:00.000Z',
    zReportUuid: '66666666-6666-4666-8666-666666666666',
    zNumber: 3,
    formattedZNumber: 'Z0003',
    expectedCash: '150.000',
    countedCash: '150.000',
    varianceAmount: '0.000',
    varianceDirection: 'balanced',
    varianceSeverity: 'balanced',
    varianceReason: null,
    reportTotals: {
      sales_count: 1,
      gross_sales: '50.000',
      net_sales: '42.000',
      tax_amount: '8.000',
      refunds_count: 0,
      refunds_amount: '0.000',
      voided_count: 0,
    },
    vatBreakdown: [],
    paymentMethodTotals: [],
    cashCountLines: [],
    cashDrawerTotals: {},
    grandTotalsBefore: {},
    grandTotalsAfter: {},
    toleranceSummary: null,
    legacyReportReference: null,
    companySnapshot: { company_id: COMPANY_ID },
    seller: null,
    operationalEventRange: { first_sequence: 1, last_sequence: 1 },
    isTraining: false,
    closedAtDevice: new Date('2026-05-16T18:00:00.123Z'),
    sessionCloseUuid: '77777777-7777-4777-8777-777777777777',
    ...overrides,
  };
}

function xReportInput(overrides: Partial<AuthorXReportInput> = {}): AuthorXReportInput {
  return {
    tenantId: TENANT_ID,
    companyId: COMPANY_ID,
    terminalId: TERMINAL_ID,
    shiftId: SHIFT_ID,
    sessionId: SESSION_ID,
    businessDate: '2026-05-16',
    operatorId: OPERATOR_ID,
    operatorName: 'Alice',
    periodStart: '2026-05-16T08:00:00.000Z',
    periodEnd: '2026-05-16T12:00:00.000Z',
    xReportUuid: '88888888-8888-4888-8888-888888888888',
    reportTotals: {
      sales_count: 1,
      gross_sales: '50.000',
      net_sales: '42.000',
      tax_amount: '8.000',
      refunds_count: 0,
      refunds_amount: '0.000',
      voided_count: 0,
    },
    vatBreakdown: [],
    paymentMethodTotals: [],
    cashDrawerTotals: {},
    operationalEventRange: { first_sequence: 1, last_sequence: 1 },
    isTraining: false,
    generatedAtDevice: new Date('2026-05-16T12:00:00.123Z'),
    ...overrides,
  };
}

function movementInput(
  overrides: Partial<AuthorZCashDrawerMovementInput> = {},
): AuthorZCashDrawerMovementInput {
  return {
    tenantId: TENANT_ID,
    companyId: COMPANY_ID,
    terminalId: TERMINAL_ID,
    shiftId: SHIFT_ID,
    sessionId: SESSION_ID,
    businessDate: '2026-05-16',
    operatorId: OPERATOR_ID,
    operatorName: 'Alice',
    movementType: 'CASH_IN',
    amount: '25.000',
    currencyCode: 'TND',
    currencyScale: 3,
    reasonCode: 'cash_drawer_deposit',
    reasonText: 'Change refill',
    cashDrawerOperationId: '99999999-9999-4999-8999-999999999999',
    approval: {
      approval_event_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      approval_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      policy_version: 'pos-cash-drawer-policy-v1',
      scope: 'cash_drawer_control',
      supervisor_user_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
      target_hash: 'target-hash',
    },
    isTraining: false,
    eventTimeDevice: new Date('2026-05-16T10:30:00.123Z'),
    movementId: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    ...overrides,
  };
}

d('zSessionAuthoring', () => {
  let adapter: SqliteTestAdapter;
  let engine: FiscalEventEngine;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    __resetWriteGateForTesting();
    setWriter(adapter as unknown as SqlSurface);
    await runMigrationsUpTo(adapter, 53);
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

    expect(buildSessionOpenPayload(input(), openedAtDevice, 7)).toEqual({
      business_date: '2026-05-16',
      currency_code: 'TND',
      currency_scale: 3,
      opened_at_device: '2026-05-16T08:00:00.123Z',
      opening_float_amount: '100.000',
      operator_id: OPERATOR_ID,
      operator_name: 'Alice',
      session_id: SESSION_ID,
      shift_id: SHIFT_ID,
      shift_number: 7,
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

  it('mints shift_number 1 for the first shift and stamps it into the SESSION_OPEN payload', async () => {
    const result = await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());

    expect(result.shiftNumber).toBe(1);

    const [row] = await adapter.select<Array<{ canonical_bytes: string }>>(
      'SELECT canonical_bytes FROM fiscal_events WHERE id = $1',
      [result.sessionOpenEvent.id],
    );
    const envelope = JSON.parse(row!.canonical_bytes) as { payload: { shift_number: number } };
    expect(envelope.payload.shift_number).toBe(1);
  });

  it('continues shift_number from the server-seeded counter on a fresh device', async () => {
    // Phase 6.1: apply v54 (the seed column) on top of the v53 beforeEach
    // baseline, then seed the per-terminal counter from a prior install's
    // server MAX. local_shifts is empty, so without the seed numbering would
    // restart at 1 and collide with the server-projected numbers.
    const v54 = migrations.find((m) => m.version === 54);
    await v54!.run!(adapter.asDatabase());
    await setShiftNumberSeed(adapter.asDatabase(), TERMINAL_ID, 5);

    const result = await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());

    expect(result.shiftNumber).toBe(6);

    const [open] = await adapter.select<Array<{ shift_number: number }>>(
      'SELECT shift_number FROM local_shifts WHERE id = $1',
      [SHIFT_ID],
    );
    expect(open!.shift_number).toBe(6);
  });

  it('inserts a local_shifts row + receipt anchor atomically inside the open tx', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());

    const shifts = await adapter.select<
      Array<{ id: string; terminal_id: string; shift_number: number; status: string; opening_cash: string; cashier_id: string; cashier_name: string }>
    >('SELECT id, terminal_id, shift_number, status, opening_cash, cashier_id, cashier_name FROM local_shifts');
    expect(shifts).toEqual([
      {
        id: SHIFT_ID,
        terminal_id: TERMINAL_ID,
        shift_number: 1,
        status: 'OPEN',
        opening_cash: '100.000',
        cashier_id: OPERATOR_ID,
        cashier_name: 'Alice',
      },
    ]);

    const anchors = await adapter.select<Array<{ shift_id: string; opening_hash_sequence: number }>>(
      'SELECT shift_id, opening_hash_sequence FROM shift_receipt_anchors',
    );
    expect(anchors).toEqual([{ shift_id: SHIFT_ID, opening_hash_sequence: 0 }]);
  });

  it('rolls back the local_shifts row and fiscal events when the open tx fails', async () => {
    // A SESSION_OPEN whose movement id collides with an existing fiscal event
    // forces the second append to throw; the whole tx (events + local_shifts
    // + anchor) must roll back.
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());
    await expect(
      authorZSessionOpenWithOpeningFloatOnDb(
        adapter,
        engine,
        // Same terminal with a STILL-OPEN local shift → the local_shifts
        // one-open partial unique index rejects the second insert and unwinds
        // the appended fiscal events.
        input({ shiftId: SECOND_SHIFT_ID, sessionId: SECOND_SESSION_ID, openingFloatMovementId: '13131313-1313-4313-8313-131313131313' }),
      ),
    ).rejects.toThrow();

    const shiftCount = await adapter.select<Array<{ n: number }>>(
      'SELECT COUNT(*) AS n FROM local_shifts',
    );
    expect(shiftCount[0]!.n).toBe(1);
  });

  it('closes the local shift when SESSION_CLOSE is authored', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());
    await appendZSessionCloseAndZReport(adapter, engine, closeInput());

    const open = await adapter.select<Array<{ id: string }>>(
      "SELECT id FROM local_shifts WHERE terminal_id = $1 AND status = 'OPEN'",
      [TERMINAL_ID],
    );
    expect(open).toEqual([]);
    const closed = await adapter.select<Array<{ status: string; closed_at: string | null }>>(
      'SELECT status, closed_at FROM local_shifts WHERE id = $1',
      [SHIFT_ID],
    );
    expect(closed[0]!.status).toBe('CLOSED');
    expect(closed[0]!.closed_at).not.toBeNull();
  });

  it('builds SESSION_CLOSE and Z_REPORT payloads with required canonical keys', () => {
    const closedAtDevice = new Date('2026-05-16T18:00:00.123Z');
    const sessionClose = buildSessionClosePayload(
      closeInput(),
      closedAtDevice,
      '77777777-7777-4777-8777-777777777777',
    );
    expect(sessionClose).toMatchObject({
      business_date: '2026-05-16',
      closure_status: 'closed',
      expected_cash: '150.000',
      generated_at_device: '2026-05-16T18:00:00.123Z',
      session_id: SESSION_ID,
      shift_id: SHIFT_ID,
      terminal_id: TERMINAL_ID,
      training_flag: false,
      variance_direction: 'balanced',
    });

    const zReport = buildZReportPayload(closeInput(), closedAtDevice, {
      first_sequence: 1,
      last_sequence: 3,
    });
    expect(zReport).toMatchObject({
      business_date: '2026-05-16',
      closed_at_device: '2026-05-16T18:00:00.123Z',
      currency_code: 'TND',
      currency_scale: 3,
      formatted_z_number: 'Z0003',
      period_type: 'DAY',
      session_id: SESSION_ID,
      shift_id: SHIFT_ID,
      terminal_id: TERMINAL_ID,
      terminal_label: 'T01',
      training_flag: false,
      z_number: 3,
      z_report_uuid: '66666666-6666-4666-8666-666666666666',
    });
  });

  it('builds X_REPORT payloads with non-closing canonical keys', () => {
    const payload = buildXReportPayload(
      xReportInput(),
      new Date('2026-05-16T12:00:00.123Z'),
    );

    expect(payload).toMatchObject({
      business_date: '2026-05-16',
      generated_at_device: '2026-05-16T12:00:00.123Z',
      receipt_count: 1,
      session_id: SESSION_ID,
      shift_id: SHIFT_ID,
      terminal_id: TERMINAL_ID,
      training_flag: false,
      x_report_uuid: '88888888-8888-4888-8888-888888888888',
    });
    expect(payload).not.toHaveProperty('z_number');
    expect(payload).not.toHaveProperty('closure_status');
  });

  it('builds Z-session cash drawer movement payloads with approval evidence', () => {
    const payload = buildZCashDrawerMovementPayload(
      movementInput(),
      new Date('2026-05-16T10:30:00.123Z'),
      'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    );

    expect(payload).toMatchObject({
      amount: '25.000',
      business_date: '2026-05-16',
      cash_drawer_operation_id: '99999999-9999-4999-8999-999999999999',
      event_time_device: '2026-05-16T10:30:00.123Z',
      movement_id: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
      movement_type: 'CASH_IN',
      reason_code: 'cash_drawer_deposit',
      session_id: SESSION_ID,
      shift_id: SHIFT_ID,
      training_flag: false,
    });
    expect(payload.approval).toMatchObject({
      approval_event_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      scope: 'cash_drawer_control',
    });
  });

  it('authors cash drawer movements on the z_session chain', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());

    const result = await appendZCashDrawerMovement(adapter, engine, movementInput());

    expect(result.movementEvent.event_type).toBe('CASH_IN');
    expect(result.movementEvent.chain_context).toBe('z_session');
    expect(result.movementEvent.sequence_number).toBe(3);
    expect(result.movementEvent.reference_event_id).toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
  });

  it('rejects Z-session movement authoring before SESSION_OPEN exists', async () => {
    await expect(appendZCashDrawerMovement(adapter, engine, movementInput()))
      .rejects
      .toBeInstanceOf(ZSessionLifecycleError);
  });

  it('authors X_REPORT on the z_session chain without closing the session', async () => {
    const open = await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());

    const result = await appendXReport(adapter, engine, xReportInput());

    expect(result.xReportEvent.event_type).toBe('X_REPORT');
    expect(result.xReportEvent.chain_context).toBe('z_session');
    expect(result.xReportEvent.sequence_number).toBe(3);
    expect(result.xReportEvent.reference_event_id).toBe(open.sessionOpenEvent.id);
  });

  it('authors SESSION_CLOSE followed by Z_REPORT on the z_session chain', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());

    const result = await appendZSessionCloseAndZReport(adapter, engine, closeInput());

    expect(result.sessionCloseEvent.event_type).toBe('SESSION_CLOSE');
    expect(result.sessionCloseEvent.chain_context).toBe('z_session');
    expect(result.sessionCloseEvent.sequence_number).toBe(3);
    expect(result.zReportEvent.event_type).toBe('Z_REPORT');
    expect(result.zReportEvent.chain_context).toBe('z_session');
    expect(result.zReportEvent.sequence_number).toBe(4);
    expect(result.zReportEvent.previous_hash).toBe(result.sessionCloseEvent.current_hash);
    expect(result.zReportEvent.reference_event_id).toBe(result.sessionCloseEvent.id);
  });

  it('computes Z_REPORT session_event_range from the matching SESSION_OPEN event', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());
    await appendZSessionCloseAndZReport(adapter, engine, closeInput());
    await authorZSessionOpenWithOpeningFloatOnDb(
      adapter,
      engine,
      input({
        shiftId: SECOND_SHIFT_ID,
        sessionId: SECOND_SESSION_ID,
        openingFloatMovementId: '13131313-1313-4313-8313-131313131313',
      }),
    );

    const result = await appendZSessionCloseAndZReport(
      adapter,
      engine,
      closeInput({
        shiftId: SECOND_SHIFT_ID,
        sessionId: SECOND_SESSION_ID,
        sessionCloseUuid: '14141414-1414-4414-8414-141414141414',
        zReportUuid: '15151515-1515-4515-8515-151515151515',
      }),
    );

    const rows = await adapter.select<Array<{ canonical_bytes: string }>>(
      'SELECT canonical_bytes FROM fiscal_events WHERE id = $1',
      [result.zReportEvent.id],
    );
    const envelope = JSON.parse(rows[0]!.canonical_bytes) as {
      payload: { session_event_range: Record<string, unknown> };
    };

    expect(envelope.payload.session_event_range).toMatchObject({
      first_sequence: 5,
      last_sequence: 7,
      session_open_event_id: expect.any(String),
      session_close_event_id: result.sessionCloseEvent.id,
    });
  });

  it('rejects a second Z_REPORT for the same session', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());
    await appendZSessionCloseAndZReport(adapter, engine, closeInput());

    await expect(
      appendZSessionCloseAndZReport(
        adapter,
        engine,
        closeInput({
          sessionCloseUuid: '16161616-1616-4616-8616-161616161616',
          zReportUuid: '17171717-1717-4717-8717-171717171717',
        }),
      ),
    )
      .rejects
      .toBeInstanceOf(ZSessionLifecycleError);
  });

  it('rejects X_REPORT authoring after the session has a Z_REPORT', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());
    await appendZSessionCloseAndZReport(adapter, engine, closeInput());

    await expect(appendXReport(adapter, engine, xReportInput()))
      .rejects
      .toBeInstanceOf(ZSessionLifecycleError);
  });

  it('rejects cash drawer movements after the session has a Z_REPORT', async () => {
    await authorZSessionOpenWithOpeningFloatOnDb(adapter, engine, input());
    await appendZSessionCloseAndZReport(adapter, engine, closeInput());

    await expect(appendZCashDrawerMovement(adapter, engine, movementInput()))
      .rejects
      .toBeInstanceOf(ZSessionLifecycleError);
  });
});
