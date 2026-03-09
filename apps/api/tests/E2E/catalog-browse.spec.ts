import { test, expect } from '@playwright/test';

test.describe('Catalog Browse', () => {
    test.beforeEach(async ({ page }) => {
        // Seed: Mechanic tenant, company (TN, TND), admin user
        // Login via API and store auth token
    });

    test('user can browse manufacturers → model series → vehicles → articles', async ({ page }) => {
        // Navigate to catalog page
        await page.goto('/catalog');

        // See list of manufacturers
        await expect(page.getByTestId('manufacturer-list')).toBeVisible();
        await expect(page.getByText('Toyota')).toBeVisible();
        await expect(page.getByText('Volkswagen')).toBeVisible();

        // Click on a manufacturer
        await page.getByText('Toyota').click();

        // See model series
        await expect(page.getByTestId('model-series-list')).toBeVisible();
        await expect(page.getByText('Corolla')).toBeVisible();

        // Click on a model series
        await page.getByText('Corolla').click();

        // See vehicles
        await expect(page.getByTestId('vehicle-list')).toBeVisible();
        await expect(page.getByText('Corolla 1.8')).toBeVisible();

        // Click on a vehicle
        await page.getByText('Corolla 1.8').click();

        // See compatible articles
        await expect(page.getByTestId('article-list')).toBeVisible();
        await expect(page.getByText('Brake Pad Set')).toBeVisible();
    });

    test('user can navigate search tree to find articles by category', async ({ page }) => {
        // Navigate to catalog search tree
        await page.goto('/catalog/search-tree');

        // See root categories
        await expect(page.getByText('Engine')).toBeVisible();
        await expect(page.getByText('Brakes')).toBeVisible();

        // Click on Brakes
        await page.getByText('Brakes').click();

        // See subcategories
        await expect(page.getByText('Brake Pads')).toBeVisible();

        // Click on Brake Pads
        await page.getByText('Brake Pads').click();

        // See articles in this category
        await expect(page.getByTestId('article-list')).toBeVisible();
    });

    test('user can search articles by article number', async ({ page }) => {
        // Navigate to catalog search
        await page.goto('/catalog/search');

        // Type article number
        await page.getByPlaceholder('Search articles...').fill('0986494123');
        await page.getByRole('button', { name: 'Search' }).click();

        // See search results
        await expect(page.getByTestId('search-results')).toBeVisible();
        await expect(page.getByText('Bosch Brake Pad Set')).toBeVisible();
    });

    test('articles show local inventory status when available', async ({ page }) => {
        // Seed: Product linked to platform article via automotive_metadata
        // Navigate to vehicle articles
        await page.goto('/catalog/vehicles/pc/v-001/articles');

        // Linked article should show "In Stock" badge
        await expect(page.getByTestId('in-stock-badge')).toBeVisible();

        // Unlinked article should show "Not in catalog" indicator
        await expect(page.getByTestId('not-in-stock-indicator')).toBeVisible();
    });
});
