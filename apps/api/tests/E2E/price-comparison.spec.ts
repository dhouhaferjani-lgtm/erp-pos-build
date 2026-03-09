import { test, expect } from '@playwright/test';

/**
 * E2E Spec: Price Comparison
 *
 * Prerequisites:
 * - Authenticated buyer user
 * - Multiple sellers with listings for the same article_number
 * - Sellers have varying prices and conditions
 */

test.describe('Price Comparison', () => {
    test('should show price comparison for an article across sellers', async ({ page }) => {
        await page.goto('/marketplace');

        // Search for a specific article
        await page.getByTestId('search-article-number').fill('BP-001');
        await page.getByTestId('search-submit').click();

        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('compare-prices-btn').click();

        const comparisonTable = page.getByTestId('price-comparison-table');
        await expect(comparisonTable).toBeVisible();

        // Should show anonymized sellers (Seller 1, Seller 2, etc.)
        const rows = page.getByTestId('price-comparison-row');
        const rowCount = await rows.count();
        expect(rowCount).toBeGreaterThan(0);

        // Each row should have price, condition, and availability
        for (let i = 0; i < rowCount; i++) {
            await expect(rows.nth(i).getByTestId('seller-label')).toHaveText(/Seller \d+/);
            await expect(rows.nth(i).getByTestId('price')).toBeVisible();
            await expect(rows.nth(i).getByTestId('availability-indicator')).toBeVisible();
        }
    });

    test('should sort prices from lowest to highest by default', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('search-article-number').fill('BP-001');
        await page.getByTestId('search-submit').click();
        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('compare-prices-btn').click();

        const prices = page.getByTestId('comparison-price');
        const priceCount = await prices.count();

        if (priceCount >= 2) {
            const firstPrice = parseFloat(
                (await prices.first().textContent()) ?? '0'
            );
            const secondPrice = parseFloat(
                (await prices.nth(1).textContent()) ?? '0'
            );
            expect(firstPrice).toBeLessThanOrEqual(secondPrice);
        }
    });

    test('should allow adding best price item to cart from comparison', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('search-article-number').fill('BP-001');
        await page.getByTestId('search-submit').click();
        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('compare-prices-btn').click();

        // Click add to cart on the best price row
        await page.getByTestId('comparison-add-to-cart').first().click();
        await page.getByTestId('quantity-input').fill('1');
        await page.getByTestId('confirm-add-btn').click();

        await expect(page.getByTestId('cart-badge')).toContainText('1');
    });

    test('should not reveal seller identity in comparison', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('search-article-number').fill('BP-001');
        await page.getByTestId('search-submit').click();
        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('compare-prices-btn').click();

        // Verify no real company names are shown
        const rows = page.getByTestId('price-comparison-row');
        const rowCount = await rows.count();

        for (let i = 0; i < rowCount; i++) {
            const sellerLabel = await rows.nth(i).getByTestId('seller-label').textContent();
            // Should only show anonymized labels like "Seller 1", "Seller 2"
            expect(sellerLabel).toMatch(/^Seller \d+$/);
        }
    });

    test('should show savings percentage compared to current listing', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('compare-prices-btn').click();

        const savingsIndicators = page.getByTestId('savings-percentage');
        if ((await savingsIndicators.count()) > 0) {
            await expect(savingsIndicators.first()).toHaveText(/%/);
        }
    });
});
