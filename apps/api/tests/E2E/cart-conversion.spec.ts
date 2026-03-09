import { test, expect } from '@playwright/test';

/**
 * E2E Spec: Cart Conversion
 *
 * Prerequisites:
 * - Authenticated user with catalog_cart.convert_po and catalog_cart.convert_so permissions
 * - Cart with catalog items (some with preferred suppliers, some without)
 * - Partner records (suppliers and customers) exist
 */

test.describe('Cart Conversion', () => {
    test('should convert catalog items to purchase order', async ({ page }) => {
        await page.goto('/cart');

        // Select catalog items
        await page.getByTestId('catalog-item-checkbox').first().check();
        await page.getByTestId('convert-btn').click();

        // Choose PO conversion
        await page.getByTestId('convert-to-po').click();
        await page.getByTestId('confirm-convert-btn').click();

        await expect(page.getByTestId('conversion-success')).toBeVisible();
        await expect(page.getByTestId('created-document-link')).toBeVisible();
    });

    test('should group items by supplier for multiple POs', async ({ page }) => {
        await page.goto('/cart');

        // Select items from different suppliers
        await page.getByTestId('catalog-item-checkbox').nth(0).check();
        await page.getByTestId('catalog-item-checkbox').nth(1).check();
        await page.getByTestId('convert-btn').click();
        await page.getByTestId('convert-to-po').click();

        // Should show grouping preview
        await expect(page.getByTestId('supplier-group')).toHaveCount(2);
        await page.getByTestId('confirm-convert-btn').click();

        await expect(page.getByTestId('conversion-success')).toBeVisible();
        await expect(page.getByTestId('created-document-link')).toHaveCount(2);
    });

    test('should convert items to sales order with customer selection', async ({ page }) => {
        await page.goto('/cart');

        await page.getByTestId('catalog-item-checkbox').first().check();
        await page.getByTestId('convert-btn').click();
        await page.getByTestId('convert-to-so').click();

        // Select customer
        await page.getByTestId('customer-select').click();
        await page.getByTestId('customer-option').first().click();
        await page.getByTestId('confirm-convert-btn').click();

        await expect(page.getByTestId('conversion-success')).toBeVisible();
    });

    test('should set cart to partial when marketplace items remain', async ({ page }) => {
        await page.goto('/cart');

        // Cart has both catalog and marketplace items
        // Convert only catalog items
        await page.getByTestId('catalog-item-checkbox').first().check();
        await page.getByTestId('convert-btn').click();
        await page.getByTestId('convert-to-po').click();
        await page.getByTestId('confirm-convert-btn').click();

        await expect(page.getByTestId('conversion-success')).toBeVisible();
        await expect(page.getByTestId('cart-status')).toHaveText('partial');
    });

    test('should set cart to converted when all items processed', async ({ page }) => {
        await page.goto('/cart');

        // Select all catalog items
        await page.getByTestId('select-all-catalog').check();
        await page.getByTestId('convert-btn').click();
        await page.getByTestId('convert-to-po').click();
        await page.getByTestId('confirm-convert-btn').click();

        await expect(page.getByTestId('conversion-success')).toBeVisible();
        await expect(page.getByTestId('cart-status')).toHaveText('converted');
    });

    test('should not allow conversion without proper permission', async ({ page }) => {
        // Login as viewer (no convert permissions)
        await page.goto('/cart');

        await expect(page.getByTestId('convert-btn')).not.toBeVisible();
    });
});
