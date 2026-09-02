import {
  expect,
  test,
  type Browser,
  type Page,
} from '@playwright/test'

import { asArray, asRecord, pollUntil, stringField } from '../e2e/campaign/journey'
import {
  createWave2Harness,
  PART1_PRODUCT_FIXTURES,
  setup1,
  setup2,
  setup3,
  setup4,
  setup5,
  setup6,
  setup7,
  setup8,
  type Row,
} from './wave2-shared'
import { money, screenshot, sql, tenantDb } from './wave2-support'

const harness = createWave2Harness({
  evidenceFile: 'part1-evidence.md',
  evidenceTitle: 'Wave 2 PO — part 1 evidence',
  productFixtures: PART1_PRODUCT_FIXTURES,
})
const {
  state,
  fail,
  req,
  product,
  tax,
  records,
  apiData,
  apiObject,
  errorText,
  quoted,
  rowParts,
  request,
  expectStatus,
  runLeg,
  pass,
  annotateRuling,
  getObject,
  createPo,
  confirmPo,
  poDetail,
  poPayloadFromDb,
  poLines,
  createConfirmedSinglePo,
  receiveApi,
  uiAddProduct,
  uiCreatePo,
  uiConfirmPo,
  uiSelectSupplier,
  uiSetLine,
  openReceiveDialog,
  uiReceive,
  createDelivery,
  confirmDelivery,
} = harness
const TODAY = harness.TODAY
let page: Page

/* Shared machinery moved to wave2-shared.ts. Kept here as a commented audit trail while part 1's row bodies remain unchanged.
import { execFileSync } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import {
  expect,
  test,
  type Browser,
  type BrowserContext,
  type Page,
} from '@playwright/test'

import {
  apiRequest,
  asArray,
  asRecord,
  journeyState,
  loginAs,
  pollUntil,
  registerFreshTenant,
  requireApiData,
  runId,
  runImportWizard,
  stringField,
} from '../e2e/campaign/journey'
import { apiRoutes, campaignSelectors, routes } from '../e2e/campaign/selectors'
import {
  apiJson,
  captureGuards,
  evidence,
  evidenceLedger,
  money,
  screenshot,
  sql,
  tenantDb,
} from './wave2-support'
import { fileURLToPath } from 'node:url'

const HERE = fileURLToPath(new URL('.', import.meta.url))

type Row = Record<string, unknown>
type ApiMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface ProductFixture {
  sku: string
  purchasePrice: string
  vat: '0' | '7' | '19'
  tracked: boolean
  type?: 'part' | 'service'
}

interface PoLineInput {
  description?: string
  freeQuantity?: string
  productId?: string
  quantity: string
  serviceId?: string
  taxConfigurationId?: string
  unitPrice: string
}

const RUN = runId.replace(/[^a-z0-9]/gi, '').slice(-10).toUpperCase()
const PASSWORD = 'Campaign!2026Safe'
const TODAY = new Date().toISOString().slice(0, 10)
const PRODUCT_FIXTURES: readonly ProductFixture[] = [
  { sku: 'P-HP-1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-HP-2', purchasePrice: '3.250', vat: '7', tracked: true },
  { sku: 'P-HP-3', purchasePrice: '4.000', vat: '0', tracked: true },
  { sku: 'P-TOT-1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-TOT-2', purchasePrice: '4.000', vat: '0', tracked: true },
  { sku: 'P-PART-1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-OVER-1', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-UNDER-3', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-UNDER-4', purchasePrice: '25.000', vat: '19', tracked: false, type: 'service' },
  { sku: 'P-LOT-1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-LOT-4', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-LOT-6', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-LOT-7', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-LOT-8', purchasePrice: '5.000', vat: '19', tracked: true },
  { sku: 'P-LOT-11', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-LOT-12', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-LOT-13', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-LOC-1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-LOC-5', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-DRAFT-1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-DRAFT-4', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-REV-1', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-REV-5', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-PRICE-1', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-PRICE-4', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-PRICE-6', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-LAND-1a', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-LAND-1b', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-LAND-4', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-LAND-8', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-VAT-4a', purchasePrice: '0.335', vat: '19', tracked: false },
  { sku: 'P-VAT-4b', purchasePrice: '0.335', vat: '19', tracked: false },
  { sku: 'P-VAT-4c', purchasePrice: '0.335', vat: '19', tracked: false },
  { sku: 'P-DISC-1', purchasePrice: '10.500', vat: '19', tracked: false },
  { sku: 'P-DISC-3', purchasePrice: '4.000', vat: '0', tracked: false },
  { sku: 'P-MATCH-1', purchasePrice: '10.500', vat: '19', tracked: false },
  { sku: 'P-MATCH-2', purchasePrice: '10.500', vat: '19', tracked: false },
  { sku: 'P-IDEM-2', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-IDEM-2b', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-IDEM-6', purchasePrice: '10.500', vat: '19', tracked: false },
  { sku: 'P-IFIRST-1', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-IFIRST-2', purchasePrice: '10.500', vat: '19', tracked: false },
  { sku: 'P-EDGE-2', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-EDGE-7', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-OVER-5a', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-OVER-5b', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-SEC-7', purchasePrice: '10.000', vat: '19', tracked: false },
] as const

const C2_PRODUCT_FIXTURES: readonly ProductFixture[] = [
  { sku: 'P-SEC-7', purchasePrice: '20.000', vat: '19', tracked: false },
  { sku: 'P-LAND-6', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-LAND-9', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-c2-HP1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-c2-HP2', purchasePrice: '3.250', vat: '7', tracked: true },
  { sku: 'P-c2-HP3', purchasePrice: '4.000', vat: '0', tracked: true },
  { sku: 'P-c2-L9', purchasePrice: '10.000', vat: '19', tracked: false },
] as const

const state: {
  c1?: string
  c1Name?: string
  c1Main?: string
  c1Wh?: string
  c2?: string
  c2Name?: string
  c2Main?: string
  cashierEmail?: string
  receiverEmail?: string
  pieceUnitId?: string
  serviceId?: string
  supplierAId?: string
  supplierAName?: string
  supplierBId?: string
  taxByRate: Partial<Record<ProductFixture['vat'], string>>
  products: Map<string, string>
  c2Products: Map<string, string>
  paymentMethodId?: string
  paymentRepositoryId?: string
  hpPoId?: string
  hpReceiptId?: string
  hpSalePriceBefore?: string
  totPoId?: string
  totPo2Id?: string
  partPoId?: string
  partInvoiceId?: string
  lotPos: Map<string, string>
  poG?: string
  poH?: string
} = {
  taxByRate: {},
  products: new Map(),
  c2Products: new Map(),
  lotPos: new Map(),
}

let adminContext: BrowserContext
let cashierContext: BrowserContext | undefined
let receiverContext: BrowserContext | undefined
let page: Page
let cashierPage: Page | undefined
let receiverPage: Page | undefined

function fail(message: string): never {
  throw new Error(message)
}

function req<K extends keyof typeof state>(key: K): NonNullable<(typeof state)[K]> {
  return state[key] ?? fail(`state.${String(key)} missing — an earlier serial row did not complete`)
}

function product(sku: string): string {
  return state.products.get(sku) ?? fail(`product ${sku} missing — W2-SETUP-3 did not complete`)
}

function tax(rate: ProductFixture['vat']): string {
  return state.taxByRate[rate] ?? fail(`VAT ${rate}% missing — W2-SETUP-1 did not complete`)
}

function records(value: unknown, description: string): Row[] {
  if (Array.isArray(value)) {
    return value.map((row, index) => asRecord(row, `${description}[${String(index)}]`))
  }
  const envelope = asRecord(value, description)
  const data = envelope['data']
  return asArray(data, `${description}.data`).map((row, index) => asRecord(row, `${description}.data[${String(index)}]`))
}

function apiData(body: unknown, description: string): unknown {
  const envelope = asRecord(body, description)
  if (!('data' in envelope)) fail(`${description} has no data envelope: ${JSON.stringify(body)}`)
  return envelope['data']
}

function apiObject(body: unknown, description: string): Row {
  return asRecord(apiData(body, description), `${description}.data`)
}

function errorText(body: unknown): string {
  return JSON.stringify(body)
}

function quoted(value: string): string {
  return `'${value.replaceAll("'", "''")}'`
}

function rowParts(row: string): string[] {
  return row.split('|')
}

async function request(
  targetPage: Page,
  method: ApiMethod,
  path: string,
  body: unknown = undefined,
  companyId: string = req('c1'),
): Promise<{ status: number; body: unknown }> {
  return apiJson(targetPage, method, path, body, companyId)
}

function expectStatus(result: { status: number; body: unknown }, status: number, label: string): void {
  expect(result.status, `${label}: ${errorText(result.body)}`).toBe(status)
}

async function runLeg(
  id: string,
  action: () => Promise<void>,
  pages: readonly Page[] = [page],
): Promise<void> {
  const guards = pages.map((guardPage) => captureGuards(guardPage))
  try {
    await action()
  } finally {
    for (const guard of guards) guard.assertClean()
  }
  await screenshot(page, id)
}

function pass(id: string, measured: string, expected: string): void {
  evidence(id, `measured: ${measured} | expected ${expected} | PASS`)
}

function annotateRuling(description: string): void {
  test.info().annotations.push({ type: 'ruling', description })
}

function bcrypt(password: string): string {
  return execFileSync(
    'php',
    ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_BCRYPT);`],
    { encoding: 'utf8' },
  ).trim()
}

async function getRows(path: string, companyId: string, description: string, targetPage: Page = page): Promise<Row[]> {
  const result = await request(targetPage, 'GET', path, undefined, companyId)
  expectStatus(result, 200, description)
  return records(apiData(result.body, description), `${description}.data`)
}

async function getObject(path: string, companyId: string, description: string, targetPage: Page = page): Promise<Row> {
  const result = await request(targetPage, 'GET', path, undefined, companyId)
  expectStatus(result, 200, description)
  return apiObject(result.body, description)
}

async function createProduct(targetPage: Page, companyId: string, fixture: ProductFixture): Promise<Row> {
  const result = await request(targetPage, 'POST', '/products', {
    name: fixture.sku,
    sku: fixture.sku,
    type: fixture.type ?? 'part',
    is_physical: fixture.type === 'service' ? false : true,
    unit_id: req('pieceUnitId'),
    purchase_price: fixture.purchasePrice,
    sale_price: fixture.purchasePrice,
    default_tax_configuration_id: tax(fixture.vat),
    tax_rate: `${fixture.vat}.00`,
    ...(fixture.tracked ? {} : { requires_batch_tracking: false }),
    is_active: true,
  }, companyId)
  expect([200, 201], `create ${fixture.sku}: ${errorText(result.body)}`).toContain(result.status)
  return apiObject(result.body, `create ${fixture.sku}`)
}

function skuOf(productId: string | undefined): string | undefined {
  if (productId === undefined) return undefined
  for (const [sku, id] of state.products) if (id === productId) return sku
  for (const [sku, id] of state.c2Products) if (id === productId) return sku
  return undefined
}

async function createPo(lines: readonly PoLineInput[], label: string): Promise<Row> {
  const result = await request(page, 'POST', '/purchase-orders', {
    partner_id: req('supplierAId'),
    location_id: req('c1Main'),
    document_date: TODAY,
    currency: 'TND',
    notes: label,
    lines: lines.map((line) => ({
      ...(line.productId !== undefined ? { product_id: line.productId } : {}),
      ...(line.serviceId !== undefined ? { service_id: line.serviceId } : {}),
      // ReceiveGoodsDialog labels its inputs by the LINE DESCRIPTION, so API-created lines default to the product SKU
      // (the UI-created HP lines carried the product name == SKU) — keeps the dialog locators uniform across classes.
      description: line.description ?? skuOf(line.productId) ?? label,
      quantity: line.quantity,
      unit_price: line.unitPrice,
      ...(line.taxConfigurationId !== undefined ? { tax_configuration_id: line.taxConfigurationId } : {}),
      ...(line.freeQuantity !== undefined ? { free_quantity: line.freeQuantity } : {}),
    })),
  })
  expectStatus(result, 201, `create ${label}`)
  return apiObject(result.body, `create ${label}`)
}

async function confirmPo(poId: string): Promise<Row> {
  const result = await request(page, 'POST', `/purchase-orders/${poId}/confirm`, {})
  expectStatus(result, 200, `confirm PO ${poId}`)
  return apiObject(result.body, `confirm PO ${poId}`)
}

async function poDetail(poId: string): Promise<Row> {
  return getObject(`/purchase-orders/${poId}`, req('c1'), `PO ${poId}`)
}

// documents.payload (fully_received / goods_received_at, GoodsReceiptService.php:727-735) is not exposed by the PO API resource — read it from Postgres.
function poPayloadFromDb(poId: string): Row {
  const raw = sql(tenantDb(), `SELECT payload::text FROM documents WHERE id=${quoted(poId)}`)[0] ?? '{}'
  return asRecord(JSON.parse(raw) as unknown, `payload of ${poId}`)
}

function poLines(po: Row, description: string): Row[] {
  return asArray(po['lines'], `${description}.lines`).map((line, index) => asRecord(line, `${description}.lines[${String(index)}]`))
}

async function createConfirmedSinglePo(sku: string, quantity: string, label: string, freeQuantity?: string): Promise<Row> {
  const fixture = PRODUCT_FIXTURES.find((candidate) => candidate.sku === sku) ?? fail(`fixture ${sku} missing`)
  const po = await createPo([{
    productId: product(sku),
    quantity,
    unitPrice: fixture.purchasePrice,
    taxConfigurationId: tax(fixture.vat),
    ...(freeQuantity !== undefined ? { freeQuantity } : {}),
  }], label)
  return confirmPo(stringField(po, 'id'))
}

async function receiveApi(
  poId: string,
  lineId: string,
  quantity: string,
  batch?: { batchNumber: string; expiryDate: string },
  extras: Record<string, unknown> = {},
): Promise<{ status: number; body: unknown }> {
  return request(page, 'POST', `/purchase-orders/${poId}/receive`, {
    location_id: req('c1Main'),
    quantities: { [lineId]: quantity },
    ...(batch !== undefined ? {
      batches: { [lineId]: { batch_number: batch.batchNumber, expiry_date: batch.expiryDate } },
    } : {}),
    ...extras,
  })
}

async function uiAddProduct(targetPage: Page, sku: string): Promise<void> {
  const picker = targetPage.getByRole('combobox', { name: /search or scan a product|rechercher ou scanner un produit/i })
  await picker.fill(sku)
  await targetPage.getByRole('option', { name: new RegExp(sku, 'i') }).click()
}

async function uiSelectSupplier(targetPage: Page): Promise<void> {
  const picker = targetPage.getByPlaceholder(/supplier|fournisseur/i)
  await picker.fill(req('supplierAName'))
  await targetPage.getByRole('option', { name: new RegExp(req('supplierAName').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') }).click()
}

async function uiSetLine(targetPage: Page, sku: string, quantity: string, price?: string): Promise<void> {
  const row = targetPage.getByRole('row').filter({ hasText: sku })
  await row.getByLabel(/^(qty|qté)$/i).fill(quantity)
  if (price !== undefined) {
    await row.locator('input[id^="line-price-input-"]').fill(price)
    await row.locator('input[id^="line-price-input-"]').press('Tab')
  }
}

async function uiCreatePo(lines: ReadonlyArray<{ sku: string; quantity: string; price?: string }>): Promise<string> {
  await page.goto('/purchases/orders/new')
  await uiSelectSupplier(page)
  for (const line of lines) {
    await uiAddProduct(page, line.sku)
    await uiSetLine(page, line.sku, line.quantity, line.price)
  }
  const createResponse = page.waitForResponse((response) => (
    response.request().method() === 'POST' && /\/api\/v1\/purchase-orders$/.test(response.url())
  ))
  await page.getByRole('button', { name: /^(save|enregistrer)$/i }).click()
  const response = await createResponse
  expect(response.status()).toBe(201)
  await expect(page).toHaveURL(/\/purchases\/orders\/[0-9a-f-]{36}\/edit(?:[/?#]|$)/i)
  return /\/purchases\/orders\/([0-9a-f-]{36})\/edit/i.exec(page.url())?.[1]
    ?? fail(`could not extract purchase order id from ${page.url()}`)
}

async function uiConfirmPo(poId: string): Promise<void> {
  await page.goto(`/purchases/orders/${poId}`)
  await page.getByRole('button', { name: /^(confirm|confirmer)$/i }).click()
  // F-W2-38 (P4): ConfirmDialog.tsx:51-52 renders no role="dialog"/aria-modal (Modal.tsx:141-142 does) — match its overlay container instead.
  const dialog = page.locator('[role="dialog"], div.fixed.inset-0.z-50').last()
  await dialog.getByRole('button', { name: /^(confirm|confirmer)$/i }).click()
  await expect(page.getByText(/confirmed|confirmé/i).first()).toBeVisible()
}

async function openReceiveDialog(poId: string): Promise<ReturnType<Page['getByRole']>> {
  await page.goto(`/purchases/orders/${poId}`)
  await page.getByRole('button', { name: /receive goods|réceptionner les marchandises/i }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  return dialog
}

async function uiReceive(
  poId: string,
  lines: ReadonlyArray<{ sku: string; quantity: string; batchNumber?: string; expiryDate?: string }>,
): Promise<{ body: unknown; status: number }> {
  const dialog = await openReceiveDialog(poId)
  for (const line of lines) {
    await dialog.getByLabel(new RegExp(`(?:quantity to receive|quantité à réceptionner).*${line.sku}`, 'i')).fill(line.quantity)
    if (line.batchNumber !== undefined) {
      await dialog.getByLabel(new RegExp(`(?:batch number|numéro de lot).*${line.sku}`, 'i')).fill(line.batchNumber)
    }
    if (line.expiryDate !== undefined) {
      await dialog.getByLabel(new RegExp(`(?:expiry date|date d'expiration).*${line.sku}`, 'i')).fill(line.expiryDate)
    }
  }
  const responsePromise = page.waitForResponse((response) => (
    response.request().method() === 'POST' && response.url().includes(`/api/v1/purchase-orders/${poId}/receive`)
  ))
  await dialog.getByRole('button', { name: /save and post|enregistrer et valider/i }).click()
  const response = await responsePromise
  return { status: response.status(), body: await response.json() as unknown }
}

async function createDelivery(productId: string, quantity: string, label: string): Promise<Row> {
  const result = await request(page, 'POST', '/delivery-notes', {
    partner_id: req('supplierAId'),
    location_id: req('c1Main'),
    document_date: TODAY,
    currency: 'TND',
    notes: label,
    lines: [{
      product_id: productId,
      description: label,
      quantity,
      unit_price: '10.500',
      tax_configuration_id: tax('19'),
    }],
  })
  expectStatus(result, 201, `create delivery ${label}`)
  return apiObject(result.body, `create delivery ${label}`)
}

async function confirmDelivery(deliveryId: string): Promise<{ status: number; body: unknown }> {
  return request(page, 'POST', `/delivery-notes/${deliveryId}/confirm`, {})
}

async function switchCompany(companyName: string): Promise<void> {
  const shell = campaignSelectors(page).shell
  await shell.companySwitcher.click()
  await page.getByRole('button', { name: companyName, exact: true }).click()
  await expect(shell.companySwitcher).toContainText(companyName)
}
*/

test.describe('Wave 2 purchase-order evidence — part 1', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(async ({ browser }: { browser: Browser }) => {
    page = await harness.open(browser)
  })

  test.afterAll(async () => {
    await harness.close()
  })

  /* SETUP leg bodies live in wave2-shared.ts and are invoked below as thin calls.
  test('W2-SETUP-1 register fresh TN parapharmacy tenant', async () => {
    test.setTimeout(360_000)
    await runLeg('W2-SETUP-1', async () => {
      await registerFreshTenant(page)

      const companies = records(requireApiData(await apiRequest(page, 'GET', apiRoutes.companies), 'companies'), 'companies')
      expect(companies).toHaveLength(1)
      const company = companies[0] ?? fail('company 1 missing')
      state.c1 = stringField(company, 'id')
      state.c1Name = stringField(company, 'name')
      journeyState.companyId = state.c1

      const units = await getRows(apiRoutes.units, state.c1, 'units')
      expect(units.length).toBeGreaterThan(0)
      const piece = units.find((unit) => unit['code'] === 'pc' || unit['symbol'] === 'pc') ?? fail('pc unit missing')
      state.pieceUnitId = stringField(piece, 'id')
      expect(piece['decimal_places'] ?? piece['decimalPlaces']).toBe(0)

      const locations = await getRows(apiRoutes.locations, state.c1, 'locations')
      const main = locations.find((location) => location['code'] === 'MAIN') ?? fail('MAIN location missing')
      state.c1Main = stringField(main, 'id')

      const taxesResult = await request(page, 'GET', '/taxation/configurations', undefined, state.c1)
      expectStatus(taxesResult, 200, 'tax configurations')
      const taxes = records(apiData(taxesResult.body, 'tax configurations'), 'tax configurations.data')
      for (const rate of ['19', '7', '0'] as const) {
        const match = taxes.find((configuration) => String(configuration['percentage_rate']) === `${rate}.00`)
          ?? fail(`VAT ${rate}% missing`)
        state.taxByRate[rate] = stringField(match, 'id')
      }
      const vatRates = taxes
        .filter((configuration) => configuration['tax_type'] === 'PERCENTAGE' && configuration['applies_to'] === 'LINE_ITEMS')
        .map((configuration) => String(configuration['percentage_rate']))
      expect(vatRates).toEqual(expect.arrayContaining(['19.00', '13.00', '7.00', '0.00']))

      const purposeCount = sql(tenantDb(), `
        SELECT COUNT(*)
        FROM accounts
        WHERE company_id = ${quoted(state.c1)}
          AND system_purpose IN (
            'inventory', 'supplier_payable', 'goods_received_not_invoiced',
            'vat_deductible', 'purchase_stamp_duty',
            'purchase_price_variance_expense', 'purchase_price_variance_income'
          )
      `)[0]
      expect(purposeCount).toBe('7')

      const methods = await getRows(apiRoutes.paymentMethods, state.c1, 'payment methods')
      const repositories = await getRows(apiRoutes.paymentRepositories, state.c1, 'payment repositories')
      const method = methods.find((candidate) => candidate['is_active'] !== false) ?? fail('active payment method missing')
      const repository = repositories.find((candidate) => candidate['is_active'] !== false && candidate['gl_account_id'] !== null)
        ?? fail('ledgered payment repository missing')
      state.paymentMethodId = stringField(method, 'id')
      state.paymentRepositoryId = stringField(repository, 'id')

      pass('W2-SETUP-1', `tenant=${journeyState.tenantId ?? '?'} units=${units.length} VAT={19,13,7,0} purposes=${purposeCount}`, '[derived] fresh tenant census')
    })
  })

  test('W2-SETUP-2 import 11 real suppliers', async () => {
    await runLeg('W2-SETUP-2', async () => {
      await runImportWizard(page, 'parties', resolve(HERE, 'real-fournisseurs.xlsx'))
      const suppliers = (await getRows(`${apiRoutes.partners}?type=supplier&per_page=100`, req('c1'), 'suppliers'))
        .filter((partner) => partner['type'] === 'supplier')
      expect(suppliers).toHaveLength(11)
      const supplierA = suppliers[0] ?? fail('SUP-A missing')
      const supplierB = suppliers[1] ?? fail('SUP-B missing')
      state.supplierAId = stringField(supplierA, 'id')
      state.supplierAName = stringField(supplierA, 'name')
      state.supplierBId = stringField(supplierB, 'id')
      pass('W2-SETUP-2', `suppliers=${suppliers.length} SUP-A=${state.supplierAName} SUP-B=${String(supplierB['name'])}`, '11 suppliers, 0 failures')
    })
  })

  test('W2-SETUP-3 seed the rev-4 virgin product fixture table', async () => {
    test.setTimeout(360_000)
    await runLeg('W2-SETUP-3', async () => {
      for (const fixture of PRODUCT_FIXTURES) {
        const created = await createProduct(page, req('c1'), fixture)
        state.products.set(fixture.sku, stringField(created, 'id'))
      }

      const variant = await request(page, 'POST', `/products/${product('P-LOT-8')}/variants`, {
        variant_code: `W2-LOT-8-${RUN}`,
        sku: `P-LOT-8-V-${RUN}`,
        name_suffix: 'Wave 2 active variant',
        is_default: true,
        attribute_values: [],
      })
      expectStatus(variant, 201, 'P-LOT-8 active variant')

      // Service line for W2-UNDER-3 is a description-only line (product_id NULL) per the matrix — no /services route on IziPOS (module:Workshop).

      const tracked = await getObject(`/products/${product('P-HP-1')}`, req('c1'), 'P-HP-1')
      const untracked = await getObject(`/products/${product('P-OVER-1')}`, req('c1'), 'P-OVER-1')
      const nonPhysical = await getObject(`/products/${product('P-UNDER-4')}`, req('c1'), 'P-UNDER-4')
      expect(tracked['requires_batch_tracking']).toBe(true)
      expect(untracked['requires_batch_tracking']).toBe(false)
      expect(nonPhysical['is_physical']).toBe(false)
      expect(nonPhysical['requires_batch_tracking']).toBe(false)

      pass('W2-SETUP-3', `products=${state.products.size} P-HP-1.tracked=true P-OVER-1.tracked=false P-UNDER-4.physical=false`, 'full fixture table created once')
    })
  })

  test('W2-SETUP-4 create the non-default WH location', async () => {
    await runLeg('W2-SETUP-4', async () => {
      const created = await request(page, 'POST', apiRoutes.locations, {
        name: `Wave 2 Warehouse ${RUN}`,
        code: `WH${RUN}`.slice(0, 20),
        type: 'warehouse',
        is_active: true,
        is_default: false,
      })
      expectStatus(created, 201, 'create WH')
      state.c1Wh = stringField(apiObject(created.body, 'create WH'), 'id')
      const locations = await getRows(apiRoutes.locations, req('c1'), 'locations after WH')
      expect(locations.filter((location) => location['is_active'] !== false)).toHaveLength(2)
      expect(locations.find((location) => location['code'] === 'MAIN')?.['is_default']).toBe(true)
      pass('W2-SETUP-4', `active_locations=2 MAIN.default=true WH=${state.c1Wh}`, '2 active locations; MAIN remains default')
    })
  })

  test('W2-SETUP-5 create company 2 through the real UI path', async () => {
    test.setTimeout(360_000)
    await runLeg('W2-SETUP-5', async () => {
      await page.goto(routes.dashboard)
      await campaignSelectors(page).shell.companySwitcher.click()
      await campaignSelectors(page).shell.addCompany.click()
      state.c2Name = `Wave 2 Company 2 ${RUN}`
      // CompanyOnboardingPage.tsx: 4-step wizard — country cards (buttons) → company (#name/#legalName) → contact (optional) → review → submit.
      const next = page.getByRole('button', { name: /^(next|suivant)$/i })
      await page.getByRole('button', { name: /tunisia|tunisie/i }).click()
      await next.click()
      await page.locator('#name').fill(state.c2Name)
      await page.locator('#legalName').fill(state.c2Name)
      await next.click()
      await next.click()
      const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST' && /\/api\/v1\/companies$/.test(response.url())
      ), { timeout: 120_000 })
      await page.getByRole('button', { name: /create|créer|submit|finish|terminer|confirm/i }).last().click()
      const response = await responsePromise
      expect(response.status()).toBe(201)

      const companies = await getRows(apiRoutes.companies, req('c1'), 'companies after c2')
      const c2 = companies.find((company) => company['name'] === state.c2Name) ?? fail('company 2 missing')
      state.c2 = stringField(c2, 'id')
      const c2Locations = await getRows(apiRoutes.locations, state.c2, 'c2 locations')
      expect(c2Locations).toHaveLength(1)
      const c2Main = c2Locations[0] ?? fail('c2 MAIN missing')
      expect(c2Main['code']).toBe('MAIN')
      expect(c2Main['is_default']).toBe(true)
      state.c2Main = stringField(c2Main, 'id')

      for (const fixture of C2_PRODUCT_FIXTURES) {
        const created = await createProduct(page, state.c2, fixture)
        state.c2Products.set(fixture.sku, stringField(created, 'id'))
      }
      expect(state.c2Products.get('P-SEC-7')).not.toBe(product('P-SEC-7'))

      const c2Units = await getRows(apiRoutes.units, state.c2, 'c2 units')
      const c2Methods = await getRows(apiRoutes.paymentMethods, state.c2, 'c2 payment methods')
      const c2Repositories = await getRows(apiRoutes.paymentRepositories, state.c2, 'c2 repositories')
      expect(c2Units.length).toBeGreaterThan(0)
      expect(c2Methods.length).toBeGreaterThan(0)
      expect(c2Repositories.length).toBeGreaterThan(0)
      expect(sql(tenantDb(), `SELECT COUNT(*) FROM accounts WHERE company_id=${quoted(state.c2)}`)[0]).not.toBe('0')

      await switchCompany(req('c1Name'))
      journeyState.companyId = req('c1')
      pass('W2-SETUP-5', `c2=${state.c2} MAIN=${state.c2Main} products=${state.c2Products.size}`, 'real company path provisioned units, methods, repositories, accounts')
    })
  })

  test('W2-SETUP-6 create and authenticate the cashier context', async ({ browser }) => {
    cashierContext = await browser.newContext()
    cashierPage = await cashierContext.newPage()
    await runLeg('W2-SETUP-6', async () => {
      state.cashierEmail = `wave2-cashier-${RUN.toLowerCase()}@test.otospex.dev`
      const created = await request(page, 'POST', '/users', {
        name: 'Wave 2 Cashier',
        email: state.cashierEmail,
        role: 'cashier',
      })
      expectStatus(created, 201, 'create cashier')
      const hash = bcrypt(PASSWORD)
      const update = `UPDATE users SET password=${quoted(hash)}, status='active' WHERE email=${quoted(state.cashierEmail)} RETURNING id`
      expect(sql(tenantDb(), update)).toHaveLength(1)

      await loginAs(cashierPage ?? fail('cashier page missing'), {
        email: state.cashierEmail,
        name: 'Wave 2 Cashier',
        password: PASSWORD,
      })
      const me = await request(cashierPage ?? fail('cashier page missing'), 'GET', '/auth/me', undefined, req('c1'))
      expectStatus(me, 200, 'cashier /auth/me')
      expect(errorText(me.body)).toMatch(/cashier/i)
      expect(sql(tenantDb(), `SELECT COUNT(*) FROM user_company_memberships WHERE user_id=(SELECT id FROM users WHERE email=${quoted(state.cashierEmail)}) AND company_id=${quoted(req('c1'))}`)[0]).toBe('1')

      pass('W2-SETUP-6', `cashier=${state.cashierEmail} context=isolated membership=c1 UPDATE=${update}`, 'cashier role authenticated in second context')
    }, [page, cashierPage])
  })

  test('W2-SETUP-7 create receiver-without-price-edit context', async ({ browser }) => {
    receiverContext = await browser.newContext()
    receiverPage = await receiverContext.newPage()
    await runLeg('W2-SETUP-7', async () => {
      const role = await request(page, 'POST', '/roles', {
        name: 'wave2-receiver',
        permissions: ['purchase-orders.receive', 'purchase-orders.view', 'documents.view', 'inventory.view'],
      })
      expectStatus(role, 201, 'create wave2-receiver role')
      const roleId = String(apiObject(role.body, 'create wave2-receiver role')['id'] ?? fail('role id missing'))
      state.receiverEmail = `wave2-receiver-${RUN.toLowerCase()}@test.otospex.dev`
      const user = await request(page, 'POST', '/users', {
        name: 'Wave 2 Receiver',
        email: state.receiverEmail,
        role: 'wave2-receiver',
      })
      expectStatus(user, 201, 'create wave2 receiver')
      const hash = bcrypt(PASSWORD)
      const update = `UPDATE users SET password=${quoted(hash)}, status='active' WHERE email=${quoted(state.receiverEmail)} RETURNING id`
      expect(sql(tenantDb(), update)).toHaveLength(1)

      await loginAs(receiverPage ?? fail('receiver page missing'), {
        email: state.receiverEmail,
        name: 'Wave 2 Receiver',
        password: PASSWORD,
      })
      const roleRead = await request(page, 'GET', `/roles/${roleId}`)
      expectStatus(roleRead, 200, 'read wave2-receiver role')
      const roleText = errorText(roleRead.body)
      expect(roleText).toMatch(/purchase-orders\.receive/)
      expect(roleText).not.toMatch(/goods-receipt\.edit-price/)
      const me = await request(receiverPage ?? fail('receiver page missing'), 'GET', '/auth/me', undefined, req('c1'))
      expectStatus(me, 200, 'receiver /auth/me')
      const meText = errorText(me.body)
      expect(meText).toMatch(/purchase-orders\.receive/)
      expect(meText).not.toMatch(/goods-receipt\.edit-price/)

      pass('W2-SETUP-7', `role=wave2-receiver guard=sanctum permissions=receive/view/no-price-edit UPDATE=${update}`, 'third isolated context authenticated')
    }, [page, receiverPage])
  })

  test('W2-SETUP-8 patch company 2 to non_registered before posting', async () => {
    await runLeg('W2-SETUP-8', async () => {
      const patched = await request(page, 'PUT', `/companies/${req('c2')}`, { name: req('c2Name'), legal_name: req('c2Name'), tax_status: 'NON_REGISTERED' }, req('c2'))
      expectStatus(patched, 200, 'patch c2 tax status')
      const company = await getObject(`/companies/${req('c2')}`, req('c2'), 'c2 after tax patch')
      expect(company['tax_status']).toBe('NON_REGISTERED')
      expect(sql(tenantDb(), `SELECT tax_status FROM companies WHERE id=${quoted(req('c2'))}`)).toEqual(['NON_REGISTERED'])
      pass('W2-SETUP-8', 'c2.tax_status=non_registered', '200 and persisted; deliberately not restored')
    })
  })
  */

  test('W2-SETUP-1 register fresh TN parapharmacy tenant', async () => setup1(harness))
  test('W2-SETUP-2 import 11 real suppliers', async () => setup2(harness))
  test('W2-SETUP-3 seed the rev-4 virgin product fixture table', async () => setup3(harness))
  test('W2-SETUP-4 create the non-default WH location', async () => setup4(harness))
  test('W2-SETUP-5 create company 2 through the real UI path', async () => setup5(harness))
  test('W2-SETUP-6 create and authenticate the cashier context', async ({ browser }) => setup6(harness, browser))
  test('W2-SETUP-7 create receiver-without-price-edit context', async ({ browser }) => setup7(harness, browser))
  test('W2-SETUP-8 patch company 2 to non_registered before posting', async () => setup8(harness))

  test('W2-HP-1 create PO-A in Unit mode through the UI', async () => {
    await runLeg('W2-HP-1', async () => {
      const before = await getObject(`/products/${product('P-HP-1')}`, req('c1'), 'P-HP-1 before HP receipt')
      state.hpSalePriceBefore = stringField(before, 'sale_price')
      state.hpPoId = await uiCreatePo([
        { sku: 'P-HP-1', quantity: '6' },
        { sku: 'P-HP-2', quantity: '4' },
        { sku: 'P-HP-3', quantity: '5' },
      ])
      const po = await poDetail(state.hpPoId)
      expect(po['status']).toBe('draft')
      expect(po['document_number']).toBeNull()
      money(stringField(po, 'subtotal'), '96.000')
      money(stringField(po, 'tax_amount'), '12.880')
      money(stringField(po, 'total'), '108.880')
      expect(po['currency']).toBe('TND')
      pass('W2-HP-1', 'status=draft number=NULL subtotal=96.000 tax=12.880 total=108.880 currency=TND', '[derived] same')
    })
  })

  test('W2-HP-2 confirm allocates PO-2026-0001', async () => {
    await runLeg('W2-HP-2', async () => {
      await uiConfirmPo(req('hpPoId'))
      const po = await poDetail(req('hpPoId'))
      expect(po['status']).toBe('confirmed')
      expect(stringField(po, 'document_number')).toBe('PO-2026-0001')
      money(stringField(po, 'subtotal'), '96.000')
      money(stringField(po, 'tax_amount'), '12.880')
      money(stringField(po, 'total'), '108.880')

      const header = sql(tenantDb(), `
        SELECT confirmed_at IS NOT NULL, confirmed_by IS NOT NULL,
               payload->>'costs_allocated_at'
        FROM documents WHERE id=${quoted(req('hpPoId'))}
      `)
      expect(header).toHaveLength(1)
      const headerParts = rowParts(header[0] ?? fail('HP header query empty'))
      expect(headerParts[0]).toBe('t')
      expect(headerParts[1]).toBe('t')
      expect(headerParts[2]).not.toBe('')

      const costs = sql(tenantDb(), `
        SELECT landed_unit_cost, allocated_costs
        FROM document_lines WHERE document_id=${quoted(req('hpPoId'))}
        ORDER BY line_number
      `).map(rowParts)
      expect(costs).toHaveLength(3)
      ;['10.500000', '3.250000', '4.000000'].forEach((expected, index) => {
        money(costs[index]?.[0] ?? fail(`HP cost row ${String(index)} missing`), expected)
        money(costs[index]?.[1] ?? fail(`HP allocation row ${String(index)} missing`), '0.000000')
      })
      pass('W2-HP-2', `number=${String(po['document_number'])} landed={${costs.map((row) => row[0]).join(',')}} allocated=0.000000×3`, '[derived] PO-2026-0001 and exact allocations')
    })
  })

  test('W2-HP-3 receive PO-A fully with three lots through the UI', async () => {
    await runLeg('W2-HP-3', async () => {
      const dialog = await openReceiveDialog(req('hpPoId'))
      await expect(dialog.getByLabel(/(?:quantity to receive|quantité à réceptionner).*P-HP-1/i)).toHaveValue('6')
      await expect(dialog.getByLabel(/(?:quantity to receive|quantité à réceptionner).*P-HP-2/i)).toHaveValue('4')
      await expect(dialog.getByLabel(/(?:quantity to receive|quantité à réceptionner).*P-HP-3/i)).toHaveValue('5')
      await screenshot(page, 'W2-HP-3-dialog')
      for (const [sku, lot] of [['P-HP-1', 'LOT-A1'], ['P-HP-2', 'LOT-A2'], ['P-HP-3', 'LOT-A3']] as const) {
        await dialog.getByLabel(new RegExp(`(?:batch number|numéro de lot).*${sku}`, 'i')).fill(lot)
        await dialog.getByLabel(new RegExp(`(?:expiry date|date d'expiration).*${sku}`, 'i')).fill('2027-12-31')
      }
      const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST' && response.url().includes(`/api/v1/purchase-orders/${req('hpPoId')}/receive`)
      ))
      await dialog.getByRole('button', { name: /save and post|enregistrer et valider/i }).click()
      const response = await responsePromise
      expect(response.status()).toBe(200)
      const body = asRecord(await response.json() as unknown, 'HP receive response')
      const meta = asRecord(body['meta'], 'HP receive meta')
      const receipt = asRecord(meta['goods_receipt'], 'HP receive meta.goods_receipt')
      expect(stringField(receipt, 'receipt_number')).toBe('GRN-2026-0001')

      const po = await poDetail(req('hpPoId'))
      expect(po['status']).toBe('received')
      const payload = poPayloadFromDb(req('hpPoId'))  // PO-A payload
      expect(payload['fully_received']).toBe(true)
      expect(payload['goods_received_at']).not.toBeNull()
      for (const line of poLines(po, 'PO-A')) {
        expect(stringField(line, 'quantity_received')).toBe(stringField(line, 'quantity'))
      }
      state.hpReceiptId = sql(tenantDb(), `SELECT id FROM goods_receipts WHERE purchase_order_id=${quoted(req('hpPoId'))} ORDER BY created_at DESC LIMIT 1`)[0]
        ?? fail('HP receipt id missing')
      pass('W2-HP-3', 'status=received fully_received=true GRN=GRN-2026-0001 quantities={6,4,5}', '[derived] same; pc inputs displayed 6/4/5')
    })
  })

  test('W2-HP-4 stock, WAC, movement and lot ledgers agree', async () => {
    await runLeg('W2-HP-4', async () => {
      const expected = [
        ['P-HP-1', '6.0000', '10.500000'],
        ['P-HP-2', '4.0000', '3.250000'],
        ['P-HP-3', '5.0000', '4.000000'],
      ] as const
      for (const [sku, quantity, cost] of expected) {
        const matrixResult = await request(page, 'GET', `/inventory/stock-matrix?search=${encodeURIComponent(sku)}`)
        expectStatus(matrixResult, 200, `${sku} stock matrix`)
        const matrixRow = records(apiData(matrixResult.body, `${sku} stock matrix`), `${sku} stock matrix.data`)
          .find((candidate) => candidate['product_id'] === product(sku) && candidate['variant_id'] === null)
          ?? fail(`${sku} missing from stock matrix`)
        const cells = asRecord(matrixRow['cells'], `${sku} stock matrix cells`)
        const mainCell = asRecord(cells[req('c1Main')], `${sku} MAIN stock cell`)
        expect(stringField(mainCell, 'on_hand')).toBe(quantity)
        const productRead = await getObject(`/products/${product(sku)}`, req('c1'), `${sku} product read`)
        money(stringField(productRead, 'cost_price'), cost)

        const rows = sql(tenantDb(), `
          SELECT sl.quantity, p.cost_price
          FROM stock_levels sl JOIN products p ON p.id=sl.product_id
          WHERE sl.company_id=${quoted(req('c1'))}
            AND sl.location_id=${quoted(req('c1Main'))}
            AND sl.product_id=${quoted(product(sku))}
            AND sl.variant_id IS NULL
        `)
        expect(rows).toHaveLength(1)
        const [actualQuantity, actualCost] = rowParts(rows[0] ?? fail(`${sku} stock row missing`))
        expect(actualQuantity).toBe(quantity)
        expect(actualCost).toBe(cost)
      }
      const movementCount = sql(tenantDb(), `
        SELECT COUNT(*) FROM stock_movements
        WHERE company_id=${quoted(req('c1'))}
          AND reference_id=${quoted(req('hpPoId'))}
          AND reference='PO-2026-0001'
          AND movement_type='receipt'
      `)[0]
      const batchCount = sql(tenantDb(), `
        SELECT COUNT(*) FROM product_batches
        WHERE company_id=${quoted(req('c1'))}
          AND product_id IN (${['P-HP-1', 'P-HP-2', 'P-HP-3'].map((sku) => quoted(product(sku))).join(',')})
          AND variant_id IS NULL
      `)[0]
      const batchStockCount = sql(tenantDb(), `
        SELECT COUNT(*) FROM inventory_batch_stock ibs
        JOIN product_batches pb ON pb.id=ibs.batch_id
        WHERE pb.company_id=${quoted(req('c1'))}
          AND pb.product_id IN (${['P-HP-1', 'P-HP-2', 'P-HP-3'].map((sku) => quoted(product(sku))).join(',')})
          AND pb.variant_id IS NULL
      `)[0]
      expect(movementCount).toBe('3')
      expect(batchCount).toBe('3')
      expect(batchStockCount).toBe('3')
      pass('W2-HP-4', `stock={6.0000,4.0000,5.0000} WAC={10.500000,3.250000,4.000000} movements=${movementCount} batches=${batchCount}/${batchStockCount}`, '[derived] same')
    })
  })

  test('W2-HP-5 GR-IR posts three balanced net-only entries', async () => {
    await runLeg('W2-HP-5', async () => {
      const entries = sql(tenantDb(), `
        SELECT l.po_line_id, je.entry_number,
               SUM(CASE WHEN a.system_purpose='inventory' THEN jl.debit ELSE 0 END),
               SUM(CASE WHEN a.system_purpose='goods_received_not_invoiced' THEN jl.credit ELSE 0 END),
               SUM(jl.debit)-SUM(jl.credit),
               BOOL_AND(jl.partner_id IS NULL)
        FROM goods_receipt_lines l
        JOIN goods_receipts r ON r.id=l.goods_receipt_id
        JOIN journal_entries je ON je.source_type='goods_receipt' AND je.source_id=l.movement_id AND je.status='posted'
        JOIN journal_lines jl ON jl.journal_entry_id=je.id
        JOIN accounts a ON a.id=jl.account_id
        WHERE r.company_id=${quoted(req('c1'))} AND r.id=${quoted(req('hpReceiptId'))}
        GROUP BY l.po_line_id, je.entry_number
        ORDER BY 3 DESC
      `).map(rowParts)
      expect(entries).toHaveLength(3)
      const amounts = entries.map((row) => row[2] ?? '').sort()
      expect(amounts).toEqual(['13.000', '20.000', '63.000'])
      for (const entry of entries) {
        money(entry[2] ?? fail('HP-5 debit missing'), entry[3] ?? fail('HP-5 credit missing'))
        money(entry[4] ?? fail('HP-5 imbalance missing'), '0.000')
        expect(entry[5]).toBe('t')
      }
      const vatLegs = sql(tenantDb(), `
        SELECT COUNT(*) FROM journal_lines jl
        JOIN journal_entries je ON je.id=jl.journal_entry_id
        JOIN accounts a ON a.id=jl.account_id
        WHERE je.source_type='goods_receipt'
          AND je.source_id IN (SELECT movement_id FROM goods_receipt_lines WHERE goods_receipt_id=${quoted(req('hpReceiptId'))})
          AND a.system_purpose='vat_deductible'
      `)[0]
      expect(vatLegs).toBe('0')
      pass('W2-HP-5', `entries=${entries.length} Dr37/Cr408={63.000,13.000,20.000} imbalance=0 partner=NULL VAT_legs=${vatLegs}`, '[derived] same')
    })
  })

  test('W2-HP-6 measure receipt side effect on sale_price', async () => {
    await runLeg('W2-HP-6', async () => {
      const after = await getObject(`/products/${product('P-HP-1')}`, req('c1'), 'P-HP-1 after HP receipt')
      const salePriceAfter = stringField(after, 'sale_price')
      annotateRuling(`F-W2-25 sale_price before=${req('hpSalePriceBefore')} after=${salePriceAfter}`)
      pass('W2-HP-6', `sale_price before=${req('hpSalePriceBefore')} after=${salePriceAfter}`, '[RULING] recorded, not asserted')
    })
  })

  test('W2-TOT-1 Total toggle is visible and derives the gross display', async () => {
    await runLeg('W2-TOT-1', async () => {
      await page.goto('/purchases/orders/new')
      await uiSelectSupplier(page)
      await uiAddProduct(page, 'P-TOT-1')
      await uiSetLine(page, 'P-TOT-1', '10')
      const row = page.getByRole('row').filter({ hasText: 'P-TOT-1' })
      const toggle = row.getByRole('button', { name: /total ht/i })
      await expect(toggle).toBeVisible()
      await expect(toggle).toHaveAttribute('aria-pressed', 'false')
      await toggle.click()
      await expect(row.getByRole('button', { name: /pu ht/i })).toHaveAttribute('aria-pressed', 'true')
      const totalInput = row.locator('input[id^="line-price-input-"]')
      await totalInput.fill('100.000')
      await totalInput.press('Tab')
      await expect(row).toContainText(/119[.,]000/)
      pass('W2-TOT-1', 'toggle visible aria-pressed=true typed_total=100.000 net_unit=10.000 gross_display=119.000', '[derived] same')
    })
  })

  test('W2-TOT-2 save exposes the gross-as-net persistence defect', async () => {
    await runLeg('W2-TOT-2', async () => {
      let requestBody = ''
      const onRequest = (requestEvent: import('@playwright/test').Request): void => {
        if (requestEvent.method() === 'POST' && /\/api\/v1\/purchase-orders$/.test(requestEvent.url())) {
          requestBody = requestEvent.postData() ?? ''
        }
      }
      page.on('request', onRequest)
      try {
        const responsePromise = page.waitForResponse((response) => (
          response.request().method() === 'POST' && /\/api\/v1\/purchase-orders$/.test(response.url())
        ))
        await page.getByRole('button', { name: /^(save|enregistrer)$/i }).click()
        const response = await responsePromise
        expect(response.status()).toBe(201)
        const responseBody = asRecord(await response.json() as unknown, 'TOT-2 create response')
        state.totPoId = stringField(asRecord(responseBody['data'], 'TOT-2 data'), 'id')
      } finally {
        page.off('request', onRequest)
      }
      expect(requestBody).toContain('"line_total":"119.000"')
      const rows = sql(tenantDb(), `
        SELECT dl.line_total, dl.unit_price, dl.landed_unit_cost,
               d.subtotal, d.tax_amount, d.total
        FROM documents d JOIN document_lines dl ON dl.document_id=d.id
        WHERE d.id=${quoted(req('totPoId'))}
      `)
      expect(rows).toHaveLength(1)
      const measured = rowParts(rows[0] ?? fail('TOT-2 SQL empty'))
      const expected = ['119.000', '11.900', '11.900000', '119.000', '22.610', '141.610']
      measured.forEach((value, index) => money(value, expected[index] ?? fail('TOT-2 expected missing')))
      pass('W2-TOT-2', `line_total=${measured[0]} unit_price=${measured[1]} landed=${measured[2]} header=${measured.slice(3).join('/')}`, '[derived bug] 119.000/11.900/11.900000 and 119.000/22.610/141.610')
    })
  })

  test('W2-TOT-3 confirmation capitalises VAT into WAC and GR-IR', async () => {
    await runLeg('W2-TOT-3', async () => {
      await uiConfirmPo(req('totPoId'))
      const receive = await uiReceive(req('totPoId'), [{
        sku: 'P-TOT-1', quantity: '10', batchNumber: 'LOT-B1', expiryDate: '2027-12-31',
      }])
      expect(receive.status, errorText(receive.body)).toBe(200)
      const po = await poDetail(req('totPoId'))
      money(stringField(po, 'subtotal'), '119.000')
      money(stringField(po, 'tax_amount'), '22.610')
      money(stringField(po, 'total'), '141.610')
      const cost = sql(tenantDb(), `SELECT cost_price FROM products WHERE id=${quoted(product('P-TOT-1'))}`)[0]
      expect(cost).toBe('11.900000')
      const grir = sql(tenantDb(), `
        SELECT COALESCE(SUM(jl.credit),0)
        FROM journal_lines jl
        JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.source_type='goods_receipt' AND je.status='posted'
        JOIN accounts a ON a.id=jl.account_id AND a.system_purpose='goods_received_not_invoiced'
        WHERE je.source_id IN (
          SELECT grl.movement_id FROM goods_receipt_lines grl
          JOIN goods_receipts gr ON gr.id=grl.goods_receipt_id
          WHERE gr.purchase_order_id=${quoted(req('totPoId'))}
        )
      `)[0] ?? fail('TOT-3 GR-IR missing')
      money(grir, '119.000')
      pass('W2-TOT-3', `header=119.000/22.610/141.610 WAC=${cost} GR-IR=${grir} operator_typed=100.000`, '[derived bug] VAT capitalised by 19.000')
    })
  })

  test('W2-TOT-4 zero-VAT Total mode leaves a dishonest header after confirm', async () => {
    await runLeg('W2-TOT-4', async () => {
      await page.goto('/purchases/orders/new')
      await uiSelectSupplier(page)
      await uiAddProduct(page, 'P-TOT-2')
      await uiSetLine(page, 'P-TOT-2', '7')
      const row = page.getByRole('row').filter({ hasText: 'P-TOT-2' })
      await row.getByRole('button', { name: /total ht/i }).click()
      const input = row.locator('input[id^="line-price-input-"]')
      await input.fill('100.000')
      await input.press('Tab')
      const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST' && /\/api\/v1\/purchase-orders$/.test(response.url())
      ))
      await page.getByRole('button', { name: /^(save|enregistrer)$/i }).click()
      const response = await responsePromise
      const createBody = asRecord(await response.json() as unknown, 'TOT-4 create response')
      state.totPo2Id = stringField(asRecord(createBody['data'], 'TOT-4 data'), 'id')
      const before = sql(tenantDb(), `
        SELECT dl.line_total, dl.unit_price, d.subtotal, d.tax_amount, d.total
        FROM documents d JOIN document_lines dl ON dl.document_id=d.id
        WHERE d.id=${quoted(req('totPo2Id'))}
      `).map(rowParts)[0] ?? fail('TOT-4 before confirm missing')
      expect(before).toEqual(['100.000', '14.285', '100.000', '0.000', '100.000'])

      await uiConfirmPo(req('totPo2Id'))
      const after = rowParts(sql(tenantDb(), `SELECT subtotal, tax_amount, total FROM documents WHERE id=${quoted(req('totPo2Id'))}`)[0]
        ?? fail('TOT-4 after confirm missing'))
      expect(after).toEqual(['100.000', '0.000', '99.995'])
      pass('W2-TOT-4', `before=${before.join('/')} after=${after.join('/')}`, '[derived] header 100.000 + 0.000 != total 99.995')
    })
  })

  test('W2-TOT-5 Total-mode payload precision and presence are validated', async () => {
    await runLeg('W2-TOT-5', async () => {
      const countBefore = sql(tenantDb(), "SELECT COUNT(*) FROM documents WHERE type='purchase_order'")[0]
      const base = {
        partner_id: req('supplierAId'),
        location_id: req('c1Main'),
        document_date: TODAY,
        currency: 'TND',
      }
      const fourDp = await request(page, 'POST', '/purchase-orders', {
        ...base,
        lines: [{
          product_id: product('P-TOT-2'), description: 'TOT-5 precision', quantity: '7.0000',
          unit_price: '14.285', tax_configuration_id: tax('0'), price_entry_mode: 'total', line_total: '100.0001',
        }],
      })
      const missing = await request(page, 'POST', '/purchase-orders', {
        ...base,
        lines: [{
          product_id: product('P-TOT-2'), description: 'TOT-5 missing', quantity: '7.0000',
          unit_price: '14.285', tax_configuration_id: tax('0'), price_entry_mode: 'total',
        }],
      })
      expectStatus(fourDp, 422, 'TOT-5 4dp line_total')
      expectStatus(missing, 422, 'TOT-5 missing line_total')
      expect(errorText(fourDp.body)).toMatch(/line_total|decimal|3 decimal/i)
      expect(errorText(missing.body)).toMatch(/line_total|required/i)
      expect(sql(tenantDb(), "SELECT COUNT(*) FROM documents WHERE type='purchase_order'")[0]).toBe(countBefore)
      pass('W2-TOT-5', `four_dp=${fourDp.status} missing=${missing.status} persisted_delta=0`, 'both 422 with per-field messages')
    })
  })

  test('W2-PART-1 create and confirm virgin PO-C through the UI', async () => {
    await runLeg('W2-PART-1', async () => {
      state.partPoId = await uiCreatePo([{ sku: 'P-PART-1', quantity: '10' }])
      await uiConfirmPo(state.partPoId)
      const po = await poDetail(state.partPoId)
      expect(po['status']).toBe('confirmed')
      expect(stringField(po, 'document_number')).toMatch(/^PO-2026-\d{4}$/)
      const landed = sql(tenantDb(), `SELECT landed_unit_cost FROM document_lines WHERE document_id=${quoted(state.partPoId)}`)[0]
      expect(landed).toBe('10.500000')
      pass('W2-PART-1', `number=${String(po['document_number'])} landed_unit_cost=${landed}`, 'confirmed PO-C at 10.500000')
    })
  })

  test('W2-PART-2 receive tranche 1 of PO-C through the UI', async () => {
    await runLeg('W2-PART-2', async () => {
      const result = await uiReceive(req('partPoId'), [{
        sku: 'P-PART-1', quantity: '4', batchNumber: 'LOT-T1', expiryDate: '2027-12-31',
      }])
      expect(result.status, errorText(result.body)).toBe(200)
      const po = await poDetail(req('partPoId'))
      expect(po['status']).toBe('confirmed')
      const payload = poPayloadFromDb(req('partPoId'))  // PO-C payload after tranche 1
      expect(payload['fully_received']).toBe(false)
      expect(payload['goods_received_at']).toBeNull()
      const persisted = rowParts(sql(tenantDb(), `
        SELECT dl.quantity_received, sl.quantity, p.cost_price, dl.accrual_unit_cost
        FROM document_lines dl
        JOIN products p ON p.id=dl.product_id
        JOIN stock_levels sl ON sl.product_id=p.id AND sl.company_id=${quoted(req('c1'))} AND sl.location_id=${quoted(req('c1Main'))} AND sl.variant_id IS NULL
        WHERE dl.document_id=${quoted(req('partPoId'))}
      `)[0] ?? fail('PART-2 persistence row missing'))
      expect(persisted).toEqual(['4.0000', '4.0000', '10.500000', '10.500000'])
      const grir = sql(tenantDb(), `
        SELECT SUM(jl.credit) FROM journal_lines jl
        JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted'
        JOIN accounts a ON a.id=jl.account_id AND a.system_purpose='goods_received_not_invoiced'
        WHERE je.source_id IN (
          SELECT grl.movement_id FROM goods_receipt_lines grl JOIN goods_receipts gr ON gr.id=grl.goods_receipt_id
          WHERE gr.purchase_order_id=${quoted(req('partPoId'))}
        )
      `)[0] ?? fail('PART-2 GR-IR missing')
      money(grir, '42.000')
      pass('W2-PART-2', `received=${persisted[0]} status=confirmed stock=${persisted[1]} WAC=${persisted[2]} GR-IR=${grir} accrual=${persisted[3]}`, '[derived] same')
    })
  })

  test('W2-PART-3 receipt-status reports forty percent', async () => {
    await runLeg('W2-PART-3', async () => {
      const result = await request(page, 'GET', `/purchase-orders/${req('partPoId')}/receipt-status`)
      expectStatus(result, 200, 'PART-3 receipt status')
      const status = apiObject(result.body, 'PART-3 receipt status')
      expect(status['status']).toBe('partially_received')
      expect(String(status['total_received'])).toMatch(/^4(?:\.0+)?$/)
      expect(String(status['total_ordered'])).toMatch(/^10(?:\.0+)?$/)
      expect(status['percentage']).toBe(40)
      pass('W2-PART-3', `status=${String(status['status'])} received=${String(status['total_received'])} ordered=${String(status['total_ordered'])} percentage=${String(status['percentage'])}`, 'partially_received / 4 / 10 / 40')
    })
  })

  test('W2-PART-4 receive tranche 2 and build the two-lot FEFO fixture', async () => {
    await runLeg('W2-PART-4', async () => {
      const result = await uiReceive(req('partPoId'), [{
        sku: 'P-PART-1', quantity: '6', batchNumber: 'LOT-T2', expiryDate: '2028-06-30',
      }])
      expect(result.status, errorText(result.body)).toBe(200)
      const po = await poDetail(req('partPoId'))
      expect(po['status']).toBe('received')
      const stock = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-PART-1'))} AND variant_id IS NULL`)[0]
      const cost = sql(tenantDb(), `SELECT cost_price FROM products WHERE id=${quoted(product('P-PART-1'))}`)[0]
      expect(stock).toBe('10.0000')
      expect(cost).toBe('10.500000')
      const grir = sql(tenantDb(), `
        SELECT SUM(jl.credit) FROM journal_lines jl
        JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted'
        JOIN accounts a ON a.id=jl.account_id AND a.system_purpose='goods_received_not_invoiced'
        WHERE je.source_id IN (
          SELECT grl.movement_id FROM goods_receipt_lines grl JOIN goods_receipts gr ON gr.id=grl.goods_receipt_id
          WHERE gr.purchase_order_id=${quoted(req('partPoId'))}
        )
      `)[0] ?? fail('PART-4 GR-IR missing')
      money(grir, '105.000')
      const lots = sql(tenantDb(), `
        SELECT pb.batch_number, pb.expiry_date, ibs.quantity
        FROM product_batches pb JOIN inventory_batch_stock ibs ON ibs.batch_id=pb.id
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-PART-1'))}
          AND ibs.location_id=${quoted(req('c1Main'))}
          AND pb.variant_id IS NULL
        ORDER BY pb.expiry_date
      `).map(rowParts)
      expect(lots).toEqual([
        ['LOT-T1', '2027-12-31', '4.0000'],
        ['LOT-T2', '2028-06-30', '6.0000'],
      ])
      pass('W2-PART-4', `status=received stock=${stock} WAC=${cost} Σ408=${grir} lots=${lots.map((lot) => `${lot[0]}:${lot[2]}`).join(',')}`, '[derived] two-lot FEFO fixture')
    })
  })

  test('W2-PART-5 create SI-2026-0001 from receipt lines through the UI', async () => {
    await runLeg('W2-PART-5', async () => {
      await page.goto(`/purchases/orders/${req('partPoId')}`)
      const createButton = page.getByRole('button', { name: /create supplier invoice|créer facture fournisseur/i })
      await expect(createButton).toBeEnabled()
      await createButton.click()
      await expect(page).toHaveURL(new RegExp(`/purchases/supplier-invoices/new\\?po=${req('partPoId')}`))
      await expect(page.getByTestId('invoice-line-quantity-0')).toBeVisible()
      // OBSERVATION (run 18): the create page builds one row per RECEIPT LINE (SupplierInvoiceCreatePage.tsx:256-268),
      // so PO-C's two tranches prefill as 4.0000 + 6.0000 — the matrix's "one line 10.0000" was an assumption; totals are unchanged.
      await expect(page.getByTestId('invoice-line-quantity-0')).toHaveValue(/4(?:\.0+)?/)
      await expect(page.getByTestId('invoice-line-unit-price-0')).toHaveValue(/10[.,]500/)
      await expect(page.getByTestId('invoice-line-quantity-1')).toHaveValue(/6(?:\.0+)?/)
      await expect(page.getByTestId('invoice-line-unit-price-1')).toHaveValue(/10[.,]500/)
      const createResponse = page.waitForResponse((response) => (
        response.request().method() === 'POST' && /\/api\/v1\/supplier-invoices$/.test(response.url())
      ))
      await page.getByRole('button', { name: /save draft|enregistrer brouillon/i }).click()
      const response = await createResponse
      expect(response.status()).toBe(201)
      const responseBody = asRecord(await response.json() as unknown, 'PART-5 invoice response')
      state.partInvoiceId = stringField(asRecord(responseBody['data'], 'PART-5 invoice data'), 'id')
      const invoice = await getObject(`/supplier-invoices/${state.partInvoiceId}`, req('c1'), 'PART-5 invoice')
      expect(invoice['number'] ?? invoice['document_number']).toBe('SI-2026-0001')
      expect(invoice['match_status']).toBe('matched')
      money(stringField(invoice, 'subtotal'), '105.000')
      money(stringField(invoice, 'tax_amount'), '19.950')
      money(stringField(invoice, 'total'), '124.950')
      money(sql(tenantDb(), `SELECT stamp_duty_amount FROM documents WHERE id=${quoted(state.partInvoiceId)}`)[0]
        ?? fail('PART-5 stamp duty missing'), '0.000')
      const invoiceLines = asArray(invoice['lines'], 'PART-5 invoice lines').map((line, index) => asRecord(line, `PART-5 line ${String(index)}`))
      expect(invoiceLines).toHaveLength(2)
      const sortedLines = [...invoiceLines].sort((a, b) => stringField(a, 'quantity').localeCompare(stringField(b, 'quantity')))
      const expectedLines = [['4.0000', '42.000'], ['6.0000', '63.000']] as const
      expectedLines.forEach(([qty, net], index) => {
        const line = sortedLines[index] ?? fail(`PART-5 line ${String(index)} missing`)
        expect(stringField(line, 'quantity')).toBe(qty)
        money(stringField(line, 'unit_price'), '10.500')
        money(stringField(line, 'line_subtotal'), net)
        money(stringField(line, 'vat_rate'), '19.00')
      })
      const snapshots = sql(tenantDb(), `
        SELECT price_match_basis, matched_receipt_line_id IS NOT NULL
        FROM document_lines WHERE document_id=${quoted(state.partInvoiceId)} ORDER BY quantity
      `).map(rowParts)
      expect(snapshots).toHaveLength(2)
      for (const snapshot of snapshots) {
        money(snapshot[0] ?? fail('PART-5 price-match basis missing'), '10.500000')
        expect(snapshot[1]).toBe('t')
      }
      pass('W2-PART-5', 'number=SI-2026-0001 lines=2 (4.0000+6.0000, receipt-line grain) price=10.500 vat=19 net=105.000 tax=19.950 stamp=0.000 total=124.950 match=matched snapshot=stamped', '[derived] same')
    })
  })

  test('W2-PART-6 post supplier invoice and clear receipt capacity', async () => {
    await runLeg('W2-PART-6', async () => {
      await page.goto(`/purchases/supplier-invoices/${req('partInvoiceId')}`)
      const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST' && response.url().includes(`/api/v1/supplier-invoices/${req('partInvoiceId')}/post`)
      ))
      await page.getByTestId('btn-post').click()
      const response = await responsePromise
      expect(response.status()).toBe(200)
      const invoice = await getObject(`/supplier-invoices/${req('partInvoiceId')}`, req('c1'), 'PART-6 invoice')
      expect(invoice['status']).toBe('posted')
      money(stringField(invoice, 'balance_due'), '124.950')

      const gl = sql(tenantDb(), `
        SELECT a.system_purpose, SUM(jl.debit), SUM(jl.credit), BOOL_AND(jl.partner_id=${quoted(req('supplierAId'))})
        FROM journal_entries je
        JOIN journal_lines jl ON jl.journal_entry_id=je.id
        JOIN accounts a ON a.id=jl.account_id
        WHERE je.source_type='supplier_invoice' AND je.source_id=${quoted(req('partInvoiceId'))} AND je.status='posted'
        GROUP BY a.system_purpose ORDER BY a.system_purpose
      `).map(rowParts)
      const byPurpose = new Map(gl.map((row) => [row[0], row]))
      money(byPurpose.get('goods_received_not_invoiced')?.[1] ?? fail('408 debit missing'), '105.000')
      money(byPurpose.get('vat_deductible')?.[1] ?? fail('4456 debit missing'), '19.950')
      money(byPurpose.get('supplier_payable')?.[2] ?? fail('401 credit missing'), '124.950')
      // MEASURED run 20: only the 401 payable leg is partner-tagged (408/4456 carry partner_id NULL — standard subledger
      // practice, 01c §5). The matrix wording "partner-tagged" applies to the payable leg; asserting every leg was a spec error.
      expect(byPurpose.get('supplier_payable')?.[3]).toBe('t')
      expect(byPurpose.has('inventory')).toBe(false)
      expect(byPurpose.has('purchase_price_variance_expense')).toBe(false)
      expect(byPurpose.has('purchase_price_variance_income')).toBe(false)
      const journal = rowParts(sql(tenantDb(), `
        SELECT COUNT(DISTINCT je.id), SUM(jl.debit), SUM(jl.credit)
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
        WHERE je.source_type='supplier_invoice' AND je.source_id=${quoted(req('partInvoiceId'))} AND je.status='posted'
      `)[0] ?? fail('PART-6 journal summary missing'))
      expect(journal[0]).toBe('1')
      money(journal[1] ?? fail('PART-6 journal debit missing'), journal[2] ?? fail('PART-6 journal credit missing'))
      const invoiced = sql(tenantDb(), `
        SELECT grl.quantity_invoiced
        FROM goods_receipt_lines grl JOIN goods_receipts gr ON gr.id=grl.goods_receipt_id
        WHERE gr.purchase_order_id=${quoted(req('partPoId'))}
        ORDER BY gr.created_at
      `)
      expect(invoiced).toEqual(['4.0000', '6.0000'])
      pass('W2-PART-6', `status=posted balance=124.950 entries=${journal[0]} Dr408=105.000 Dr4456=19.950 Cr401=124.950 balanced=${journal[1]}/${journal[2]} invoiced=${invoiced.join('+')}`, '[derived] same; no PPV/inventory plug')
    })
  })

  test('W2-PART-7 record first supplier payment through the UI', async () => {
    await runLeg('W2-PART-7', async () => {
      // FIXTURE (runs 21-22): a fresh tenant's cash register holds 0.000 and forbids a negative balance (50.000 outflow → 422),
      // and the gated /adjustments route refuses a never-funded repository and points to the accounting opening balance —
      // so fund CASH-01 the way the product prescribes: ACCOUNTING opening batch → rows (debit on the drawer's own GL
      // account, repository_code) → validate → post (mirrors e2e/campaign/onboarding.campaign.ts:475-505).
      await harness.fundDrawer('W2-PART-7')
      await page.goto(`/purchases/supplier-invoices/${req('partInvoiceId')}`)
      await page.getByTestId('btn-record-payment').click()
      const dialog = page.getByRole('dialog')
      await dialog.locator('#supplier-payment-amount').fill('50.000')
      await dialog.locator('#supplier-payment-method').selectOption(req('paymentMethodId'))
      await dialog.locator('#supplier-payment-repository').selectOption(req('paymentRepositoryId'))
      const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST' && /\/api\/v1\/payments$/.test(response.url())
      ))
      await dialog.getByRole('button', { name: /record payment|enregistrer le paiement/i }).click()
      const response = await responsePromise
      expect(response.status()).toBe(201)
      const invoice = await getObject(`/supplier-invoices/${req('partInvoiceId')}`, req('c1'), 'PART-7 invoice')
      money(stringField(invoice, 'balance_due'), '74.950')
      expect(invoice['status']).toBe('posted')
      const paymentBody = asRecord(await response.json() as unknown, 'PART-7 payment response')
      const paymentId = stringField(asRecord(paymentBody['data'], 'PART-7 payment data'), 'id')
      const paymentGl = sql(tenantDb(), `
        SELECT a.system_purpose, SUM(jl.debit), SUM(jl.credit)
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
        JOIN accounts a ON a.id=jl.account_id
        WHERE je.source_type='supplier_payment' AND je.source_id=${quoted(paymentId)} AND je.status='posted'
        GROUP BY a.system_purpose ORDER BY a.system_purpose
      `).map(rowParts)
      const payableLeg = paymentGl.find((row) => row[0] === 'supplier_payable') ?? fail('PART-7 payable leg missing')
      money(payableLeg[1] ?? fail('PART-7 payable debit missing'), '50.000')
      const repositoryCredit = sql(tenantDb(), `
        SELECT jl.credit
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
        JOIN payment_repositories pr ON pr.gl_account_id=jl.account_id
        WHERE je.source_type='supplier_payment' AND je.source_id=${quoted(paymentId)}
          AND je.status='posted' AND pr.id=${quoted(req('paymentRepositoryId'))}
      `)
      expect(repositoryCredit).toHaveLength(1)
      money(repositoryCredit[0] ?? fail('PART-7 repository credit missing'), '50.000')
      const movement = sql(tenantDb(), `
        SELECT direction, amount, payment_repository_id
        FROM repository_movements
        WHERE source_type='payment' AND source_id=${quoted(paymentId)}
      `)
      expect(movement).toEqual([`out|50.000|${req('paymentRepositoryId')}`])
      const payable = await pollUntil(
        async () => sql(tenantDb(), `SELECT payable_balance FROM partners WHERE id=${quoted(req('supplierAId'))}`)[0] ?? '',
        (value) => value === '74.950',
      )
      expect(payable).toBe('74.950')
      pass('W2-PART-7', `balance=74.950 status=posted Dr401=50.000 CrRepository=${repositoryCredit[0]} movement=out/50.000 payable=${payable}`, '[derived] same')
    })
  })

  test('W2-PART-8 pay the exact residual and close AP', async () => {
    await runLeg('W2-PART-8', async () => {
      await page.goto(`/purchases/supplier-invoices/${req('partInvoiceId')}`)
      await page.getByTestId('btn-record-payment').click()
      const dialog = page.getByRole('dialog')
      await dialog.locator('#supplier-payment-amount').fill('74.950')
      await dialog.locator('#supplier-payment-method').selectOption(req('paymentMethodId'))
      await dialog.locator('#supplier-payment-repository').selectOption(req('paymentRepositoryId'))
      const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST' && /\/api\/v1\/payments$/.test(response.url())
      ))
      await dialog.getByRole('button', { name: /record payment|enregistrer le paiement/i }).click()
      const response = await responsePromise
      expect(response.status()).toBe(201)
      const invoice = await getObject(`/supplier-invoices/${req('partInvoiceId')}`, req('c1'), 'PART-8 invoice')
      money(stringField(invoice, 'balance_due'), '0.000')
      expect(invoice['status']).toBe('paid')
      const ap = rowParts(sql(tenantDb(), `
        SELECT
          SUM(CASE WHEN jl.credit>0 THEN jl.credit ELSE 0 END),
          SUM(CASE WHEN jl.debit>0 THEN jl.debit ELSE 0 END)
        FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id
        WHERE a.system_purpose='supplier_payable' AND jl.partner_id=${quoted(req('supplierAId'))}
      `)[0] ?? fail('PART-8 AP query empty'))
      money(ap[0] ?? fail('PART-8 AP credit missing'), '124.950')
      money(ap[1] ?? fail('PART-8 AP debit missing'), '124.950')
      const payable = await pollUntil(
        async () => sql(tenantDb(), `SELECT payable_balance FROM partners WHERE id=${quoted(req('supplierAId'))}`)[0] ?? '',
        (value) => value === '0.000',
      )
      expect(payable).toBe('0.000')
      pass('W2-PART-8', `balance=0.000 status=paid ΣCr401=${ap[0]} ΣDr401=${ap[1]} payable=${payable}`, '[derived] same')
    })
  })

  test('W2-LOT-1 tracked receipt dialog requires batch and expiry', async () => {
    await runLeg('W2-LOT-1', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-1', '6.0000', 'PO-T1')
      const poId = stringField(confirmed, 'id')
      state.lotPos.set('T1', poId)
      const dialog = await openReceiveDialog(poId)
      await expect(dialog.getByLabel(/(?:quantity to receive|quantité à réceptionner).*P-LOT-1/i)).toHaveValue('6')
      await expect(dialog.getByLabel(/(?:batch number|numéro de lot).*P-LOT-1/i)).toBeVisible()
      await expect(dialog.getByLabel(/(?:expiry date|date d'expiration).*P-LOT-1/i)).toBeVisible()
      await expect(dialog.getByRole('button', { name: /save draft|enregistrer brouillon/i })).toBeDisabled()
      await expect(dialog.getByRole('button', { name: /save and post|enregistrer et valider/i })).toBeDisabled()
      pass('W2-LOT-1', 'qty_display=6 batch_input=visible expiry_input=visible both_actions=disabled', 'tracked dialog contract')
    })
  })

  test('W2-LOT-2 post LOT-1 into both stock ledgers', async () => {
    await runLeg('W2-LOT-2', async () => {
      const dialog = page.getByRole('dialog')
      await dialog.getByLabel(/(?:batch number|numéro de lot).*P-LOT-1/i).fill('LOT-1')
      await dialog.getByLabel(/(?:expiry date|date d'expiration).*P-LOT-1/i).fill('2027-06-30')
      const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST' && response.url().includes(`/api/v1/purchase-orders/${state.lotPos.get('T1') ?? ''}/receive`)
      ))
      await dialog.getByRole('button', { name: /save and post|enregistrer et valider/i }).click()
      const response = await responsePromise
      expect(response.status()).toBe(200)
      const rows = sql(tenantDb(), `
        SELECT pb.batch_number, pb.expiry_date, ibs.quantity, sl.quantity, dl.batch_id IS NOT NULL
        FROM product_batches pb
        JOIN inventory_batch_stock ibs ON ibs.batch_id=pb.id AND ibs.location_id=${quoted(req('c1Main'))}
        JOIN stock_levels sl ON sl.product_id=pb.product_id AND sl.location_id=ibs.location_id AND sl.company_id=${quoted(req('c1'))} AND sl.variant_id IS NULL
        JOIN document_lines dl ON dl.product_id=pb.product_id AND dl.document_id=${quoted(state.lotPos.get('T1') ?? fail('T1 PO missing'))}
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-LOT-1'))} AND pb.variant_id IS NULL
      `).map(rowParts)
      expect(rows).toEqual([['LOT-1', '2027-06-30', '6.0000', '6.0000', 't']])
      pass('W2-LOT-2', 'batch=LOT-1 expiry=2027-06-30 batch_stock=6.0000 stock=6.0000 line.batch_id=set', '[derived] same')
    })
  })

  test('W2-LOT-3 no shelf-life default reaches a fresh expiry field', async () => {
    await runLeg('W2-LOT-3', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-6', '6.0000', 'PO-T6')
      state.lotPos.set('T6', stringField(confirmed, 'id'))
      const dialog = await openReceiveDialog(state.lotPos.get('T6') ?? fail('T6 missing'))
      const expiry = dialog.getByLabel(/(?:expiry date|date d'expiration).*P-LOT-6/i)
      await expect(expiry).toHaveValue('')
      await dialog.getByRole('button', { name: /cancel|annuler/i }).click()
      pass('W2-LOT-3', 'fresh_expiry_value=""', 'no default offered despite shelf-life metadata')
    })
  })

  test('W2-LOT-4 untracked product silently drops supplied batch data', async () => {
    await runLeg('W2-LOT-4', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-4', '2.0000', 'PO-T4')
      const poId = stringField(confirmed, 'id')
      state.lotPos.set('T4', poId)
      const lineId = stringField(poLines(confirmed, 'PO-T4')[0] ?? fail('T4 line missing'), 'id')
      const received = await receiveApi(poId, lineId, '2.0000', { batchNumber: 'SHOULD-DROP', expiryDate: '2027-12-31' })
      expectStatus(received, 200, 'LOT-4 receive')
      const lotCount = sql(tenantDb(), `SELECT COUNT(*) FROM product_batches WHERE company_id=${quoted(req('c1'))} AND product_id=${quoted(product('P-LOT-4'))} AND variant_id IS NULL`)[0]
      const batchStockCount = sql(tenantDb(), `
        SELECT COUNT(*) FROM inventory_batch_stock ibs JOIN product_batches pb ON pb.id=ibs.batch_id
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-LOT-4'))} AND pb.variant_id IS NULL
      `)[0]
      expect(lotCount).toBe('0')
      expect(batchStockCount).toBe('0')
      pass('W2-LOT-4', `status=200 product_batches=${lotCount} inventory_batch_stock=${batchStockCount} warning=none`, 'batch payload silently dropped')
    })
  })

  test('W2-LOT-5 API receiveAll fails while the UI always submits quantities', async () => {
    await runLeg('W2-LOT-5', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-1', '1.0000', 'PO-T1 API-only probe')
      const poId = stringField(confirmed, 'id')
      const lineId = stringField(poLines(confirmed, 'PO-T1 probe')[0] ?? fail('LOT-5 line missing'), 'id')
      const apiProbe = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {})
      expectStatus(apiProbe, 422, 'LOT-5 empty body')
      // [post-L1] aggregated, UUID-free refusal (AFTER string from the L-1 r2 review)
      expect(errorText(apiProbe.body)).toMatch(/BATCH_DATA_REQUIRED/)
      expect(errorText(apiProbe.body)).toContain('Batch data is required for batch-tracked products: line 1 (P-LOT-1).')
      expect(errorText(apiProbe.body)).not.toContain(product('P-LOT-1'))

      const dialog = await openReceiveDialog(poId)
      await dialog.getByLabel(/(?:batch number|numéro de lot).*P-LOT-1/i).fill('LOT-API-PROOF')
      await dialog.getByLabel(/(?:expiry date|date d'expiration).*P-LOT-1/i).fill('2027-12-31')
      let submittedPayload: Row | null = null
      const onRequest = (requestEvent: import('@playwright/test').Request): void => {
        if (requestEvent.method() === 'POST' && requestEvent.url().includes(`/purchase-orders/${poId}/receive`)) {
          const body = requestEvent.postDataJSON() as unknown
          submittedPayload = asRecord(body, 'LOT-5 UI request')
        }
      }
      page.on('request', onRequest)
      try {
        const responsePromise = page.waitForResponse((response) => response.url().includes(`/purchase-orders/${poId}/receive`) && response.request().method() === 'POST')
        await dialog.getByRole('button', { name: /save and post|enregistrer et valider/i }).click()
        expect((await responsePromise).status()).toBe(200)
      } finally {
        page.off('request', onRequest)
      }
      const payload = asRecord(submittedPayload, 'LOT-5 UI payload')
      const quantities = asRecord(payload['quantities'], 'LOT-5 UI quantities')
      expect(quantities[lineId]).toBe('1')
      pass('W2-LOT-5', `api=${apiProbe.status}/GOODS_RECEIPT_FAILED UI.quantities[${lineId}]=${String(quantities[lineId])}`, 'F-SOE-2 is API-only')
    })
  })

  test('W2-LOT-6 expired lot is accepted and marked not expired', async () => {
    await runLeg('W2-LOT-6', async () => {
      const poId = state.lotPos.get('T6') ?? fail('T6 missing')
      const confirmed = await poDetail(poId)
      const lineId = stringField(poLines(confirmed, 'PO-T6')[0] ?? fail('T6 line missing'), 'id')
      // [post-L1] expired lots are now REFUSED by default and admitted only via the permissioned allow_expired override,
      // which stores a TRUTHFUL is_expired = true (AFTER strings from the L-1 r2 review). LOT-9/10's fixture survives via the override.
      const refused = await receiveApi(poId, lineId, '6.0000', { batchNumber: 'LOT-EXPIRED', expiryDate: '2020-01-01' })
      expectStatus(refused, 422, 'LOT-6 expired refusal')
      expect(errorText(refused.body)).toMatch(/EXPIRED_LOT_REFUSED/)
      expect(errorText(refused.body)).toContain('Cannot receive expired lot for line 1 (P-LOT-6): expiry date 2020-01-01 is before today.')
      const received = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {
        location_id: req('c1Main'), quantities: { [lineId]: '6.0000' },
        batches: { [lineId]: { batch_number: 'LOT-EXPIRED', expiry_date: '2020-01-01' } }, allow_expired: true,
      })
      expectStatus(received, 200, 'LOT-6 permitted override')
      const lot = rowParts(sql(tenantDb(), `
        SELECT expiry_date, is_expired FROM product_batches
        WHERE company_id=${quoted(req('c1'))} AND product_id=${quoted(product('P-LOT-6'))} AND variant_id IS NULL AND batch_number='LOT-EXPIRED'
      `)[0] ?? fail('LOT-6 batch missing'))
      expect(lot).toEqual(['2020-01-01', 't'])
      const stock = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-LOT-6'))} AND variant_id IS NULL`)[0]
      expect(stock).toBe('6.0000')
      pass('W2-LOT-6', `refusal=422/EXPIRED_LOT_REFUSED override=200 expiry=${lot[0]} is_expired=${lot[1]} stock=${stock}`, '[post-L1] refuse by default; permissioned override stores truthful is_expired')
    })
  })

  test('W2-LOT-7 same batch number silently keeps the first expiry', async () => {
    await runLeg('W2-LOT-7', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-7', '6.0000', 'PO-T7')
      const poId = stringField(confirmed, 'id')
      state.lotPos.set('T7', poId)
      const lineId = stringField(poLines(confirmed, 'PO-T7')[0] ?? fail('T7 line missing'), 'id')
      expectStatus(await receiveApi(poId, lineId, '2.0000', { batchNumber: 'LOT-SAME', expiryDate: '2027-01-31' }), 200, 'LOT-7 tranche 1')
      const refreshed = await poDetail(poId)
      const refreshedLineId = stringField(poLines(refreshed, 'PO-T7 refreshed')[0] ?? fail('T7 refreshed line missing'), 'id')
      // [post-L1] a conflicting expiry on the same batch is now REFUSED; the same expiry reuses the lot and sums quantities.
      const conflict = await receiveApi(poId, refreshedLineId, '4.0000', { batchNumber: 'LOT-SAME', expiryDate: '2028-01-31' })
      expectStatus(conflict, 422, 'LOT-7 conflicting expiry')
      expect(errorText(conflict.body)).toMatch(/BATCH_EXPIRY_CONFLICT/)
      expect(errorText(conflict.body)).toContain('Batch LOT-SAME for line 1 (P-LOT-7) already has expiry date 2027-01-31; supplied expiry date 2028-01-31 conflicts.')
      expectStatus(await receiveApi(poId, refreshedLineId, '4.0000', { batchNumber: 'LOT-SAME', expiryDate: '2027-01-31' }), 200, 'LOT-7 tranche 2 same expiry')
      const lots = sql(tenantDb(), `
        SELECT pb.batch_number, pb.expiry_date, ibs.quantity
        FROM product_batches pb JOIN inventory_batch_stock ibs ON ibs.batch_id=pb.id
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-LOT-7'))} AND pb.variant_id IS NULL
      `).map(rowParts)
      expect(lots).toEqual([['LOT-SAME', '2027-01-31', '6.0000']])
      pass('W2-LOT-7', 'conflict=422/BATCH_EXPIRY_CONFLICT same_expiry=200 rows=1 quantity=6.0000', '[post-L1] conflicting re-declaration refused; same expiry sums')
    })
  })

  test('W2-LOT-8 active variant requires variant-scoped batch data', async () => {
    await runLeg('W2-LOT-8', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-8', '2.0000', 'PO-T8')
      const poId = stringField(confirmed, 'id')
      state.lotPos.set('T8', poId)
      const lineId = stringField(poLines(confirmed, 'PO-T8')[0] ?? fail('T8 line missing'), 'id')
      const receiptsBefore = sql(tenantDb(), `SELECT COUNT(*) FROM goods_receipts WHERE purchase_order_id=${quoted(poId)}`)[0]
      const movementsBefore = sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE reference_id=${quoted(poId)}`)[0]
      const result = await receiveApi(poId, lineId, '2.0000', { batchNumber: 'LOT-VARIANT', expiryDate: '2027-12-31' })
      expectStatus(result, 422, 'LOT-8 receive')
      expect(errorText(result.body)).toMatch(/VARIANT_REQUIRED/)
      expect(errorText(result.body)).toContain('Product P-LOT-8 on line 1 has active variants; batches must be variant-scoped — a variant_id is required.')
      expect(errorText(result.body)).not.toContain(product('P-LOT-8'))
      expect(sql(tenantDb(), `SELECT COUNT(*) FROM goods_receipts WHERE purchase_order_id=${quoted(poId)}`)[0]).toBe(receiptsBefore)
      expect(sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE reference_id=${quoted(poId)}`)[0]).toBe(movementsBefore)
      pass('W2-LOT-8', 'status=422 reason=VARIANT_REQUIRED receipts_delta=0 movements_delta=0 label=SKU-based', '[post-L1] typed variant refusal, UUID-free')
    })
  })

  test('W2-LOT-9 FEFO consumes earliest expiry first and posts exit GL', async () => {
    await runLeg('W2-LOT-9', async () => {
      const before = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-PART-1'))} AND variant_id IS NULL`)[0]
      expect(before).toBe('10.0000')
      const delivery = await createDelivery(product('P-PART-1'), '5.0000', 'W2-LOT-9 FEFO driver')
      const deliveryId = stringField(delivery, 'id')
      const deliveryLines = poLines(delivery, 'LOT-9 delivery')
      expect(deliveryLines[0]?.['batch_id'] ?? null).toBeNull()
      const confirmed = await confirmDelivery(deliveryId)
      expectStatus(confirmed, 200, 'LOT-9 delivery confirm')

      const lots = sql(tenantDb(), `
        SELECT pb.batch_number, ibs.quantity
        FROM product_batches pb JOIN inventory_batch_stock ibs ON ibs.batch_id=pb.id
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-PART-1'))}
          AND ibs.location_id=${quoted(req('c1Main'))}
          AND pb.variant_id IS NULL
        ORDER BY pb.expiry_date
      `).map(rowParts)
      expect(lots).toEqual([['LOT-T1', '0.0000'], ['LOT-T2', '5.0000']])
      const aggregate = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-PART-1'))} AND variant_id IS NULL`)[0]
      expect(aggregate).toBe('5.0000')
      const lotSum = sql(tenantDb(), `
        SELECT SUM(ibs.quantity) FROM inventory_batch_stock ibs JOIN product_batches pb ON pb.id=ibs.batch_id
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-PART-1'))} AND ibs.location_id=${quoted(req('c1Main'))} AND pb.variant_id IS NULL
      `)[0]
      expect(lotSum).toBe('5.0000')
      expect(sql(tenantDb(), `
        SELECT COUNT(*) FROM inventory_batch_stock ibs JOIN product_batches pb ON pb.id=ibs.batch_id
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-PART-1'))} AND pb.variant_id IS NULL AND ibs.quantity<0
      `)[0]).toBe('0')
      const movementId = sql(tenantDb(), `
        SELECT id FROM stock_movements
        WHERE company_id=${quoted(req('c1'))} AND reference_id=${quoted(deliveryId)} AND movement_type='issue'
        ORDER BY created_at DESC LIMIT 1
      `)[0] ?? fail('LOT-9 issue movement missing')
      const exitEntry = sql(tenantDb(), `
        SELECT COUNT(*) FROM journal_entries
        WHERE source_type='inventory_exit' AND source_id=${quoted(movementId)} AND status='posted'
      `)[0]
      expect(exitEntry).toBe('1')
      pass('W2-LOT-9', `LOT-T1=0.0000 LOT-T2=5.0000 aggregate=${aggregate} lot_sum=${lotSum} negative=0 exit_GL=${exitEntry} movement=${movementId}`, '[derived] earliest-expiry-first and posted exit entry')
    })
  })

  test('W2-LOT-10 expired-lot shortfall rolls back without a negative lot', async () => {
    await runLeg('W2-LOT-10', async () => {
      const before = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-LOT-6'))} AND variant_id IS NULL`)[0]
      expect(before).toBe('6.0000')
      const delivery = await createDelivery(product('P-LOT-6'), '3.0000', 'W2-LOT-10 expired FEFO driver')
      const result = await confirmDelivery(stringField(delivery, 'id'))
      expectStatus(result, 422, 'LOT-10 delivery confirm')
      expect(errorText(result.body)).toMatch(/INVALID_STATUS_TRANSITION/)
      expect(errorText(result.body)).toMatch(/Insufficient batch stock to fulfill atomic consume\. Shortfall: 3\.0000/)
      const negative = sql(tenantDb(), `
        SELECT COUNT(*) FROM inventory_batch_stock ibs JOIN product_batches pb ON pb.id=ibs.batch_id
        WHERE pb.company_id=${quoted(req('c1'))} AND pb.product_id=${quoted(product('P-LOT-6'))} AND pb.variant_id IS NULL AND ibs.quantity<0
      `)[0]
      const after = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-LOT-6'))} AND variant_id IS NULL`)[0]
      expect(negative).toBe('0')
      expect(after).toBe(before)
      pass('W2-LOT-10', `status=422 code=INVALID_STATUS_TRANSITION shortfall=3.0000 negative_lots=${negative} stock=${before}→${after}`, '[derived] lot-shortfall rollback')
    })
  })

  test('W2-LOT-10b aggregate shortfall returns INSUFFICIENT_STOCK first', async () => {
    await runLeg('W2-LOT-10b', async () => {
      const delivery = await createDelivery(product('P-PART-1'), '6.0000', 'W2-LOT-10b aggregate driver')
      const result = await confirmDelivery(stringField(delivery, 'id'))
      expectStatus(result, 422, 'LOT-10b delivery confirm')
      expect(errorText(result.body)).toMatch(/INSUFFICIENT_STOCK/)
      expect(errorText(result.body)).not.toMatch(/INVALID_STATUS_TRANSITION/)
      const stock = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-PART-1'))} AND variant_id IS NULL`)[0]
      expect(stock).toBe('5.0000')
      pass('W2-LOT-10b', `status=422 code=INSUFFICIENT_STOCK stock_unchanged=${stock}`, 'aggregate guard precedes batch draw')
    })
  })

  test('W2-LOT-11 unknown expiry shapes are both rejected at validation', async () => {
    await runLeg('W2-LOT-11', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-11', '2.0000', 'PO-T11')
      const poId = stringField(confirmed, 'id')
      state.lotPos.set('T11', poId)
      const lineId = stringField(poLines(confirmed, 'PO-T11')[0] ?? fail('T11 line missing'), 'id')
      const missing = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {
        location_id: req('c1Main'), quantities: { [lineId]: '1.0000' },
        batches: { [lineId]: { batch_number: 'LOT-NOEXP' } },
      })
      const nullExpiry = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {
        location_id: req('c1Main'), quantities: { [lineId]: '1.0000' },
        batches: { [lineId]: { batch_number: 'LOT-NULLEXP', expiry_date: null } },
      })
      expectStatus(missing, 422, 'LOT-11 missing expiry')
      expectStatus(nullExpiry, 422, 'LOT-11 null expiry')
      expect(errorText(missing.body)).toMatch(/expiry/i)
      expect(errorText(nullExpiry.body)).toMatch(/expiry/i)
      expect(sql(tenantDb(), `SELECT COUNT(*) FROM goods_receipts WHERE purchase_order_id=${quoted(poId)}`)[0]).toBe('0')
      pass('W2-LOT-11', `missing_expiry=${missing.status} null_expiry=${nullExpiry.status} receipts=0`, 'both 422 at validation')
    })
  })

  test('W2-LOT-12 stray batch keys are ignored only when the real key exists', async () => {
    await runLeg('W2-LOT-12', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-12', '2.0000', 'PO-T12')
      const poId = stringField(confirmed, 'id')
      state.lotPos.set('T12', poId)
      const lineId = stringField(poLines(confirmed, 'PO-T12')[0] ?? fail('T12 line missing'), 'id')
      const foreignPo = await poDetail(state.lotPos.get('T11') ?? fail('T11 missing'))
      const strayLineId = stringField(poLines(foreignPo, 'PO-T11')[0] ?? fail('T11 line missing'), 'id')
      const accepted = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {
        location_id: req('c1Main'),
        quantities: { [lineId]: '1.0000' },
        batches: {
          [strayLineId]: { batch_number: 'STRAY-IGNORED', expiry_date: '2027-12-31' },
          [lineId]: { batch_number: 'LOT-REAL', expiry_date: '2027-12-31' },
        },
      })
      expectStatus(accepted, 200, 'LOT-12 real plus stray')
      const rejected = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {
        location_id: req('c1Main'),
        quantities: { [lineId]: '1.0000' },
        batches: { [strayLineId]: { batch_number: 'STRAY-ONLY', expiry_date: '2027-12-31' } },
      })
      expectStatus(rejected, 422, 'LOT-12 stray only')
      expect(errorText(rejected.body)).toContain('Batch data is required')
      const batches = sql(tenantDb(), `SELECT batch_number FROM product_batches WHERE company_id=${quoted(req('c1'))} AND product_id=${quoted(product('P-LOT-12'))} AND variant_id IS NULL ORDER BY batch_number`)
      expect(batches).toEqual(['LOT-REAL'])
      pass('W2-LOT-12', `real_plus_stray=${accepted.status} stray_only=${rejected.status} batches=${batches.join(',')}`, 'stray ignored with real key; missing real key rejected')
    })
  })

  test('W2-LOT-13 manufacturing date may postdate expiry', async () => {
    await runLeg('W2-LOT-13', async () => {
      const confirmed = await createConfirmedSinglePo('P-LOT-13', '1.0000', 'PO-T13')
      const poId = stringField(confirmed, 'id')
      state.lotPos.set('T13', poId)
      const lineId = stringField(poLines(confirmed, 'PO-T13')[0] ?? fail('T13 line missing'), 'id')
      const result = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {
        location_id: req('c1Main'), quantities: { [lineId]: '1.0000' },
        batches: { [lineId]: {
          batch_number: 'LOT-INVERTED-DATES',
          manufacturing_date: '2028-01-01',
          expiry_date: '2027-01-01',
        } },
      })
      expectStatus(result, 200, 'LOT-13 receive')
      const dates = sql(tenantDb(), `
        SELECT manufacturing_date, expiry_date FROM product_batches
        WHERE company_id=${quoted(req('c1'))} AND product_id=${quoted(product('P-LOT-13'))} AND variant_id IS NULL AND batch_number='LOT-INVERTED-DATES'
      `)
      expect(dates).toEqual(['2028-01-01|2027-01-01'])
      pass('W2-LOT-13', `status=200 manufacturing=2028-01-01 expiry=2027-01-01`, '[derived] validation gap persists')
    })
  })

  test('W2-OVER-1 reject 10.0001 against PO-G atomically', async () => {
    await runLeg('W2-OVER-1', async () => {
      const confirmed = await createConfirmedSinglePo('P-OVER-1', '10.0000', 'PO-G')
      state.poG = stringField(confirmed, 'id')
      const lineId = stringField(poLines(confirmed, 'PO-G')[0] ?? fail('PO-G line missing'), 'id')
      const before = rowParts(sql(tenantDb(), `
        SELECT
          (SELECT COUNT(*) FROM goods_receipts WHERE purchase_order_id=${quoted(req('poG'))}),
          (SELECT COUNT(*) FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND product_id=${quoted(product('P-OVER-1'))} AND variant_id IS NULL),
          (SELECT COUNT(*) FROM journal_entries)
      `)[0] ?? fail('OVER-1 before counts missing'))
      const result = await request(page, 'POST', `/purchase-orders/${req('poG')}/receive`, {
        location_id: req('c1Main'), quantities: { [lineId]: '10.0001' },
      })
      expectStatus(result, 422, 'OVER-1 receive')
      const responseText = errorText(result.body)
      expect(responseText).toMatch(/GOODS_RECEIPT_FAILED/)
      expect(responseText).toMatch(/OVER_RECEIPT/)
      expect(responseText).toContain('Cannot receive more than ordered for line 1 (P-OVER-1): requested more than the remaining quantity.')
      expect(responseText).not.toContain(lineId)
      const after = rowParts(sql(tenantDb(), `
        SELECT
          (SELECT COUNT(*) FROM goods_receipts WHERE purchase_order_id=${quoted(req('poG'))}),
          (SELECT COUNT(*) FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND product_id=${quoted(product('P-OVER-1'))} AND variant_id IS NULL),
          (SELECT COUNT(*) FROM journal_entries)
      `)[0] ?? fail('OVER-1 after counts missing'))
      expect(after).toEqual(before)
      pass('W2-OVER-1', `status=422 code=GOODS_RECEIPT_FAILED leaked_line=${lineId} counts=${before.join('/')}→${after.join('/')}`, 'hard ceiling and full rollback')
    })
  })

  test('W2-OVER-2 dialog disables both actions for 10.0001', async () => {
    await runLeg('W2-OVER-2', async () => {
      const dialog = await openReceiveDialog(req('poG'))
      let receiveRequests = 0
      const onRequest = (requestEvent: import('@playwright/test').Request): void => {
        if (requestEvent.method() === 'POST' && requestEvent.url().includes(`/purchase-orders/${req('poG')}/receive`)) {
          receiveRequests += 1
        }
      }
      page.on('request', onRequest)
      try {
        await dialog.getByLabel(/(?:quantity to receive|quantité à réceptionner).*P-OVER-1/i).fill('10.0001')
        await expect(dialog.getByRole('button', { name: /save draft|enregistrer brouillon/i })).toBeDisabled()
        await expect(dialog.getByRole('button', { name: /save and post|enregistrer et valider/i })).toBeDisabled()
        expect(receiveRequests).toBe(0)
      } finally {
        page.off('request', onRequest)
      }
      await dialog.getByRole('button', { name: /cancel|annuler/i }).click()
      pass('W2-OVER-2', `typed=10.0001 save_draft=disabled save_post=disabled requests=${receiveRequests}`, 'client-side refusal; no request')
    })
  })

  test('W2-OVER-3 free quantity has its own independent ceiling', async () => {
    await runLeg('W2-OVER-3', async () => {
      const confirmed = await createConfirmedSinglePo('P-EDGE-7', '10.0000', 'PO-H', '2.0000')
      state.poH = stringField(confirmed, 'id')
      const lineId = stringField(poLines(confirmed, 'PO-H')[0] ?? fail('PO-H line missing'), 'id')
      const result = await request(page, 'POST', `/purchase-orders/${req('poH')}/receive`, {
        location_id: req('c1Main'), free_quantities: { [lineId]: '2.0001' },
      })
      expectStatus(result, 422, 'OVER-3 free receive')
      expect(errorText(result.body)).toMatch(/free|gratuit/i)
      expect(sql(tenantDb(), `SELECT COUNT(*) FROM goods_receipts WHERE purchase_order_id=${quoted(req('poH'))}`)[0]).toBe('0')
      pass('W2-OVER-3', 'free_requested=2.0001 free_ordered=2.0000 status=422 receipts=0', 'free ceiling independent; PO-H untouched')
    })
  })

  test('W2-OVER-4 five-decimal quantity is rejected before bccomp', async () => {
    await runLeg('W2-OVER-4', async () => {
      const po = await poDetail(req('poG'))
      const lineId = stringField(poLines(po, 'PO-G')[0] ?? fail('PO-G line missing'), 'id')
      const result = await request(page, 'POST', `/purchase-orders/${req('poG')}/receive`, {
        location_id: req('c1Main'), quantities: { [lineId]: '10.00005' },
      })
      expectStatus(result, 422, 'OVER-4 five decimals')
      expect(errorText(result.body)).toMatch(/quantit|decimal|4 decimal/i)
      expect(sql(tenantDb(), `SELECT COUNT(*) FROM goods_receipts WHERE purchase_order_id=${quoted(req('poG'))}`)[0]).toBe('0')
      pass('W2-OVER-4', 'quantity=10.00005 status=422 phase=validation receipts=0', 'bccomp truncation window unreachable over HTTP')
    })
  })

  test('W2-OVER-5 negative quantities are misleadingly rejected or silently skipped', async () => {
    await runLeg('W2-OVER-5', async () => {
      const poG = await poDetail(req('poG'))
      const poGLine = stringField(poLines(poG, 'PO-G')[0] ?? fail('PO-G line missing'), 'id')
      const negativeOnly = await request(page, 'POST', `/purchase-orders/${req('poG')}/receive`, {
        location_id: req('c1Main'), quantities: { [poGLine]: '-5.0000' },
      })
      expectStatus(negativeOnly, 422, 'OVER-5 negative only')
      expect(errorText(negativeOnly.body)).toMatch(/No items to receive\. Please specify quantities to receive\./)

      const po = await createPo([
        { productId: product('P-OVER-5a'), quantity: '10.0000', unitPrice: '10.000', taxConfigurationId: tax('19') },
        { productId: product('P-OVER-5b'), quantity: '10.0000', unitPrice: '10.000', taxConfigurationId: tax('19') },
      ], 'PO-OV5')
      const confirmed = await confirmPo(stringField(po, 'id'))
      const lines = poLines(confirmed, 'PO-OV5')
      const lineA = lines.find((line) => line['product_id'] === product('P-OVER-5a')) ?? fail('PO-OV5 line A missing')
      const lineB = lines.find((line) => line['product_id'] === product('P-OVER-5b')) ?? fail('PO-OV5 line B missing')
      const lineAId = stringField(lineA, 'id')
      const lineBId = stringField(lineB, 'id')
      const mixed = await request(page, 'POST', `/purchase-orders/${stringField(confirmed, 'id')}/receive`, {
        location_id: req('c1Main'), quantities: { [lineAId]: '4.0000', [lineBId]: '-2.0000' },
      })
      expectStatus(mixed, 200, 'OVER-5 mixed receive')
      const movementA = sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE product_id=${quoted(product('P-OVER-5a'))} AND reference_id=${quoted(stringField(confirmed, 'id'))}`)[0]
      const movementB = sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE product_id=${quoted(product('P-OVER-5b'))} AND reference_id=${quoted(stringField(confirmed, 'id'))}`)[0]
      const stockA = sql(tenantDb(), `SELECT quantity FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-OVER-5a'))} AND variant_id IS NULL`)[0]
      const stockB = sql(tenantDb(), `SELECT COUNT(*) FROM stock_levels WHERE company_id=${quoted(req('c1'))} AND location_id=${quoted(req('c1Main'))} AND product_id=${quoted(product('P-OVER-5b'))} AND variant_id IS NULL`)[0]
      expect(movementA).toBe('1')
      expect(movementB).toBe('0')
      expect(stockA).toBe('4.0000')
      expect(stockB).toBe('0')
      pass('W2-OVER-5', `negative_only=${negativeOnly.status}/wrong-cause mixed=${mixed.status} A=4.0000/${movementA}movement B=0/${movementB}movement`, '[derived] minus admitted then skipped')
    })
  })

  test('W2-UNDER-1 receive six of ten and leave PO-G outstanding', async () => {
    await runLeg('W2-UNDER-1', async () => {
      const result = await uiReceive(req('poG'), [{ sku: 'P-OVER-1', quantity: '6' }])
      expect(result.status, errorText(result.body)).toBe(200)
      const po = await poDetail(req('poG'))
      expect(po['status']).toBe('confirmed')
      const receiptStatusResult = await request(page, 'GET', `/purchase-orders/${req('poG')}/receipt-status`)
      expectStatus(receiptStatusResult, 200, 'UNDER-1 receipt status')
      const status = apiObject(receiptStatusResult.body, 'UNDER-1 receipt status')
      expect(status['status']).toBe('partially_received')
      expect(String(status['total_received'])).toMatch(/^6(?:\.0+)?$/)
      const remaining = String(status['total_ordered']) === '10.0000' ? '4.0000' : stringField(asRecord(asArray(status['lines'], 'UNDER-1 lines')[0], 'UNDER-1 line'), 'quantity_remaining')
      expect(remaining).toMatch(/^4(?:\.0+)?$/)
      const persistence = rowParts(sql(tenantDb(), `
        SELECT sl.quantity, p.cost_price FROM stock_levels sl JOIN products p ON p.id=sl.product_id
        WHERE sl.company_id=${quoted(req('c1'))} AND sl.location_id=${quoted(req('c1Main'))} AND sl.product_id=${quoted(product('P-OVER-1'))} AND sl.variant_id IS NULL
      `)[0] ?? fail('UNDER-1 stock missing'))
      expect(persistence).toEqual(['6.0000', '10.000000'])
      pass('W2-UNDER-1', `status=confirmed receipt_status=partially_received remaining=${remaining} stock=${persistence[0]} WAC=${persistence[1]}`, '[derived] same')
    })
  })

  test('W2-UNDER-2 partially received PO has no close affordance', async () => {
    await runLeg('W2-UNDER-2', async () => {
      await page.goto(`/purchases/orders/${req('poG')}`)
      await expect(page.getByRole('button', { name: /close order|close purchase|clôturer|annuler la commande/i })).toHaveCount(0)
      const revert = await request(page, 'POST', `/documents/${req('poG')}/revert`, {})
      const remove = await request(page, 'DELETE', `/purchase-orders/${req('poG')}`)
      expectStatus(revert, 422, 'UNDER-2 revert')
      expectStatus(remove, 422, 'UNDER-2 delete')
      expect(errorText(revert.body)).toMatch(/PURCHASE_ORDER_HAS_RECEIPTS/)
      expect(errorText(remove.body)).toMatch(/DOCUMENT_NOT_DELETABLE/)
      pass('W2-UNDER-2', `close_controls=0 revert=${revert.status}/PURCHASE_ORDER_HAS_RECEIPTS delete=${remove.status}/DOCUMENT_NOT_DELETABLE`, 'permanently outstanding F-W2-05')
    })
  })

  test('W2-UNDER-3 goods plus service PO never reaches received', async () => {
    await runLeg('W2-UNDER-3', async () => {
      const po = await createPo([
        { productId: product('P-UNDER-3'), quantity: '5.0000', unitPrice: '10.000', taxConfigurationId: tax('19') },
        { description: 'S1a', quantity: '1.0000', unitPrice: '25.000', taxConfigurationId: tax('19') },
      ], 'PO-U')
      const confirmed = await confirmPo(stringField(po, 'id'))
      const poId = stringField(confirmed, 'id')
      const received = await uiReceive(poId, [{ sku: 'P-UNDER-3', quantity: '5' }])
      expect(received.status, errorText(received.body)).toBe(200)
      const after = await poDetail(poId)
      expect(after['status']).toBe('confirmed')
      const lines = sql(tenantDb(), `
        SELECT product_id IS NOT NULL, service_id IS NOT NULL, quantity, quantity_received
        FROM document_lines WHERE document_id=${quoted(poId)} ORDER BY line_number
      `).map(rowParts)
      expect(lines).toEqual([
        ['t', 'f', '5.0000', '5.0000'],
        ['f', 'f', '1.0000', '0.0000'],
      ])
      pass('W2-UNDER-3', `status=confirmed goods=5.0000/5.0000 service=0.0000/1.0000`, '[derived] service line makes received unreachable')
    })
  })

  test('W2-UNDER-4 receiveAll persists an orphan non-physical receipt line', async () => {
    await runLeg('W2-UNDER-4', async () => {
      const po = await createPo([
        { productId: product('P-OVER-1'), quantity: '5.0000', unitPrice: '10.000', taxConfigurationId: tax('19') },
        { productId: product('P-UNDER-4'), quantity: '1.0000', unitPrice: '25.000', taxConfigurationId: tax('19') },
      ], 'PO-U2')
      const confirmed = await confirmPo(stringField(po, 'id'))
      const poId = stringField(confirmed, 'id')
      const result = await request(page, 'POST', `/purchase-orders/${poId}/receive`, {})
      expectStatus(result, 200, 'UNDER-4 receiveAll')
      const orphan = sql(tenantDb(), `
        SELECT grl.received_qty, grl.movement_id, grl.landed_unit_cost, grl.quantity_invoiced
        FROM goods_receipt_lines grl
        JOIN goods_receipts gr ON gr.id=grl.goods_receipt_id
        WHERE gr.purchase_order_id=${quoted(poId)} AND grl.product_id=${quoted(product('P-UNDER-4'))}
      `).map(rowParts)
      expect(orphan).toHaveLength(1)
      expect(orphan[0]).toEqual(['1.0000', '', '', '0.0000'])
      const receiptLines = await request(page, 'GET', `/purchase-orders/${poId}/receipt-lines?uninvoiced=1`)
      expectStatus(receiptLines, 200, 'UNDER-4 uninvoiced receipt lines')
      const visible = records(apiData(receiptLines.body, 'UNDER-4 receipt lines'), 'UNDER-4 receipt lines.data')
        .some((line) => line['product_id'] === product('P-UNDER-4'))
      pass('W2-UNDER-4', `received=1.0000 movement_id=NULL landed_unit_cost=NULL invoiced=0.0000 visible_to_matcher=${String(visible)}`, '[derived] orphan exists; matcher visibility recorded')
    })
  })
})
