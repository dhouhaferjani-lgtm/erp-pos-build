<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\Broadcasting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Bug 1 — fire CatalogChannelEvent on catalog-model saved/deleted.
 *
 * Two binding shapes:
 *
 *   1. Direct-column models (Product, Menu) carry `tenant_id` + `company_id`
 *      directly. Bind with `Model::observe(CatalogModelObserver::class)` —
 *      Laravel resolves the observer from the container, the default
 *      no-resolver path reads the attributes off the model.
 *
 *   2. Relation-resolved models (MenuCategory, MenuCategoryItem) carry only
 *      a foreign key. Bind via `Event::listen('eloquent.saved: ...', fn) =>
 *      CatalogModelObserver::broadcastFor($tenantId, $companyId, $reason)`
 *      so the caller resolves the tenant context any way it likes before
 *      delegating to the same broadcast helper.
 *
 * `Model::observe(new CatalogModelObserver($closure))` would NOT work for
 * shape (2) because Laravel resolves the observer from the container by
 * class name, losing the closure passed to the constructor.
 *
 * Failures are swallowed and logged: an event-bus or broadcasting outage
 * must never block the underlying CRUD operation. Background polling (60s)
 * is the fallback for missed events.
 */
final class CatalogModelObserver
{
    public function saved(Model $model): void
    {
        $this->dispatch($model, 'saved');
    }

    public function deleted(Model $model): void
    {
        $this->dispatch($model, 'deleted');
    }

    private function dispatch(Model $model, string $action): void
    {
        /** @var mixed $tenantRaw */
        $tenantRaw = $model->getAttribute('tenant_id');
        /** @var mixed $companyRaw */
        $companyRaw = $model->getAttribute('company_id');

        if (! is_string($tenantRaw) || ! is_string($companyRaw) || $tenantRaw === '' || $companyRaw === '') {
            return;
        }

        self::broadcastFor(
            tenantId: $tenantRaw,
            companyId: $companyRaw,
            reason: sprintf('%s.%s', class_basename($model), $action),
            modelClass: $model::class,
        );
    }

    /**
     * Shared broadcast helper. Used directly by relation-resolved Event::listen
     * subscribers in MenuServiceProvider.
     */
    public static function broadcastFor(
        string $tenantId,
        string $companyId,
        string $reason,
        string $modelClass = '',
    ): void {
        try {
            broadcast(new CatalogChannelEvent(
                tenantId: $tenantId,
                companyId: $companyId,
                reason: $reason,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast CatalogChannelEvent', [
                'model' => $modelClass,
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
