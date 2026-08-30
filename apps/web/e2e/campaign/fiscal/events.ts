import { randomUUID } from 'node:crypto'
import { FiscalEventCanonicalEncoder } from './FiscalEventCanonicalEncoder'
import { withMilliseconds } from './util'

const EVENT_TYPE = 'SALE_RECEIPT'
const SIGNATURE_VERSION = 'hash-chain-integrity-v1'

export type CampaignFiscalEventType =
  | 'SALE_RECEIPT'
  | 'SESSION_CLOSE'
  | 'SESSION_OPEN'
  | 'Z_REPORT'

export interface SaleEnvelopeCoordinates {
  businessDate: string
  companyId: string
  countryCode: string
  currencyCode: string
  currencyScale: number
  eventTimeDevice: string
  genesisSeed: string
  methodCode: string
  operatorId: string
  productId: string
  productName: string
  productSku: string
  shiftId: string
  tenantId: string
  terminalId: string
}

export interface RefundEnvelopeCoordinates extends SaleEnvelopeCoordinates {
  originalEventId: string
  originalReceiptUuid: string
  previousHash: string
}

export interface AuthoredEnvelope {
  canonicalBytes: string
  currentHash: string
  eventId: string
  eventVersion: number
  payload: Record<string, unknown>
  sequenceNumber: number
  requestBody: {
    envelopes: Array<{
      envelope_id: string
      idempotency_key: string
      payload: Record<string, unknown>
      payload_version: number
      type: 'FISCAL_EVENT'
    }>
  }
}

export interface AuthoredFiscalEnvelope extends AuthoredEnvelope {
  receiptUuid: string
}

export interface FiscalEnvelopeCoordinates {
  body: Record<string, unknown>
  businessDate: string
  chainContext: 'operational' | 'z_session'
  companyId: string
  eventTimeDevice: string
  eventType: CampaignFiscalEventType
  eventVersion: number
  operatorId: string
  previousHash: string
  referenceEventId: string | null
  sequenceNumber: number
  sourceEventClass: string | null
  sourceEventId: string | null
  tenantId: string
  terminalId: string
}

export async function buildSaleEnvelope(
  coordinates: SaleEnvelopeCoordinates,
): Promise<AuthoredFiscalEnvelope> {
  const receiptUuid = randomUUID()
  const money = receiptAmounts(coordinates.currencyScale)
  const body: Record<string, unknown> = {
    approval_references: [],
    business_date: coordinates.businessDate,
    buyer: null,
    cash_rounding_adjustment: money.zero,
    cash_rounding_denomination: money.zero,
    cashier_id: coordinates.operatorId,
    cashier_name: 'Campaign Owner',
    consumption_mode: null,
    currency_code: coordinates.currencyCode,
    currency_scale: coordinates.currencyScale,
    event_time_device: withMilliseconds(coordinates.eventTimeDevice),
    invoice_type_code: 'SALE',
    line_items: [lineItem(coordinates)],
    lottery_code: null,
    notes: 'Automated onboarding campaign sale',
    original_receipt_reference: null,
    payments: [payment(coordinates.methodCode, money.gross)],
    receipt_uuid: receiptUuid,
    seller: seller(coordinates.countryCode),
    shift_id: coordinates.shiftId,
    subtotal: money.net,
    table_id: null,
    terminal_id: coordinates.terminalId,
    total: money.gross,
    training_flag: false,
    transaction_discount_amount: money.zero,
    transaction_discount_reason: null,
    vat_breakdown: [{
      discount_allocated: money.zero,
      gross_amount: money.gross,
      net_amount: money.net,
      rate: '19.00',
      tax_category_code: '',
      vat_amount: money.vat,
    }],
    vat_total: money.vat,
    vouchers_redeemed: [],
  }

  return authorReceiptEnvelope({
    body,
    businessDate: coordinates.businessDate,
    chainContext: 'operational',
    companyId: coordinates.companyId,
    eventTimeDevice: coordinates.eventTimeDevice,
    eventType: EVENT_TYPE,
    eventVersion: 5,
    operatorId: coordinates.operatorId,
    previousHash: coordinates.genesisSeed,
    referenceEventId: null,
    sequenceNumber: 1,
    sourceEventClass: null,
    sourceEventId: null,
    tenantId: coordinates.tenantId,
    terminalId: coordinates.terminalId,
  }, receiptUuid)
}

export async function buildRefundEnvelope(
  coordinates: RefundEnvelopeCoordinates,
): Promise<AuthoredFiscalEnvelope> {
  const receiptUuid = randomUUID()
  const money = receiptAmounts(coordinates.currencyScale)
  const body: Record<string, unknown> = {
    approval_references: [],
    business_date: coordinates.businessDate,
    buyer: null,
    cash_rounding_adjustment: money.zero,
    cash_rounding_denomination: money.zero,
    cashier_id: coordinates.operatorId,
    cashier_name: 'Campaign Owner',
    consumption_mode: null,
    currency_code: coordinates.currencyCode,
    currency_scale: coordinates.currencyScale,
    event_time_device: withMilliseconds(coordinates.eventTimeDevice),
    invoice_type_code: 'REFUND',
    line_items: [lineItem(coordinates)],
    lottery_code: null,
    notes: 'Automated onboarding campaign refund',
    original_line_references: [{
      disposition: 'restock',
      original_line_index: 0,
      product_id: coordinates.productId,
      quantity: '1.000',
    }],
    original_receipt_reference: {
      fiscal_event_id: coordinates.originalEventId,
      original_business_date: coordinates.businessDate,
      original_receipt_uuid: coordinates.originalReceiptUuid,
      refund_reason: 'campaign return',
    },
    payments: [payment(coordinates.methodCode, money.gross)],
    receipt_uuid: receiptUuid,
    refund_destination: 'cash',
    seller: seller(coordinates.countryCode),
    settlement_allocation: null,
    shift_id: coordinates.shiftId,
    subtotal: money.net,
    table_id: null,
    terminal_id: coordinates.terminalId,
    total: money.gross,
    training_flag: false,
    transaction_discount_amount: money.zero,
    transaction_discount_reason: null,
    vat_breakdown: [{
      gross_amount: money.gross,
      net_amount: money.net,
      rate: '19.00',
      tax_category_code: '',
      vat_amount: money.vat,
    }],
    vat_total: money.vat,
    vouchers_redeemed: [],
  }

  return authorReceiptEnvelope({
    body,
    businessDate: coordinates.businessDate,
    chainContext: 'operational',
    companyId: coordinates.companyId,
    eventTimeDevice: coordinates.eventTimeDevice,
    eventType: EVENT_TYPE,
    eventVersion: 4,
    operatorId: coordinates.operatorId,
    previousHash: coordinates.previousHash,
    referenceEventId: null,
    sequenceNumber: 2,
    sourceEventClass: null,
    sourceEventId: null,
    tenantId: coordinates.tenantId,
    terminalId: coordinates.terminalId,
  }, receiptUuid)
}

function authorReceiptEnvelope(
  coordinates: FiscalEnvelopeCoordinates,
  receiptUuid: string,
): AuthoredFiscalEnvelope {
  return {
    ...authorFiscalEnvelope(coordinates),
    receiptUuid,
  }
}

export function authorFiscalEnvelope(
  coordinates: FiscalEnvelopeCoordinates,
): AuthoredEnvelope {
  const eventId = randomUUID()
  const canonicalEnvelope: Record<string, unknown> = {
    business_date: coordinates.businessDate,
    chain_context: coordinates.chainContext,
    company_id: coordinates.companyId,
    event_time_device: coordinates.eventTimeDevice,
    event_type: coordinates.eventType,
    event_version: coordinates.eventVersion,
    operator_id: coordinates.operatorId,
    payload: coordinates.body,
    previous_hash: coordinates.previousHash,
    reference_document_id: null,
    reference_event_id: coordinates.referenceEventId,
    sequence_number: coordinates.sequenceNumber,
    signature_version: SIGNATURE_VERSION,
    tenant_id: coordinates.tenantId,
    terminal_id: coordinates.terminalId,
  }
  const encoder = new FiscalEventCanonicalEncoder()
  const canonicalBytes = encoder.encode(canonicalEnvelope)
  const currentHash = encoder.sha256Hex(canonicalBytes)
  const transportPayload: Record<string, unknown> = {
    ...canonicalEnvelope,
    canonical_bytes: canonicalBytes,
    current_hash: currentHash,
    id: eventId,
    last_server_time_seen: null,
    source_event_class: coordinates.sourceEventClass,
    source_event_id: coordinates.sourceEventId,
  }

  return {
    canonicalBytes,
    currentHash,
    eventId,
    eventVersion: coordinates.eventVersion,
    payload: coordinates.body,
    sequenceNumber: coordinates.sequenceNumber,
    requestBody: {
      envelopes: [{
        envelope_id: randomUUID(),
        idempotency_key: `${coordinates.terminalId}:${coordinates.chainContext}:${coordinates.sequenceNumber}`,
        payload: transportPayload,
        payload_version: 1,
        type: 'FISCAL_EVENT',
      }],
    },
  }
}

function lineItem(coordinates: SaleEnvelopeCoordinates): Record<string, unknown> {
  const money = receiptAmounts(coordinates.currencyScale)
  return {
    gtin: null,
    line_discount_amount: money.zero,
    line_discount_reason: null,
    line_subtotal: money.net,
    line_vat: money.vat,
    name: coordinates.productName,
    non_collected_subtype: null,
    product_id: coordinates.productId,
    quantity: '1.000',
    sku: coordinates.productSku,
    tax_category_code: '',
    unit_price: money.gross,
    variant_id: null,
    variant_name: null,
    variant_sku: null,
    vat_rate: '19.00',
  }
}

function payment(methodCode: string, amount: string): Record<string, unknown> {
  return {
    amount,
    foreign_currency_amount: null,
    foreign_currency_code: null,
    instrument_serial: null,
    instrument_type: null,
    method_code: methodCode,
  }
}

function seller(countryCode: string): Record<string, unknown> {
  return {
    address: {
      city: 'Campaign City',
      country_code: countryCode,
      postal_code: '1000',
      street: '1 Campaign Street',
    },
    name: 'AutoERP Campaign SARL',
    tax_jurisdiction_country_code: countryCode,
    tax_number: '1234567AM000',
  }
}

function receiptAmounts(scale: number): { gross: string; net: string; vat: string; zero: string } {
  if (scale !== 2 && scale !== 3) {
    throw new Error(`Campaign receipt builder supports currency scales 2 and 3; got ${String(scale)}`)
  }
  const suffix = scale === 3 ? '0' : ''
  return {
    gross: `23.80${suffix}`,
    net: `20.00${suffix}`,
    vat: `3.80${suffix}`,
    zero: `0.00${suffix}`,
  }
}
