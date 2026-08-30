import { describe, expect, it } from 'vitest'
import {
  buildSessionCloseEnvelope,
  buildSessionOpenEnvelope,
  buildZReportEnvelope,
} from './zSession'

const common = {
  businessDate: '2026-08-29',
  companyId: '22222222-2222-4222-8222-222222222222',
  currencyCode: 'TND',
  currencyScale: 3 as const,
  operatorId: '33333333-3333-4333-8333-333333333333',
  operatorName: 'Campaign Owner',
  sessionId: '66666666-6666-4666-8666-666666666666',
  shiftId: '66666666-6666-4666-8666-666666666666',
  tenantId: '11111111-1111-4111-8111-111111111111',
  terminalId: '55555555-5555-4555-8555-555555555555',
  terminalLabel: 'CMP-I3',
}

const report = {
  ...common,
  cashMethodCode: 'CASH',
  cashMethodId: '77777777-7777-4777-8777-777777777777',
  companyName: 'Campaign TN',
  openedAtDevice: '2026-08-29T10:00:00Z',
  periodEnd: '2026-08-29T10:10:00.000Z',
  periodStart: '2026-08-29T10:00:00.000Z',
  refundEventTimeDevice: '2026-08-29T10:05:00Z',
  refundHash: 'c'.repeat(64),
  refundSequenceNumber: 2,
  saleEventTimeDevice: '2026-08-29T10:01:00Z',
  saleHash: 'b'.repeat(64),
  saleSequenceNumber: 1,
}

const validOpenCoordinates = () => ({
  ...common,
  eventTimeDevice: '2026-08-29T10:00:00Z',
  genesisSeed: 'a'.repeat(64),
  openingFloatAmount: '1000.000',
  shiftNumber: 1,
})

const validCloseCoordinates = () => ({
  ...report,
  eventTimeDevice: '2026-08-29T10:10:00Z',
  previousHash: 'd'.repeat(64),
  sessionCloseUuid: '88888888-8888-4888-8888-888888888888',
})

const validZReportCoordinates = () => ({
  ...report,
  closeEventId: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
  closeHash: 'd'.repeat(64),
  closeSequenceNumber: 2,
  eventTimeDevice: '2026-08-29T10:10:00Z',
  openEventId: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
  openHash: 'a'.repeat(64),
  openSequenceNumber: 1,
  zReportUuid: '99999999-9999-4999-8999-999999999999',
})

describe('campaign Z-session envelope authoring', () => {
  it('authors SESSION_OPEN with the exact payload and canonical envelope contracts', async () => {
    const open = await buildSessionOpenEnvelope({
      ...common,
      eventTimeDevice: '2026-08-29T10:00:00Z',
      genesisSeed: 'a'.repeat(64),
      openingFloatAmount: '1000.000',
      shiftNumber: 1,
    })
    const canonical = JSON.parse(open.canonicalBytes) as Record<string, unknown>
    const transport = open.requestBody.envelopes[0]?.payload ?? {}

    expect(Object.keys(canonical).sort()).toEqual([
      'business_date', 'chain_context', 'company_id', 'event_time_device',
      'event_type', 'event_version', 'operator_id', 'payload', 'previous_hash',
      'reference_document_id', 'reference_event_id', 'sequence_number',
      'signature_version', 'tenant_id', 'terminal_id',
    ])
    expect(Object.keys(open.payload).sort()).toEqual([
      'business_date', 'currency_code', 'currency_scale', 'opened_at_device',
      'opening_float_amount', 'operator_id', 'operator_name', 'session_id',
      'shift_id', 'shift_number', 'terminal_id', 'terminal_label', 'training_flag',
    ])
    expect(canonical).toMatchObject({
      chain_context: 'z_session',
      event_type: 'SESSION_OPEN',
      event_version: 1,
      previous_hash: 'a'.repeat(64),
      sequence_number: 1,
    })
    expect(transport).toMatchObject({
      current_hash: open.currentHash,
      id: open.eventId,
      source_event_class: 'pos_session',
      source_event_id: common.sessionId,
    })
    expect(open.payload).toMatchObject({
      opened_at_device: '2026-08-29T10:00:00.000Z',
      opening_float_amount: '1000.000',
      session_id: common.sessionId,
      shift_id: common.shiftId,
      training_flag: false,
    })
  })

  it('authors SESSION_CLOSE then Z_REPORT with device-derived totals and sealed linkage', async () => {
    const open = await buildSessionOpenEnvelope({
      ...common,
      eventTimeDevice: '2026-08-29T10:00:00Z',
      genesisSeed: 'a'.repeat(64),
      openingFloatAmount: '1000.000',
      shiftNumber: 1,
    })
    const close = await buildSessionCloseEnvelope({
      ...report,
      eventTimeDevice: '2026-08-29T10:10:00Z',
      previousHash: open.currentHash,
      sessionCloseUuid: '88888888-8888-4888-8888-888888888888',
    })
    const zReport = await buildZReportEnvelope({
      ...report,
      closeEventId: close.eventId,
      closeHash: close.currentHash,
      closeSequenceNumber: close.sequenceNumber,
      eventTimeDevice: '2026-08-29T10:10:00Z',
      openEventId: open.eventId,
      openHash: open.currentHash,
      openSequenceNumber: open.sequenceNumber,
      zReportUuid: '99999999-9999-4999-8999-999999999999',
    })
    const closeTransport = close.requestBody.envelopes[0]?.payload ?? {}
    const zTransport = zReport.requestBody.envelopes[0]?.payload ?? {}

    expect(Object.keys(close.payload).sort()).toEqual([
      'business_date', 'cash_count_lines', 'cash_drawer_totals', 'closure_status',
      'counted_cash', 'expected_cash', 'generated_at_device', 'manager_approval',
      'operational_event_range', 'operator_id', 'operator_name', 'payment_method_totals',
      'period_end', 'period_start', 'receipt_count', 'refunds_totals', 'sales_totals',
      'session_close_uuid', 'session_id', 'shift_id', 'terminal_id', 'training_flag',
      'variance_amount', 'variance_direction', 'variance_reason', 'variance_severity',
      'vat_breakdown', 'voids_totals',
    ])
    expect(Object.keys(zReport.payload).sort()).toEqual([
      'business_date', 'cash_count', 'cash_drawer_totals', 'closed_at_device',
      'company_snapshot', 'currency_code', 'currency_scale', 'formatted_z_number',
      'grand_totals_after', 'grand_totals_before', 'legacy_report_reference',
      'operational_event_range', 'operator_id', 'operator_name', 'payment_method_totals',
      'period_end', 'period_start', 'period_type', 'receipt_totals', 'refunds_totals',
      'seller', 'session_event_range', 'session_id', 'shift_id', 'terminal_id',
      'terminal_label', 'tolerance_summary', 'training_flag', 'vat_breakdown',
      'voids_totals', 'z_number', 'z_report_uuid',
    ])
    expect(close.sequenceNumber).toBe(2)
    expect(closeTransport).toMatchObject({
      source_event_class: 'pos_session_close',
      source_event_id: '88888888-8888-4888-8888-888888888888',
    })
    expect(close.payload).toMatchObject({
      counted_cash: '1000.000',
      expected_cash: '1000.000',
      receipt_count: 1,
      refunds_totals: { amount: '23.800', count: 1 },
      sales_totals: {
        gross_sales: '23.800',
        net_sales: '20.000',
        tax_amount: '3.800',
      },
      variance_amount: '0.000',
      vat_breakdown: [{
        gross_amount: '0.000',
        net_amount: '0.000',
        tax_rate: 19,
        vat_amount: '0.000',
      }],
    })
    expect(close.payload['sales_totals']).toEqual({
      gross_sales: '23.800',
      net_sales: '20.000',
      tax_amount: '3.800',
    })
    expect(zReport.sequenceNumber).toBe(3)
    expect(zTransport).toMatchObject({
      reference_event_id: close.eventId,
      source_event_class: 'z_report',
      source_event_id: '99999999-9999-4999-8999-999999999999',
    })
    expect(zReport.payload).toMatchObject({
      grand_totals_after: {
        cumulative_refunds: '23.800',
        cumulative_sales: '23.800',
        cumulative_tax: '3.800',
        perpetual_grand_total: '0.000',
        receipt_count_lifetime: 1,
      },
      grand_totals_before: {
        cumulative_refunds: '0.000',
        cumulative_sales: '0.000',
        cumulative_tax: '0.000',
        perpetual_grand_total: '0.000',
        receipt_count_lifetime: 0,
      },
      operational_event_range: {
        first_receipt_hash: report.saleHash,
        first_receipt_sequence: 1,
        last_receipt_hash: report.refundHash,
        last_receipt_sequence: 2,
        receipt_count: 2,
      },
      payment_method_totals: [{
        payment_type: 'CASH',
        total_amount: '0.000',
        transaction_count: 2,
      }],
      receipt_totals: {
        count: 1,
        gross_sales: '23.800',
        net_sales: '20.000',
        tax_amount: '3.800',
      },
      session_event_range: {
        first_sequence: 1,
        last_sequence: 2,
        session_close_event_id: close.eventId,
        session_close_hash: close.currentHash,
        session_open_event_id: open.eventId,
        session_open_hash: open.currentHash,
      },
      vat_breakdown: [{
        gross_amount: '0.000',
        net_amount: '0.000',
        tax_rate: 19,
        vat_amount: '0.000',
      }],
      z_number: 1,
    })
  })

  it.each([
    {
      build: () => buildSessionOpenEnvelope({ ...validOpenCoordinates(), shiftNumber: 0 }),
      message: 'shiftNumber must be a positive integer',
      name: 'non-positive shift number',
    },
    {
      build: () => buildZReportEnvelope({ ...validZReportCoordinates(), openSequenceNumber: 2 }),
      message: 'session event range must be SESSION_OPEN sequence 1 through SESSION_CLOSE sequence 2',
      name: 'invalid session sequence range',
    },
    {
      build: () => buildSessionOpenEnvelope({
        ...validOpenCoordinates(),
        sessionId: 'not-a-uuid',
        shiftId: 'not-a-uuid',
      }),
      message: 'sessionId must be a canonical lowercase UUID',
      name: 'malformed UUID',
    },
    {
      build: () => buildSessionOpenEnvelope({
        ...validOpenCoordinates(),
        shiftId: '77777777-7777-4777-8777-777777777777',
      }),
      message: 'sessionId and shiftId must be the same UUID',
      name: 'different session and shift UUIDs',
    },
    {
      build: () => buildSessionOpenEnvelope({ ...validOpenCoordinates(), businessDate: '2026/08/29' }),
      message: 'businessDate must be an ISO date',
      name: 'malformed business date',
    },
    {
      build: () => buildSessionOpenEnvelope({
        ...validOpenCoordinates(),
        eventTimeDevice: '2026-08-29T10:00:00.123Z',
      }),
      message: 'eventTimeDevice must use UTC second precision',
      name: 'outer timestamp with milliseconds',
    },
    {
      build: () => buildSessionOpenEnvelope({ ...validOpenCoordinates(), currencyCode: 'tnd' }),
      message: 'currencyCode must be a three-letter uppercase code',
      name: 'malformed currency code',
    },
    {
      build: () => buildSessionOpenEnvelope({ ...validOpenCoordinates(), operatorName: '  ' }),
      message: 'operatorName and terminalLabel must be non-empty',
      name: 'blank display label',
    },
    {
      build: () => buildSessionOpenEnvelope({ ...validOpenCoordinates(), genesisSeed: 'A'.repeat(64) }),
      message: 'genesisSeed must be a lowercase 64-character hash',
      name: 'malformed hash',
    },
    {
      build: () => buildSessionOpenEnvelope({ ...validOpenCoordinates(), openingFloatAmount: '1000.00' }),
      message: 'openingFloatAmount must be an exact-scale money string',
      name: 'money at the wrong scale',
    },
    {
      build: () => buildSessionCloseEnvelope({ ...validCloseCoordinates(), saleSequenceNumber: 2 }),
      message: 'operational range must be sale sequence 1 through refund sequence 2',
      name: 'invalid operational sequence range',
    },
    {
      build: () => buildSessionCloseEnvelope({
        ...validCloseCoordinates(),
        periodStart: '2026-08-29T10:00:00Z',
      }),
      message: 'periodStart must use UTC millisecond precision',
      name: 'nested period timestamp without milliseconds',
    },
    {
      build: () => buildSessionCloseEnvelope({
        ...validCloseCoordinates(),
        openedAtDevice: '2026-08-29T10:00:00.000Z',
      }),
      message: 'openedAtDevice must use UTC second precision',
      name: 'nested device timestamp with milliseconds',
    },
    {
      build: () => buildSessionCloseEnvelope({
        ...validCloseCoordinates(),
        periodStart: '2026-08-29T10:02:00.000Z',
      }),
      message: 'reporting window must contain both operational receipts',
      name: 'reporting window excluding a receipt',
    },
    {
      build: () => buildSessionCloseEnvelope({
        ...validCloseCoordinates(),
        eventTimeDevice: '2026-08-29T10:04:00Z',
      }),
      message: 'SESSION_CLOSE and Z_REPORT must follow the open, sale, and refund',
      name: 'close before the refund',
    },
  ])('refuses $name before authoring immutable bytes', async ({ build, message }) => {
    await expect(build()).rejects.toMatchObject({ message })
  })
})
