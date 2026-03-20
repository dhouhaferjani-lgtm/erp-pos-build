import { test, expect } from '@playwright/test'
import { register, uniqueName } from './helpers'

test.describe('Product smoke tests', () => {
  test('create a product and verify it appears in the list', async ({ page }) => {
    await register(page)

    // Navigate to products
    await page.goto('/products')
    await page.waitForLoadState('networkidle')

    // Click create
    await page.getByRole('link', { name: /new product|create|add/i }).first().click()
    await page.waitForLoadState('networkidle')

    const productName = uniqueName('Smoke-Product')
    await page.getByLabel(/name/i).first().fill(productName)
    await page.getByLabel(/sku|code/i).first().fill(uniqueName('SKU'))
    await page.getByLabel(/sell.*price|price/i).first().fill('99.99')

    await page.getByRole('button', { name: /save|create|submit/i }).first().click()
    await page.waitForLoadState('networkidle')

    // Go back to list and verify product exists
    await page.goto('/products')
    await expect(page.getByText(productName)).toBeVisible({ timeout: 10_000 })
  })
})
