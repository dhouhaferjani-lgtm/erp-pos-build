import { execFileSync } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
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
  money,
  screenshot,
  sql,
  tenantDb,
} from './wave2-support'

const HERE = fileURLToPath(new URL('.', import.meta.url))

export type Row = Record<string, unknown>
export type ApiMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface ProductFixture {
  sku: string
  purchasePrice: string
  vat: '0' | '7' | '19'
  tracked: boolean
  type?: 'part' | 'service'
}

export interface PoLineInput {
  description?: string
  discountAmount?: string
  discountPercent?: string
  freeQuantity?: string
  isBonusLine?: boolean
  lineTotal?: string
  priceEntryMode?: 'unit' | 'total'
  productId?: string
  quantity: string
  serviceId?: string
  taxConfigurationId?: string
  unitPrice: string
}

export interface Wave2State {
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
  supplierC2Id?: string
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
  [key: string]: unknown
}

export const PART1_PRODUCT_FIXTURES: readonly ProductFixture[] = [
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

export const PART2_PRODUCT_FIXTURES: readonly ProductFixture[] = PART1_PRODUCT_FIXTURES.slice(17)

export const PART2_SUPPORT_FIXTURES: readonly ProductFixture[] = [
  { sku: 'P-HP-1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-HP-2', purchasePrice: '3.250', vat: '7', tracked: true },
  { sku: 'P-HP-3', purchasePrice: '4.000', vat: '0', tracked: true },
  { sku: 'P-OVER-1', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-UNDER-4', purchasePrice: '25.000', vat: '19', tracked: false, type: 'service' },
  { sku: 'P-LOT-11', purchasePrice: '10.500', vat: '19', tracked: true },
] as const

export const C2_PRODUCT_FIXTURES: readonly ProductFixture[] = [
  { sku: 'P-SEC-7', purchasePrice: '20.000', vat: '19', tracked: false },
  { sku: 'P-LAND-6', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-LAND-9', purchasePrice: '10.000', vat: '19', tracked: false },
  { sku: 'P-c2-HP1', purchasePrice: '10.500', vat: '19', tracked: true },
  { sku: 'P-c2-HP2', purchasePrice: '3.250', vat: '7', tracked: true },
  { sku: 'P-c2-HP3', purchasePrice: '4.000', vat: '0', tracked: true },
  { sku: 'P-c2-L9', purchasePrice: '10.000', vat: '19', tracked: false },
] as const

interface HarnessOptions {
  createCompany2Supplier?: boolean
  evidenceFile: string
  evidenceTitle: string
  productFixtures: readonly ProductFixture[]
}

export class Wave2Harness {
  readonly RUN = runId.replace(/[^a-z0-9]/gi, '').slice(-10).toUpperCase()
  readonly PASSWORD = 'Campaign!2026Safe'
  readonly TODAY = new Date().toISOString().slice(0, 10)
  readonly state: Wave2State = {
    taxByRate: {}, products: new Map(), c2Products: new Map(), lotPos: new Map(),
  }
  readonly evidenceLedger: string[] = []
  readonly createCompany2Supplier: boolean
  readonly productFixtures: readonly ProductFixture[]
  private readonly evidenceFile: string
  private readonly evidenceTitle: string
  private adminContext?: BrowserContext
  private cashierContext?: BrowserContext
  private receiverContext?: BrowserContext
  page?: Page
  cashierPage?: Page
  receiverPage?: Page

  constructor(options: HarnessOptions) {
    this.createCompany2Supplier = options.createCompany2Supplier ?? false
    this.evidenceFile = options.evidenceFile
    this.evidenceTitle = options.evidenceTitle
    this.productFixtures = options.productFixtures
  }

  async open(browser: Browser): Promise<Page> {
    this.adminContext = await browser.newContext()
    this.page = await this.adminContext.newPage()
    return this.page
  }

  async openCashier(browser: Browser): Promise<Page> {
    this.cashierContext = await browser.newContext()
    this.cashierPage = await this.cashierContext.newPage()
    return this.cashierPage
  }

  async openReceiver(browser: Browser): Promise<Page> {
    this.receiverContext = await browser.newContext()
    this.receiverPage = await this.receiverContext.newPage()
    return this.receiverPage
  }

  async close(): Promise<void> {
    const directory = resolve(HERE, '../test-results-local/wave2')
    await mkdir(directory, { recursive: true })
    await writeFile(resolve(directory, this.evidenceFile), `# ${this.evidenceTitle}\n\n${this.evidenceLedger.map((line) => `- ${line}`).join('\n')}\n`, 'utf8')
    await this.receiverContext?.close()
    await this.cashierContext?.close()
    await this.adminContext?.close()
  }

  fail(message: string): never { throw new Error(message) }

  req = <K extends keyof Wave2State>(key: K): NonNullable<Wave2State[K]> => (
    this.state[key] ?? this.fail(`state.${String(key)} missing — an earlier serial row did not complete`)
  ) as NonNullable<Wave2State[K]>

  product = (sku: string): string => this.state.products.get(sku)
    ?? this.fail(`product ${sku} missing — W2-SETUP-3 did not complete`)

  c2Product = (sku: string): string => this.state.c2Products.get(sku)
    ?? this.fail(`c2 product ${sku} missing — W2-SETUP-5 did not complete`)

  tax = (rate: ProductFixture['vat']): string => this.state.taxByRate[rate]
    ?? this.fail(`VAT ${rate}% missing — W2-SETUP-1 did not complete`)

  records = (value: unknown, description: string): Row[] => {
    if (Array.isArray(value)) return value.map((row, index) => asRecord(row, `${description}[${String(index)}]`))
    const data = asRecord(value, description)['data']
    return asArray(data, `${description}.data`).map((row, index) => asRecord(row, `${description}.data[${String(index)}]`))
  }

  apiData = (body: unknown, description: string): unknown => {
    const envelope = asRecord(body, description)
    if (!('data' in envelope)) this.fail(`${description} has no data envelope: ${JSON.stringify(body)}`)
    return envelope['data']
  }

  apiObject = (body: unknown, description: string): Row => asRecord(this.apiData(body, description), `${description}.data`)
  errorText = (body: unknown): string => JSON.stringify(body)
  quoted = (value: string): string => `'${value.replaceAll("'", "''")}'`
  rowParts = (row: string): string[] => row.split('|')

  request = async (
    targetPage: Page,
    method: ApiMethod,
    path: string,
    body: unknown = undefined,
    companyId: string = this.req('c1'),
  ): Promise<{ status: number; body: unknown }> => apiJson(targetPage, method, path, body, companyId)

  expectStatus = (result: { status: number; body: unknown }, status: number, label: string): void => {
    expect(result.status, `${label}: ${this.errorText(result.body)}`).toBe(status)
  }

  runLeg = async (
    id: string,
    action: () => Promise<void>,
    pages: readonly Page[] = [this.page ?? this.fail('admin page missing')],
    options: { allowRecorded5xx?: boolean } = {},
  ): Promise<void> => {
    const guards = pages.map((guardPage) => captureGuards(guardPage))
    try {
      await action()
    } finally {
      for (const guard of guards) {
        if (options.allowRecorded5xx === true) {
          // The recorded 5xx also surfaces as a Chromium console-error line ("… status of 5xx …") — drop both shapes here only.
          expect(guard.findings.filter((finding) => finding.kind !== 'http-5xx' && !/status of 5\d\d/.test(finding.message))).toEqual([])
        } else {
          guard.assertClean()
        }
      }
    }
    await screenshot(this.page ?? this.fail('admin page missing'), id)
  }

  recordEvidence = (id: string, line: string): void => {
    const entry = `${id} | ${line}`
    this.evidenceLedger.push(entry)
    evidence(id, line)
  }

  pass = (id: string, measured: string, expected: string): void => {
    this.recordEvidence(id, `measured: ${measured} | expected ${expected} | PASS`)
  }

  failAsExpected = (id: string, measured: string, expected: string): void => {
    this.recordEvidence(id, `measured: ${measured} | expected ${expected} | FAIL-AS-EXPECTED`)
  }

  annotateRuling = (description: string): void => {
    test.info().annotations.push({ type: 'ruling', description })
  }

  bcrypt = (password: string): string => execFileSync(
    'php', ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' },
  ).trim()

  getRows = async (path: string, companyId: string, description: string, targetPage: Page = this.page ?? this.fail('admin page missing')): Promise<Row[]> => {
    const result = await this.request(targetPage, 'GET', path, undefined, companyId)
    this.expectStatus(result, 200, description)
    return this.records(this.apiData(result.body, description), `${description}.data`)
  }

  getObject = async (path: string, companyId: string, description: string, targetPage: Page = this.page ?? this.fail('admin page missing')): Promise<Row> => {
    const result = await this.request(targetPage, 'GET', path, undefined, companyId)
    this.expectStatus(result, 200, description)
    return this.apiObject(result.body, description)
  }

  createProduct = async (targetPage: Page, companyId: string, fixture: ProductFixture): Promise<Row> => {
    const result = await this.request(targetPage, 'POST', '/products', {
      name: fixture.sku, sku: fixture.sku, type: fixture.type ?? 'part',
      is_physical: fixture.type !== 'service', unit_id: this.req('pieceUnitId'),
      purchase_price: fixture.purchasePrice, sale_price: fixture.purchasePrice,
      default_tax_configuration_id: this.tax(fixture.vat), tax_rate: `${fixture.vat}.00`,
      ...(fixture.tracked ? {} : { requires_batch_tracking: false }), is_active: true,
    }, companyId)
    expect([200, 201], `create ${fixture.sku}: ${this.errorText(result.body)}`).toContain(result.status)
    return this.apiObject(result.body, `create ${fixture.sku}`)
  }

  skuOf = (productId: string | undefined): string | undefined => {
    if (productId === undefined) return undefined
    for (const [sku, id] of this.state.products) if (id === productId) return sku
    for (const [sku, id] of this.state.c2Products) if (id === productId) return sku
    return undefined
  }

  createPo = async (lines: readonly PoLineInput[], label: string, options: { companyId?: string; locationId?: string; partnerId?: string } = {}): Promise<Row> => {
    const targetPage = this.page ?? this.fail('admin page missing')
    const companyId = options.companyId ?? this.req('c1')
    const result = await this.request(targetPage, 'POST', '/purchase-orders', {
      partner_id: options.partnerId ?? (companyId === this.req('c2') ? this.req('supplierC2Id') : this.req('supplierAId')),
      location_id: options.locationId ?? (companyId === this.req('c2') ? this.req('c2Main') : this.req('c1Main')),
      document_date: this.TODAY, currency: 'TND', notes: label,
      lines: lines.map((line) => ({
        ...(line.productId !== undefined ? { product_id: line.productId } : {}),
        ...(line.serviceId !== undefined ? { service_id: line.serviceId } : {}),
        description: line.description ?? this.skuOf(line.productId) ?? label,
        quantity: line.quantity, unit_price: line.unitPrice,
        ...(line.taxConfigurationId !== undefined ? { tax_configuration_id: line.taxConfigurationId } : {}),
        ...(line.freeQuantity !== undefined ? { free_quantity: line.freeQuantity } : {}),
        ...(line.discountPercent !== undefined ? { discount_percent: line.discountPercent } : {}),
        ...(line.discountAmount !== undefined ? { discount_amount: line.discountAmount } : {}),
        ...(line.priceEntryMode !== undefined ? { price_entry_mode: line.priceEntryMode } : {}),
        ...(line.lineTotal !== undefined ? { line_total: line.lineTotal } : {}),
        ...(line.isBonusLine !== undefined ? { is_bonus_line: line.isBonusLine } : {}),
      })),
    }, companyId)
    this.expectStatus(result, 201, `create ${label}`)
    return this.apiObject(result.body, `create ${label}`)
  }

  confirmPo = async (poId: string, companyId: string = this.req('c1')): Promise<Row> => {
    const result = await this.request(this.page ?? this.fail('admin page missing'), 'POST', `/purchase-orders/${poId}/confirm`, {}, companyId)
    this.expectStatus(result, 200, `confirm PO ${poId}`)
    return this.apiObject(result.body, `confirm PO ${poId}`)
  }

  poDetail = async (poId: string, companyId: string = this.req('c1')): Promise<Row> => this.getObject(`/purchase-orders/${poId}`, companyId, `PO ${poId}`)

  poPayloadFromDb = (poId: string): Row => {
    const raw = sql(tenantDb(), `SELECT payload::text FROM documents WHERE id=${this.quoted(poId)}`)[0] ?? '{}'
    return asRecord(JSON.parse(raw) as unknown, `payload of ${poId}`)
  }

  poLines = (po: Row, description: string): Row[] => asArray(po['lines'], `${description}.lines`)
    .map((line, index) => asRecord(line, `${description}.lines[${String(index)}]`))

  createConfirmedSinglePo = async (sku: string, quantity: string, label: string, freeQuantity?: string): Promise<Row> => {
    const fixture = this.productFixtures.find((candidate) => candidate.sku === sku) ?? this.fail(`fixture ${sku} missing`)
    const po = await this.createPo([{
      productId: this.product(sku), quantity, unitPrice: fixture.purchasePrice,
      taxConfigurationId: this.tax(fixture.vat), ...(freeQuantity !== undefined ? { freeQuantity } : {}),
    }], label)
    return this.confirmPo(stringField(po, 'id'))
  }

  receiveApi = async (poId: string, lineId: string, quantity: string, batch?: { batchNumber: string; expiryDate: string }, extras: Record<string, unknown> = {}, companyId: string = this.req('c1')): Promise<{ status: number; body: unknown }> => this.request(
    this.page ?? this.fail('admin page missing'), 'POST', `/purchase-orders/${poId}/receive`, {
      location_id: companyId === this.req('c1') ? this.req('c1Main') : this.req('c2Main'), quantities: { [lineId]: quantity },
      ...(batch === undefined ? {} : { batches: { [lineId]: { batch_number: batch.batchNumber, expiry_date: batch.expiryDate } } }), ...extras,
    }, companyId,
  )

  uiAddProduct = async (targetPage: Page, sku: string): Promise<void> => {
    const picker = targetPage.getByRole('combobox', { name: /search or scan a product|rechercher ou scanner un produit/i })
    await picker.fill(sku)
    await targetPage.getByRole('option', { name: new RegExp(sku, 'i') }).click()
  }

  uiSelectSupplier = async (targetPage: Page): Promise<void> => {
    const picker = targetPage.getByPlaceholder(/supplier|fournisseur/i)
    await picker.fill(this.req('supplierAName'))
    await targetPage.getByRole('option', { name: new RegExp(this.req('supplierAName').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') }).click()
  }

  uiSetLine = async (targetPage: Page, sku: string, quantity: string, price?: string): Promise<void> => {
    const row = targetPage.getByRole('row').filter({ hasText: sku })
    await row.getByLabel(/^(qty|qté)$/i).fill(quantity)
    if (price !== undefined) {
      await row.locator('input[id^="line-price-input-"]').fill(price)
      await row.locator('input[id^="line-price-input-"]').press('Tab')
    }
  }

  uiCreatePo = async (lines: ReadonlyArray<{ sku: string; quantity: string; price?: string }>): Promise<string> => {
    const targetPage = this.page ?? this.fail('admin page missing')
    await targetPage.goto('/purchases/orders/new')
    await this.uiSelectSupplier(targetPage)
    for (const line of lines) { await this.uiAddProduct(targetPage, line.sku); await this.uiSetLine(targetPage, line.sku, line.quantity, line.price) }
    const createResponse = targetPage.waitForResponse((response) => response.request().method() === 'POST' && /\/api\/v1\/purchase-orders$/.test(response.url()))
    await targetPage.getByRole('button', { name: /^(save|enregistrer)$/i }).click()
    expect((await createResponse).status()).toBe(201)
    await expect(targetPage).toHaveURL(/\/purchases\/orders\/[0-9a-f-]{36}\/edit(?:[/?#]|$)/i)
    return /\/purchases\/orders\/([0-9a-f-]{36})\/edit/i.exec(targetPage.url())?.[1] ?? this.fail(`could not extract purchase order id from ${targetPage.url()}`)
  }

  uiConfirmPo = async (poId: string): Promise<void> => {
    const targetPage = this.page ?? this.fail('admin page missing')
    await targetPage.goto(`/purchases/orders/${poId}`)
    await targetPage.getByRole('button', { name: /^(confirm|confirmer)$/i }).click()
    const dialog = targetPage.locator('[role="dialog"], div.fixed.inset-0.z-50').last()
    await dialog.getByRole('button', { name: /^(confirm|confirmer)$/i }).click()
    await expect(targetPage.getByText(/confirmed|confirmé/i).first()).toBeVisible()
  }

  openReceiveDialog = async (poId: string): Promise<ReturnType<Page['getByRole']>> => {
    const targetPage = this.page ?? this.fail('admin page missing')
    await targetPage.goto(`/purchases/orders/${poId}`)
    await targetPage.getByRole('button', { name: /receive goods|réceptionner les marchandises/i }).click()
    const dialog = targetPage.getByRole('dialog')
    await expect(dialog).toBeVisible()
    return dialog
  }

  uiReceive = async (poId: string, lines: ReadonlyArray<{ sku: string; quantity: string; batchNumber?: string; expiryDate?: string }>): Promise<{ body: unknown; status: number }> => {
    const targetPage = this.page ?? this.fail('admin page missing')
    const dialog = await this.openReceiveDialog(poId)
    for (const line of lines) {
      await dialog.getByLabel(new RegExp(`(?:quantity to receive|quantité à réceptionner).*${line.sku}`, 'i')).fill(line.quantity)
      if (line.batchNumber !== undefined) await dialog.getByLabel(new RegExp(`(?:batch number|numéro de lot).*${line.sku}`, 'i')).fill(line.batchNumber)
      if (line.expiryDate !== undefined) await dialog.getByLabel(new RegExp(`(?:expiry date|date d'expiration).*${line.sku}`, 'i')).fill(line.expiryDate)
    }
    const responsePromise = targetPage.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes(`/api/v1/purchase-orders/${poId}/receive`))
    await dialog.getByRole('button', { name: /save and post|enregistrer et valider/i }).click()
    const response = await responsePromise
    return { status: response.status(), body: await response.json() as unknown }
  }

  createDelivery = async (productId: string, quantity: string, label: string): Promise<Row> => {
    const result = await this.request(this.page ?? this.fail('admin page missing'), 'POST', '/delivery-notes', {
      partner_id: this.req('supplierAId'), location_id: this.req('c1Main'), document_date: this.TODAY,
      currency: 'TND', notes: label, lines: [{ product_id: productId, description: label, quantity, unit_price: '10.500', tax_configuration_id: this.tax('19') }],
    })
    this.expectStatus(result, 201, `create delivery ${label}`)
    return this.apiObject(result.body, `create delivery ${label}`)
  }

  confirmDelivery = async (deliveryId: string): Promise<{ status: number; body: unknown }> => this.request(this.page ?? this.fail('admin page missing'), 'POST', `/delivery-notes/${deliveryId}/confirm`, {})

  switchCompany = async (companyName: string): Promise<void> => {
    const targetPage = this.page ?? this.fail('admin page missing')
    const shell = campaignSelectors(targetPage).shell
    await shell.companySwitcher.click()
    await targetPage.getByRole('button', { name: companyName, exact: true }).click()
    await expect(shell.companySwitcher).toContainText(companyName)
  }

  fundDrawer = async (evidenceId: string): Promise<void> => {
    const drawerRow = this.rowParts(sql(tenantDb(), `SELECT pr.code, a.code FROM payment_repositories pr JOIN accounts a ON a.id=pr.account_id WHERE pr.id=${this.quoted(this.req('paymentRepositoryId'))}`)[0] ?? this.fail('drawer GL account missing'))
    const create = await this.request(this.page ?? this.fail('admin page missing'), 'POST', apiRoutes.openingBatches(this.req('c1')), { cutover_date: this.TODAY, name: `Wave 2 cash opening ${this.RUN}`, source_system: 'wave2', type: 'ACCOUNTING' })
    this.expectStatus(create, 201, 'opening batch create')
    const batchId = stringField(this.apiObject(create.body, 'opening batch'), 'id')
    const imported = await this.request(this.page ?? this.fail('admin page missing'), 'POST', apiRoutes.openingBatchImport(this.req('c1'), batchId), { rows: [{ account_code: drawerRow[1], debit: '200.000', description: 'Wave 2 fixture: opening cash float', repository_code: drawerRow[0] }] })
    expect([200, 201], `opening rows import: ${this.errorText(imported.body)}`).toContain(imported.status)
    const validated = await this.request(this.page ?? this.fail('admin page missing'), 'POST', apiRoutes.openingBatchValidate(this.req('c1'), batchId), {})
    expect([200, 201], `opening batch validate: ${this.errorText(validated.body)}`).toContain(validated.status)
    const posted = await this.request(this.page ?? this.fail('admin page missing'), 'POST', apiRoutes.openingBatchPost(this.req('c1'), batchId), {})
    expect([200, 201], `opening batch post: ${this.errorText(posted.body)}`).toContain(posted.status)
    const after = await this.getObject(`/payment-repositories/${this.req('paymentRepositoryId')}`, this.req('c1'), 'drawer after opening')
    money(stringField(after, 'balance'), '200.000')
    this.recordEvidence(evidenceId, `fixture: ${drawerRow[0]} funded 200.000 via ACCOUNTING opening batch (Dr ${drawerRow[1]}) before the first supplier payment`)
  }
}

export function createWave2Harness(options: HarnessOptions): Wave2Harness { return new Wave2Harness(options) }

export async function setup1(h: Wave2Harness): Promise<void> {
  test.setTimeout(360_000)
  await h.runLeg('W2-SETUP-1', async () => {
    const page = h.page ?? h.fail('admin page missing')
    await registerFreshTenant(page)
    const companies = h.records(requireApiData(await apiRequest(page, 'GET', apiRoutes.companies), 'companies'), 'companies')
    expect(companies).toHaveLength(1)
    const company = companies[0] ?? h.fail('company 1 missing')
    h.state.c1 = stringField(company, 'id'); h.state.c1Name = stringField(company, 'name'); journeyState.companyId = h.state.c1
    const units = await h.getRows(apiRoutes.units, h.state.c1, 'units')
    const piece = units.find((unit) => unit['code'] === 'pc' || unit['symbol'] === 'pc') ?? h.fail('pc unit missing')
    h.state.pieceUnitId = stringField(piece, 'id'); expect(piece['decimal_places'] ?? piece['decimalPlaces']).toBe(0)
    const locations = await h.getRows(apiRoutes.locations, h.state.c1, 'locations')
    h.state.c1Main = stringField(locations.find((location) => location['code'] === 'MAIN') ?? h.fail('MAIN location missing'), 'id')
    const taxesResult = await h.request(page, 'GET', '/taxation/configurations', undefined, h.state.c1)
    h.expectStatus(taxesResult, 200, 'tax configurations')
    const taxes = h.records(h.apiData(taxesResult.body, 'tax configurations'), 'tax configurations.data')
    for (const rate of ['19', '7', '0'] as const) h.state.taxByRate[rate] = stringField(taxes.find((configuration) => String(configuration['percentage_rate']) === `${rate}.00`) ?? h.fail(`VAT ${rate}% missing`), 'id')
    expect(taxes.filter((configuration) => configuration['tax_type'] === 'PERCENTAGE' && configuration['applies_to'] === 'LINE_ITEMS').map((configuration) => String(configuration['percentage_rate']))).toEqual(expect.arrayContaining(['19.00', '13.00', '7.00', '0.00']))
    const purposeCount = sql(tenantDb(), `SELECT COUNT(*) FROM accounts WHERE company_id=${h.quoted(h.state.c1)} AND system_purpose IN ('inventory','supplier_payable','goods_received_not_invoiced','vat_deductible','purchase_stamp_duty','purchase_price_variance_expense','purchase_price_variance_income')`)[0]
    expect(purposeCount).toBe('7')
    const methods = await h.getRows(apiRoutes.paymentMethods, h.state.c1, 'payment methods')
    const repositories = await h.getRows(apiRoutes.paymentRepositories, h.state.c1, 'payment repositories')
    h.state.paymentMethodId = stringField(methods.find((candidate) => candidate['is_active'] !== false) ?? h.fail('active payment method missing'), 'id')
    h.state.paymentRepositoryId = stringField(repositories.find((candidate) => candidate['is_active'] !== false && candidate['gl_account_id'] !== null) ?? h.fail('ledgered payment repository missing'), 'id')
    h.pass('W2-SETUP-1', `tenant=${journeyState.tenantId ?? '?'} units=${units.length} VAT={19,13,7,0} purposes=${purposeCount}`, '[derived] fresh tenant census')
  })
}

export async function setup2(h: Wave2Harness): Promise<void> {
  await h.runLeg('W2-SETUP-2', async () => {
    const page = h.page ?? h.fail('admin page missing')
    await runImportWizard(page, 'parties', resolve(HERE, 'real-fournisseurs.xlsx'))
    const suppliers = (await h.getRows(`${apiRoutes.partners}?type=supplier&per_page=100`, h.req('c1'), 'suppliers')).filter((partner) => partner['type'] === 'supplier')
    expect(suppliers).toHaveLength(11)
    const a = suppliers[0] ?? h.fail('SUP-A missing'); const b = suppliers[1] ?? h.fail('SUP-B missing')
    h.state.supplierAId = stringField(a, 'id'); h.state.supplierAName = stringField(a, 'name'); h.state.supplierBId = stringField(b, 'id')
    h.pass('W2-SETUP-2', `suppliers=${suppliers.length} SUP-A=${h.state.supplierAName} SUP-B=${String(b['name'])}`, '11 suppliers, 0 failures')
  })
}

export async function setup3(h: Wave2Harness): Promise<void> {
  test.setTimeout(360_000)
  await h.runLeg('W2-SETUP-3', async () => {
    const page = h.page ?? h.fail('admin page missing')
    for (const fixture of h.productFixtures) h.state.products.set(fixture.sku, stringField(await h.createProduct(page, h.req('c1'), fixture), 'id'))
    if (h.state.products.has('P-LOT-8')) {
      const variant = await h.request(page, 'POST', `/products/${h.product('P-LOT-8')}/variants`, { variant_code: `W2-LOT-8-${h.RUN}`, sku: `P-LOT-8-V-${h.RUN}`, name_suffix: 'Wave 2 active variant', is_default: true, attribute_values: [] })
      h.expectStatus(variant, 201, 'P-LOT-8 active variant')
    }
    const trackedSku = h.state.products.has('P-HP-1') ? 'P-HP-1' : 'P-LOC-1'
    const untrackedSku = h.state.products.has('P-OVER-1') ? 'P-OVER-1' : 'P-REV-1'
    expect((await h.getObject(`/products/${h.product(trackedSku)}`, h.req('c1'), trackedSku))['requires_batch_tracking']).toBe(true)
    expect((await h.getObject(`/products/${h.product(untrackedSku)}`, h.req('c1'), untrackedSku))['requires_batch_tracking']).toBe(false)
    if (h.state.products.has('P-UNDER-4')) {
      const nonPhysical = await h.getObject(`/products/${h.product('P-UNDER-4')}`, h.req('c1'), 'P-UNDER-4')
      expect(nonPhysical['is_physical']).toBe(false); expect(nonPhysical['requires_batch_tracking']).toBe(false)
    }
    h.pass('W2-SETUP-3', `products=${h.state.products.size} P-HP-1.tracked=true P-OVER-1.tracked=false P-UNDER-4.physical=false`, 'full fixture table created once')
  })
}

export async function setup4(h: Wave2Harness): Promise<void> {
  await h.runLeg('W2-SETUP-4', async () => {
    const created = await h.request(h.page ?? h.fail('admin page missing'), 'POST', apiRoutes.locations, { name: `Wave 2 Warehouse ${h.RUN}`, code: `WH${h.RUN}`.slice(0, 20), type: 'warehouse', is_active: true, is_default: false })
    h.expectStatus(created, 201, 'create WH'); h.state.c1Wh = stringField(h.apiObject(created.body, 'create WH'), 'id')
    const locations = await h.getRows(apiRoutes.locations, h.req('c1'), 'locations after WH')
    expect(locations.filter((location) => location['is_active'] !== false)).toHaveLength(2); expect(locations.find((location) => location['code'] === 'MAIN')?.['is_default']).toBe(true)
    h.pass('W2-SETUP-4', `active_locations=2 MAIN.default=true WH=${h.state.c1Wh}`, '2 active locations; MAIN remains default')
  })
}

export async function setup5(h: Wave2Harness): Promise<void> {
  test.setTimeout(360_000)
  await h.runLeg('W2-SETUP-5', async () => {
    const page = h.page ?? h.fail('admin page missing')
    await page.goto(routes.dashboard); await campaignSelectors(page).shell.companySwitcher.click(); await campaignSelectors(page).shell.addCompany.click()
    h.state.c2Name = `Wave 2 Company 2 ${h.RUN}`
    const next = page.getByRole('button', { name: /^(next|suivant)$/i })
    await page.getByRole('button', { name: /tunisia|tunisie/i }).click(); await next.click(); await page.locator('#name').fill(h.state.c2Name); await page.locator('#legalName').fill(h.state.c2Name); await next.click(); await next.click()
    const responsePromise = page.waitForResponse((response) => response.request().method() === 'POST' && /\/api\/v1\/companies$/.test(response.url()), { timeout: 120_000 })
    await page.getByRole('button', { name: /create|créer|submit|finish|terminer|confirm/i }).last().click(); expect((await responsePromise).status()).toBe(201)
    const companies = await h.getRows(apiRoutes.companies, h.req('c1'), 'companies after c2')
    h.state.c2 = stringField(companies.find((company) => company['name'] === h.state.c2Name) ?? h.fail('company 2 missing'), 'id')
    const locations = await h.getRows(apiRoutes.locations, h.state.c2, 'c2 locations'); expect(locations).toHaveLength(1)
    const main = locations[0] ?? h.fail('c2 MAIN missing'); expect(main['code']).toBe('MAIN'); expect(main['is_default']).toBe(true); h.state.c2Main = stringField(main, 'id')
    if (h.createCompany2Supplier) {
      const supplier = await h.request(page, 'POST', '/partners', {
        name: `Wave 2 SUP-B ${h.RUN}`,
        type: 'supplier',
      }, h.state.c2)
      h.expectStatus(supplier, 201, 'create c2 SUP-B')
      h.state.supplierC2Id = stringField(h.apiObject(supplier.body, 'create c2 SUP-B'), 'id')
    }
    for (const fixture of C2_PRODUCT_FIXTURES) h.state.c2Products.set(fixture.sku, stringField(await h.createProduct(page, h.state.c2, fixture), 'id'))
    expect(h.state.c2Products.get('P-SEC-7')).not.toBe(h.product('P-SEC-7'))
    expect((await h.getRows(apiRoutes.units, h.state.c2, 'c2 units')).length).toBeGreaterThan(0)
    expect((await h.getRows(apiRoutes.paymentMethods, h.state.c2, 'c2 payment methods')).length).toBeGreaterThan(0)
    expect((await h.getRows(apiRoutes.paymentRepositories, h.state.c2, 'c2 repositories')).length).toBeGreaterThan(0)
    expect(sql(tenantDb(), `SELECT COUNT(*) FROM accounts WHERE company_id=${h.quoted(h.state.c2)}`)[0]).not.toBe('0')
    await h.switchCompany(h.req('c1Name')); journeyState.companyId = h.req('c1')
    h.pass('W2-SETUP-5', `c2=${h.state.c2} MAIN=${h.state.c2Main} products=${h.state.c2Products.size}`, 'real company path provisioned units, methods, repositories, accounts')
  })
}

export async function setup6(h: Wave2Harness, browser: Browser): Promise<void> {
  const cashierPage = await h.openCashier(browser)
  await h.runLeg('W2-SETUP-6', async () => {
    h.state.cashierEmail = `wave2-cashier-${h.RUN.toLowerCase()}@test.otospex.dev`
    const created = await h.request(h.page ?? h.fail('admin page missing'), 'POST', '/users', { name: 'Wave 2 Cashier', email: h.state.cashierEmail, role: 'cashier' })
    h.expectStatus(created, 201, 'create cashier')
    const update = `UPDATE users SET password=${h.quoted(h.bcrypt(h.PASSWORD))}, status='active' WHERE email=${h.quoted(h.state.cashierEmail)} RETURNING id`
    expect(sql(tenantDb(), update)).toHaveLength(1)
    await loginAs(cashierPage, { email: h.state.cashierEmail, name: 'Wave 2 Cashier', password: h.PASSWORD })
    const me = await h.request(cashierPage, 'GET', '/auth/me', undefined, h.req('c1')); h.expectStatus(me, 200, 'cashier /auth/me'); expect(h.errorText(me.body)).toMatch(/cashier/i)
    expect(sql(tenantDb(), `SELECT COUNT(*) FROM user_company_memberships WHERE user_id=(SELECT id FROM users WHERE email=${h.quoted(h.state.cashierEmail)}) AND company_id=${h.quoted(h.req('c1'))}`)[0]).toBe('1')
    h.pass('W2-SETUP-6', `cashier=${h.state.cashierEmail} context=isolated membership=c1 UPDATE=${update}`, 'cashier role authenticated in second context')
  }, [h.page ?? h.fail('admin page missing'), cashierPage])
}

export async function setup7(h: Wave2Harness, browser: Browser): Promise<void> {
  const receiverPage = await h.openReceiver(browser)
  await h.runLeg('W2-SETUP-7', async () => {
    const page = h.page ?? h.fail('admin page missing')
    const role = await h.request(page, 'POST', '/roles', { name: 'wave2-receiver', permissions: ['purchase-orders.receive', 'purchase-orders.view', 'documents.view', 'inventory.view'] })
    h.expectStatus(role, 201, 'create wave2-receiver role')
    const roleId = String(h.apiObject(role.body, 'create wave2-receiver role')['id'] ?? h.fail('role id missing'))
    h.state.receiverEmail = `wave2-receiver-${h.RUN.toLowerCase()}@test.otospex.dev`
    h.expectStatus(await h.request(page, 'POST', '/users', { name: 'Wave 2 Receiver', email: h.state.receiverEmail, role: 'wave2-receiver' }), 201, 'create wave2 receiver')
    const update = `UPDATE users SET password=${h.quoted(h.bcrypt(h.PASSWORD))}, status='active' WHERE email=${h.quoted(h.state.receiverEmail)} RETURNING id`
    expect(sql(tenantDb(), update)).toHaveLength(1)
    await loginAs(receiverPage, { email: h.state.receiverEmail, name: 'Wave 2 Receiver', password: h.PASSWORD })
    const roleRead = await h.request(page, 'GET', `/roles/${roleId}`); h.expectStatus(roleRead, 200, 'read wave2-receiver role')
    expect(h.errorText(roleRead.body)).toMatch(/purchase-orders\.receive/); expect(h.errorText(roleRead.body)).not.toMatch(/goods-receipt\.edit-price/)
    const me = await h.request(receiverPage, 'GET', '/auth/me', undefined, h.req('c1')); h.expectStatus(me, 200, 'receiver /auth/me')
    expect(h.errorText(me.body)).toMatch(/purchase-orders\.receive/); expect(h.errorText(me.body)).not.toMatch(/goods-receipt\.edit-price/)
    h.pass('W2-SETUP-7', `role=wave2-receiver guard=sanctum permissions=receive/view/no-price-edit UPDATE=${update}`, 'third isolated context authenticated')
  }, [h.page ?? h.fail('admin page missing'), receiverPage])
}

export async function setup8(h: Wave2Harness): Promise<void> {
  await h.runLeg('W2-SETUP-8', async () => {
    const patched = await h.request(h.page ?? h.fail('admin page missing'), 'PUT', `/companies/${h.req('c2')}`, { name: h.req('c2Name'), legal_name: h.req('c2Name'), tax_status: 'NON_REGISTERED' }, h.req('c2'))
    h.expectStatus(patched, 200, 'patch c2 tax status')
    expect((await h.getObject(`/companies/${h.req('c2')}`, h.req('c2'), 'c2 after tax patch'))['tax_status']).toBe('NON_REGISTERED')
    expect(sql(tenantDb(), `SELECT tax_status FROM companies WHERE id=${h.quoted(h.req('c2'))}`)).toEqual(['NON_REGISTERED'])
    h.pass('W2-SETUP-8', 'c2.tax_status=non_registered', '200 and persisted; deliberately not restored')
  })
}
