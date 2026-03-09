import { test, expect } from '@playwright/test';

/**
 * E2E Spec: Reservation Timer
 *
 * Prerequisites:
 * - Authenticated buyer user
 * - Cart with marketplace items that have active reservations
 * - Reservation expiry configured (default 24 hours)
 */

test.describe('Reservation Timer', () => {
    test('should display countdown timer for reserved items', async ({ page }) => {
        await page.goto('/cart');

        const timer = page.getByTestId('reservation-timer').first();
        await expect(timer).toBeVisible();

        // Should show hours:minutes format
        await expect(timer).toHaveText(/\d+h\s*\d+m/);
    });

    test('should show warning when reservation is expiring soon', async ({ page }) => {
        // This test assumes a reservation close to expiry exists
        await page.goto('/cart');

        const expiringItem = page.getByTestId('reservation-expiring-soon');
        if (await expiringItem.isVisible()) {
            await expect(expiringItem).toHaveClass(/warning/);
            await expect(page.getByTestId('reservation-warning-text')).toContainText(
                'expiring soon'
            );
        }
    });

    test('should show expired status and disable checkout for expired reservations', async ({
        page,
    }) => {
        // Navigate to cart with an expired reservation
        await page.goto('/cart');

        const expiredItem = page.getByTestId('reservation-expired');
        if (await expiredItem.isVisible()) {
            await expect(expiredItem).toHaveClass(/expired/);

            // Checkout should be disabled for expired items
            await page.getByTestId('cart-item-checkbox').first().check();
            await expect(page.getByTestId('marketplace-checkout-btn')).toBeDisabled();
        }
    });

    test('should enforce re-reserve limit on repeated add/remove', async ({ page }) => {
        await page.goto('/marketplace');

        // Add item to cart
        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('add-to-cart-btn').click();

        // Remove and re-add multiple times to hit limit
        for (let i = 0; i < 3; i++) {
            await page.goto('/cart');
            await page.getByTestId('remove-item-btn').first().click();
            await page.getByTestId('confirm-remove-btn').click();

            await page.goto('/marketplace');
            await page.getByTestId('listing-card').first().click();
            await page.getByTestId('add-to-cart-btn').click();
        }

        // On the 4th attempt, should see re-reserve limit error
        await page.goto('/cart');
        await page.getByTestId('remove-item-btn').first().click();
        await page.getByTestId('confirm-remove-btn').click();

        await page.goto('/marketplace');
        await page.getByTestId('listing-card').first().click();
        await page.getByTestId('add-to-cart-btn').click();

        await expect(page.getByTestId('error-message')).toContainText('Re-reserve limit exceeded');
    });

    test('should release reservation when item removed from cart', async ({ page }) => {
        await page.goto('/cart');

        const timerBefore = page.getByTestId('reservation-timer').first();
        await expect(timerBefore).toBeVisible();

        await page.getByTestId('remove-item-btn').first().click();
        await page.getByTestId('confirm-remove-btn').click();

        // Timer should no longer be visible for removed item
        await expect(timerBefore).not.toBeVisible();
    });
});
