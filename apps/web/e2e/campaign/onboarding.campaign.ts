import { createHash, randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { expect, test, type Page } from '@playwright/test'
import { FiscalEventCanonicalEncoder } from './fiscal/FiscalEventCanonicalEncoder'
import { routes } from './selectors'
import { buildRefundEnvelope, buildSaleEnvelope } from './fiscal/events'
import {
  buildSessionCloseEnvelope,
  buildSessionOpenEnvelope,
  buildZReportEnvelope,
} from './fiscal/zSession'
import {
  buildFixedZSessionEnvelopes,
  fixedZSessionCoordinates,
  Z_SESSION_GOLDEN_HASHES,
} from './fiscal/zSession.golden'
import { formatScaleThreeMoney, semanticLeafPaths, withMilliseconds } from './fiscal/util'
import {
  addLedgerEvidence,
  apiRequest,
  apiRoutes,
  asArray,
  asRecord,
  assertMoneyEqual,
  campaignCountry,
  currencyForCountry,
  currencyScaleForCountry,
  finalizeCampaignLedger,
  initializeCampaignLedger,
  journeyState,
  normalizeMoney,
  pollUntil,
  recordProductFinding,
  recordTestResult,
  registerFreshTenant,
  renderFixture,
  requireApiData,
  runId,
  runImportWizard,
  stringField,
  whereDidItLand,
  type ApiResult,
  ensureSession,
  ledgerFindings,
  reuseMode,
} from './journey'

interface GoldenFixture {
  expected_canonical_string: string
  expected_sha256_hex: string
}

interface Census {
  locations: Record<string, unknown>[]
  methods: Record<string, unknown>[]
  repositories: Record<string, unknown>[]
  units: Record<string, unknown>[]
}

async function readGolden(name: string): Promise<GoldenFixture> {
  const path = resolve(process.cwd(), '../api/tests/Fixtures/Fiscal', name)
  return JSON.parse(await readFile(path, 'utf8')) as GoldenFixture
}

test.describe('automated onboarding campaign', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(async () => {
    await initializeCampaignLedger()
  })

  // Playwright requires an object pattern; keeping it empty prevents L0a from launching a browser.
  // eslint-disable-next-line no-empty-pattern
  test.afterEach(async ({}, testInfo) => {
    await recordTestResult(testInfo)
  })

  test.afterAll(async () => {
    await finalizeCampaignLedger()
  })

  test.beforeEach(async ({ page }, testInfo) => {
    const leg = /^L\d+[a-z]?/.exec(testInfo.title)?.[0] ?? ''
    if (leg === 'L0a' || leg === 'L0' || leg === 'L10') return
    await ensureSession(page)
  })

  test('L0a — golden vector', async () => {
    const encoder = new FiscalEventCanonicalEncoder()

    for (const filename of [
      'sale-receipt-v5-golden.json',
      'sale-receipt-v4-refund-golden.json',
    ]) {
      const golden = await readGolden(filename)
      const canonical = encoder.encode(JSON.parse(golden.expected_canonical_string) as unknown)
      const nodeHash = createHash('sha256').update(canonical).digest('hex')

      expect(canonical, filename).toBe(golden.expected_canonical_string)
      expect(encoder.sha256Hex(canonical), filename).toBe(golden.expected_sha256_hex)
      expect(nodeHash, `${filename} Node crypto cross-check`).toBe(golden.expected_sha256_hex)
    }

    expect(normalizeMoney('1250.5')).toBe('1250.500')
    expect(normalizeMoney('-300')).toBe('-300.000')
    expect(normalizeMoney('12.5000')).toBe('12.500')
    expect(() => normalizeMoney('12.5001')).toThrow(/scale 3/)
    assertMoneyEqual('4780.2500', '4780.250')

    const coordinates = {
      businessDate: '2026-08-29',
      companyId: '22222222-2222-4222-8222-222222222222',
      countryCode: 'TN',
      currencyCode: 'TND',
      currencyScale: 3,
      eventTimeDevice: '2026-08-29T10:00:00Z',
      genesisSeed: 'a'.repeat(64),
      methodCode: 'CASH',
      operatorId: '33333333-3333-4333-8333-333333333333',
      productId: '44444444-4444-4444-8444-444444444444',
      productName: 'Campaign product',
      productSku: 'CAMPAIGN-SKU',
      shiftId: '66666666-6666-4666-8666-666666666666',
      tenantId: '11111111-1111-4111-8111-111111111111',
      terminalId: '55555555-5555-4555-8555-555555555555',
    }
    const sale = await buildSaleEnvelope(coordinates)
    const saleCanonical = JSON.parse(sale.canonicalBytes) as Record<string, unknown>
    expect(Object.keys(saleCanonical).sort()).toEqual([
      'business_date', 'chain_context', 'company_id', 'event_time_device',
      'event_type', 'event_version', 'operator_id', 'payload', 'previous_hash',
      'reference_document_id', 'reference_event_id', 'sequence_number',
      'signature_version', 'tenant_id', 'terminal_id',
    ])
    expect(createHash('sha256').update(sale.canonicalBytes).digest('hex')).toBe(sale.currentHash)

    const refund = await buildRefundEnvelope({
      ...coordinates,
      eventTimeDevice: '2026-08-29T10:05:00Z',
      originalEventId: sale.eventId,
      originalReceiptUuid: sale.receiptUuid,
      previousHash: sale.currentHash,
    })
    expect(refund.eventVersion).toBe(4)
    expect(refund.payload['currency_code']).toBe('TND')
    expect(refund.payload['currency_scale']).toBe(3)
    expect(refund.payload['invoice_type_code']).toBe('REFUND')

    const zSession = await buildFixedZSessionEnvelopes()
    expect(zSession.open.currentHash).toBe(Z_SESSION_GOLDEN_HASHES.SESSION_OPEN)
    expect(zSession.close.currentHash).toBe(Z_SESSION_GOLDEN_HASHES.SESSION_CLOSE)
    expect(zSession.zReport.currentHash).toBe(Z_SESSION_GOLDEN_HASHES.Z_REPORT)
    for (const envelope of Object.values(zSession)) {
      expect(Object.keys(JSON.parse(envelope.canonicalBytes) as Record<string, unknown>)).toHaveLength(15)
      expect(createHash('sha256').update(envelope.canonicalBytes).digest('hex')).toBe(envelope.currentHash)
    }
    const semantic = JSON.parse(await readFile(
      resolve(process.cwd(), 'e2e/campaign/fiscal/zSession.semantic.json'),
      'utf8',
    )) as { citations: Record<string, string[]>; values: Record<string, unknown> }
    expect(Object.keys(semantic.citations).sort()).toEqual(semanticLeafPaths(semantic.values).sort())
    expect(Object.values(semantic.citations).every((citations) => citations.length > 0)).toBe(true)
    const zCashCount = zSession.zReport.payload['cash_count'] as Record<string, unknown>
    expect(semantic.values).toMatchObject({
      cash_count: {
        counted_cash: zCashCount['counted_cash'],
        expected_cash: zCashCount['expected_cash'],
        variance_amount: zCashCount['variance_amount'],
        variance_direction: zCashCount['variance_direction'],
        variance_reason: zCashCount['variance_reason'],
      },
      cash_count_lines: zCashCount['lines'],
      cash_drawer_totals: zSession.zReport.payload['cash_drawer_totals'],
      event_times: {
        period_end: fixedZSessionCoordinates.periodEnd,
        period_start: fixedZSessionCoordinates.periodStart,
        refund: fixedZSessionCoordinates.refundEventTimeDevice,
        sale: fixedZSessionCoordinates.saleEventTimeDevice,
        session_close_and_z: fixedZSessionCoordinates.eventTimeDevice,
        session_open: fixedZSessionCoordinates.openedAtDevice,
      },
      formatted_z_number: zSession.zReport.payload['formatted_z_number'],
      grand_totals_after: zSession.zReport.payload['grand_totals_after'],
      grand_totals_before: zSession.zReport.payload['grand_totals_before'],
      operational_event_range: zSession.zReport.payload['operational_event_range'],
      payment_method_totals: zSession.zReport.payload['payment_method_totals'],
      receipt_totals: zSession.zReport.payload['receipt_totals'],
      refunds_totals: zSession.zReport.payload['refunds_totals'],
      session_event_range: zSession.zReport.payload['session_event_range'],
      vat_breakdown: zSession.zReport.payload['vat_breakdown'],
      voids_totals: zSession.zReport.payload['voids_totals'],
      z_number: zSession.zReport.payload['z_number'],
    })
    await addLedgerEvidence('L0a', 'sale/refund plus SESSION_OPEN/SESSION_CLOSE/Z_REPORT golden vectors byte/hash matched; every canonical wrapper has 15 keys; semantic vector has per-field citations')
  })

  test('L0 — health + register', async ({ page }) => {
    // Registration provisions the tenant DB synchronously (576 migrations, 20–60 s; staging caps the
    // request at 60 s, the P0-2 hotfix raises it to 300 s) — give this leg more than the 90 s default.
    test.setTimeout(360_000)
    await registerFreshTenant(page)
    const country = campaignCountry()
    const companiesResult = await apiRequest(page, 'GET', apiRoutes.companies)
    const companies = records(requireApiData(companiesResult, 'registered companies'), 'registered companies')
    if (!reuseMode) expect(companies).toHaveLength(1)
    // Reuse mode: the tenant already carries the second company from a prior run — journey on the original one.
    const company = (reuseMode
      ? companies.find((candidate) => !String(candidate['name'] ?? '').includes('Campaign Second'))
      : companies[0]) ?? fail('registered company missing')
    const companyId = stringField(company, 'id')
    journeyState.companyId = companyId
    journeyState.companyName = stringField(company, 'name')
    expect(company['country_code']).toBe(country)
    expect(company['currency']).toBe(currencyForCountry(country))

    const first = await census(page, companyId)
    assertDayOneCensus(first)
    const location = first.locations[0] ?? fail('first location missing')
    journeyState.locationId = stringField(location, 'id')
    const cashMethod = first.methods.find((method) => method['is_cash_tender'] === true)
      ?? fail('cash tender missing')
    journeyState.cashMethodId = stringField(cashMethod, 'id')
    journeyState.cashMethodCode = stringField(cashMethod, 'code')
    const cashRepository = first.repositories.find((repository) =>
      repository['type'] === 'cash_register' && repository['location_id'] === journeyState.locationId)
      ?? fail('location-owned cash repository missing')
    journeyState.cashRepositoryId = stringField(cashRepository, 'id')
    journeyState.cashRepositoryCode = stringField(cashRepository, 'code')
    await addLedgerEvidence('L0', censusLine('company 1', companyId, first))

    await addLedgerEvidence('L0', 'P3 discoverability (owner-ruled 2026-08-29, not a journey defect): company creation lives in the header company dropdown → Add Company → /company-onboarding; Settings → Companies edits only')
    await addLedgerEvidence('L0', 'P3 discoverability: AddCompanyModal.tsx is a dead duplicate create surface (rendered by nothing)')

    if (reuseMode) return
    const suffix = runId.replace(/[^a-z0-9]/gi, '').slice(-16)
    const secondCreate = await apiRequest(page, 'POST', apiRoutes.companyCreate, {
      country_code: country,
      currency: currencyForCountry(country),
      legal_name: `Campaign Second ${suffix}`,
      locale: 'fr',
      name: `Campaign Second ${suffix}`,
      timezone: 'Africa/Tunis',
    }, companyId)
    const secondCompany = asRecord(requireApiData(secondCreate, 'create second company'), 'second company')
    const secondCompanyId = stringField(secondCompany, 'id')
    const second = await census(page, secondCompanyId)
    expect(second.units.length, 'company 2 seeded units').toBeGreaterThanOrEqual(19)
    expect(second.methods.length, 'company 2 seeded payment methods').toBeGreaterThanOrEqual(1)
    expect(second.methods.filter((method) => method['is_cash_tender'] === true)).toHaveLength(1)
    expect(second.locations, 'company 2 default location').toHaveLength(1)
    const secondLocationId = stringField(second.locations[0] ?? fail('company 2 location missing'), 'id')
    await addLedgerEvidence('L0', censusLine('company 2', secondCompanyId, second))

    const ownedCash = second.repositories.filter((repository) =>
      repository['type'] === 'cash_register' && repository['location_id'] === secondLocationId)
    const ownedSafes = second.repositories.filter((repository) => repository['type'] === 'safe')
    if (ownedCash.length === 1 && ownedSafes.length === 1) {
      await addLedgerEvidence('L0', `company 2 repositories: 1 cash register on ${secondLocationId} + 1 safe`)
    } else {
      // Company 2 lacks its own CASH+SAFE on its default location. Since G-3c (dev 9badbe294) the
      // provisioning exists, so on a current tree this branch means a regression; recorded as a
      // finding (marks L0 FAIL, L10 keeps the run red) without throwing, so L1–L8 still run.
    await recordProductFinding({
        evidence: {
          request: {
            company_id: secondCompanyId,
            method: 'GET',
            path: apiRoutes.paymentRepositories,
          },
          response: {
            company_id: secondCompanyId,
            expected_location_id: secondLocationId,
            matching_cash_count: ownedCash.length,
            repositories: second.repositories.map((repository) => ({
              code: repository['code'],
              location_id: repository['location_id'],
              type: repository['type'],
            })),
            safe_count: ownedSafes.length,
          },
        },
        leg: 'L0',
        what: 'Second company is not provisioned with company-owned payment repositories',
        where: `GET ${apiRoutes.paymentRepositories} with X-Company-Id ${secondCompanyId}`,
      })
    }
  })

  test('L1 — parties import with balances', async ({ page }) => {
    const companyId = requiredState('companyId')
    const fixture = await renderFixture('parties.csv.template')
    const documentsBefore = await apiRecords(page, `${apiRoutes.documents}?limit=100`, companyId, 'documents before L1')
    const beforeIds = new Set(documentsBefore.map((document) => stringField(document, 'id')))
    await runImportWizard(page, 'parties', fixture)

    const partners = await apiRecords(page, `${apiRoutes.partners}?per_page=100&search=${encodeURIComponent(runId)}`, companyId, 'campaign partners')
    const campaignPartners = partners.filter((partner) => stringValue(partner['code']).includes(runId))
    expect(campaignPartners, 'all four sign-quadrant partners').toHaveLength(4)
    const documentsAfter = await apiRecords(page, `${apiRoutes.documents}?limit=100`, companyId, 'documents after L1')
    const historical = documentsAfter.filter((document) =>
      !beforeIds.has(stringField(document, 'id')) && stringValue(document['document_number']).startsWith('HIST-'))
    if (historical.length === 0) {
      // A company that already POSTED an AR/AP opening batch (earlier import, or a reused tenant)
      // gets its next balance-bearing rows silently skipped with a row warning (PartiesBalancesPhase
      // batch_conflict): the parties are created, the balances are not, and nothing blocks the
      // wizard. Recorded as a finding (Session G owns imports) — the hard assertion below still fails.
      await recordProductFinding({
        evidence: { request: { method: 'UI', path: routes.importDashboard, importer: 'parties' }, response: 'completion panel: rows imported with warnings; 0 HIST documents created' },
        leg: 'L1',
        what: 'Second parties-with-balances import after a posted AR/AP opening batch silently skips every balance (batch_conflict warning only)',
        where: 'Import wizard → Business partners (balances phase)',
      })
    }
    expect(historical, 'right balance-bearing importer creates four HIST documents').toHaveLength(4)
    assertHistoricalDocuments(historical)

    const customer = campaignPartners.find((partner) => stringValue(partner['code']).startsWith('CUST-POS-'))
      ?? fail('positive customer missing')
    const supplier = campaignPartners.find((partner) => stringValue(partner['code']).startsWith('SUPP-POS-'))
      ?? fail('positive supplier missing')
    journeyState.customerId = stringField(customer, 'id')
    journeyState.supplierId = stringField(supplier, 'id')
    assertMoneyEqual(stringField(customer, 'receivable_balance'), '1250.500')
    assertMoneyEqual(stringField(supplier, 'payable_balance'), '4780.250')
    const customerBalance = await apiObject(
      page,
      `${apiRoutes.partnerBalance(companyId, journeyState.customerId)}?purpose=customer_receivable`,
      companyId,
      'customer balance',
    )
    const supplierBalance = await apiObject(
      page,
      `${apiRoutes.partnerBalance(companyId, journeyState.supplierId)}?purpose=supplier_payable`,
      companyId,
      'supplier balance',
    )
    assertMoneyEqual(stringField(customerBalance, 'balance'), '1250.500')
    assertMoneyEqual(stringField(supplierBalance, 'balance'), '-4780.250')
    const invoice = historical.find((document) =>
      stringValue(document['document_number']).startsWith('HIST-INV-')) ?? fail('HIST invoice missing')
    journeyState.customerDocumentId = stringField(invoice, 'id')

    const rerun = await runImportWizard(page, 'parties', fixture, true)
    const partnersAfterRerun = await apiRecords(page, `${apiRoutes.partners}?per_page=100&search=${encodeURIComponent(runId)}`, companyId, 'partners after re-run')
    expect(partnersAfterRerun.filter((partner) => stringValue(partner['code']).includes(runId)), 're-run creates no duplicate partners').toHaveLength(4)
    const documentsRerun = await apiRecords(page, `${apiRoutes.documents}?limit=100`, companyId, 'documents after L1 rerun')
    expect(documentsRerun.filter((document) =>
      !beforeIds.has(stringField(document, 'id')) && stringValue(document['document_number']).startsWith('HIST-')),
    'same workbook creates no extra HIST documents').toHaveLength(4)
    await whereDidItLand('L1', 'parties opening balances', [
      async () => `HIST documents=4 (${historical.map((document) => stringValue(document['document_number'])).join(',')})`,
      async () => 'customer receivable=1250.500; supplier payable=4780.250',
      async () => `rerun document delta=0; duplicate policy skip=${String(rerun.duplicatePolicyApplied)}`,
    ])
    const rerunWorkbook = rerun.workbookText ?? fail('L1 rerun result workbook was not downloaded')
    if (!/duplicate|skipped|already exists/i.test(rerunWorkbook)) {
      await recordProductFinding({
        evidence: {
          request: { file: fixture, method: 'UI rerun' },
          response: 'Result workbook contains no duplicate/skipped/already-exists marker',
        },
        leg: 'L1',
        what: 'Idempotent parties rerun workbook does not report skipped duplicate rows',
        where: 'Import result workbook',
      })
    }
    expect(rerunWorkbook, 'rerun workbook reports skipped/duplicate rows').toMatch(/duplicate|skipped|already exists/i)
  })

  test('L2 — products import with opening stock', async ({ page }) => {
    const companyId = requiredState('companyId')
    const locationId = requiredState('locationId')
    const fixture = await renderFixture('products.csv.template')
    await runImportWizard(page, 'products', fixture)
    const products = await campaignProducts(page, companyId)
    expect(products, 'four products imported').toHaveLength(4)

    const expectedUnits = new Map([
      [`BATCH-${runId}`, 'pcs'],
      [`UNIT-${runId}`, 'kg'],
      [`BLANK-${runId}`, ''],
      [`ZERO-${runId}`, 'pcs'],
    ])
    for (const product of products) {
      const sku = stringField(product, 'sku')
      expect(stringValue(product['unit'] ?? ''), `${sku} legacy unit string`).toBe(expectedUnits.get(sku))
    }
    const batchProduct = products.find((product) => stringField(product, 'sku') === `BATCH-${runId}`)
      ?? fail('batch campaign product missing')
    journeyState.productId = stringField(batchProduct, 'id')
    journeyState.productSku = stringField(batchProduct, 'sku')
    journeyState.productName = stringField(batchProduct, 'name')
    journeyState.productOpeningQuantity = '20.000'
    const stockBeforeRerun = await stock(page, companyId, journeyState.productId, locationId)
    assertMoneyEqual(stringField(stockBeforeRerun, 'quantity'), '20.000')
    await runImportWizard(page, 'products', fixture)
    expect(await campaignProducts(page, companyId), 'product rerun does not duplicate rows').toHaveLength(4)
    const stockAfterRerun = await stock(page, companyId, journeyState.productId, locationId)
    assertMoneyEqual(stringField(stockAfterRerun, 'quantity'), '20.000')
    await whereDidItLand('L2', 'catalog and stock', [
      async () => 'products=4; unit strings pcs/kg/blank/pcs',
      async () => 'MAIN opening quantity=20.000; rerun quantity=20.000',
    ])

    const unresolved = products.filter((product) =>
      expectedUnits.get(stringField(product, 'sku')) !== '' && product['unit_id'] === null)
    if (unresolved.length > 0) {
      await recordProductFinding({
        evidence: {
          request: { method: 'GET', path: `${apiRoutes.products}?search=${runId}` },
          response: unresolved.map((product) => ({
            sku: product['sku'], unit: product['unit'], unit_id: product['unit_id'],
          })),
        },
        leg: 'L2',
        what: 'Products import never resolves `unit` → `unit_id`',
        where: 'Products import / ProductService and GET /products',
      })
    }
    // KNOWN GAP I2-F2 (import never writes unit_id — ProductService::importProduct): the finding above
    // marks L2 FAIL in the ledger and L10 keeps the run red; no throw, so L3–L8 still run.
    if (unresolved.length === 0) {
      await addLedgerEvidence('L2', 'every product with a unit code resolved unit_id')
    }
  })

  test('L3 — opening lots with expiries', async ({ page }) => {
    const companyId = requiredState('companyId')
    const productId = requiredState('productId')
    const locationId = requiredState('locationId')
    const batches = await apiRecords(page, apiRoutes.batchStock(productId), companyId, 'campaign product lots')
    const defaultLot = batches.find((batch) => batch['batch_number'] === 'DEFAULT') ?? fail('DEFAULT lot missing')
    expect(defaultLot['expiry_date'], 'DEFAULT lot preserves CSV expiry').toBe('2027-12-31')
    const batchStock = records(defaultLot['batch_stock'], 'DEFAULT batch stock')
    const atMain = batchStock.find((row) => row['location_id'] === locationId) ?? fail('DEFAULT lot MAIN row missing')
    assertMoneyEqual(stringField(atMain, 'quantity'), '20.000')
    const aggregate = await stock(page, companyId, productId, locationId)
    assertMoneyEqual(stringField(atMain, 'quantity'), stringField(aggregate, 'quantity'))
    await addLedgerEvidence('L3', 'DEFAULT lot expiry=2027-12-31; lot and aggregate quantity=20.000')
  })

  test('L4 — GL bank and cash-float openings', async ({ page }) => {
    const companyId = requiredState('companyId')
    const repositories = await apiRecords(page, apiRoutes.paymentRepositories, companyId, 'repositories before opening')
    const drawer = repositories.find((repository) => stringField(repository, 'id') === requiredState('cashRepositoryId'))
      ?? fail('cash drawer missing')
    const drawerGl = asRecord(drawer['gl_account'], 'drawer GL account')
    journeyState.cashGlAccountCode = stringField(drawerGl, 'code')
    const purposesPayload = asRecord(
      requireApiData(await apiRequest(page, 'GET', apiRoutes.accountPurposes(companyId), undefined, companyId), 'accounts with purposes'),
      'accounts with purposes',
    )
    const accounts = records(purposesPayload['accounts'], 'accounts with purposes')
    const bankAccount = accounts.find((account) =>
      account['system_purpose'] === 'bank' || stringValue(account['code']).startsWith('512'))
      ?? fail('bank GL account missing')
    journeyState.salesRevenueAccountCode = purposeAccountCode(accounts, 'product_revenue')
    journeyState.vatCollectedAccountCode = purposeAccountCode(accounts, 'vat_collected')
    journeyState.salesReturnAccountCode = purposeAccountCode(accounts, 'sales_return')
    journeyState.customerReceivableAccountCode = purposeAccountCode(accounts, 'customer_receivable')
    const bankCode = `BANK-${runId.replace(/[^a-z0-9]/gi, '').slice(-12).toUpperCase()}`
    const bankCreate = await apiRequest(page, 'POST', apiRoutes.paymentRepositories, {
      bank_name: 'Campaign Bank',
      code: bankCode,
      gl_account_id: stringField(bankAccount, 'id'),
      location_id: requiredState('locationId'), // attributed like the seeded repositories (census scopes by location)
      name: `Campaign Bank ${runId}`,
      type: 'bank_account',
    }, companyId)
    const bank = asRecord(requireApiData(bankCreate, 'bank repository create'), 'bank repository')
    journeyState.bankRepositoryCode = bankCode

    const batchCreate = await apiRequest(page, 'POST', apiRoutes.openingBatches(companyId), {
      cutover_date: businessDate(),
      name: `Campaign cash openings ${runId}`,
      source_system: 'campaign',
      type: 'ACCOUNTING',
    }, companyId)
    const batch = asRecord(requireApiData(batchCreate, 'accounting opening batch'), 'opening batch')
    const batchId = stringField(batch, 'id')
    journeyState.openingBatchId = batchId
    await expectSuccess(page, 'POST', apiRoutes.openingBatchImport(companyId, batchId), {
      rows: [
        {
          account_code: stringField(drawerGl, 'code'),
          debit: '1000.000',
          description: 'Campaign opening cash float',
          repository_code: stringField(drawer, 'code'),
        },
        {
          account_code: stringField(bankAccount, 'code'),
          debit: '5000.000',
          description: 'Campaign opening bank balance',
          repository_code: bankCode,
        },
      ],
    }, companyId, 'opening rows import')
    await expectSuccess(page, 'POST', apiRoutes.openingBatchValidate(companyId, batchId), undefined, companyId, 'opening batch validate')
    // Opening-balance equity is CUMULATIVE across the AR/AP (L1) and stock (L2) openings already
    // posted, so the pin is the DELTA of its net credit across this post, not an absolute.
    const equityAccount = accounts.find((account) => account['system_purpose'] === 'opening_balance_equity')
      ?? fail('opening-balance-equity account missing')
    const equityBefore = await openingEquityNetCredit(page, companyId, stringField(equityAccount, 'code'))
    await expectSuccess(page, 'POST', apiRoutes.openingBatchPost(companyId, batchId), undefined, companyId, 'opening batch post')

    const drawerAfter = await apiObject(page, apiRoutes.paymentRepository(stringField(drawer, 'id')), companyId, 'drawer after opening')
    const bankAfter = await apiObject(page, apiRoutes.paymentRepository(stringField(bank, 'id')), companyId, 'bank after opening')
    assertMoneyEqual(stringField(drawerAfter, 'balance'), '1000.000')
    assertMoneyEqual(stringField(bankAfter, 'balance'), '5000.000')
    const trial = await apiObject(page, `${apiRoutes.trialBalance}?as_of_date=${businessDate()}`, companyId, 'trial balance')
    expect(trial['is_balanced']).toBe(true)
    assertMoneyEqual(stringField(trial, 'total_debit'), stringField(trial, 'total_credit'))
    const equityAfter = await openingEquityNetCredit(page, companyId, stringField(equityAccount, 'code'))
    assertMoneyEqual(moneySubtract(equityAfter, equityBefore), '6000.000')
    await addLedgerEvidence('L4', `drawer=1000.000; ${bankCode}=5000.000; opening equity Δ=6000.000 (before ${equityBefore}, after ${equityAfter}); trial balanced`)
  })

  test('L5 — lock', async ({ page }) => {
    const companyId = requiredState('companyId')
    const batchId = requiredState('openingBatchId')
    await expectSuccess(page, 'POST', apiRoutes.openingBatchLock(companyId, batchId), undefined, companyId, 'lock opening batch')
    const locked = await apiObject(page, apiRoutes.openingBatch(companyId, batchId), companyId, 'locked opening batch')
    expect(locked['is_locked']).toBe(true)
    expect(locked['status']).toBe('LOCKED')

    const before = await apiRecords(page, `${apiRoutes.documents}?limit=100`, companyId, 'documents before locked rerun')
    const fixture = await renderFixture('parties-locked.csv.template')
    const lockedImport = await runImportWizard(page, 'parties', fixture, true)
    const after = await apiRecords(page, `${apiRoutes.documents}?limit=100`, companyId, 'documents after locked rerun')
    expect(after, 'locked company refuses additional balance documents').toHaveLength(before.length)
    const lockedWorkbook = lockedImport.workbookText ?? fail('locked import result workbook was not downloaded')
    expect(lockedWorkbook, 'fresh balance row reports the opening lock refusal').toMatch(/balance_not_posted|opening_locked/i)
    await addLedgerEvidence('L5', `opening batch ${batchId} LOCKED; fresh balance row reports opening_locked; document delta=0`)
  })

  test('L5b — session open', async ({ page }) => {
    const companyId = requiredState('companyId')
    const locationId = requiredState('locationId')
    const terminalResult = await apiRequest(page, 'POST', apiRoutes.terminalCreate, {
      code: `CMP-${runId.replace(/[^a-z0-9]/gi, '').slice(-12).toUpperCase()}`,
      location_id: locationId,
      name: `Campaign terminal ${runId}`,
    }, companyId)
    const terminal = asRecord(requireApiData(terminalResult, 'terminal create'), 'terminal')
    journeyState.terminalId = stringField(terminal, 'id')
    journeyState.terminalGenesisSeed = stringField(terminal, 'genesis_seed')
    journeyState.terminalCode = stringField(terminal, 'code')
    journeyState.terminalLabel = stringField(terminal, 'name')
    expect(terminal['is_active']).toBe(true)
    expect(terminal['fiscal_schema_version']).toBe(3)
    expect(terminal['v4_refund_authoring_enabled']).toBe(true)

    const terminalId = requiredState('terminalId')
    const preflight = await Promise.all([
      apiRequest(page, 'GET', `${apiRoutes.shifts}?terminal_id=${terminalId}`, undefined, companyId),
      apiRequest(page, 'GET', `${apiRoutes.zReports}?terminal_id=${terminalId}`, undefined, companyId),
      apiRequest(page, 'GET', apiRoutes.terminalZChainState(terminalId), undefined, companyId),
    ])
    for (const [index, result] of preflight.entries()) {
      expect(result.status, `L5b read permission preflight ${String(index + 1)} (403 means reuse principal lacks POS reads): ${JSON.stringify(result.body)}`).toBe(200)
    }
    const virginState = asRecord(requireApiData(preflight[2] ?? fail('z-chain preflight missing'), 'virgin z-chain state'), 'virgin z-chain state')
    expect(virginState).toEqual({
      grand_totals: null,
      z_hash_sequence: 0,
      z_last_hash: 'GENESIS',
      z_number: 0,
    })

    const sessionId = randomUUID()
    const openedAt = eventTime()
    const currencyScale = supportedFiscalScale()
    const open = await buildSessionOpenEnvelope({
      businessDate: businessDate(),
      companyId,
      currencyCode: currencyForCountry(campaignCountry()),
      currencyScale,
      eventTimeDevice: openedAt,
      genesisSeed: requiredState('terminalGenesisSeed'),
      openingFloatAmount: formatScaleThreeMoney('1000.000', currencyScale),
      operatorId: requiredState('userId'),
      operatorName: requiredCredentials().name,
      sessionId,
      shiftId: sessionId,
      shiftNumber: 1,
      tenantId: requiredState('tenantId'),
      terminalId,
      terminalLabel: requiredState('terminalLabel'),
    })
    const ingest = await apiRequest(page, 'POST', apiRoutes.fiscalEvents, open.requestBody, companyId)
    expect(ingest.status, JSON.stringify(ingest.body)).toBe(200)
    const ingestResults = records(asRecord(ingest.body, 'session open ingest response')['results'], 'session open ingest results')
    assertStoredFiscalResult(ingestResults[0] ?? fail('session open ingest result missing'), open.eventId)

    journeyState.sessionId = sessionId
    journeyState.sessionOpenEventId = open.eventId
    journeyState.sessionOpenEventTimeDevice = openedAt
    journeyState.sessionOpenHash = open.currentHash
    journeyState.sessionOpenSequenceNumber = open.sequenceNumber

    const shiftResult = await pollUntil(
      () => apiRequest(page, 'GET', apiRoutes.shift(sessionId), undefined, companyId),
      (value) => value.status === 200 && projectedShiftStatus(value) === 'OPEN',
    )
    const shift = asRecord(requireApiData(shiftResult, 'projected open shift'), 'projected open shift')
    expect(shift['id']).toBe(sessionId)
    expect(shift['session_id']).toBe(sessionId)
    expect(shift['shift_number']).toBe(1)
    expect(shift['status']).toBe('OPEN')
    assertMoneyEqual(stringField(shift, 'opening_cash'), formatScaleThreeMoney('1000.000', currencyScale))
    const current = await apiObject(page, apiRoutes.currentShift(requiredState('terminalCode')), companyId, 'current open shift')
    expect(current['id']).toBe(sessionId)
    await addLedgerEvidence('L5b', `server-minimal synthetic lifecycle (OPENING_FLOAT event deliberately omitted): terminal=${terminalId}; session_id=shift_id=${sessionId}; SESSION_OPEN seq=1 projected open; read preflight=200/200/200; virgin Z state=0`)
  })

  test('L6 — POS sale', async ({ page }) => {
    const companyId = requiredState('companyId')
    const productId = requiredState('productId')
    const locationId = requiredState('locationId')
    const saleEventTimeDevice = eventTime(1)
    const sale = await buildSaleEnvelope({
      businessDate: businessDate(),
      companyId,
      countryCode: campaignCountry(),
      currencyCode: currencyForCountry(campaignCountry()),
      currencyScale: supportedFiscalScale(),
      eventTimeDevice: saleEventTimeDevice,
      genesisSeed: requiredState('terminalGenesisSeed'),
      methodCode: requiredState('cashMethodCode'),
      operatorId: requiredState('userId'),
      productId,
      productName: requiredState('productName'),
      productSku: requiredState('productSku'),
      shiftId: requiredState('sessionId'),
      tenantId: requiredState('tenantId'),
      terminalId: requiredState('terminalId'),
    })
    const ingest = await apiRequest(page, 'POST', apiRoutes.fiscalEvents, sale.requestBody, companyId)
    expect(ingest.status, JSON.stringify(ingest.body)).toBe(200)
    const ingestBody = asRecord(ingest.body, 'sale ingest response')
    const ingestResults = records(ingestBody['results'], 'sale ingest results')
    assertStoredFiscalResult(ingestResults[0] ?? fail('sale ingest result missing'), sale.eventId)
    journeyState.saleEventId = sale.eventId
    journeyState.saleEventTimeDevice = saleEventTimeDevice
    journeyState.saleHash = sale.currentHash
    journeyState.saleReceiptUuid = sale.receiptUuid
    journeyState.saleSequenceNumber = sale.sequenceNumber

    const projectedStock = await pollUntil(
      () => stock(page, companyId, productId, locationId),
      (value) => moneyIs(stringField(value, 'quantity'), '19.000'),
    )
    assertMoneyEqual(stringField(projectedStock, 'quantity'), '19.000')
    const lots = await pollUntil(
      () => apiRecords(page, apiRoutes.batchStock(productId), companyId, 'sale batch projection'),
      (value) => defaultLotQuantity(value, locationId) === '19.0000' || defaultLotQuantity(value, locationId) === '19.000',
    )
    assertMoneyEqual(defaultLotQuantity(lots, locationId), '19.000')
    // The projected receipt list does not expose the fiscal event id / device uuid: match the
    // campaign terminal's SALE receipt (this terminal has exactly one).
    const receipts = await pollUntil(
      () => apiRecords(page, `${apiRoutes.receipts}?per_page=100`, companyId, 'projected receipts'),
      (value) => value.some((candidate) => isCampaignReceipt(candidate, 'sale')),
    )
    const receipt = receipts.find((value) => isCampaignReceipt(value, 'sale'))
      ?? fail('projected sale receipt missing')
    expect(stringField(receipt, 'fiscal_status'), 'sale receipt fiscal status').toBe('fiscalized')
    const receiptId = stringField(receipt, 'id')
    const journal = await pollUntil(
      () => apiRecords(page, `${apiRoutes.journalEntries}?per_page=100`, companyId, 'sale journal'),
      (value) => value.some((entry) => entry['source_id'] === receiptId),
    )
    const saleEntry = journal.find((entry) => entry['source_id'] === receiptId)
      ?? fail('sale journal entry missing')
    assertJournalAccountCode(saleEntry, requiredState('salesRevenueAccountCode'), 'credit', '20.000')
    assertJournalAccountCode(saleEntry, requiredState('vatCollectedAccountCode'), 'credit', '3.800')
    assertJournalAccountCode(saleEntry, requiredState('cashGlAccountCode'), 'debit', '23.800')
    await whereDidItLand('L6', 'v5 sale projection', [
      async () => `fiscal_event=${sale.eventId}; receipt=${sale.receiptUuid}`,
      async () => 'MAIN stock and DEFAULT lot 20.000→19.000',
      async () => 'GL revenue Cr20.000; VAT Cr3.800; cash Dr23.800',
    ])
    // Pin the drawer after the sale so L7's 1000.000 proves "sale + refund", not "neither".
    const drawerAfterSale = await apiObject(page, apiRoutes.paymentRepository(requiredState('cashRepositoryId')), companyId, 'drawer after sale')
    assertMoneyEqual(stringField(drawerAfterSale, 'balance'), '1023.800')
  })

  test('L7 — refund', async ({ page }) => {
    const companyId = requiredState('companyId')
    const productId = requiredState('productId')
    const locationId = requiredState('locationId')
    const refundEventTimeDevice = eventTime(5)
    const refund = await buildRefundEnvelope({
      businessDate: businessDate(),
      companyId,
      countryCode: campaignCountry(),
      currencyCode: currencyForCountry(campaignCountry()),
      currencyScale: supportedFiscalScale(),
      eventTimeDevice: refundEventTimeDevice,
      genesisSeed: requiredState('terminalGenesisSeed'),
      methodCode: requiredState('cashMethodCode'),
      operatorId: requiredState('userId'),
      originalEventId: requiredState('saleEventId'),
      originalReceiptUuid: requiredState('saleReceiptUuid'),
      previousHash: requiredState('saleHash'),
      productId,
      productName: requiredState('productName'),
      productSku: requiredState('productSku'),
      shiftId: requiredState('sessionId'),
      tenantId: requiredState('tenantId'),
      terminalId: requiredState('terminalId'),
    })
    const ingest = await apiRequest(page, 'POST', apiRoutes.fiscalEvents, refund.requestBody, companyId)
    expect(ingest.status, JSON.stringify(ingest.body)).toBe(200)
    const ingestResults = records(asRecord(ingest.body, 'refund ingest response')['results'], 'refund ingest results')
    assertStoredFiscalResult(ingestResults[0] ?? fail('refund ingest result missing'), refund.eventId)
    journeyState.refundEventId = refund.eventId
    journeyState.refundEventTimeDevice = refundEventTimeDevice
    journeyState.refundHash = refund.currentHash
    journeyState.refundSequenceNumber = refund.sequenceNumber
    await pollUntil(
      () => stock(page, companyId, productId, locationId),
      (value) => moneyIs(stringField(value, 'quantity'), '20.000'),
    )
    const lots = await pollUntil(
      () => apiRecords(page, apiRoutes.batchStock(productId), companyId, 'refund batch projection'),
      (value) => moneyIs(defaultLotQuantity(value, locationId), '20.000'),
    )
    assertMoneyEqual(defaultLotQuantity(lots, locationId), '20.000')
    const payments = await pollUntil(
      () => apiRecords(page, `${apiRoutes.payments}?per_page=100`, companyId, 'refund payments'),
      (value) => value.some((payment) => payment['payment_type'] === 'pos_refund'),
    )
    expect(payments.some((payment) => payment['payment_type'] === 'pos_refund')).toBe(true)
    const refundReceipt = await pollUntil(
      () => apiRecords(page, `${apiRoutes.receipts}?per_page=100&invoice_type_codes[]=SALE&invoice_type_codes[]=REFUND`, companyId, 'refund receipt'), // index defaults to SALE only
      (value) => value.some((candidate) => isCampaignReceipt(candidate, 'refund')),
    )
    const refundReceiptId = stringField(
      refundReceipt.find((candidate) => isCampaignReceipt(candidate, 'refund'))
        ?? fail('projected refund receipt missing'),
      'id',
    )
    const journal = await pollUntil(
      () => apiRecords(page, `${apiRoutes.journalEntries}?per_page=100`, companyId, 'refund journal'),
      (value) => value.some((entry) => entry['source_id'] === refundReceiptId),
    )
    const refundEntry = journal.find((entry) => entry['source_id'] === refundReceiptId)
      ?? fail('refund journal entry missing')
    // Contract (GeneralLedgerService::createPOSRefundReversalEntry, source_type
    // 'pos_receipt_refund'): the refund is the SYMMETRIC REVERSAL of the sale —
    // Dr product_revenue (net) + Dr vat_collected (sealed VAT) / Cr the drawer's
    // GL account (gross). The contra-revenue `sales_return` purpose exists but
    // has no posting helper yet (documented Phase-1.5 refinement); it is used
    // only by RefundCompensationService (dead-lettered projections). Asserting
    // sales_return here would be a FALSE product finding (gate r1 I2-R1-05).
    expect(refundEntry['source_type'], 'refund entry source_type').toBe('pos_receipt_refund')
    assertJournalAccountCode(refundEntry, requiredState('salesRevenueAccountCode'), 'debit', '20.000')
    assertJournalAccountCode(refundEntry, requiredState('vatCollectedAccountCode'), 'debit', '3.800')
    assertJournalAccountCode(refundEntry, requiredState('cashGlAccountCode'), 'credit', '23.800')
    const repository = await apiObject(page, apiRoutes.paymentRepository(requiredState('cashRepositoryId')), companyId, 'drawer after refund')
    assertMoneyEqual(stringField(repository, 'balance'), '1000.000')
    const trialAfterPos = await apiObject(page, `${apiRoutes.trialBalance}?as_of_date=${businessDate()}`, companyId, 'trial balance after POS')
    expect(trialAfterPos['is_balanced'], 'trial balance after sale + refund').toBe(true)
    await addLedgerEvidence('L7', `v4 refund ${refund.eventId}; stock+lot restored=20.000; POS-refund payment; drawer=1000.000; trial balanced after POS`)
  })

  test('L8 — payment allocation vs historical invoice', async ({ page }) => {
    const companyId = requiredState('companyId')
    const payment = await apiRequest(page, 'POST', apiRoutes.payments, {
      allocations: [{ document_id: requiredState('customerDocumentId'), amount: '1250.500' }],
      amount: '1250.500',
      currency: currencyForCountry(campaignCountry()),
      partner_id: requiredState('customerId'),
      payment_date: businessDate(),
      payment_method_id: requiredState('cashMethodId'),
      reference: `CAMPAIGN-${runId}`,
      repository_id: requiredState('cashRepositoryId'),
    }, companyId)
    const paymentData = asRecord(requireApiData(payment, 'customer payment'), 'customer payment')
    const paymentId = stringField(paymentData, 'id')
    expect(paymentData['status']).toBe('completed')
    assertMoneyEqual(stringField(paymentData, 'unallocated_amount'), '0.000')
    const document = await apiObject(page, apiRoutes.document(requiredState('customerDocumentId')), companyId, 'settled HIST invoice')
    expect(document['status']).toBe('paid')
    assertMoneyEqual(stringField(document, 'balance_due'), '0.000')
    const balance = await apiObject(
      page,
      `${apiRoutes.partnerBalance(companyId, requiredState('customerId'))}?purpose=customer_receivable`,
      companyId,
      'settled customer balance',
    )
    assertMoneyEqual(stringField(balance, 'balance'), '0.000')
    const repository = await apiObject(page, apiRoutes.paymentRepository(requiredState('cashRepositoryId')), companyId, 'drawer after customer payment')
    assertMoneyEqual(stringField(repository, 'balance'), '2250.500')
    const journal = await pollUntil(
      () => apiRecords(page, `${apiRoutes.journalEntries}?per_page=100`, companyId, 'payment journal'),
      (value) => value.some((entry) => entry['source_id'] === paymentId),
    )
    const paymentEntry = journal.find((entry) => entry['source_id'] === paymentId)
      ?? fail('customer payment journal entry missing')
    assertJournalAccountCode(paymentEntry, requiredState('customerReceivableAccountCode'), 'credit', '1250.500')
    await addLedgerEvidence('L8', 'HIST invoice paid/balance=0; partner receivable=0; drawer=2250.500; AR credited=1250.500')
  })

  test('L9 — cash count + Z', async ({ page }) => {
    const companyId = requiredState('companyId')
    const terminalId = requiredState('terminalId')
    const sessionId = requiredState('sessionId')
    const drawerBefore = await apiObject(
      page,
      apiRoutes.paymentRepository(requiredState('cashRepositoryId')),
      companyId,
      'drawer before close and Z',
    )
    const drawerBalanceBefore = stringField(drawerBefore, 'balance')
    assertMoneyEqual(drawerBalanceBefore, '2250.500')

    const closeEventTimeDevice = eventTime(10)
    const reportCoordinates = {
      businessDate: businessDate(),
      cashMethodCode: requiredState('cashMethodCode'),
      cashMethodId: requiredState('cashMethodId'),
      companyId,
      companyName: requiredState('companyName'),
      currencyCode: currencyForCountry(campaignCountry()),
      currencyScale: supportedFiscalScale(),
      eventTimeDevice: closeEventTimeDevice,
      openedAtDevice: requiredState('sessionOpenEventTimeDevice'),
      operatorId: requiredState('userId'),
      operatorName: requiredCredentials().name,
      periodEnd: withMilliseconds(closeEventTimeDevice),
      periodStart: withMilliseconds(requiredState('sessionOpenEventTimeDevice')),
      refundEventTimeDevice: requiredState('refundEventTimeDevice'),
      refundHash: requiredState('refundHash'),
      refundSequenceNumber: requiredSequenceState('refundSequenceNumber'),
      saleEventTimeDevice: requiredState('saleEventTimeDevice'),
      saleHash: requiredState('saleHash'),
      saleSequenceNumber: requiredSequenceState('saleSequenceNumber'),
      sessionId,
      shiftId: sessionId,
      tenantId: requiredState('tenantId'),
      terminalId,
      terminalLabel: requiredState('terminalLabel'),
    }
    expect(Date.parse(reportCoordinates.periodStart)).toBeLessThanOrEqual(Date.parse(reportCoordinates.saleEventTimeDevice))
    expect(Date.parse(reportCoordinates.periodEnd)).toBeGreaterThanOrEqual(Date.parse(reportCoordinates.refundEventTimeDevice))
    expect(Date.parse(reportCoordinates.openedAtDevice)).toBeLessThanOrEqual(Date.parse(closeEventTimeDevice))
    expect(Date.parse(closeEventTimeDevice)).toBeGreaterThan(Date.parse(reportCoordinates.refundEventTimeDevice))

    const close = await buildSessionCloseEnvelope({
      ...reportCoordinates,
      previousHash: requiredState('sessionOpenHash'),
      sessionCloseUuid: randomUUID(),
    })
    const closeIngest = await apiRequest(page, 'POST', apiRoutes.fiscalEvents, close.requestBody, companyId)
    expect(closeIngest.status, JSON.stringify(closeIngest.body)).toBe(200)
    const closeResults = records(asRecord(closeIngest.body, 'session close ingest response')['results'], 'session close ingest results')
    assertStoredFiscalResult(closeResults[0] ?? fail('session close ingest result missing'), close.eventId)

    const closedShiftResult = await pollUntil(
      () => apiRequest(page, 'GET', apiRoutes.shift(sessionId), undefined, companyId),
      (value) => value.status === 200 && projectedShiftStatus(value) === 'CLOSED',
    )
    const closedShift = asRecord(requireApiData(closedShiftResult, 'projected closed shift'), 'projected closed shift')
    expect(closedShift['status']).toBe('CLOSED')
    assertMoneyEqual(
      stringField(closedShift, 'expected_cash'),
      formatScaleThreeMoney('1000.000', reportCoordinates.currencyScale),
    )
    assertMoneyEqual(
      stringField(closedShift, 'actual_cash'),
      formatScaleThreeMoney('1000.000', reportCoordinates.currencyScale),
    )
    assertMoneyEqual(
      stringField(closedShift, 'variance'),
      formatScaleThreeMoney('0.000', reportCoordinates.currencyScale),
    )

    const virginBeforeZ = await apiObject(
      page,
      apiRoutes.terminalZChainState(terminalId),
      companyId,
      'virgin z-chain state immediately before first Z',
    )
    expect(virginBeforeZ).toEqual({
      grand_totals: null,
      z_hash_sequence: 0,
      z_last_hash: 'GENESIS',
      z_number: 0,
    })

    const zReport = await buildZReportEnvelope({
      ...reportCoordinates,
      closeEventId: close.eventId,
      closeHash: close.currentHash,
      closeSequenceNumber: close.sequenceNumber,
      openEventId: requiredState('sessionOpenEventId'),
      openHash: requiredState('sessionOpenHash'),
      openSequenceNumber: requiredSequenceState('sessionOpenSequenceNumber'),
      zReportUuid: randomUUID(),
    })
    const zIngest = await apiRequest(page, 'POST', apiRoutes.fiscalEvents, zReport.requestBody, companyId)
    expect(zIngest.status, JSON.stringify(zIngest.body)).toBe(200)
    const zResults = records(asRecord(zIngest.body, 'Z ingest response')['results'], 'Z ingest results')
    assertStoredFiscalResult(zResults[0] ?? fail('Z ingest result missing'), zReport.eventId)

    const zDetailResult = await pollUntil(
      () => apiRequest(page, 'GET', `${apiRoutes.zReport(1)}?terminal_id=${terminalId}`, undefined, companyId),
      (value) => value.status === 200,
    )
    const zDetail = asRecord(requireApiData(zDetailResult, 'projected Z detail'), 'projected Z detail')
    expect(zDetail['z_number']).toBe(1)
    expect(zDetail['fiscal_hash']).toBe(zReport.currentHash)
    expect(
      zDetail['is_first_z_report'],
      'I3-F5 (previous_z_hash legacy surface) — flip to true when the projection stops writing the close hash; see docs/qa/ONBOARDING-CAMPAIGN.md',
    ).toBe(false)
    const reportData = asRecord(zDetail['report_data'], 'Z report_data')
    expect(reportData).toMatchObject({
      actual_cash: formatScaleThreeMoney('1000.000', reportCoordinates.currencyScale),
      expected_cash: formatScaleThreeMoney('1000.000', reportCoordinates.currencyScale),
      gross_sales: formatScaleThreeMoney('23.800', reportCoordinates.currencyScale),
      net_sales: formatScaleThreeMoney('20.000', reportCoordinates.currencyScale),
      refunds_amount: formatScaleThreeMoney('23.800', reportCoordinates.currencyScale),
      refunds_count: 1,
      sales_count: 1,
      tax_amount: formatScaleThreeMoney('3.800', reportCoordinates.currencyScale),
      variance: formatScaleThreeMoney('0.000', reportCoordinates.currencyScale),
      voided_count: 0,
    })
    const expectedVatBreakdown = [{
      gross_amount: formatScaleThreeMoney('0.000', reportCoordinates.currencyScale),
      net_amount: formatScaleThreeMoney('0.000', reportCoordinates.currencyScale),
      tax_rate: 19,
      vat_amount: formatScaleThreeMoney('0.000', reportCoordinates.currencyScale),
    }]
    const expectedPaymentTotals = [{
      payment_type: requiredState('cashMethodCode'),
      total_amount: formatScaleThreeMoney('0.000', reportCoordinates.currencyScale),
      transaction_count: 2,
    }]
    expect(reportData['vat_breakdown']).toEqual(expectedVatBreakdown)
    expect(reportData['payment_methods']).toEqual(expectedPaymentTotals)
    const cashCount = asRecord(reportData['cash_count'], 'Z report_data.cash_count')
    expect(cashCount['variance_reason']).toBe('campaign counted balance')
    const canonicalZ = asRecord(reportData['canonical_z_report'], 'Z report_data.canonical_z_report')
    expect(canonicalZ['vat_breakdown']).toEqual(expectedVatBreakdown)
    expect(canonicalZ['payment_method_totals']).toEqual(expectedPaymentTotals)
    expect(canonicalZ['operational_event_range']).toEqual({
      first_receipt_hash: requiredState('saleHash'),
      first_receipt_sequence: 1,
      last_receipt_hash: requiredState('refundHash'),
      last_receipt_sequence: 2,
      receipt_count: 2,
    })
    expect(canonicalZ['session_event_range']).toEqual({
      first_sequence: 1,
      last_sequence: 2,
      session_close_event_id: close.eventId,
      session_close_hash: close.currentHash,
      session_open_event_id: requiredState('sessionOpenEventId'),
      session_open_hash: requiredState('sessionOpenHash'),
    })

    const zList = await apiRecords(
      page,
      `${apiRoutes.zReports}?terminal_id=${terminalId}`,
      companyId,
      'projected Z list',
    )
    expect(zList).toHaveLength(1)
    expect(zList[0]?.['z_number']).toBe(1)

    const replay = await apiRequest(page, 'POST', apiRoutes.fiscalEvents, zReport.requestBody, companyId)
    expect(replay.status, JSON.stringify(replay.body)).toBe(200)
    const replayResults = records(asRecord(replay.body, 'Z replay response')['results'], 'Z replay results')
    assertReplayedFiscalResult(replayResults[0] ?? fail('Z replay result missing'), zReport.eventId)
    expect(await apiRecords(
      page,
      `${apiRoutes.zReports}?terminal_id=${terminalId}`,
      companyId,
      'Z list after replay',
    )).toHaveLength(1)

    const zState = await apiObject(page, apiRoutes.terminalZChainState(terminalId), companyId, 'Z state after first Z')
    expect(zState).toEqual({
      grand_totals: {
        cumulative_refunds: formatScaleThreeMoney('23.800', reportCoordinates.currencyScale),
        cumulative_sales: formatScaleThreeMoney('23.800', reportCoordinates.currencyScale),
        cumulative_tax: formatScaleThreeMoney('3.800', reportCoordinates.currencyScale),
        perpetual_grand_total: formatScaleThreeMoney('0.000', reportCoordinates.currencyScale),
        receipt_count_lifetime: 1,
      },
      z_hash_sequence: 1,
      z_last_hash: zReport.currentHash,
      z_number: 1,
    })
    const currentAfterClose = await apiRequest(
      page,
      'GET',
      apiRoutes.currentShift(requiredState('terminalCode')),
      undefined,
      companyId,
    )
    expect(currentAfterClose.status, JSON.stringify(currentAfterClose.body)).toBe(200)
    expect(requireApiData(currentAfterClose, 'current shift after close')).toBeNull()
    const shifts = await apiRecords(
      page,
      `${apiRoutes.shifts}?terminal_id=${terminalId}`,
      companyId,
      'terminal shift list after close',
    )
    expect(shifts).toHaveLength(1)
    expect(shifts[0]?.['status']).toBe('CLOSED')

    const drawerAfter = await apiObject(
      page,
      apiRoutes.paymentRepository(requiredState('cashRepositoryId')),
      companyId,
      'drawer after close and Z',
    )
    assertMoneyEqual(stringField(drawerAfter, 'balance'), drawerBalanceBefore)
    // B5: the repository SET after close + Z is exactly the day-one pair plus L4's bank repository —
    // an implicit close/Z-time provisioning would surface here (fiscal gate I3C-2).
    const censusAfterZ = await census(page, companyId)
    assertDayOneCensus(censusAfterZ, { allowExtraRepositories: true })
    expect(censusAfterZ.repositories, 'exactly cash register + safe + L4 bank after Z').toHaveLength(3)
    expect(censusAfterZ.repositories.filter((repository) => repository['type'] === 'bank_account' && repository['code'] === requiredState('bankRepositoryCode')), 'the third repository is L4\'s bank').toHaveLength(1)
    await addLedgerEvidence('L9', `SESSION_CLOSE seq=2 + Z_REPORT seq=3 projected; shift expected=actual=1000.000 variance=0; Z=1 first=false (known legacy defect); net VAT row=0; ${requiredState('cashMethodCode')} net=0/2 transactions; replay stored=false/one Z row; drawer unchanged=${drawerBalanceBefore}; current shift=null; census asserted one POS location, one cash tender, one drawer, one safe, >=19 units, and exactly 3 repositories (seeded drawer + safe + L4 bank)`)
  })

  test('L10 — findings gate', async () => {
    const findings = ledgerFindings()
    expect(
      findings.map((finding) => `${finding.leg}: ${finding.what} @ ${finding.where}`),
      'product findings recorded during the journey (the run is RED while any exist)',
    ).toEqual([])
  })
})

async function census(page: Page, companyId: string): Promise<Census> {
  const [locations, methods, allRepositories, units] = await Promise.all([
    apiRecords(page, apiRoutes.locations, companyId, 'locations'),
    apiRecords(page, apiRoutes.paymentMethods, companyId, 'payment methods'),
    apiRecords(page, apiRoutes.paymentRepositories, companyId, 'payment repositories'),
    apiRecords(page, apiRoutes.units, companyId, 'units'),
  ])
  // GET /payment-repositories is TENANT-scoped today (PaymentRepositoryController::index() has no
  // company_id filter, unlike show()): with X-Company-Id set, another company's drawers still come
  // back. The payload carries no company_id, so scope by the company's own locations (day-one
  // repositories are attributed to a location — PaymentRepositoryProvisioningService) and treat
  // rows attributed to a foreign location as the second-of-everything leak finding.
  const ownLocationIds = new Set(locations.map((location) => stringField(location, 'id')))
  const foreign = allRepositories.filter((repository) =>
    typeof repository['location_id'] === 'string' && !ownLocationIds.has(repository['location_id']))
  if (foreign.length > 0 && !reportedRepositoryLeak) {
    reportedRepositoryLeak = true
    await recordProductFinding({
      evidence: {
        request: { method: 'GET', path: apiRoutes.paymentRepositories, header: { 'X-Company-Id': companyId } },
        response: foreign.map((repository) => ({ code: repository['code'], location_id: repository['location_id'], type: repository['type'] })),
      },
      leg: 'L0',
      what: 'GET /payment-repositories is tenant-scoped: company B sees company A\'s drawers and safes (index() lacks the company_id filter show() has)',
      where: 'apps/api PaymentRepositoryController::index()',
    })
  }
  const repositories = allRepositories.filter((repository) =>
    typeof repository['location_id'] !== 'string' || ownLocationIds.has(repository['location_id']))
  return { locations, methods, repositories, units }
}
let reportedRepositoryLeak = false

function assertDayOneCensus(
  value: Census,
  options: { allowExtraRepositories?: boolean } = {},
): void {
  expect(value.locations, 'exactly one day-one location').toHaveLength(1)
  expect(value.locations[0]?.['pos_enabled'], 'day-one location is POS enabled').toBe(true)
  expect(value.methods.length, 'payment methods seeded').toBeGreaterThanOrEqual(1)
  expect(value.methods.filter((method) => method['is_cash_tender'] === true), 'one cash tender').toHaveLength(1)
  const locationId = stringField(value.locations[0] ?? fail('location missing'), 'id')
  expect(value.repositories.filter((repository) =>
    repository['type'] === 'cash_register' && repository['location_id'] === locationId),
  'one location-owned cash register').toHaveLength(1)
  // Seeded safes CARRY the company's location (PaymentRepositorySeeder attributes both rows).
  expect(value.repositories.filter((repository) => repository['type'] === 'safe'), 'one safe').toHaveLength(1)
  if (!reuseMode && options.allowExtraRepositories !== true) {
    expect(value.repositories, 'only the company\'s cash register and safe are provisioned').toHaveLength(2)
  }
  expect(value.units.length, 'country units seeded').toBeGreaterThanOrEqual(19)
}

function censusLine(label: string, companyId: string, value: Census): string {
  return `${label} ${companyId}: locations=${value.locations.length}, methods=${value.methods.length}, cash_tenders=${value.methods.filter((method) => method['is_cash_tender'] === true).length}, repositories=${value.repositories.map((repository) => `${stringValue(repository['type'])}:${stringValue(repository['code'])}@${stringValue(repository['location_id'])}`).join(',')}, units=${value.units.length}`
}

async function apiRecords(
  page: Page,
  path: string,
  companyId: string,
  description: string,
): Promise<Record<string, unknown>[]> {
  const result = await apiRequest(page, 'GET', path, undefined, companyId)
  return records(requireApiData(result, description), description)
}

async function apiObject(
  page: Page,
  path: string,
  companyId: string,
  description: string,
): Promise<Record<string, unknown>> {
  const result = await apiRequest(page, 'GET', path, undefined, companyId)
  return asRecord(requireApiData(result, description), description)
}

async function expectSuccess(
  page: Page,
  method: 'POST' | 'PATCH',
  path: string,
  body: unknown,
  companyId: string,
  description: string,
): Promise<ApiResult> {
  const result = await apiRequest(page, method, path, body, companyId)
  expect(result.status, `${description}: ${JSON.stringify(result.body)}`).toBeGreaterThanOrEqual(200)
  expect(result.status, `${description}: ${JSON.stringify(result.body)}`).toBeLessThan(300)
  return result
}

function records(value: unknown, description: string): Record<string, unknown>[] {
  if (Array.isArray(value)) return value.map((row, index) => asRecord(row, `${description}[${index}]`))
  const envelope = asRecord(value, description)
  return asArray(envelope['data'], `${description}.data`).map((row, index) =>
    asRecord(row, `${description}.data[${index}]`))
}

function assertHistoricalDocuments(documents: Record<string, unknown>[]): void {
  const expectations = [
    { prefix: 'HIST-INV-', type: 'invoice', amount: '1250.500' },
    { prefix: 'HIST-CN-', type: 'credit_note', amount: '300.000' },
    { prefix: 'HIST-SINV-', type: 'supplier_invoice', amount: '4780.250' },
    { prefix: 'HIST-SCN-', type: 'supplier_credit_note', amount: '150.000' },
  ]
  for (const expected of expectations) {
    const document = documents.find((candidate) =>
      stringValue(candidate['document_number']).startsWith(expected.prefix))
      ?? fail(`${expected.prefix} document missing`)
    expect(document['type'], `${expected.prefix} type`).toBe(expected.type)
    assertMoneyEqual(stringField(document, 'total'), expected.amount)
  }
}

async function campaignProducts(page: Page, companyId: string): Promise<Record<string, unknown>[]> {
  const products = await apiRecords(
    page,
    `${apiRoutes.products}?per_page=100&search=${encodeURIComponent(runId)}`,
    companyId,
    'campaign products',
  )
  return products.filter((product) => stringValue(product['sku']).includes(runId))
}

async function stock(
  page: Page,
  companyId: string,
  productId: string,
  locationId: string,
): Promise<Record<string, unknown>> {
  return apiObject(page, apiRoutes.stockLevel(productId, locationId), companyId, 'stock level')
}

function defaultLotQuantity(batches: Record<string, unknown>[], locationId: string): string {
  const defaultLot = batches.find((batch) => batch['batch_number'] === 'DEFAULT')
    ?? fail('DEFAULT lot missing')
  const atLocation = records(defaultLot['batch_stock'], 'DEFAULT lot stock')
    .find((row) => row['location_id'] === locationId) ?? fail('DEFAULT lot location missing')
  return stringField(atLocation, 'quantity')
}

function purposeAccountCode(
  accounts: Record<string, unknown>[],
  purpose: string,
): string {
  const account = accounts.find((candidate) => candidate['system_purpose'] === purpose)
    ?? fail(`${purpose} system account missing`)
  return stringField(account, 'code')
}

function assertStoredFiscalResult(result: Record<string, unknown>, eventId: string): void {
  expect(result['stored'], 'fiscal event persisted').toBe(true)
  expect(result['fiscal_event_id'], 'persisted fiscal event id').toBe(eventId)
  expect(result['sequence_conflict'], 'fiscal chain sequence accepted').toBe(false)
  expect(result['exception_class'], 'fiscal event was not quarantined').toBeNull()
}

function assertReplayedFiscalResult(result: Record<string, unknown>, eventId: string): void {
  expect(result['stored'], 'replayed fiscal event was not inserted again').toBe(false)
  expect(result['fiscal_event_id'], 'replay resolves the existing fiscal event id').toBe(eventId)
  expect(result['sequence_conflict'], 'exact replay is not a sequence conflict').toBe(false)
  expect(result['exception_class'], 'exact replay is not quarantined').toBeNull()
}

function assertJournalAccountCode(
  entry: Record<string, unknown>,
  accountCode: string,
  side: 'debit' | 'credit',
  amount: string,
): void {
  const line = journalLines(entry).find((candidate) => candidate['account_code'] === accountCode)
    ?? fail(`${accountCode} journal line missing`)
  assertMoneyEqual(stringField(line, side), amount)
}


function journalLines(entry: Record<string, unknown>): Record<string, unknown>[] {
  return asArray(entry['lines'], 'journal entry lines').map((line, index) =>
    asRecord(line, `journal line ${index}`))
}


function requiredState(key: keyof typeof journeyState): string {
  const value = journeyState[key]
  if (typeof value !== 'string' || value === '') throw new Error(`Campaign state ${key} is missing`)
  return value
}

function requiredSequenceState(
  key: 'refundSequenceNumber' | 'saleSequenceNumber' | 'sessionOpenSequenceNumber',
): number {
  const value = journeyState[key]
  if (value === undefined || !Number.isInteger(value) || value < 1) {
    throw new Error(`Campaign state ${key} is missing`)
  }
  return value
}

function requiredCredentials() {
  return journeyState.credentials ?? fail('Campaign state credentials are missing')
}

function supportedFiscalScale(): 2 | 3 {
  const scale = currencyScaleForCountry(campaignCountry())
  if (scale === 2 || scale === 3) return scale
  throw new Error(`Unsupported fiscal currency scale: ${String(scale)}`)
}

function projectedShiftStatus(result: ApiResult): string {
  if (result.status !== 200 || !isObjectWithData(result.body)) return ''
  const data = result.body['data']
  return typeof data === 'object' && data !== null && !Array.isArray(data)
    ? stringValue((data as Record<string, unknown>)['status'])
    : ''
}

function isObjectWithData(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) && 'data' in value
}

function moneyIs(actual: string, expected: string): boolean {
  return normalizeMoney(actual) === normalizeMoney(expected)
}

function stringValue(value: unknown): string {
  return typeof value === 'string' ? value : value === null || value === undefined ? '' : String(value)
}

function businessDate(): string {
  return new Date().toISOString().slice(0, 10)
}

function eventTime(offsetMinutes = 0): string {
  const date = new Date(Date.now() + offsetMinutes * 60_000)
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

function fail(message: string): never {
  throw new Error(message)
}

/** Net credit (credit − debit) of one account on the trial balance as of today, scale 3, no floats. */
async function openingEquityNetCredit(page: Page, companyId: string, accountCode: string): Promise<string> {
  const trial = await apiObject(page, `${apiRoutes.trialBalance}?as_of_date=${businessDate()}`, companyId, 'trial balance')
  const line = records(trial['lines'], 'trial balance lines').find((candidate) => candidate['account_code'] === accountCode)
  if (!line) return '0.000'
  return moneySubtract(normalizeMoney(stringField(line, 'credit')), normalizeMoney(stringField(line, 'debit')))
}

/** Scale-3 string subtraction with BigInt — never a float. */
function moneySubtract(a: string, b: string): string {
  const toUnits = (value: string): bigint => {
    const normalized = normalizeMoney(value)
    const negative = normalized.startsWith('-')
    const [whole, fraction = ''] = normalized.replace('-', '').split('.')
    const units = BigInt(`${whole}${fraction.padEnd(3, '0').slice(0, 3)}`)
    return negative ? -units : units
  }
  const delta = toUnits(a) - toUnits(b)
  const sign = delta < 0n ? '-' : ''
  const magnitude = (delta < 0n ? -delta : delta).toString().padStart(4, '0')
  return `${sign}${magnitude.slice(0, -3)}.${magnitude.slice(-3)}`
}

/** A projected receipt authored by the campaign terminal, by kind (sale = no original; refund = points at one). */
function isCampaignReceipt(receipt: Record<string, unknown>, kind: 'sale' | 'refund'): boolean {
  if (receipt['terminal_id'] !== journeyState.terminalId) return false
  const type = stringValue(receipt['receipt_type']).toLowerCase()
  const invoiceType = stringValue(receipt['invoice_type_code']).toUpperCase()
  const isRefund = receipt['original_receipt_id'] !== null || invoiceType === 'REFUND' || /refund|return/.test(type)
  return kind === 'refund' ? isRefund : !isRefund
}
