import { test, expect } from '@playwright/test'
import { register } from './helpers'

test.describe('Settings smoke tests', () => {
  test('company settings page loads after registration', async ({ page }) => {
    await register(page)

    await page.goto('/settings')
    await page.waitForLoadState('networkidle')

    // Settings page should show company-related fields
    await expect(page.getByText(/company|settings|general/i).first()).toBeVisible()
  })
})
