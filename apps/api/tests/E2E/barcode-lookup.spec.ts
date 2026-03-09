import { test, expect } from '@playwright/test';

test.describe('Barcode Lookup', () => {
    test.beforeEach(async ({ page }) => {
        // Seed: Mechanic tenant, company (TN, TND), admin user
        // Login via API and store auth token
    });

    test('user scans barcode and product creation form is pre-populated with platform data', async ({ page }) => {
        // Navigate to product creation page
        await page.goto('/products/create');

        // Click barcode scan button
        await page.getByRole('button', { name: 'Scan Barcode' }).click();

        // Enter barcode manually (simulating scan)
        await page.getByPlaceholder('Enter barcode').fill('4005209123456');
        await page.getByRole('button', { name: 'Look Up' }).click();

        // Wait for platform lookup result
        await expect(page.getByTestId('lookup-result')).toBeVisible();
        await expect(page.getByText('Bosch Brake Pad Set')).toBeVisible();

        // Click "Use this data" to pre-populate form
        await page.getByRole('button', { name: 'Use This Data' }).click();

        // Verify form fields are pre-populated
        await expect(page.getByLabel('Product Name')).toHaveValue('Bosch Brake Pad Set');
        await expect(page.getByLabel('SKU')).toHaveValue('Bosch-0986494123');
        await expect(page.getByLabel('Barcode')).toHaveValue('4005209123456');

        // Verify automotive metadata is pre-populated
        await expect(page.getByLabel('Article Number')).toHaveValue('0986494123');
        await expect(page.getByLabel('Supplier Brand')).toHaveValue('Bosch');
        await expect(page.getByLabel('Platform Link Status')).toHaveValue('linked');
    });

    test('user scans unknown barcode and sees not-found message', async ({ page }) => {
        // Navigate to product creation page
        await page.goto('/products/create');

        // Click barcode scan button
        await page.getByRole('button', { name: 'Scan Barcode' }).click();

        // Enter unknown barcode
        await page.getByPlaceholder('Enter barcode').fill('9999999999999');
        await page.getByRole('button', { name: 'Look Up' }).click();

        // Should see not-found message
        await expect(page.getByText('No matching article found')).toBeVisible();

        // User can still create product manually
        await expect(page.getByRole('button', { name: 'Create Manually' })).toBeVisible();
    });

    test('user sees error message when platform is unavailable', async ({ page }) => {
        // Platform is down (circuit breaker open)
        // Navigate to product creation page
        await page.goto('/products/create');

        // Click barcode scan button and enter barcode
        await page.getByRole('button', { name: 'Scan Barcode' }).click();
        await page.getByPlaceholder('Enter barcode').fill('4005209123456');
        await page.getByRole('button', { name: 'Look Up' }).click();

        // Should see platform unavailable message
        await expect(page.getByText('Platform temporarily unavailable')).toBeVisible();

        // User can still create product manually
        await expect(page.getByRole('button', { name: 'Create Manually' })).toBeVisible();
    });
});
