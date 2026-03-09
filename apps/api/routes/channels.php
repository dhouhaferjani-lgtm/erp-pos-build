<?php

use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Product Cost Price Update Channel
 *
 * Private channel for real-time product cost/price updates.
 * Authorization ensures users can only subscribe to products in their tenant and company.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.product.{productId}
 * Event: product.cost-price-updated
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.product.{productId}', function (User $user, string $tenantId, string $companyId, string $productId) {
    return $user->canAccessChannel($tenantId, $companyId, $productId);
});

/**
 * Import Progress Channel
 *
 * Private channel for real-time import progress updates.
 * Authorization ensures users can only subscribe to imports in their tenant and company.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.imports
 * Events: import.progress, import.completed
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.imports', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessImportChannel($tenantId, $companyId);
});

/**
 * POS Terminal Activation Channel
 *
 * Private channel for real-time terminal activation notifications.
 * Authorization ensures users can only subscribe to terminals in their tenant and company.
 *
 * Channel pattern: private-tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}
 * Event: terminal.activated
 */
Broadcast::channel('tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}', function (User $user, string $tenantId, string $companyId) {
    return $user->canAccessImportChannel($tenantId, $companyId);
});
