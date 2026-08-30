import { authorFiscalEnvelope, type AuthoredEnvelope } from './events'
import { formatScaleThreeMoney, withMilliseconds } from './util'

const HASH_PATTERN = /^[0-9a-f]{64}$/
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/
const ISO_SECONDS_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/
const ISO_MILLISECONDS_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/

interface ZSessionEnvelopeCoordinates {
  businessDate: string
  companyId: string
  currencyCode: string
  currencyScale: 2 | 3
  eventTimeDevice: string
  operatorId: string
  operatorName: string
  sessionId: string
  shiftId: string
  tenantId: string
  terminalId: string
  terminalLabel: string
}

export interface SessionOpenEnvelopeCoordinates extends ZSessionEnvelopeCoordinates {
  genesisSeed: string
  openingFloatAmount: string
  shiftNumber: number
}

interface ZSessionReportCoordinates extends ZSessionEnvelopeCoordinates {
  cashMethodCode: string
  cashMethodId: string
  companyName: string
  openedAtDevice: string
  periodEnd: string
  periodStart: string
  refundEventTimeDevice: string
  refundHash: string
  refundSequenceNumber: number
  saleEventTimeDevice: string
  saleHash: string
  saleSequenceNumber: number
}

export interface SessionCloseEnvelopeCoordinates extends ZSessionReportCoordinates {
  previousHash: string
  sessionCloseUuid: string
}

export interface ZReportEnvelopeCoordinates extends ZSessionReportCoordinates {
  closeEventId: string
  closeHash: string
  closeSequenceNumber: number
  openEventId: string
  openHash: string
  openSequenceNumber: number
  zReportUuid: string
}

export async function buildSessionOpenEnvelope(
  coordinates: SessionOpenEnvelopeCoordinates,
): Promise<AuthoredEnvelope> {
  validateBaseCoordinates(coordinates)
  assertHash(coordinates.genesisSeed, 'genesisSeed')
  assertMoney(coordinates.openingFloatAmount, coordinates.currencyScale, 'openingFloatAmount')
  if (!Number.isInteger(coordinates.shiftNumber) || coordinates.shiftNumber < 1) {
    throw new Error('shiftNumber must be a positive integer')
  }

  const payload: Record<string, unknown> = {
    business_date: coordinates.businessDate,
    currency_code: coordinates.currencyCode,
    currency_scale: coordinates.currencyScale,
    opened_at_device: withMilliseconds(coordinates.eventTimeDevice),
    opening_float_amount: coordinates.openingFloatAmount,
    operator_id: coordinates.operatorId,
    operator_name: coordinates.operatorName,
    session_id: coordinates.sessionId,
    shift_id: coordinates.shiftId,
    shift_number: coordinates.shiftNumber,
    terminal_id: coordinates.terminalId,
    terminal_label: coordinates.terminalLabel,
    training_flag: false,
  }

  return authorFiscalEnvelope({
    body: payload,
    businessDate: coordinates.businessDate,
    chainContext: 'z_session',
    companyId: coordinates.companyId,
    eventTimeDevice: coordinates.eventTimeDevice,
    eventType: 'SESSION_OPEN',
    eventVersion: 1,
    operatorId: coordinates.operatorId,
    previousHash: coordinates.genesisSeed,
    referenceEventId: null,
    sequenceNumber: 1,
    sourceEventClass: 'pos_session',
    sourceEventId: coordinates.sessionId,
    tenantId: coordinates.tenantId,
    terminalId: coordinates.terminalId,
  })
}

export async function buildSessionCloseEnvelope(
  coordinates: SessionCloseEnvelopeCoordinates,
): Promise<AuthoredEnvelope> {
  validateReportCoordinates(coordinates)
  assertHash(coordinates.previousHash, 'previousHash')
  assertUuid(coordinates.sessionCloseUuid, 'sessionCloseUuid')
  const vector = reportVector(coordinates)

  const payload: Record<string, unknown> = {
    business_date: coordinates.businessDate,
    cash_count_lines: vector.cashCountLines,
    cash_drawer_totals: vector.cashDrawerTotals,
    closure_status: 'closed',
    counted_cash: vector.expectedCash,
    expected_cash: vector.expectedCash,
    generated_at_device: withMilliseconds(coordinates.eventTimeDevice),
    manager_approval: null,
    operational_event_range: vector.operationalEventRange,
    operator_id: coordinates.operatorId,
    operator_name: coordinates.operatorName,
    payment_method_totals: vector.paymentMethodTotals,
    period_end: coordinates.periodEnd,
    period_start: coordinates.periodStart,
    receipt_count: 1,
    refunds_totals: vector.refundsTotals,
    sales_totals: vector.salesTotals,
    session_close_uuid: coordinates.sessionCloseUuid,
    session_id: coordinates.sessionId,
    shift_id: coordinates.shiftId,
    terminal_id: coordinates.terminalId,
    training_flag: false,
    variance_amount: vector.zero,
    variance_direction: 'balanced',
    variance_reason: 'campaign counted balance',
    variance_severity: 'balanced',
    vat_breakdown: vector.vatBreakdown,
    voids_totals: { count: 0 },
  }

  return authorFiscalEnvelope({
    body: payload,
    businessDate: coordinates.businessDate,
    chainContext: 'z_session',
    companyId: coordinates.companyId,
    eventTimeDevice: coordinates.eventTimeDevice,
    eventType: 'SESSION_CLOSE',
    eventVersion: 1,
    operatorId: coordinates.operatorId,
    previousHash: coordinates.previousHash,
    referenceEventId: null,
    sequenceNumber: 2,
    sourceEventClass: 'pos_session_close',
    sourceEventId: coordinates.sessionCloseUuid,
    tenantId: coordinates.tenantId,
    terminalId: coordinates.terminalId,
  })
}

export async function buildZReportEnvelope(
  coordinates: ZReportEnvelopeCoordinates,
): Promise<AuthoredEnvelope> {
  validateReportCoordinates(coordinates)
  for (const [value, name] of [
    [coordinates.closeEventId, 'closeEventId'],
    [coordinates.openEventId, 'openEventId'],
    [coordinates.zReportUuid, 'zReportUuid'],
  ] as const) {
    assertUuid(value, name)
  }
  assertHash(coordinates.closeHash, 'closeHash')
  assertHash(coordinates.openHash, 'openHash')
  if (coordinates.openSequenceNumber !== 1 || coordinates.closeSequenceNumber !== 2) {
    throw new Error('session event range must be SESSION_OPEN sequence 1 through SESSION_CLOSE sequence 2')
  }
  const vector = reportVector(coordinates)

  const payload: Record<string, unknown> = {
    business_date: coordinates.businessDate,
    cash_count: {
      counted_cash: vector.expectedCash,
      expected_cash: vector.expectedCash,
      lines: vector.cashCountLines,
      variance_amount: vector.zero,
      variance_direction: 'balanced',
      variance_reason: 'campaign counted balance',
      variance_severity: 'balanced',
    },
    cash_drawer_totals: vector.cashDrawerTotals,
    closed_at_device: withMilliseconds(coordinates.eventTimeDevice),
    company_snapshot: {
      company_id: coordinates.companyId,
      name: coordinates.companyName,
    },
    currency_code: coordinates.currencyCode,
    currency_scale: coordinates.currencyScale,
    formatted_z_number: 'Z0001',
    grand_totals_after: vector.grandTotalsAfter,
    grand_totals_before: vector.grandTotalsBefore,
    legacy_report_reference: null,
    operational_event_range: vector.operationalEventRange,
    operator_id: coordinates.operatorId,
    operator_name: coordinates.operatorName,
    payment_method_totals: vector.paymentMethodTotals,
    period_end: coordinates.periodEnd,
    period_start: coordinates.periodStart,
    period_type: 'DAY',
    receipt_totals: vector.receiptTotals,
    refunds_totals: vector.refundsTotals,
    seller: null,
    session_event_range: {
      first_sequence: coordinates.openSequenceNumber,
      last_sequence: coordinates.closeSequenceNumber,
      session_close_event_id: coordinates.closeEventId,
      session_close_hash: coordinates.closeHash,
      session_open_event_id: coordinates.openEventId,
      session_open_hash: coordinates.openHash,
    },
    session_id: coordinates.sessionId,
    shift_id: coordinates.shiftId,
    terminal_id: coordinates.terminalId,
    terminal_label: coordinates.terminalLabel,
    tolerance_summary: null,
    training_flag: false,
    vat_breakdown: vector.vatBreakdown,
    voids_totals: { count: 0 },
    z_number: 1,
    z_report_uuid: coordinates.zReportUuid,
  }

  return authorFiscalEnvelope({
    body: payload,
    businessDate: coordinates.businessDate,
    chainContext: 'z_session',
    companyId: coordinates.companyId,
    eventTimeDevice: coordinates.eventTimeDevice,
    eventType: 'Z_REPORT',
    eventVersion: 1,
    operatorId: coordinates.operatorId,
    previousHash: coordinates.closeHash,
    referenceEventId: coordinates.closeEventId,
    sequenceNumber: 3,
    sourceEventClass: 'z_report',
    sourceEventId: coordinates.zReportUuid,
    tenantId: coordinates.tenantId,
    terminalId: coordinates.terminalId,
  })
}

function reportVector(coordinates: ZSessionReportCoordinates) {
  const zero = formatScaleThreeMoney('0.000', coordinates.currencyScale)
  const gross = formatScaleThreeMoney('23.800', coordinates.currencyScale)
  const net = formatScaleThreeMoney('20.000', coordinates.currencyScale)
  const vat = formatScaleThreeMoney('3.800', coordinates.currencyScale)
  const expectedCash = formatScaleThreeMoney('1000.000', coordinates.currencyScale)

  return {
    cashCountLines: [{
      actual_amount: expectedCash,
      currency_code: coordinates.currencyCode,
      expected_amount: expectedCash,
      payment_method_id: coordinates.cashMethodId,
      transaction_count: 2,
      variance_amount: zero,
      variance_direction: 'balanced',
    }],
    cashDrawerTotals: {
      expected_cash: expectedCash,
      opening_cash: expectedCash,
    },
    expectedCash,
    grandTotalsAfter: {
      cumulative_refunds: gross,
      cumulative_sales: gross,
      cumulative_tax: vat,
      perpetual_grand_total: zero,
      receipt_count_lifetime: 1,
    },
    grandTotalsBefore: {
      cumulative_refunds: zero,
      cumulative_sales: zero,
      cumulative_tax: zero,
      perpetual_grand_total: zero,
      receipt_count_lifetime: 0,
    },
    operationalEventRange: {
      first_receipt_hash: coordinates.saleHash,
      first_receipt_sequence: coordinates.saleSequenceNumber,
      last_receipt_hash: coordinates.refundHash,
      last_receipt_sequence: coordinates.refundSequenceNumber,
      receipt_count: 2,
    },
    paymentMethodTotals: [{
      payment_type: coordinates.cashMethodCode,
      total_amount: zero,
      transaction_count: 2,
    }],
    receiptTotals: {
      count: 1,
      gross_sales: gross,
      net_sales: net,
      tax_amount: vat,
    },
    refundsTotals: {
      amount: gross,
      count: 1,
    },
    salesTotals: {
      gross_sales: gross,
      net_sales: net,
      tax_amount: vat,
    },
    vatBreakdown: [{
      gross_amount: zero,
      net_amount: zero,
      tax_rate: 19,
      vat_amount: zero,
    }],
    zero,
  }
}

function validateBaseCoordinates(coordinates: ZSessionEnvelopeCoordinates): void {
  for (const [value, name] of [
    [coordinates.companyId, 'companyId'],
    [coordinates.operatorId, 'operatorId'],
    [coordinates.sessionId, 'sessionId'],
    [coordinates.shiftId, 'shiftId'],
    [coordinates.tenantId, 'tenantId'],
    [coordinates.terminalId, 'terminalId'],
  ] as const) {
    assertUuid(value, name)
  }
  if (coordinates.sessionId !== coordinates.shiftId) {
    throw new Error('sessionId and shiftId must be the same UUID')
  }
  if (!DATE_PATTERN.test(coordinates.businessDate)) {
    throw new Error('businessDate must be an ISO date')
  }
  if (!ISO_SECONDS_PATTERN.test(coordinates.eventTimeDevice)) {
    throw new Error('eventTimeDevice must use UTC second precision')
  }
  if (!/^[A-Z]{3}$/.test(coordinates.currencyCode)) {
    throw new Error('currencyCode must be a three-letter uppercase code')
  }
  if (coordinates.operatorName.trim() === '' || coordinates.terminalLabel.trim() === '') {
    throw new Error('operatorName and terminalLabel must be non-empty')
  }
}

function validateReportCoordinates(coordinates: ZSessionReportCoordinates): void {
  validateBaseCoordinates(coordinates)
  assertUuid(coordinates.cashMethodId, 'cashMethodId')
  assertHash(coordinates.saleHash, 'saleHash')
  assertHash(coordinates.refundHash, 'refundHash')
  if (coordinates.saleSequenceNumber !== 1 || coordinates.refundSequenceNumber !== 2) {
    throw new Error('operational range must be sale sequence 1 through refund sequence 2')
  }
  for (const [value, name] of [
    [coordinates.periodStart, 'periodStart'],
    [coordinates.periodEnd, 'periodEnd'],
  ] as const) {
    if (!ISO_MILLISECONDS_PATTERN.test(value)) {
      throw new Error(`${name} must use UTC millisecond precision`)
    }
  }
  for (const [value, name] of [
    [coordinates.openedAtDevice, 'openedAtDevice'],
    [coordinates.saleEventTimeDevice, 'saleEventTimeDevice'],
    [coordinates.refundEventTimeDevice, 'refundEventTimeDevice'],
  ] as const) {
    if (!ISO_SECONDS_PATTERN.test(value)) {
      throw new Error(`${name} must use UTC second precision`)
    }
  }

  const periodStart = Date.parse(coordinates.periodStart)
  const periodEnd = Date.parse(coordinates.periodEnd)
  const openedAt = Date.parse(coordinates.openedAtDevice)
  const saleAt = Date.parse(coordinates.saleEventTimeDevice)
  const refundAt = Date.parse(coordinates.refundEventTimeDevice)
  const closeAt = Date.parse(coordinates.eventTimeDevice)
  if (periodStart > saleAt || periodEnd < refundAt) {
    throw new Error('reporting window must contain both operational receipts')
  }
  if (openedAt > closeAt || closeAt <= saleAt || closeAt <= refundAt) {
    throw new Error('SESSION_CLOSE and Z_REPORT must follow the open, sale, and refund')
  }
}

function assertUuid(value: string, name: string): void {
  if (!UUID_PATTERN.test(value)) throw new Error(`${name} must be a canonical lowercase UUID`)
}

function assertHash(value: string, name: string): void {
  if (!HASH_PATTERN.test(value)) throw new Error(`${name} must be a lowercase 64-character hash`)
}

function assertMoney(value: string, scale: 2 | 3, name: string): void {
  const pattern = scale === 3 ? /^-?\d+\.\d{3}$/ : /^-?\d+\.\d{2}$/
  if (!pattern.test(value)) throw new Error(`${name} must be an exact-scale money string`)
}
