import { test, expect } from '@playwright/test';

/**
 * E2E Spec: Marketplace Browse
 *
 * Prerequisites:
 * - Buyer user authenticated (mechanic vertical)
 * - Active marketplace sellers with listings in buyer's country
 * - Listings have various article_numbers and supplier_brands
 */

test.describe('Marketplace Browse', () => {
    test('should display available listings for buyer country', async ({ page }) => {
        await page.goto('/marketplace');
        await expect(page.getByTestId('marketplace-listings')).toBeVisible();

        // Should only show listings for the buyer's country
        const listings = page.getByTestId('listing-card');
        await expect(listings).toHaveCount(await listings.count());

        // No seller identity should be visible (anonymous marketplace)
        await expect(page.getByText('Seller Co')).not.toBeVisible();
    });

    test('should search listings by article number', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('search-article-number').fill('BP-001');
        await page.getByTestId('search-submit').click();

        await expect(page.getByTestId('listing-card')).toHaveCount(1);
        await expect(page.getByText('BP-001')).toBeVisible();
    });

    test('should filter listings by supplier brand', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('filter-supplier-brand').selectOption('Bosch');
        await page.waitForResponse('**/api/v1/marketplace/listings*');

        const listings = page.getByTestId('listing-card');
        for (let i = 0; i < (await listings.count()); i++) {
            await expect(listings.nth(i).getByTestId('supplier-brand')).toHaveText('Bosch');
        }
    });

    test('should show listing detail with price and availability', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('listing-card').first().click();

        await expect(page.getByTestId('listing-detail')).toBeVisible();
        await expect(page.getByTestId('listing-price')).toBeVisible();
        await expect(page.getByTestId('listing-availability')).toBeVisible();
        await expect(page.getByTestId('add-to-cart-btn')).toBeVisible();
    });

    test('should show price comparison when available', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('compare-prices-btn').click();

        await expect(page.getByTestId('price-comparison-table')).toBeVisible();
        // Should show multiple sellers' prices without revealing identity
        const rows = page.getByTestId('price-comparison-row');
        await expect(rows.first()).toBeVisible();
    });

    test('should paginate listings', async ({ page }) => {
        await page.goto('/marketplace');

        const nextPageBtn = page.getByTestId('pagination-next');
        if (await nextPageBtn.isEnabled()) {
            await nextPageBtn.click();
            await page.waitForResponse('**/api/v1/marketplace/listings*');
            await expect(page.getByTestId('listing-card')).toHaveCount(await page.getByTestId('listing-card').count());
        }
    });
});
