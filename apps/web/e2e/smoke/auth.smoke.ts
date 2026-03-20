import { test, expect } from '@playwright/test'
import { register } from './helpers'

test.describe('Auth smoke tests', () => {
  test('register a new user and land on dashboard', async ({ page }) => {
    await register(page)
    // Dashboard should render something meaningful
    await expect(page.locator('[data-testid="dashboard"], main')).toBeVisible()
  })

  test('login page loads', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('button', { name: /sign in|log in/i })).toBeVisible()
  })
})
