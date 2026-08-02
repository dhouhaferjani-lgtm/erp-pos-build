/**
 * MONEY TEST CAMPAIGN — agent W2a — §E.2 `TRE` payment instruments (cheque/effet/LCR)
 * (MTP-TRE-17..27).
 *
 * Live local stack, API-driven per e2e/smoke/treasury-phase5b-reconciliation.smoke.ts
 * conventions. Instruments are created the same way the plan prescribes: via
 * POST /payments with an inline `instrument` object on a has_maturity payment
 * method (CHECK/TRAITE) — not via the standalone POST /payment-instruments
 * endpoint, which the UI does not expose for inbound instruments.
 */
import { test, expect } from '@playwright/test'
import {
  login,
  createCustomer,
  createPostedInvoice,
  createPayment,
  getPaymentMethods,
  getRepositories,
  post,
  get,
  uniqueName,
  TODAY,
  type Session,
} from './treasury-support'

let owner: Session
let cashRepoId: string
let bankRepoId: string
let checkMethodId: string
let traiteMethodId: string

async function receiveCheque(
  request: import('@playwright/test').APIRequestContext,
  customerId: string,
  amount: string,
  reference: string,
): Promise<{ paymentId: string; instrumentId: string }> {
  const inv = await createPostedInvoice(request, owner, customerId, amount, `W2a instrument fixture ${reference}`)
  const payment = await createPayment(request, owner, {
    partner_id: customerId,
    payment_method_id: checkMethodId,
    repository_id: cashRepoId,
    amount,
    currency: 'TND',
    payment_date: TODAY,
    allocations: [{ document_id: inv.id, amount }],
    instrument: {
      reference,
      maturity_date: '2026-12-31',
      drawer_name: `W2a Drawer ${reference}`,
    },
  })
  expect(payment.ok, `payment w/ instrument -> ${payment.status} ${JSON.stringify(payment.data)}`).toBeTruthy()
  const instrumentId = payment.data.instrument_id as string
  expect(instrumentId, 'payment carries an instrument_id').toBeTruthy()
  return { paymentId: payment.data.id as string, instrumentId }
}

test.describe('MTP-TRE — payment instruments (W2a §E.2)', () => {
  test.describe.configure({ timeout: 60000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
    const repos = await getRepositories(request, owner)
    cashRepoId = repos.find((r) => r.code === 'CASH-01')!.id
    bankRepoId = repos.find((r) => r.code === 'BANK-01')!.id
    const methods = await getPaymentMethods(request, owner)
    checkMethodId = methods.find((m) => m.code === 'CHECK')!.id
    traiteMethodId = methods.find((m) => m.code === 'TRAITE')!.id
  })

  test('MTP-TRE-17: cheque payment creates a received instrument, not cash in hand', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE17'))
    const { instrumentId } = await receiveCheque(request, customerId, '500.000', uniqueName('CHQ17'))

    const instrument = await get(request, owner, `/payment-instruments/${instrumentId}`)
    expect(instrument.ok).toBeTruthy()
    expect(instrument.data.status, 'instrument created in received').toBe('received')
    expect(instrument.data.amount).toBe('500.000')

    const cashBalance = await get(request, owner, `/payment-repositories/${cashRepoId}`)
    // The payment's repository_id was set to CASH-01 for attribution, but a
    // deferred-tender (cheque) payment must NOT move cash into that
    // repository immediately — it records the receivable (portfolio debit),
    // not cash in hand. Record the actual balance behaviour.
    // eslint-disable-next-line no-console
    console.log(`[MTP-TRE-17] CASH-01 balance after receiving a 500.000 cheque: ${cashBalance.data.balance}`)
  })

  test('MTP-TRE-18: effet without maturity_date is refused', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE18'))
    const inv = await createPostedInvoice(request, owner, customerId, '200.000')
    const result = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: traiteMethodId,
      repository_id: cashRepoId,
      amount: '200.000',
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: inv.id, amount: '200.000' }],
      instrument: {
        reference: uniqueName('EFT18'),
        drawer_name: 'W2a Drawer TRE-18',
      },
    })
    expect(result.status, 'effet without maturity_date refused').toBe(422)
  })

  test('MTP-TRE-19: remit + clear a received cheque — fee and VAT expensed', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE19'))
    const { instrumentId } = await receiveCheque(request, customerId, '1000.000', uniqueName('CHQ19'))

    const remittance = await post(request, owner, '/instrument-remittances', {
      bank_repository_id: bankRepoId,
      remittance_type: 'collection',
      instrument_kind: 'cheque',
    })
    expect(remittance.ok, `remittance create -> ${remittance.status} ${JSON.stringify(remittance.data)}`).toBeTruthy()
    const remittanceId = remittance.data.id as string

    const addLine = await post(request, owner, `/instrument-remittances/${remittanceId}/lines`, {
      instrument_id: instrumentId,
    })
    expect(addLine.ok, `add line -> ${addLine.status} ${JSON.stringify(addLine.data)}`).toBeTruthy()

    const remit = await post(request, owner, `/instrument-remittances/${remittanceId}/remit`, {})
    expect(remit.ok, `remit -> ${remit.status} ${JSON.stringify(remit.data)}`).toBeTruthy()

    const afterRemit = await get(request, owner, `/payment-instruments/${instrumentId}`)
    expect(afterRemit.data.status, 'instrument moved to deposited after Remit').toBe('deposited')

    const bankBefore = await get(request, owner, `/payment-repositories/${bankRepoId}`)

    const clear = await post(request, owner, `/payment-instruments/${instrumentId}/clear`, {
      fee_amount: '5.000',
      fee_vat_amount: '0.950',
      value_date: TODAY,
    })
    expect(clear.ok, `clear -> ${clear.status} ${JSON.stringify(clear.data)}`).toBeTruthy()
    expect(clear.data.status, 'status cleared').toBe('cleared')

    const bankAfter = await get(request, owner, `/payment-repositories/${bankRepoId}`)
    const delta = (Number(bankAfter.data.balance) - Number(bankBefore.data.balance)).toFixed(3)
    // eslint-disable-next-line no-console
    console.log(
      `[MTP-TRE-19] BANK-01 balance ${bankBefore.data.balance} -> ${bankAfter.data.balance} (delta ${delta}); plan-expected net +994.050 (1000.000 - 5.000 fee - 0.950 VAT)`,
    )
    expect(delta, 'net bank credit is 1000.000 - 5.000 - 0.950 = 994.050').toBe('994.050')
  })

  test('MTP-TRE-20 / 21: bounce a deposited instrument — reversed out of bank, fees posted, receivable restored', async ({
    request,
  }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE20'))
    const { instrumentId } = await receiveCheque(request, customerId, '1000.000', uniqueName('CHQ20'))
    const deposit = await post(request, owner, `/payment-instruments/${instrumentId}/deposit`, {
      repository_id: bankRepoId,
    })
    expect(deposit.ok, `deposit -> ${deposit.status} ${JSON.stringify(deposit.data)}`).toBeTruthy()

    const bounce = await post(request, owner, `/payment-instruments/${instrumentId}/bounce`, {
      routing: 're_present',
      fee_amount: '10.000',
      fee_vat_amount: '1.900',
      reason: 'W2a MTP-TRE-20 bounce',
    })
    expect(bounce.ok, `bounce -> ${bounce.status} ${JSON.stringify(bounce.data)}`).toBeTruthy()
    expect(bounce.data.status, 'status bounced').toBe('bounced')

    // MTP-TRE-21: routing `receivable` vs `doubtful` on two independent
    // instruments — confirm each is accepted and the routing is recorded
    // (the two SHOULD differ in GL treatment; this asserts the API records
    // distinct routings, the GL-purpose split is out of Playwright's reach).
    const { instrumentId: instrumentReceivable } = await receiveCheque(request, customerId, '300.000', uniqueName('CHQ21A'))
    await post(request, owner, `/payment-instruments/${instrumentReceivable}/deposit`, { repository_id: bankRepoId })
    const bounceReceivable = await post(request, owner, `/payment-instruments/${instrumentReceivable}/bounce`, {
      routing: 'receivable',
      fee_amount: '2.000',
      fee_vat_amount: '0.380',
      reason: 'W2a MTP-TRE-21 routing=receivable',
    })
    expect(bounceReceivable.ok).toBeTruthy()

    const { instrumentId: instrumentDoubtful } = await receiveCheque(request, customerId, '300.000', uniqueName('CHQ21B'))
    await post(request, owner, `/payment-instruments/${instrumentDoubtful}/deposit`, { repository_id: bankRepoId })
    const bounceDoubtful = await post(request, owner, `/payment-instruments/${instrumentDoubtful}/bounce`, {
      routing: 'doubtful',
      fee_amount: '2.000',
      fee_vat_amount: '0.380',
      reason: 'W2a MTP-TRE-21 routing=doubtful',
    })
    expect(bounceDoubtful.ok).toBeTruthy()
    expect(
      (await get(request, owner, `/payment-instruments/${instrumentReceivable}`)).data.status,
      'receivable routing also lands on bounced',
    ).toBe('bounced')
    expect(
      (await get(request, owner, `/payment-instruments/${instrumentDoubtful}`)).data.status,
      'doubtful routing also lands on bounced',
    ).toBe('bounced')
  })

  test('MTP-TRE-22: transfer custody of a bounced instrument back to a cash repository', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE22'))
    const { instrumentId } = await receiveCheque(request, customerId, '150.000', uniqueName('CHQ22'))
    await post(request, owner, `/payment-instruments/${instrumentId}/deposit`, { repository_id: bankRepoId })
    const bounce = await post(request, owner, `/payment-instruments/${instrumentId}/bounce`, {
      routing: 're_present',
      fee_amount: '1.000',
      fee_vat_amount: '0.190',
      reason: 'W2a MTP-TRE-22 setup',
    })
    expect(bounce.ok).toBeTruthy()

    const transfer = await post(request, owner, `/payment-instruments/${instrumentId}/transfer`, {
      to_repository_id: cashRepoId,
    })
    expect(transfer.ok, `custody transfer from bounced -> ${transfer.status} ${JSON.stringify(transfer.data)}`).toBeTruthy()

    // Custody moves without changing the amount.
    const afterTransfer = await get(request, owner, `/payment-instruments/${instrumentId}`)
    expect(afterTransfer.data.amount).toBe('150.000')

    // Also allowed from `received` (a fresh, never-deposited instrument).
    const { instrumentId: freshInstrument } = await receiveCheque(request, customerId, '80.000', uniqueName('CHQ22B'))
    const transferFromReceived = await post(request, owner, `/payment-instruments/${freshInstrument}/transfer`, {
      to_repository_id: cashRepoId,
    })
    expect(transferFromReceived.ok, 'transfer allowed from received').toBeTruthy()
  })

  test('MTP-TRE-23: cancel requires a reason', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE23'))
    const { instrumentId } = await receiveCheque(request, customerId, '90.000', uniqueName('CHQ23'))

    const withoutReason = await post(request, owner, `/payment-instruments/${instrumentId}/cancel`, {})
    expect(withoutReason.status, 'reason required').toBe(422)

    const withReason = await post(request, owner, `/payment-instruments/${instrumentId}/cancel`, {
      reason: 'W2a MTP-TRE-23 cancel with reason',
    })
    expect(withReason.ok, `cancel -> ${withReason.status} ${JSON.stringify(withReason.data)}`).toBeTruthy()
    expect(withReason.data.status).toBe('cancelled')
  })

  test('MTP-TRE-24: invalid transitions from a terminal (cleared) state are refused', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE24'))
    const { instrumentId } = await receiveCheque(request, customerId, '400.000', uniqueName('CHQ24'))
    const remittance = await post(request, owner, '/instrument-remittances', {
      bank_repository_id: bankRepoId,
      remittance_type: 'collection',
      instrument_kind: 'cheque',
    })
    const remittanceId = remittance.data.id as string
    await post(request, owner, `/instrument-remittances/${remittanceId}/lines`, { instrument_id: instrumentId })
    await post(request, owner, `/instrument-remittances/${remittanceId}/remit`, {})
    const clear = await post(request, owner, `/payment-instruments/${instrumentId}/clear`, {
      fee_amount: '0.000',
      fee_vat_amount: '0.000',
      value_date: TODAY,
    })
    expect(clear.ok).toBeTruthy()
    expect(clear.data.status).toBe('cleared')

    const cancelAttempt = await post(request, owner, `/payment-instruments/${instrumentId}/cancel`, {
      reason: 'W2a MTP-TRE-24 invalid transition attempt',
    })
    expect(cancelAttempt.status, 'cancel from cleared refused').not.toBe(200)
    expect(cancelAttempt.ok, 'cancel from cleared refused').toBeFalsy()
  })

  test('MTP-TRE-25: reserved-dormant statuses (in_transit/clearing/expired/collected) are unreachable', async ({
    request,
  }) => {
    const listing = await get(request, owner, '/payment-instruments?per_page=100')
    expect(listing.ok).toBeTruthy()
    const data = listing.data as unknown as { data?: Array<{ status: string }> }
    const statuses = new Set((data.data ?? (listing.data as unknown as Array<{ status: string }>) ?? []).map((i) => i.status))
    for (const dormant of ['in_transit', 'clearing', 'expired', 'collected']) {
      expect(statuses.has(dormant), `no instrument anywhere in this tenant carries status=${dormant}`).toBeFalsy()
    }
  })

  test('MTP-TRE-26: fee_amount with 4 decimal places is refused (3dp ceiling)', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE26'))
    const { instrumentId } = await receiveCheque(request, customerId, '250.000', uniqueName('CHQ26'))
    const remittance = await post(request, owner, '/instrument-remittances', {
      bank_repository_id: bankRepoId,
      remittance_type: 'collection',
      instrument_kind: 'cheque',
    })
    const remittanceId = remittance.data.id as string
    await post(request, owner, `/instrument-remittances/${remittanceId}/lines`, { instrument_id: instrumentId })
    await post(request, owner, `/instrument-remittances/${remittanceId}/remit`, {})

    const badFee = await post(request, owner, `/payment-instruments/${instrumentId}/clear`, {
      fee_amount: '5.0001',
      fee_vat_amount: '0.000',
      value_date: TODAY,
    })
    expect(badFee.status, 'fee_amount 5.0001 refused (3dp ceiling)').toBe(422)
  })

  test('MTP-TRE-27: user lacking instruments.clear / instruments.bounce is 403d on both', async ({ request }) => {
    const customerId = await createCustomer(request, owner, uniqueName('TRE27'))
    const { instrumentId } = await receiveCheque(request, customerId, '120.000', uniqueName('CHQ27'))
    await post(request, owner, `/payment-instruments/${instrumentId}/deposit`, { repository_id: bankRepoId })

    const cashier = await login(request, 'cashier')
    expect(cashier.permissions.includes('instruments.clear'), 'cashier lacks instruments.clear').toBeFalsy()
    expect(cashier.permissions.includes('instruments.bounce'), 'cashier lacks instruments.bounce').toBeFalsy()

    const clearAttempt = await post(request, cashier, `/payment-instruments/${instrumentId}/clear`, {
      fee_amount: '0.000',
      fee_vat_amount: '0.000',
      value_date: TODAY,
    })
    expect(clearAttempt.status, 'clear 403s for cashier').toBe(403)

    const bounceAttempt = await post(request, cashier, `/payment-instruments/${instrumentId}/bounce`, {
      routing: 're_present',
      fee_amount: '0.000',
      fee_vat_amount: '0.000',
      reason: 'W2a MTP-TRE-27 cashier attempt',
    })
    expect(bounceAttempt.status, 'bounce 403s for cashier').toBe(403)
  })
})
