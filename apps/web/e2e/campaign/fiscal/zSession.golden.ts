import {
  buildSessionCloseEnvelope,
  buildSessionOpenEnvelope,
  buildZReportEnvelope,
} from './zSession'

export const Z_SESSION_GOLDEN_HASHES = {
  SESSION_CLOSE: 'ad584a13813c28644ce6ba965ec6b25f9391b680e2177a62da06ea0ea3405742',
  SESSION_OPEN: '74d7d69b7abeb49320d09babceae5a9ae9698a748c89ebad4855a5a31ded36e3',
  Z_REPORT: '352d19690e32174635f20a0d3aaaf5d3b1d4c66c45ce26bbb3243ece0bd323ca',
} as const

export const fixedZSessionCoordinates = {
  businessDate: '2026-08-29',
  cashMethodCode: 'CASH',
  cashMethodId: '77777777-7777-4777-8777-777777777777',
  companyId: '22222222-2222-4222-8222-222222222222',
  companyName: 'Campaign TN',
  currencyCode: 'TND',
  currencyScale: 3 as const,
  eventTimeDevice: '2026-08-29T10:10:00Z',
  openedAtDevice: '2026-08-29T10:00:00Z',
  operatorId: '33333333-3333-4333-8333-333333333333',
  operatorName: 'Campaign Owner',
  periodEnd: '2026-08-29T10:10:00.000Z',
  periodStart: '2026-08-29T10:00:00.000Z',
  refundEventTimeDevice: '2026-08-29T10:05:00Z',
  refundHash: 'c'.repeat(64),
  refundSequenceNumber: 2,
  saleEventTimeDevice: '2026-08-29T10:01:00Z',
  saleHash: 'b'.repeat(64),
  saleSequenceNumber: 1,
  sessionId: '66666666-6666-4666-8666-666666666666',
  shiftId: '66666666-6666-4666-8666-666666666666',
  tenantId: '11111111-1111-4111-8111-111111111111',
  terminalId: '55555555-5555-4555-8555-555555555555',
  terminalLabel: 'CMP-I3',
}

export async function buildFixedZSessionEnvelopes() {
  const open = await buildSessionOpenEnvelope({
    ...fixedZSessionCoordinates,
    eventTimeDevice: fixedZSessionCoordinates.openedAtDevice,
    genesisSeed: 'a'.repeat(64),
    openingFloatAmount: '1000.000',
    shiftNumber: 1,
  })
  const close = await buildSessionCloseEnvelope({
    ...fixedZSessionCoordinates,
    previousHash: open.currentHash,
    sessionCloseUuid: '88888888-8888-4888-8888-888888888888',
  })
  const zReport = await buildZReportEnvelope({
    ...fixedZSessionCoordinates,
    closeEventId: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    closeHash: close.currentHash,
    closeSequenceNumber: close.sequenceNumber,
    openEventId: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    openHash: open.currentHash,
    openSequenceNumber: open.sequenceNumber,
    zReportUuid: '99999999-9999-4999-8999-999999999999',
  })
  return { close, open, zReport }
}
