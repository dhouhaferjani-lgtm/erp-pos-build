import { test, expect } from '@playwright/test';

/**
 * E2E Spec: Marketplace Checkout
 *
 * Prerequisites:
 * - Buyer user authenticated with marketplace.order permission
 * - Cart with marketplace items (reservations active)
 * - Seller is ERP tenant with stock
 */

test.describe('Marketplace Checkout', () => {
    test('should checkout marketplace items from cart', async ({ page }) => {
        await page.goto('/cart');

        // Select marketplace items
        await page.getByTestId('cart-item-checkbox').first().check();
        await page.getByTestId('marketplace-checkout-btn').click();

        // Confirmation dialog
        await expect(page.getByTestId('checkout-confirmation')).toBeVisible();
        await expect(page.getByTestId('checkout-total')).toBeVisible();
        await page.getByTestId('confirm-checkout-btn').click();

        // Should redirect to order confirmation
        await expect(page.getByTestId('order-success')).toBeVisible();
        await expect(page.getByTestId('order-number')).toBeVisible();
    });

    test('should show error when reservation expired', async ({ page }) => {
        await page.goto('/cart');

        // Select an item with expired reservation
        await page.getByTestId('expired-reservation-item').check();
        await page.getByTestId('marketplace-checkout-btn').click();

        await page.getByTestId('confirm-checkout-btn').click();

        await expect(page.getByTestId('error-message')).toContainText('reservation');
    });

    test('should alert on price change since cart addition', async ({ page }) => {
        // This test assumes a price changed between adding to cart and checkout
        await page.goto('/cart');

        await page.getByTestId('cart-item-checkbox').first().check();
        await page.getByTestId('marketplace-checkout-btn').click();

        // If price changed, should show warning
        const priceWarning = page.getByTestId('price-change-warning');
        if (await priceWarning.isVisible()) {
            await expect(priceWarning).toContainText('Price has changed');
            await page.getByTestId('accept-new-price-btn').click();
        }

        await page.getByTestId('confirm-checkout-btn').click();
        await expect(page.getByTestId('order-success')).toBeVisible();
    });

    test('should create PO in buyer system after checkout', async ({ page }) => {
        await page.goto('/cart');

        await page.getByTestId('cart-item-checkbox').first().check();
        await page.getByTestId('marketplace-checkout-btn').click();
        await page.getByTestId('confirm-checkout-btn').click();

        await expect(page.getByTestId('order-success')).toBeVisible();

        // Navigate to purchase orders
        const orderNumber = await page.getByTestId('order-number').textContent();
        await page.goto('/purchase-orders');

        // Should find the generated PO with marketplace reference
        await page.getByTestId('search-reference').fill(orderNumber ?? '');
        await expect(page.getByTestId('po-row')).toHaveCount(1);
    });

    test('should not allow checkout without marketplace.order permission', async ({ page }) => {
        // Login as viewer (no marketplace.order permission)
        await page.goto('/cart');

        await expect(page.getByTestId('marketplace-checkout-btn')).not.toBeVisible();
    });
});
