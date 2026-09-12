import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
const __dirname = dirname(fileURLToPath(import.meta.url))
import { expect, test } from '@playwright/test'
import { campaignSelectors } from '../campaign/selectors'
import type { Page } from '@playwright/test'

test.describe.configure({ mode: 'serial' })

async function runImportWizard(page: Page, kind: 'products' | 'parties', path: string): Promise<void> {
  const ui = campaignSelectors(page).import
  await page.goto(`/settings/import/${kind}`)
  await ui.fileInput.setInputFiles(path)
  await ui.next.click()
  await ui.validate.click()
  await expect(ui.step.options.or(ui.step.preview)).toBeVisible()
  if (await ui.step.options.isVisible()) await ui.next.click()
  await expect(ui.step.preview).toBeVisible()
  await ui.proceed.click()
  const dialog = page.getByRole('dialog', { name: 'Import with Errors' })
  await expect(dialog.or(ui.step.execute)).toBeVisible()
  if (await dialog.isVisible()) await dialog.getByRole('button', { name: /Import .* valid rows/ }).click()
  await expect(ui.step.execute).toBeVisible()
  await ui.execute.click()
  await expect(ui.step.complete).toBeVisible({ timeout: 90_000 })
}

const fixtures = resolve(__dirname, '../../../api/tests/Fixtures/Import/imp1')
const evidence = resolve(__dirname, '../../../../docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b')
const email = `imp1-${Date.now()}@test.otospex.dev`
const password = 'Campaign!2026Safe'
let authorization: Record<string, string> = {}

test.beforeAll(async ({ request }) => {
  const response = await request.post('http://localhost:8012/api/v1/auth/register', { data: { name: 'IMP1 Evidence', email, password, password_confirmation: password, company_name: 'IMP1 Evidence', country_code: 'TN', vertical: 'parapharmacy', currency: 'TND', locale: 'en', platform: 'web' }, timeout: 180_000 })
  expect(response.status(), (await response.text()).slice(0, 300)).toBe(201)
  const registration = await response.json() as { data: { token: string } }
  authorization = { Accept: 'application/json', Authorization: `Bearer ${registration.data.token}` }
})

for (const name of ['imp1-success.csv', 'imp1-partial.csv', 'imp1-partial-async.csv', 'imp1-suppliers-balances-200.xlsx']) test(`IMP1 history and rows-to-fix: ${name}`, async ({ page }) => {
  mkdirSync(evidence, { recursive: true })
  await page.goto('/login')
  const consent = page.getByRole('button', { name: 'Accept', exact: true })
  if (await consent.isVisible()) await consent.click()
  await page.getByLabel(/email/i).fill(email)
  await page.getByLabel(/^(password|mot de passe)(\s*\*)?$/i).fill(password)
  await page.getByRole('button', { name: /sign in|log in|se connecter/i }).click()
  await expect(page).not.toHaveURL(/\/login/, { timeout: 60_000 })
  {
    await test.step(name, async () => {
      await runImportWizard(page, name.includes('suppliers') ? 'parties' : 'products', resolve(fixtures, name))
      await page.screenshot({ path: resolve(evidence, `${name}-completion.png`), fullPage: true })
      await page.getByRole('button', { name: 'Download rows to fix' }).scrollIntoViewIfNeeded()
      await page.screenshot({ path: resolve(evidence, `${name}-completion-actions.png`), fullPage: true })
      if (name.includes('partial')) expect.soft(await page.getByRole('button', { name: /download rows to fix/i }).count(), `${name}: completion rows-to-fix action`).toBeGreaterThan(0)
      await page.goto('/settings/import/history')
      await page.screenshot({ path: resolve(evidence, `${name}-history-loading.png`), fullPage: true })
      const row = page.getByRole('row').filter({ hasText: name }).first()
      await expect(row).toBeVisible()
      writeFileSync(resolve(evidence, `${name}-history.txt`), await row.innerText())
      if (name === 'imp1-success.csv') await expect(row).toContainText('Completed')
      const expectedCounts = name === 'imp1-success.csv' ? ['10 imported', '0 failed'] : name === 'imp1-partial.csv' ? ['9 imported', '1 skipped', '2 failed'] : name.includes('async') ? ['95 imported', '5 failed'] : ['200 imported', '0 failed']
      for (const count of expectedCounts) await expect(row).toContainText(count)
      if (name.includes('partial')) await expect(row).toContainText('Completed with errors')
      if (name.includes('suppliers')) {
        // dev 477c877a3 fixed the queued supplier-balance currency scaling this
        // scenario used to pin as a failure. The file now finalizes cleanly, so
        // the row must read Completed with truthful counters and NO operator alert.
        await expect(row).toContainText('Completed')
        await expect(row).not.toContainText('Completed with errors')
        await expect(row.getByRole('alert')).toHaveCount(0)
        await expect(row).toContainText('200')
      }
      await page.screenshot({ path: resolve(evidence, `${name}-history.png`), fullPage: true })
      const download = page.waitForEvent('download')
      await row.getByRole('button', { name: /download result workbook|full report/i }).click()
      await (await download).saveAs(resolve(evidence, `${name}-full-report.xlsx`))
      if (name.includes('partial')) {
        const button = row.getByRole('button', { name: /failed rows|download rows to fix/i }).first()
        await expect(button).toBeVisible()
        const csvDownload = page.waitForEvent('download')
        await button.click()
        await (await csvDownload).saveAs(resolve(evidence, `${name}-rows.csv`))
        const bytes = readFileSync(resolve(evidence, `${name}-rows.csv`))
        const csv = bytes.toString('utf8')
        expect.soft(bytes.subarray(0, 3).toString('hex'), `${name}: UTF-8 BOM`).toBe('efbbbf')
        expect.soft(csv, `${name}: status/code/message headers`).toContain('_status,_code,_message')
        if (name === 'imp1-partial.csv') expect.soft(csv, 'warning-only row exported').toContain('IMP1-WARNING')
        await row.getByRole('combobox', { name: 'File format' }).selectOption('xlsx')
        const excelDownload = page.waitForEvent('download')
        await row.getByRole('button', { name: 'Download rows to fix' }).click()
        await (await excelDownload).saveAs(resolve(evidence, `${name}-rows.xlsx`))
      }
    })
  }
})


test('corrected export reuses mapping and can be re-run', async ({ page }) => {
  await page.goto('/login')
  const consent = page.getByRole('button', { name: 'Accept', exact: true })
  if (await consent.isVisible()) await consent.click()
  await page.getByLabel(/email/i).fill(email)
  await page.getByLabel(/^(password|mot de passe)(\s*\*)?$/i).fill(password)
  await page.getByRole('button', { name: /sign in|log in|se connecter/i }).click()
  await expect(page).not.toHaveURL(/\/login/)
  await page.goto('/settings/import/history')
  const original = page.getByRole('row').filter({ hasText: 'imp1-partial.csv' }).first()
  const reupload = original.getByRole('link', { name: 'Re-upload corrected file' })
  const reuploadUrl = await reupload.getAttribute('href')
  expect(reuploadUrl).toContain('reimport_of=')
  const corrected = readFileSync(resolve(evidence, 'imp1-partial.csv-rows.csv'), 'utf8')
    .replace(',NO-SUCH-UNIT,', ',pc,').replace(',invalid,', ',12.500,')
  writeFileSync(resolve(evidence, 'imp1-corrected.csv'), corrected)
  for (const attempt of [1, 2]) {
    await page.goto(reuploadUrl!)
    const ui = campaignSelectors(page).import
    await ui.fileInput.setInputFiles(resolve(evidence, 'imp1-corrected.csv'))
    await ui.next.click()
    await expect(ui.step.options.or(ui.step.preview)).toBeVisible()
    if (await ui.step.options.isVisible()) await ui.next.click()
    await expect(ui.step.preview).toBeVisible()
    await ui.proceed.click()
    await expect(ui.step.execute).toBeVisible()
    await ui.execute.click()
    await expect(ui.step.complete).toBeVisible()
    await page.screenshot({ path: resolve(evidence, `corrected-attempt-${attempt}.png`), fullPage: true })
  }
})

test('history filters and second-company isolation', async ({ page, request }) => {
  const companiesResponse = await request.get('http://localhost:8012/api/v1/user/companies', { headers: authorization })
  const companies = await companiesResponse.json() as { data: { id: string }[] }
  const company = companies.data[0]
  expect(company).toBeDefined()
  for (const [filename, status] of [['imp1-hard-fail.csv', 422], ['imp1-all-invalid.csv', 201]] as const) {
    const response = await request.post('http://localhost:8012/api/v1/imports', {
      headers: { ...authorization, 'X-Company-Id': company!.id },
      multipart: { type: 'products', file: { name: filename, mimeType: 'text/csv', buffer: readFileSync(resolve(fixtures, filename)) } },
    })
    expect(response.status()).toBe(status)
    writeFileSync(resolve(evidence, `${filename}-upload.json`), JSON.stringify(await response.json(), null, 2))
  }
  await page.goto('/login')
  const consent = page.getByRole('button', { name: 'Accept', exact: true })
  if (await consent.isVisible()) await consent.click()
  await page.getByLabel(/email/i).fill(email)
  await page.getByLabel(/^(password|mot de passe)(\s*\*)?$/i).fill(password)
  await page.getByRole('button', { name: /sign in|log in|se connecter/i }).click()
  await expect(page).not.toHaveURL(/\/login/)
  await page.goto('/settings/import/history')
  await expect(page.getByRole('row').nth(1)).toContainText('imp1-all-invalid.csv')
  await page.getByRole('button', { name: 'Failed', exact: true }).click()
  await expect(page.getByRole('row').filter({ hasText: 'imp1-hard-fail.csv' })).toBeVisible()
  await page.getByRole('button', { name: 'Completed with errors', exact: true }).click()
  await expect(page.getByRole('row').filter({ hasText: 'imp1-success.csv' })).toHaveCount(0)
  await expect(page.getByRole('row').filter({ hasText: 'imp1-partial.csv' })).toBeVisible()
  await page.getByRole('button', { name: 'Completed', exact: true }).click()
  await expect(page.getByRole('row').filter({ hasText: 'imp1-success.csv' })).toBeVisible()
  await expect(page.getByRole('row').filter({ hasText: 'imp1-partial.csv' })).toHaveCount(0)
  await page.evaluate(() => localStorage.setItem('autoerp-language', 'fr'))
  await page.reload()
  await expect(page.getByRole('button', { name: 'Télécharger les lignes à corriger' }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Télécharger les lignes à corriger' }).first().scrollIntoViewIfNeeded()
  await page.screenshot({ path: resolve(evidence, 'history-fr.png'), fullPage: true })
  await page.evaluate(() => localStorage.setItem('autoerp-language', 'ar'))
  await page.reload()
  await expect(page.getByRole('button', { name: 'تنزيل الصفوف التي تحتاج إلى تصحيح' }).first()).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  await page.getByRole('button', { name: 'تنزيل الصفوف التي تحتاج إلى تصحيح' }).first().scrollIntoViewIfNeeded()
  await page.screenshot({ path: resolve(evidence, 'history-ar-rtl.png'), fullPage: true })
  await page.evaluate(() => localStorage.setItem('autoerp-language', 'en'))
  await page.reload()
  const secondResponse = await request.post('http://localhost:8012/api/v1/companies', { headers: { ...authorization, 'X-Company-Id': company!.id }, data: { name: 'IMP1 Second Company', legal_name: 'IMP1 Second Company', country_code: 'TN', currency: 'TND', locale: 'en', timezone: 'Africa/Tunis' } })
  expect(secondResponse.status(), (await secondResponse.text()).slice(0, 300)).toBe(201)
  const second = await secondResponse.json() as { data: { id: string } }
  const history = await request.get('http://localhost:8012/api/v1/imports', { headers: { ...authorization, 'X-Company-Id': second.data.id } })
  expect(await history.json()).toMatchObject({ data: [] })
  await page.reload()
  await page.getByRole('button', { name: /select company/i }).click()
  await page.getByRole('button', { name: /IMP1 Second Company/ }).click()
  await expect(page.getByRole('row').filter({ hasText: 'imp1-' })).toHaveCount(0)
  await page.screenshot({ path: resolve(evidence, 'second-company-empty.png'), fullPage: true })
})
