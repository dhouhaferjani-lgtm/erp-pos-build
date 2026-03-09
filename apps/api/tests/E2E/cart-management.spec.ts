import { test, expect } from '@playwright/test';

/**
 * E2E Spec: Cart Management
 *
 * Prerequisites:
 * - Authenticated user with catalog_cart.create permission
 * - Products available in catalog
 * - Marketplace listings available
 */

test.describe('Cart Management', () => {
    test('should create a new cart', async ({ page }) => {
        await page.goto('/cart');

        await page.getByTestId('create-cart-btn').click();
        await page.getByTestId('cart-name-input').fill('My Brake Job Cart');
        await page.getByTestId('save-cart-btn').click();

        await expect(page.getByTestId('cart-name')).toHaveText('My Brake Job Cart');
        await expect(page.getByTestId('cart-status')).toHaveText('active');
    });

    test('should add catalog item to cart', async ({ page }) => {
        await page.goto('/catalog');

        // Find a product and add to cart
        await page.getByTestId('product-card').first().click();
        await page.getByTestId('add-to-cart-btn').click();

        // Select quantity
        await page.getByTestId('quantity-input').fill('2');
        await page.getByTestId('confirm-add-btn').click();

        await expect(page.getByTestId('cart-badge')).toContainText('1');
    });

    test('should add manual item to cart', async ({ page }) => {
        await page.goto('/cart');

        await page.getByTestId('add-manual-item-btn').click();
        await page.getByTestId('manual-item-name').fill('Custom Gasket');
        await page.getByTestId('manual-item-quantity').fill('1');
        await page.getByTestId('manual-item-price').fill('15.500');
        await page.getByTestId('manual-item-notes').fill('Need exact dimensions');
        await page.getByTestId('save-manual-item-btn').click();

        await expect(page.getByTestId('cart-item')).toContainText('Custom Gasket');
    });

    test('should add marketplace item to cart with reservation', async ({ page }) => {
        await page.goto('/marketplace');

        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('quantity-input').fill('3');
        await page.getByTestId('add-to-cart-btn').click();

        // Should show reservation timer
        await page.goto('/cart');
        await expect(page.getByTestId('reservation-timer')).toBeVisible();
    });

    test('should remove item from cart', async ({ page }) => {
        await page.goto('/cart');

        const itemCount = await page.getByTestId('cart-item').count();
        await page.getByTestId('remove-item-btn').first().click();
        await page.getByTestId('confirm-remove-btn').click();

        await expect(page.getByTestId('cart-item')).toHaveCount(itemCount - 1);
    });

    test('should share cart with team members', async ({ page }) => {
        await page.goto('/cart');

        await page.getByTestId('share-cart-btn').click();
        await page.getByTestId('share-toggle').check();
        await page.getByTestId('team-member-select').selectOption({ index: 0 });
        await page.getByTestId('save-sharing-btn').click();

        await expect(page.getByTestId('shared-badge')).toBeVisible();
    });

    test('should update item quantity', async ({ page }) => {
        await page.goto('/cart');

        const quantityInput = page.getByTestId('item-quantity-input').first();
        await quantityInput.fill('5');
        await quantityInput.press('Enter');

        await page.waitForResponse('**/api/v1/catalog-carts/**/items/**');
        await expect(quantityInput).toHaveValue('5');
    });
});
