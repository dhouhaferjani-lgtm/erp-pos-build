import { test, expect } from '@playwright/test';

test.describe('Vertical Scoping', () => {
    test('TireShop user only sees tire-related catalog entries', async ({ page }) => {
        // Seed: TireShop tenant, company (TN, TND), admin user
        // Login as TireShop user

        // Navigate to catalog
        await page.goto('/catalog');

        // Manufacturer list should be filtered to tire-relevant manufacturers
        await expect(page.getByText('Michelin')).toBeVisible();
        await expect(page.getByText('Continental')).toBeVisible();
        await expect(page.getByText('Bridgestone')).toBeVisible();

        // Navigate to search tree
        await page.goto('/catalog/search-tree');

        // Should only show tire-related categories
        await expect(page.getByText('Tires')).toBeVisible();
        await expect(page.getByText('Tire Accessories')).toBeVisible();

        // Search should automatically scope to tire products
        await page.goto('/catalog/search');
        await page.getByPlaceholder('Search articles...').fill('225/45 R17');
        await page.getByRole('button', { name: 'Search' }).click();

        // Results should only contain tire products
        await expect(page.getByTestId('search-results')).toBeVisible();
        // All results should have tire-related product groups
    });

    test('CarGlass user only sees glass-related catalog entries', async ({ page }) => {
        // Seed: CarGlass tenant, company (TN, TND), admin user
        // Login as CarGlass user

        // Navigate to catalog
        await page.goto('/catalog');

        // Manufacturer list should be filtered to glass-relevant manufacturers
        await expect(page.getByText('Pilkington')).toBeVisible();
        await expect(page.getByText('Saint-Gobain')).toBeVisible();

        // Navigate to search tree
        await page.goto('/catalog/search-tree');

        // Should only show glass-related categories
        await expect(page.getByText('Windshields')).toBeVisible();
        await expect(page.getByText('Side Windows')).toBeVisible();
    });

    test('Mechanic user sees full catalog without vertical filtering', async ({ page }) => {
        // Seed: Mechanic tenant, company (TN, TND), admin user
        // Login as Mechanic user

        // Navigate to catalog
        await page.goto('/catalog');

        // Should see all manufacturers across all product groups
        await expect(page.getByText('Bosch')).toBeVisible();
        await expect(page.getByText('Michelin')).toBeVisible();
        await expect(page.getByText('Pilkington')).toBeVisible();

        // Navigate to search tree
        await page.goto('/catalog/search-tree');

        // Should see all root categories
        await expect(page.getByText('Engine')).toBeVisible();
        await expect(page.getByText('Brakes')).toBeVisible();
        await expect(page.getByText('Suspension')).toBeVisible();
        await expect(page.getByText('Tires')).toBeVisible();
        await expect(page.getByText('Glass')).toBeVisible();
    });

    test('PartsRetailer user sees full catalog (no vertical scope)', async ({ page }) => {
        // Seed: PartsRetailer tenant, company (TN, TND), admin user
        // Login as PartsRetailer user

        // Navigate to catalog - should have full access like Mechanic
        await page.goto('/catalog');

        await expect(page.getByText('Bosch')).toBeVisible();
        await expect(page.getByText('Michelin')).toBeVisible();
        await expect(page.getByText('Pilkington')).toBeVisible();
    });
});
