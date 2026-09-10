import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
const __dirname = dirname(fileURLToPath(import.meta.url))
import { expect, test } from '@playwright/test'
import { campaignSelectors } from '../campaign/selectors'
import type { Page } from '@playwright/test'

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
const evidence = resolve(__dirname, '../../../../docs/superpowers/reviews/2026-09-10-imp1-evidence')

for (const name of ['imp1-success.csv', 'imp1-partial.csv', 'imp1-partial-async.csv', 'imp1-suppliers-balances-200.xlsx']) test(`IMP1 history and rows-to-fix: ${name}`, async ({ page }) => {
  mkdirSync(evidence, { recursive: true })
  await page.goto('/login')
  await page.getByLabel(/email/i).fill('imp1-evidence-20260910@test.otospex.dev')
  await page.getByLabel(/^(password|mot de passe)(\s*\*)?$/i).fill('Campaign!2026Safe')
  await page.getByRole('button', { name: /sign in|log in|se connecter/i }).click()
  await expect(page).not.toHaveURL(/\/login/, { timeout: 60_000 })
  {
    await test.step(name, async () => {
      await runImportWizard(page, name.includes('suppliers') ? 'parties' : 'products', resolve(fixtures, name))
      await page.screenshot({ path: resolve(evidence, `${name}-completion.png`), fullPage: true })
      if (name.includes('partial')) expect.soft(await page.getByRole('button', { name: /download rows to fix/i }).count(), `${name}: completion rows-to-fix action`).toBeGreaterThan(0)
      await page.goto('/settings/import/history')
      await page.screenshot({ path: resolve(evidence, `${name}-history-loading.png`), fullPage: true })
      const row = page.getByRole('row').filter({ hasText: name }).first()
      await expect(row).toBeVisible()
      writeFileSync(resolve(evidence, `${name}-history.txt`), await row.innerText())
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
      }
    })
  }
})
